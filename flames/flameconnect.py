#!/usr/bin/env python3
"""
Flame Connect - Simple on/off test script
Submits credentials directly to Azure AD B2C (no browser needed).
"""

import json
import os
import re
import sys
import time
import secrets
import hashlib
import base64
import urllib.parse
import getpass
import requests

# ── Config ────────────────────────────────────────────────────────────────────
TENANT      = "gdhvb2cflameconnect"
CLIENT_ID   = "1af761dc-085a-411f-9cb9-53e5e2115bd2"
POLICY      = "B2C_1A_FirePhoneSignUpOrSignInWithPhoneOrEmail"
REDIRECT_URI = f"msal{CLIENT_ID}://auth"
SCOPE       = f"https://{TENANT}.onmicrosoft.com/Mobile/read offline_access openid"
API_BASE    = "https://mobileapi.gdhv-iot.com"
TOKEN_CACHE = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".flameconnect_tokens.json")

API_HEADERS = {
    "app_name":              "FlameConnect",
    "api_version":           "1.0",
    "app_version":           "2.22.0",
    "app_device_os":         "android",
    "device_version":        "14",
    "device_manufacturer":   "Python",
    "device_model":          "FlameConnectReader",
    "lang_code":             "en",
    "country":               "US",
    "logging_required_flag": "True",
    "Content-Type":          "application/json",
}

# ── PKCE ──────────────────────────────────────────────────────────────────────

def _pkce_pair():
    verifier  = secrets.token_urlsafe(64)
    digest    = hashlib.sha256(verifier.encode()).digest()
    challenge = base64.urlsafe_b64encode(digest).rstrip(b"=").decode()
    return verifier, challenge

# ── B2C direct login (no browser) ────────────────────────────────────────────

def _b2c_direct_login(email, password):
    """Submit credentials directly to B2C, return access + refresh tokens."""
    verifier, challenge = _pkce_pair()

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

    # Step 1: GET login page
    resp = session.get(auth_url, allow_redirects=True)
    resp.raise_for_status()
    html     = resp.text
    page_url = resp.url

    # Step 2: Parse CSRF + transId from page JS
    csrf_match = re.search(r'"csrf"\s*:\s*"([^"]+)"', html)
    tx_match   = re.search(r'"transId"\s*:\s*"([^"]+)"', html)
    if not csrf_match or not tx_match:
        raise RuntimeError("Could not parse B2C login page — policy name may be wrong")
    csrf = csrf_match.group(1)
    tx   = tx_match.group(1)

    # Build SelfAsserted + confirmed URLs
    parsed  = urllib.parse.urlparse(page_url)
    origin  = f"{parsed.scheme}://{parsed.netloc}"
    # Preserve mixed-case policy
    segments = parsed.path.strip("/").split("/")
    base    = f"/{segments[0]}/{POLICY}/"
    post_url      = f"{origin}{base}SelfAsserted?tx={tx}&p={POLICY}"
    confirmed_url = f"{origin}{base}api/CombinedSigninAndSignup/confirmed"

    # Step 3: POST credentials
    post_headers = {
        "X-CSRF-TOKEN":    csrf,
        "X-Requested-With": "XMLHttpRequest",
        "Referer":         auth_url,
        "Origin":          origin,
        "Accept":          "application/json, text/javascript, */*; q=0.01",
        "Content-Type":    "application/x-www-form-urlencoded; charset=UTF-8",
    }
    # Build unquoted cookie header (B2C needs plain values, not quoted)
    cookies = {c.name: c.value for c in session.cookies}
    cookie_header = "; ".join(f"{k}={v}" for k, v in cookies.items())
    post_headers["Cookie"] = cookie_header

    resp = session.post(
        post_url,
        data={"request_type": "RESPONSE", "email": email, "password": password},
        headers=post_headers,
        allow_redirects=False,
    )
    resp.raise_for_status()
    try:
        body = resp.json()
        if str(body.get("status")) == "400":
            raise RuntimeError("Invalid email or password")
    except (json.JSONDecodeError, AttributeError):
        pass

    # Merge new cookies
    for raw in resp.headers.getall("Set-Cookie") if hasattr(resp.headers, "getall") else [resp.headers.get("Set-Cookie", "")]:
        if raw:
            pair = raw.split(";", 1)[0]
            if "=" in pair:
                k, v = pair.split("=", 1)
                cookies[k] = v
    cookie_header = "; ".join(f"{k}={v}" for k, v in cookies.items())

    # Step 4: GET confirmed, follow redirects manually until custom scheme
    confirmed_qs = (
        f"rememberMe=false"
        f"&csrf_token={csrf}"
        f"&tx={tx}"
        f"&p={POLICY}"
    )
    next_url = confirmed_url + "?" + confirmed_qs
    get_headers = {"Cookie": cookie_header}

    for _ in range(20):
        resp = session.get(next_url, headers=get_headers, allow_redirects=False)
        if resp.status_code in (301, 302, 303, 307, 308):
            location = resp.headers.get("Location", "")
            if location.startswith(f"msal{CLIENT_ID}://auth"):
                # Extract code from redirect URL
                parsed_loc = urllib.parse.urlparse(location)
                params     = urllib.parse.parse_qs(parsed_loc.query)
                code       = params.get("code", [None])[0]
                if not code:
                    raise RuntimeError("No code in redirect URL")
                return _exchange_code(code, verifier)
            if not location.startswith("http"):
                location = urllib.parse.urljoin(next_url, location)
            next_url = location
        elif resp.status_code == 200:
            match = re.search(rf"(msal{CLIENT_ID}://auth\?[^\s\"'<]+)", resp.text)
            if match:
                parsed_loc = urllib.parse.urlparse(match.group(1))
                params     = urllib.parse.parse_qs(parsed_loc.query)
                code       = params.get("code", [None])[0]
                return _exchange_code(code, verifier)
            raise RuntimeError("200 response but no redirect found")
        else:
            raise RuntimeError(f"Unexpected HTTP {resp.status_code}")

    raise RuntimeError("Too many redirects")


