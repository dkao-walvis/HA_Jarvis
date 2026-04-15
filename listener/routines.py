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
import re
import requests
import urllib.request
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

def run_music_routine(db_pass, room, context, fallback_query, trigger_source='routine'):
    """
    Pick a seed from music_seeds for `context`, filter out seeds played in the
    last 7 days (relax to 3, 1, any if exhausted), weighted random pick, ask
    Claude to refine it into a good query string, POST to api.php to play.
    Log the play to music_history.
    """
    import mysql.connector

    DB = dict(host='localhost', user='admin', password=db_pass, database='jarvis_brain')
    conn = mysql.connector.connect(**DB)
    cur  = conn.cursor(dictionary=True)

    # Speaker friendly-name mapping
    SPEAKER_BY_ROOM = {
        'office':    'Darren office speaker',
        'kitchen':   'Kitchen speaker',
        'game_room': 'Game room speaker',
    }
    speaker = SPEAKER_BY_ROOM.get(room, room)

    # Fetch enabled seeds for this context
    cur.execute("SELECT * FROM music_seeds WHERE context=%s AND enabled=1", (context,))
    seeds = cur.fetchall()
    if not seeds:
        _fallback_play(speaker, fallback_query, conn, cur, context, trigger_source, db_pass)
        conn.close()
        return

    # Filter by cooldown — try 7d, then 3d, then 1d, then any
    eligible = []
    for days in (7, 3, 1, 0):
        cur.execute("""
            SELECT DISTINCT seed_id FROM music_history
            WHERE context=%s AND seed_id IS NOT NULL AND ts >= NOW() - INTERVAL %s DAY
        """, (context, days))
        recent = {r['seed_id'] for r in cur.fetchall()}
        eligible = [s for s in seeds if s['id'] not in recent]
        if eligible or days == 0:
            break
    if not eligible:
        eligible = seeds  # last resort

    # Weighted random pick
    total = sum(s['weight'] for s in eligible) or 1
    roll = random.uniform(0, total)
    acc = 0
    chosen = eligible[0]
    for s in eligible:
        acc += s['weight']
        if roll <= acc:
            chosen = s
            break

    # Optional Claude refinement
    query = _ask_claude_refine_seed(chosen['seed_text'], chosen.get('genre'), context) or chosen['seed_text']

    # POST to api.php
    api_key = _load_ha_jarvis_api_key(db_pass)
    payload = json.dumps({
        'action':    'play',
        'entity_id': speaker,
        'query':     query,
        'api_key':   api_key,
    }).encode()
    req = urllib.request.Request(
        'http://localhost/HA_Jarvis/api.php',
        data=payload,
        headers={'Content-Type': 'application/json', 'X-API-Key': api_key},
    )
    try:
        urllib.request.urlopen(req, timeout=20).read()
    except Exception as e:
        _log_learning(cur, 'weight_change', context, chosen['id'], chosen['seed_text'],
                      None, None, f'play failed: {e}')
        conn.commit(); conn.close()
        return

    # Log history + bump counters
    cur.execute("""
        INSERT INTO music_history (context, seed_id, seed_text, speaker, trigger_source, query_sent)
        VALUES (%s, %s, %s, %s, %s, %s)
    """, (context, chosen['id'], chosen['seed_text'], speaker, trigger_source, query))
    cur.execute("""
        UPDATE music_seeds SET last_played_at=NOW(), play_count=play_count+1 WHERE id=%s
    """, (chosen['id'],))
    conn.commit()
    conn.close()
    return {'seed': chosen['seed_text'], 'query': query, 'speaker': speaker}


