"""Flames Connect API client — auth, token cache, fireplace control."""

import base64
import hashlib
import json
import logging
import os
import re
import secrets
import struct
import time
import urllib.parse

import requests

from .const import (
    API_BASE, API_HEADERS, CLIENT_ID, PARAM_BRIGHTNESS,
    PARAM_POWER, POLICY, REDIRECT_URI, SCOPE, TENANT,
)

_LOGGER = logging.getLogger(__name__)

TOKEN_CACHE = os.path.expanduser("~/.flameconnect_tokens.json")


class FlamesConnectError(Exception):
    pass


class FlamesConnectAPI:
    def __init__(self, email: str, password: str):
        self._email    = email
        self._password = password
        self._token    = None

    # ── Auth ──────────────────────────────────────────────────────────────────

    def _pkce_pair(self):
        verifier  = secrets.token_urlsafe(64)
        digest    = hashlib.sha256(verifier.encode()).digest()
        challenge = base64.urlsafe_b64encode(digest).rstrip(b"=").decode()
        return verifier, challenge

    def _b2c_login(self):
        verifier, challenge = self._pkce_pair()
        auth_params = {
            "client_id":             CLIENT_ID,
            "response_type":         "code",
            "redirect_uri":          REDIRECT_URI,
            "scope":                 SCOPE,
            "code_challenge":        challenge,
            "code_challenge_method": "S256",
            "state":                 secrets.token_urlsafe(16),
        }
        auth_url = (
            f"https://{TENANT}.b2clogin.com/{TENANT}.onmicrosoft.com"
            f"/{POLICY}/oauth2/v2.0/authorize?"
            + urllib.parse.urlencode(auth_params)
        )

        session = requests.Session()
        resp    = session.get(auth_url, allow_redirects=True)
        resp.raise_for_status()
        html     = resp.text
        page_url = resp.url

        csrf_match = re.search(r'"csrf"\s*:\s*"([^"]+)"', html)
        tx_match   = re.search(r'"transId"\s*:\s*"([^"]+)"', html)
        if not csrf_match or not tx_match:
            raise FlamesConnectError("Could not parse B2C login page")
        csrf = csrf_match.group(1)
        tx   = tx_match.group(1)

        parsed   = urllib.parse.urlparse(page_url)
        origin   = f"{parsed.scheme}://{parsed.netloc}"
        segments = parsed.path.strip("/").split("/")
        base     = f"/{segments[0]}/{POLICY}/"
        post_url      = f"{origin}{base}SelfAsserted?tx={tx}&p={POLICY}"
        confirmed_url = f"{origin}{base}api/CombinedSigninAndSignup/confirmed"

        cookies = {c.name: c.value for c in session.cookies}
        cookie_header = "; ".join(f"{k}={v}" for k, v in cookies.items())

        post_headers = {
            "X-CSRF-TOKEN":     csrf,
            "X-Requested-With": "XMLHttpRequest",
            "Referer":          auth_url,
            "Origin":           origin,
            "Accept":           "application/json, text/javascript, */*; q=0.01",
            "Content-Type":     "application/x-www-form-urlencoded; charset=UTF-8",
            "Cookie":           cookie_header,
        }

        resp = session.post(
            post_url,
            data={"request_type": "RESPONSE", "email": self._email, "password": self._password},
            headers=post_headers,
            allow_redirects=False,
        )
        resp.raise_for_status()
        try:
            body = resp.json()
            if str(body.get("status")) == "400":
                raise FlamesConnectError("Invalid email or password")
        except (json.JSONDecodeError, AttributeError):
            pass

        for raw in (resp.headers.getall("Set-Cookie") if hasattr(resp.headers, "getall") else [resp.headers.get("Set-Cookie", "")]):
            if raw:
                pair = raw.split(";", 1)[0]
                if "=" in pair:
                    k, v = pair.split("=", 1)
                    cookies[k] = v
        cookie_header = "; ".join(f"{k}={v}" for k, v in cookies.items())

        confirmed_qs = f"rememberMe=false&csrf_token={csrf}&tx={tx}&p={POLICY}"
        next_url     = confirmed_url + "?" + confirmed_qs
        get_headers  = {"Cookie": cookie_header}

        for _ in range(20):
            resp = session.get(next_url, headers=get_headers, allow_redirects=False)
            if resp.status_code in (301, 302, 303, 307, 308):
                location = resp.headers.get("Location", "")
                if location.startswith(f"msal{CLIENT_ID}://auth"):
                    params = urllib.parse.parse_qs(urllib.parse.urlparse(location).query)
                    code   = params.get("code", [None])[0]
                    if not code:
                        raise FlamesConnectError("No code in redirect URL")
                    return self._exchange_code(code, verifier)
                if not location.startswith("http"):
                    location = urllib.parse.urljoin(next_url, location)
                next_url = location
            elif resp.status_code == 200:
                match = re.search(rf"(msal{CLIENT_ID}://auth\?[^\s\"'<]+)", resp.text)
                if match:
                    params = urllib.parse.parse_qs(urllib.parse.urlparse(match.group(1)).query)
                    code   = params.get("code", [None])[0]
                    return self._exchange_code(code, verifier)
                raise FlamesConnectError("200 response but no redirect found")
            else:
                raise FlamesConnectError(f"Unexpected HTTP {resp.status_code}")

        raise FlamesConnectError("Too many redirects during auth")

    def _exchange_code(self, code, verifier):
        token_url = (
            f"https://{TENANT}.b2clogin.com/{TENANT}.onmicrosoft.com"
            f"/{POLICY}/oauth2/v2.0/token"
        )
        resp = requests.post(token_url, data={
            "grant_type":    "authorization_code",
            "client_id":     CLIENT_ID,
            "code":          code,
            "redirect_uri":  REDIRECT_URI,
            "code_verifier": verifier,
            "scope":         SCOPE,
        })
        resp.raise_for_status()
        return resp.json()

    def _refresh_token(self, refresh_token):
        token_url = (
            f"https://{TENANT}.b2clogin.com/{TENANT}.onmicrosoft.com"
            f"/{POLICY}/oauth2/v2.0/token"
        )
        resp = requests.post(token_url, data={
            "grant_type":    "refresh_token",
            "client_id":     CLIENT_ID,
            "refresh_token": refresh_token,
            "scope":         SCOPE,
        })
        resp.raise_for_status()
        return resp.json()

    def _save_tokens(self, tokens):
        tokens["saved_at"] = time.time()
        with open(TOKEN_CACHE, "w") as f:
            json.dump(tokens, f, indent=2)
        os.chmod(TOKEN_CACHE, 0o600)

    def _load_tokens(self):
        if not os.path.exists(TOKEN_CACHE):
            return None
        with open(TOKEN_CACHE) as f:
            return json.load(f)

    def get_access_token(self) -> str:
        tokens = self._load_tokens()
        if tokens and tokens.get("refresh_token"):
            age = time.time() - tokens.get("saved_at", 0)
            if age < tokens.get("expires_in", 3600) - 60:
                return tokens["access_token"]
            _LOGGER.debug("Token expired, refreshing")
            try:
                tokens = self._refresh_token(tokens["refresh_token"])
                self._save_tokens(tokens)
                return tokens["access_token"]
            except Exception as e:
                _LOGGER.warning("Token refresh failed: %s — re-authenticating", e)

        _LOGGER.info("Authenticating with Flames Connect")
        tokens = self._b2c_login()
        self._save_tokens(tokens)
        return tokens["access_token"]

    # ── API ───────────────────────────────────────────────────────────────────

    def _headers(self) -> dict:
        return {**API_HEADERS, "Authorization": f"Bearer {self.get_access_token()}"}

    def get_fires(self) -> list:
        resp = requests.get(f"{API_BASE}/api/Fires/GetFires", headers=self._headers())
        resp.raise_for_status()
        data = resp.json()
        return data if isinstance(data, list) else (data.get("fires") or data.get("Fires") or [])

    def get_fire_state(self, fire_id: str) -> dict:
        resp = requests.get(
            f"{API_BASE}/api/Fires/GetFireOverview",
            params={"FireId": fire_id},
            headers=self._headers(),
        )
        resp.raise_for_status()
        return resp.json()

    def _write_parameter(self, fire_id: str, param_id: int, value_b64: str):
        payload = {
            "FireId":      fire_id,
            "Parameters": [{"ParameterId": param_id, "Value": value_b64}],
        }
        resp = requests.post(
            f"{API_BASE}/api/Fires/WriteWifiParameters",
            json=payload,
            headers=self._headers(),
        )
        resp.raise_for_status()
        return resp.json()

    def set_power(self, fire_id: str, on: bool, temp_c: float = 20.0):
        mode   = 0x01 if on else 0x00
        temp_i = int(temp_c)
        temp_d = int((temp_c - temp_i) * 10)
        raw    = struct.pack("<HB", PARAM_POWER, 3) + bytes([mode, temp_i, temp_d])
        return self._write_parameter(fire_id, PARAM_POWER, base64.b64encode(raw).decode())

    def set_brightness(self, fire_id: str, brightness: int):
        """brightness: 0-255"""
        level = max(0, min(255, brightness))
        raw   = struct.pack("<HB", PARAM_BRIGHTNESS, 1) + bytes([level])
        return self._write_parameter(fire_id, PARAM_BRIGHTNESS, base64.b64encode(raw).decode())