def _exchange_code(code, verifier):
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

# ── Token cache ───────────────────────────────────────────────────────────────

def _save_tokens(tokens):
    tokens["saved_at"] = time.time()
    with open(TOKEN_CACHE, "w") as f:
        json.dump(tokens, f, indent=2)
    os.chmod(TOKEN_CACHE, 0o600)

def _load_tokens():
    if not os.path.exists(TOKEN_CACHE):
        return None
    with open(TOKEN_CACHE) as f:
        return json.load(f)

def _refresh(refresh_token):
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

def _vault_secret(name):
    token = "184e36fd5df9423621dd3179a0a93d59dba3e168512aa1950f70310d688f21b1"
    r = requests.get(
        f"http://100.69.36.45:8800/api/secrets/{name}",
        headers={"Authorization": f"Bearer {token}"},
        timeout=5,
    )
    r.raise_for_status()
    return r.json()["value"]

def get_access_token():
    tokens = _load_tokens()
    if tokens and tokens.get("refresh_token"):
        age = time.time() - tokens.get("saved_at", 0)
        if age < tokens.get("expires_in", 3600) - 60:
            return tokens["access_token"]
        try:
            tokens = _refresh(tokens["refresh_token"])
            _save_tokens(tokens)
            return tokens["access_token"]
        except Exception as e:
            pass  # fall through to re-login

    creds    = json.loads(_vault_secret("flameconnect_creds"))
    tokens   = _b2c_direct_login(creds["email"], creds["password"])
    _save_tokens(tokens)
    return tokens["access_token"]

# ── API ───────────────────────────────────────────────────────────────────────

def list_fires(token):
    resp = requests.get(
        f"{API_BASE}/api/Fires/GetFires",
        headers={**API_HEADERS, "Authorization": f"Bearer {token}"},
    )
    resp.raise_for_status()
    return resp.json()

def get_fire_state(token, fire_id):
    resp = requests.get(
        f"{API_BASE}/api/Fires/GetFireOverview",
        params={"FireId": fire_id},
        headers={**API_HEADERS, "Authorization": f"Bearer {token}"},
    )
    resp.raise_for_status()
    return resp.json()

def _encode_mode(on: bool, temp_c: float = 20.0) -> str:
    """Encode MODE parameter (321) as base64 binary blob.
    Header: 2-byte LE param ID + 1-byte payload size
    Payload: mode byte + temp integer + temp decimal tenth
    """
    import struct
    mode    = 0x01 if on else 0x00
    temp_i  = int(temp_c)
    temp_d  = int((temp_c - temp_i) * 10)
    raw     = struct.pack("<HB", 321, 3) + bytes([mode, temp_i, temp_d])
    return base64.b64encode(raw).decode("ascii")

def set_power(token, fire_id, on: bool):
    payload = {
        "FireId": fire_id,
        "Parameters": [{"ParameterId": 321, "Value": _encode_mode(on)}],
    }
    resp = requests.post(
        f"{API_BASE}/api/Fires/WriteWifiParameters",
        json=payload,
        headers={**API_HEADERS, "Authorization": f"Bearer {token}"},
    )
    resp.raise_for_status()
    return resp.json()

# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    if len(sys.argv) < 2 or sys.argv[1] not in ("on", "off", "status"):
        print("Usage: python flameconnect_test.py [on|off|status]")
        sys.exit(1)

    cmd   = sys.argv[1]
    token = get_access_token()

    fires = list_fires(token)
    print(f"Raw fires response:\n{json.dumps(fires, indent=2)}\n")

    fire_list = fires if isinstance(fires, list) else (
        fires.get("fires") or fires.get("Fires") or []
    )
    if not fire_list:
        print("No fires found.")
        sys.exit(1)

    fire     = fire_list[0]
    fire_id  = fire.get("FireId") or fire.get("fireId") or fire.get("id")
    fire_name = fire.get("FireName") or fire.get("fireName") or fire.get("name") or fire_id
    print(f"Using fire: {fire_name} (id={fire_id})\n")

    if cmd == "status":
        state = get_fire_state(token, fire_id)
        print(json.dumps(state, indent=2))
    elif cmd == "on":
        print("Turning ON...")
        print(json.dumps(set_power(token, fire_id, True), indent=2))
    elif cmd == "off":
        print("Turning OFF...")
        print(json.dumps(set_power(token, fire_id, False), indent=2))

if __name__ == "__main__":
    main()
