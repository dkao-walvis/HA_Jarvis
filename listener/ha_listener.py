#!/usr/bin/env python3
"""
HA Jarvis — Home Assistant WebSocket Listener
Subscribes to HA state changes and caches whitelisted entities in MySQL.
"""

import asyncio
import json
import logging
import os
import signal
import sys
import time
from datetime import datetime

import pymysql
import requests
import websockets
import morning_routine
import dinner_routine
import office_routine

# ── Config ────────────────────────────────────────────────────────────────────

VAULT_URL   = "http://100.69.36.45:8800"
VAULT_TOKEN = "184e36fd5df9423621dd3179a0a93d59dba3e168512aa1950f70310d688f21b1"

HA_URL      = "http://homeassistant:8123"
HA_WS_URL   = "ws://homeassistant:8123/api/websocket"

DB_HOST     = "localhost"
DB_NAME     = "jarvis_ha"
DB_USER     = "admin"

RECONNECT_DELAY  = 10   # seconds between reconnect attempts
LOG_LEVEL        = logging.INFO
ROUTINE_COOLDOWN = 300  # seconds — minimum gap between same routine firing (5 min)

# Cooldown tracker: routine_key → last fired timestamp
_last_fired: dict[str, float] = {}

# ── Logging ───────────────────────────────────────────────────────────────────

logging.basicConfig(
    level=LOG_LEVEL,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler(os.path.join(os.path.dirname(__file__), "ha_listener.log")),
    ]
)
log = logging.getLogger("ha_listener")

# ── Vault ─────────────────────────────────────────────────────────────────────

def vault_secret(name: str) -> str:
    r = requests.get(
        f"{VAULT_URL}/api/secrets/{name}",
        headers={"Authorization": f"Bearer {VAULT_TOKEN}"},
        timeout=5
    )
    r.raise_for_status()
    return r.json()["value"]

# ── MySQL ─────────────────────────────────────────────────────────────────────

def get_db(db_pass: str) -> pymysql.Connection:
    return pymysql.connect(
        host=DB_HOST, db=DB_NAME, user=DB_USER, password=db_pass,
        charset="utf8mb4", autocommit=True,
        cursorclass=pymysql.cursors.DictCursor
    )

def ensure_cache_table(conn: pymysql.Connection) -> None:
    with conn.cursor() as cur:
        cur.execute("""
            CREATE TABLE IF NOT EXISTS entity_cache (
                entity_id   VARCHAR(255) PRIMARY KEY,
                state       TEXT,
                attributes  JSON,
                last_changed DATETIME,
                cached_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        """)
    log.info("entity_cache table ready")

_whitelist_cache: set = set()
_whitelist_cache_ts: float = 0.0
WHITELIST_TTL_SECONDS = 60

def get_whitelisted(conn: pymysql.Connection) -> set:
    """Return the enabled-entity whitelist, cached for WHITELIST_TTL_SECONDS.

    Previously hit MySQL on EVERY state_changed event, which at ~100 events/sec
    during a Frigate/HA flap storm meant ~100 queries/sec just for this lookup.
    A 60s TTL cache is plenty fresh for the automation logic that consumes it.
    """
    global _whitelist_cache, _whitelist_cache_ts
    now = time.time()
    if _whitelist_cache and (now - _whitelist_cache_ts) < WHITELIST_TTL_SECONDS:
        return _whitelist_cache
    with conn.cursor() as cur:
        cur.execute("SELECT entity_id FROM entities WHERE enabled = 1")
        _whitelist_cache = {row["entity_id"] for row in cur.fetchall()}
    _whitelist_cache_ts = now
    return _whitelist_cache

def upsert_state(conn: pymysql.Connection, entity_id: str, state: str,
                 attributes: dict, last_changed: str) -> None:
    with conn.cursor() as cur:
        cur.execute("""
            INSERT INTO entity_cache (entity_id, state, attributes, last_changed, cached_at)
            VALUES (%s, %s, %s, %s, NOW())
            ON DUPLICATE KEY UPDATE
                state        = VALUES(state),
                attributes   = VALUES(attributes),
                last_changed = VALUES(last_changed),
                cached_at    = NOW()
        """, (entity_id, state, json.dumps(attributes), last_changed[:19] if last_changed else None))

# ── WebSocket listener ────────────────────────────────────────────────────────

