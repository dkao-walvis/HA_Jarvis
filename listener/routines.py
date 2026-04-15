#!/usr/bin/env python3
"""
HA Jarvis — Shared routine helpers
Used by morning_routine.py, dinner_routine.py, office_routine.py

LLM strategy: ONE Gemini call per day maximum.
The daily music query is generated once (by morning routine or first routine of the day)
and cached in the settings table. All other routines reuse it — no repeat LLM calls.
"""

import json
import logging
import os
import random
import requests
import pymysql
from datetime import date

log = logging.getLogger("routines")

JARVIS_API   = "http://localhost/HA_Jarvis/api.php"
FLIPFLOP_ENV = "/var/www/html/flipflop/.env"
LOCAL_CODEX_URL = os.getenv("LOCAL_CODEX_URL", "http://127.0.0.1:3310/ask_codex")
LOCAL_CODEX_MODEL = os.getenv("LOCAL_CODEX_MODEL", "gpt-5.1-codex")

DAILY_QUERY_DATE_KEY   = "daily_music_query_date"
DAILY_QUERY_MORNING    = "daily_music_query_morning"
DAILY_QUERY_DINNER     = "daily_music_query_dinner"
DAILY_QUERY_OFFICE     = "daily_music_query_office"
DAILY_QUERY_OFFICE_POOL = "daily_music_query_office_pool"

# legacy key kept for dashboard display
DAILY_QUERY_KEY = "daily_music_query"

# ── Env ───────────────────────────────────────────────────────────────────────

def load_env(path: str) -> dict:
    env = {}
    if not os.path.exists(path):
        return env
    with open(path) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            k, v = line.split('=', 1)
            env[k.strip()] = v.strip().strip('"').strip("'")
    return env

# ── DB ────────────────────────────────────────────────────────────────────────

def get_db(db_pass: str) -> pymysql.Connection:
    return pymysql.connect(
        host='localhost', db='jarvis_ha', user='admin', password=db_pass,
        charset='utf8mb4', autocommit=True,
        cursorclass=pymysql.cursors.DictCursor
    )

def db_get(conn: pymysql.Connection, key: str) -> str | None:
    with conn.cursor() as cur:
        cur.execute("SELECT `value` FROM settings WHERE `key` = %s", (key,))
        row = cur.fetchone()
    return row['value'] if row else None

def db_set(conn: pymysql.Connection, key: str, value: str) -> None:
    with conn.cursor() as cur:
        cur.execute(
            "INSERT INTO settings (`key`, `value`) VALUES (%s, %s) "
            "ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            (key, value)
        )

def get_weather(conn: pymysql.Connection) -> dict | None:
    with conn.cursor() as cur:
        cur.execute("""
            SELECT ec.state, ec.attributes
            FROM entity_cache ec
            JOIN entities e ON e.entity_id = ec.entity_id
            WHERE e.entity_id LIKE 'weather.%' AND e.enabled = 1
            LIMIT 1
        """)
        row = cur.fetchone()
    if not row:
        return None
    attrs = json.loads(row['attributes']) if isinstance(row['attributes'], str) else row['attributes']
    return {
        'condition':   row['state'],
        'temperature': attrs.get('temperature'),
        'humidity':    attrs.get('humidity'),
    }

def get_speaker(conn: pymysql.Connection, room: str) -> str | None:
    with conn.cursor() as cur:
        cur.execute("""
            SELECT friendly_name FROM entities
            WHERE entity_id LIKE 'media_player.%%'
            AND enabled = 1
            AND (LOWER(friendly_name) LIKE %s OR LOWER(entity_id) LIKE %s)
            LIMIT 1
        """, (f'%{room.lower()}%', f'%{room.lower()}%'))
        row = cur.fetchone()
    return row['friendly_name'] if row else None

def get_api_key(conn: pymysql.Connection) -> str | None:
    with conn.cursor() as cur:
        cur.execute("SELECT api_key FROM api_keys WHERE enabled = 1 AND label LIKE '%arvis%' LIMIT 1")
        row = cur.fetchone()
        if not row:
            cur.execute("SELECT api_key FROM api_keys WHERE enabled = 1 LIMIT 1")
            row = cur.fetchone()
    return row['api_key'] if row else None

# ── Daily music query cache ───────────────────────────────────────────────────

CONTEXT_KEY_MAP = {
    "morning": DAILY_QUERY_MORNING,
    "dinner":  DAILY_QUERY_DINNER,
    "office":  DAILY_QUERY_OFFICE,
}

def get_daily_query(conn: pymysql.Connection, context: str) -> str | None:
    """Return today's cached query for a given context, or None if not generated yet."""
    stored_date = db_get(conn, DAILY_QUERY_DATE_KEY)
    key         = CONTEXT_KEY_MAP.get(context, DAILY_QUERY_MORNING)
    stored      = db_get(conn, key)
    if stored_date == str(date.today()) and stored:
        log.info(f"Reusing today's {context} query: {stored}")
        return stored
    return None

def save_daily_queries(conn: pymysql.Connection, queries: dict) -> None:
    """Save all context queries and update date + dashboard key."""
    for context, key in CONTEXT_KEY_MAP.items():
        if context in queries:
            db_set(conn, key, queries[context])
            log.info(f"Saved {context} query: {queries[context]}")
    if 'office_pool' in queries:
        db_set(conn, DAILY_QUERY_OFFICE_POOL, json.dumps(queries['office_pool']))
        log.info(f"Saved office pool: {queries['office_pool']}")
    db_set(conn, DAILY_QUERY_DATE_KEY, str(date.today()))
    db_set(conn, DAILY_QUERY_KEY, queries.get("morning", ""))