def _ask_claude_refine_seed(seed_text, genre, context):
    """Ask local Claude for a YouTube search query that produces a long playlist/radio for this seed.
    Return the query string, or None on any error."""
    try:
        prompt = (
            f"Seed: {seed_text}\n"
            f"Genre: {genre or 'unspecified'}\n"
            f"Context: {context}\n\n"
            f"Emit a single YouTube search query that returns a LONG playlist or radio "
            f"mix of this seed. Return only the query, no explanation, no quotes."
        )
        system = (
            "You are a music curator. Generate concise YouTube search queries "
            "that return long playlist/radio variants of the seed. One line only."
        )
        body = json.dumps({'prompt': prompt, 'context': system}).encode()
        req = urllib.request.Request(
            'http://100.69.36.45:3000/ask_claude',
            data=body,
            headers={'Content-Type': 'application/json'},
        )
        resp = urllib.request.urlopen(req, timeout=15).read()
        reply = json.loads(resp).get('reply', '').strip()
        if not _is_sane_query(reply):
            return None
        return reply
    except Exception:
        return None


def _is_sane_query(r):
    """Reject Claude replies that look like upstream errors or non-query content."""
    if not r:
        return False
    if len(r) > 200:
        return False
    if '{' in r:
        return False
    import re as _re
    if _re.search(r'\b(api error|http \d{3}|internal server error|rate limit|status\.claude\.com|503|502|429)\b', r, _re.I):
        return False
    if _re.match(r'\s*error\b', r, _re.I):
        return False
    return True


def _fallback_play(speaker, fallback_query, conn, cur, context, trigger_source, db_pass=None):
    """No seeds available — play the fallback query directly."""
    api_key = _load_ha_jarvis_api_key(db_pass)
    payload = json.dumps({
        'action': 'play', 'entity_id': speaker,
        'query': fallback_query, 'api_key': api_key,
    }).encode()
    req = urllib.request.Request(
        'http://localhost/HA_Jarvis/api.php',
        data=payload,
        headers={'Content-Type': 'application/json', 'X-API-Key': api_key},
    )
    try:
        urllib.request.urlopen(req, timeout=20).read()
    except Exception:
        pass
    cur.execute("""
        INSERT INTO music_history (context, seed_id, seed_text, speaker, trigger_source, query_sent)
        VALUES (%s, NULL, %s, %s, %s, %s)
    """, (context, fallback_query, speaker, trigger_source, fallback_query))
    conn.commit()


def _log_learning(cur, action, context, seed_id, seed_text, old, new, reason):
    cur.execute("""
        INSERT INTO music_learning_log (action, context, seed_id, seed_text, old_value, new_value, reason)
        VALUES (%s, %s, %s, %s, %s, %s, %s)
    """, (action, context, seed_id, seed_text, old, new, reason))


def _load_ha_jarvis_api_key(db_pass=None):
    """Read API key from env, or from jarvis_ha api_keys table via pymysql."""
    k = os.environ.get('HA_JARVIS_API_KEY')
    if k:
        return k
    # Fall back to reading from the jarvis_ha DB (same pattern as get_api_key helper)
    try:
        import pymysql as _pymysql
        # db_pass not passed in from _ask_claude_refine_seed path; read from env or load_env
        pw = db_pass or os.environ.get('DB_PASS', '')
        if not pw:
            env = load_env(FLIPFLOP_ENV)
            pw = env.get('DB_PASS', '')
        conn = _pymysql.connect(
            host='localhost', db='jarvis_ha', user='admin', password=pw,
            charset='utf8mb4', autocommit=True,
            cursorclass=_pymysql.cursors.DictCursor
        )
        with conn.cursor() as cur:
            cur.execute("SELECT api_key FROM api_keys WHERE enabled=1 AND label LIKE '%arvis%' LIMIT 1")
            row = cur.fetchone()
            if not row:
                cur.execute("SELECT api_key FROM api_keys WHERE enabled=1 LIMIT 1")
                row = cur.fetchone()
        conn.close()
        return row['api_key'] if row else ''
    except Exception:
        return ''


def run_stop_routine(db_pass: str, room: str) -> bool:
    conn    = get_db(db_pass)
    speaker = get_speaker(conn, room)
    api_key = get_api_key(conn)
    if not speaker:
        log.error(f"No '{room}' speaker found in whitelist")
        return False
    return stop_music(speaker, api_key)