async def listen(ha_token: str, db_pass: str) -> None:  # noqa: C901
    conn = get_db(db_pass)
    try:
        ensure_cache_table(conn)

        log.info(f"Connecting to HA WebSocket at {HA_WS_URL}")

        async with websockets.connect(HA_WS_URL, ping_interval=30, ping_timeout=10) as ws:

            # Step 1 — auth
            msg = json.loads(await ws.recv())
            assert msg["type"] == "auth_required", f"Expected auth_required, got {msg}"

            await ws.send(json.dumps({"type": "auth", "access_token": ha_token}))
            msg = json.loads(await ws.recv())
            assert msg["type"] == "auth_ok", f"Auth failed: {msg}"
            log.info("Authenticated with Home Assistant")

            # Step 2 — subscribe to all state changes
            await ws.send(json.dumps({"id": 1, "type": "subscribe_events", "event_type": "state_changed"}))
            msg = json.loads(await ws.recv())
            assert msg.get("success"), f"Subscribe failed: {msg}"
            log.info("Subscribed to state_changed events")

            # Step 3 — seed cache with current states of whitelisted entities
            await seed_cache(ws, conn)

            # Step 4 — process incoming events
            log.info("Listening for state changes...")
            msg_id = 10

            while True:
                try:
                    raw = await asyncio.wait_for(ws.recv(), timeout=60)
                except asyncio.TimeoutError:
                    # Refresh whitelist periodically even if no events
                    continue

                msg = json.loads(raw)
                if msg.get("type") != "event":
                    continue

                event = msg.get("event", {})
                if event.get("event_type") != "state_changed":
                    continue

                data      = event.get("data", {})
                entity_id = data.get("entity_id", "")
                new_state = data.get("new_state")

                if not new_state:
                    continue

                # Refresh whitelist on each event (lightweight query)
                whitelist = get_whitelisted(conn)

                if entity_id not in whitelist:
                    continue

                old_state_val = data.get("old_state", {}).get("state", "") if data.get("old_state") else ""
                state         = new_state.get("state", "")
                attributes    = new_state.get("attributes", {})
                last_changed  = new_state.get("last_changed", "")

                # Skip 'unavailable' transitions entirely. When an integration
                # (e.g. Frigate) flaps, every entity cycles available ↔ unavailable
                # at high rate; upserting every flap burns MySQL + log disk for
                # no automation value, since the last-known good state already
                # lives in entity_cache. Real recoveries (unavailable → real value)
                # still flow through normally.
                if state == "unavailable":
                    continue

                upsert_state(conn, entity_id, state, attributes, last_changed)
                log.debug(f"Cached {entity_id} = {state} (was {old_state_val})")

                # ── Morning routine: alarm disarmed ──────────────────────────────
                if (
                    "alarm_control_panel" in entity_id
                    and old_state_val in ("triggered", "armed_away", "armed_home", "armed_night")
                    and state == "disarmed"
                ):
                    log.info(f"Alarm disarmed ({entity_id}) — triggering morning routine")
                    asyncio.get_event_loop().run_in_executor(
                        None, morning_routine.run, db_pass
                    )

                # ── Office routine: DISABLED — moved to HA automation + Jarvis ────
                # Office music is now handled by:
                #   - HA automation "Office Music – Auto play when DK in office"
                #   - Jarvis Telegram bot (manual change via play_office.php)
                # if entity_id.startswith("input_boolean.") and any(k in entity_id.lower() for k in ("dk_in_office", "dk_working", "dk_office", "dkinoffice")):
                #     pass  # old office_routine code removed

                # ── Dinner routine: dinner time entity on/off ─────────────────────
                if "dinner" in entity_id.lower():
                    if old_state_val in ("off", "unavailable") and state == "on":
                        if time.time() - _last_fired.get("dinner_start", 0) >= ROUTINE_COOLDOWN:
                            _last_fired["dinner_start"] = time.time()
                            log.info(f"Dinner time ON ({entity_id}) — triggering dinner routine")
                            asyncio.get_event_loop().run_in_executor(
                                None, dinner_routine.on_dinner_start, db_pass
                            )
                        else:
                            log.info(f"Dinner time ON ({entity_id}) — skipped (cooldown)")
                    elif old_state_val == "on" and state == "off":
                        if time.time() - _last_fired.get("dinner_end", 0) >= ROUTINE_COOLDOWN:
                            _last_fired["dinner_end"] = time.time()
                            log.info(f"Dinner time OFF ({entity_id}) — stopping dinner music")
                            asyncio.get_event_loop().run_in_executor(
                                None, dinner_routine.on_dinner_end, db_pass
                            )
                        else:
                            log.info(f"Dinner time OFF ({entity_id}) — skipped (cooldown)")

    finally:
        conn.close()


async def seed_cache(ws, conn: pymysql.Connection) -> None:
    """Fetch current states of all whitelisted entities on startup."""
    whitelist = get_whitelisted(conn)
    if not whitelist:
        log.info("No whitelisted entities to seed")
        return

    log.info(f"Seeding cache for {len(whitelist)} whitelisted entities...")
    await ws.send(json.dumps({"id": 2, "type": "get_states"}))

    while True:
        msg = json.loads(await ws.recv())
        if msg.get("id") == 2:
            break

    seeded = 0
    for s in msg.get("result", []):
        entity_id = s.get("entity_id", "")
        if entity_id not in whitelist:
            continue
        upsert_state(
            conn,
            entity_id,
            s.get("state", ""),
            s.get("attributes", {}),
            s.get("last_changed", "")
        )
        seeded += 1

    log.info(f"Seeded {seeded} entities into cache")


# ── Main loop with reconnect ──────────────────────────────────────────────────

async def main() -> None:
    log.info("HA Jarvis Listener starting...")

    try:
        ha_token = vault_secret("openclaw_to_ha")
        db_pass  = vault_secret("mysql_admin_password")
        log.info("Secrets loaded from vault")
    except Exception as e:
        log.critical(f"Failed to load secrets from vault: {e}")
        sys.exit(1)

    while True:
        try:
            await listen(ha_token, db_pass)
        except websockets.exceptions.ConnectionClosed as e:
            log.warning(f"WebSocket closed: {e} — reconnecting in {RECONNECT_DELAY}s")
        except OSError as e:
            log.warning(f"Connection error: {e} — reconnecting in {RECONNECT_DELAY}s")
        except Exception as e:
            log.error(f"Unexpected error: {e} — reconnecting in {RECONNECT_DELAY}s")

        await asyncio.sleep(RECONNECT_DELAY)


def handle_signal(sig, frame):
    log.info("Shutting down...")
    sys.exit(0)


if __name__ == "__main__":
    signal.signal(signal.SIGINT,  handle_signal)
    signal.signal(signal.SIGTERM, handle_signal)
    asyncio.run(main())