# ── Local Codex ───────────────────────────────────────────────────────────────

def ask_local_codex(weather: dict) -> dict:
    """
    ONE call per day. Returns three YouTube playlist search queries:
    morning (upbeat), dinner (calm/relaxing), office (focus).
    Uses weather to tailor the mood. Always targets playlists, not long single videos.
    """
    condition   = weather.get('condition', 'clear')
    temperature = weather.get('temperature')
    humidity    = weather.get('humidity')

    w = f"Weather today: {condition}"
    if temperature is not None:
        w += f", {temperature}°C"
    if humidity is not None:
        w += f", humidity {humidity}%"

    prompt = (
        f"{w}.\n\n"
        "Suggest YouTube search queries for background instrumental music playlists. "
        "Important rules:\n"
        "- Instrumental only — absolutely NO lyrics, NO vocals\n"
        "- Style can be jazz, classical, piano, lo-fi, ambient, or any mix — whatever fits the mood\n"
        "- Each query must find a PLAYLIST of multiple songs, NOT a single long video\n"
        "- Add 'playlist' to each query\n"
        "- Morning: upbeat, energizing, positive — good for starting the day\n"
        "- Dinner: calm, warm, relaxing — good for a quiet evening meal\n"
        "- Office: 5 DIFFERENT focused, steady queries — good for concentration and deep work, each a different style\n"
        "- Tailor each to today's weather mood\n\n"
        "Reply with ONLY this exact format, nothing else:\n"
        "morning: <query>\n"
        "dinner: <query>\n"
        "office1: <query>\n"
        "office2: <query>\n"
        "office3: <query>\n"
        "office4: <query>\n"
        "office5: <query>"
    )

    payload = {
        "system": (
            "You generate concise YouTube playlist search queries. "
            "Follow the requested output format exactly with no extra text."
        ),
        "prompt": prompt,
        "model": LOCAL_CODEX_MODEL,
    }

    r = requests.post(LOCAL_CODEX_URL, json=payload, timeout=30)
    r.raise_for_status()
    data = r.json()
    text = data.get('reply', '').strip()
    if not text:
        raise ValueError("Empty response from local-codex")
    queries = {}
    office_pool = []
    for line in text.splitlines():
        if ':' in line:
            key, val = line.split(':', 1)
            key = key.strip().lower()
            val = val.strip()
            if key in ('morning', 'dinner'):
                queries[key] = val
            elif key.startswith('office'):
                office_pool.append(val)
    if office_pool:
        queries['office'] = office_pool[0]
        queries['office_pool'] = office_pool
    log.info(f"Local Codex generated queries: {queries}")
    return queries

def get_or_generate_query(conn: pymysql.Connection, context: str, fallback: str, force_refresh: bool = False) -> str:
    """
    Return today's cached query for context (morning/dinner/office).
    If none exists yet, call local-codex ONCE to generate all contexts and cache them.
    For office context, picks randomly from the daily pool on each call.
    """
    cached = get_daily_query(conn, context)
    if cached:
        if context == 'office':
            pool_json = db_get(conn, DAILY_QUERY_OFFICE_POOL)
            if pool_json:
                pool = json.loads(pool_json)
                if pool:
                    pick = random.choice(pool)
                    log.info(f"Picked office query from pool: {pick}")
                    return pick
        return cached

    weather = get_weather(conn)
    if not weather:
        log.warning("No weather in cache — using fallback query")
        return fallback

    try:
        queries = ask_local_codex(weather)
        if not queries:
            raise ValueError("Empty response from local-codex")
        save_daily_queries(conn, queries)
        return queries.get(context, fallback)
    except Exception as e:
        log.error(f"local-codex failed: {e} — using fallback query")
        return fallback

# ── Play / Stop ───────────────────────────────────────────────────────────────

def play_music(speaker: str, query: str, api_key: str) -> bool:
    r = requests.post(JARVIS_API, json={
        'action': 'play', 'entity_id': speaker, 'query': query, 'api_key': api_key
    }, timeout=30)
    result = r.json()
    if result.get('status') == 'ok':
        log.info(f"Playing '{result.get('playing')}' on {result.get('on')}")
        return True
    log.error(f"Play failed: {result.get('message')}")
    return False

def stop_music(speaker: str, api_key: str) -> bool:
    r = requests.post(JARVIS_API, json={
        'action': 'call', 'entity_id': speaker,
        'service': 'media_stop', 'api_key': api_key
    }, timeout=10)
    result = r.json()
    ok = result.get('status') == 'ok'
    log.info(f"Stopped music on {speaker}" if ok else f"Stop failed: {result.get('message')}")
    return ok

# ── Shared runners ────────────────────────────────────────────────────────────

def run_music_routine(db_pass: str, room: str, context: str, fallback_query: str, force_refresh: bool = False) -> bool:
    conn    = get_db(db_pass)
    speaker = get_speaker(conn, room)
    api_key = get_api_key(conn)

    if not speaker:
        log.error(f"No '{room}' speaker found in whitelist")
        return False
    if not api_key:
        log.error("No active API key found")
        return False

    query = get_or_generate_query(conn, context, fallback_query, force_refresh=force_refresh)
    return play_music(speaker, query, api_key)


def run_stop_routine(db_pass: str, room: str) -> bool:
    conn    = get_db(db_pass)
    speaker = get_speaker(conn, room)
    api_key = get_api_key(conn)
    if not speaker:
        log.error(f"No '{room}' speaker found in whitelist")
        return False
    return stop_music(speaker, api_key)
