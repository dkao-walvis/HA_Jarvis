# HA Jarvis

A Home Assistant integration layer that bridges OpenClaw AI agents with the smart home.

## What It Does

HA Jarvis has three main responsibilities:
1. **Live state caching** — a Python daemon subscribes to Home Assistant via WebSocket and caches whitelisted entity states in a local MySQL database.
2. **Agent control API** — a PHP REST API lets OpenClaw agents read and control HA devices by friendly name without hitting Home Assistant directly every time.
3. **Voice proxy** — a Siri Shortcut endpoint that sends dictated commands to Claude (via jarvis_listener port 3000) with pre-fetched home state, and mirrors the exchange to Telegram.

## Architecture

```
Siri Shortcut (voice)
      │
      ▼
voice_proxy.php  ──── fetches entity states ────► api.php
      │                                                │
      ▼                                                ▼
jarvis_listener:3000                          Home Assistant
(real Claude CLI)                             100.94.244.25:8123
      │
      ▼
Telegram mirror (🎙️ + 🤖 in one message)

OpenClaw Agents (Telegram)
      │
      ▼
  api.php  (PHP REST API, auth via API keys)
      │
      ├── reads cached state from MySQL (jarvis_ha)
      └── proxies commands to Home Assistant REST API
                          ▲
                          │
          ha_listener.py  (Python WebSocket daemon)
          │  ├── subscribes to HA state changes
          │  ├── caches whitelisted entities in MySQL
          │  └── fires routines on trigger events
          │
          └── routines: morning, office, dinner
```

## Components

| File / Dir | Purpose |
|---|---|
| `listener/ha_listener.py` | WebSocket daemon — connects to HA, caches state, triggers routines |
| `listener/morning_routine.py` | Automation: morning scene triggers |
| `listener/office_routine.py` | Automation: office scene triggers |
| `listener/dinner_routine.py` | Automation: dinner scene triggers |
| `listener/routines.py` | Shared routine helpers |
| `listener/ytdlp_service.py` | yt-dlp media download service |
| `api.php` | REST API for agents to read/control HA entities |
| `admin.php` | Web admin console — manage API keys, audit log, kill switch |
| `config.php` | Central config — HA URL, DB credentials (fetched from Legion Vault) |
| `db.php` | MySQL PDO helpers |
| `voice_proxy.php` | Voice command proxy — Siri → Claude → Telegram mirror |
| `voice_debug.php` | Voice debug/testing endpoint |
| `flames/` | (TBD) |

## API

**Base URL:** `http://<host>/HA_Jarvis/api.php`

**Auth:** `X-API-Key: <key>` header or `?api_key=<key>` param

| Method | Example | Description |
|---|---|---|
| `GET` | `?action=get&entity_id=Office Light` | Get current state of an entity |
| `GET` | `?action=list` | List all allowed entities |
| `POST` | `{"action":"call","entity_id":"Office Light","service":"turn_on","data":{}}` | Call an HA service |

Entity IDs accept friendly names (`"Office Light"`) or raw HA IDs (`"light.dk_office"`).

## Voice Proxy

**Endpoint:** `POST http://<host>/HA_Jarvis/voice_proxy.php`
**Auth:** `X-API-Key: <key>`

Flow:
1. Receives dictated text from Siri Shortcut
2. Pre-fetches all entity states from `api.php?action=list`
3. Builds system prompt from Jarvis's workspace files (IDENTITY.md, SOUL.md, TOOLS.md, MEMORY.md) + current home state
4. Sends to `jarvis_listener:3000/v1/chat/completions` (real Claude CLI, no API key needed)
5. Mirrors `🎙️ <what you said>\n\n🤖 <reply>` to Telegram
6. Returns plain text reply to Siri

**No service restart needed** — PHP changes take effect immediately.

## Infrastructure

- **Home Assistant:** `http://100.94.244.25:8123` (VM inside Tulong)
- **Database:** MySQL on localhost, database `jarvis_ha`
- **Jarvis API key (MySQL):** `edcad79dff035e1531518bed409b31eb25ef843b71ec20d9`
- **Secrets:** All credentials fetched from Legion Vault (`http://100.69.36.45:8800`)
  - `openclaw_to_ha` — HA long-lived access token
  - `mysql_admin_password` — DB password

## Listener Service

```bash
# Install
cd listener/
pip install -r requirements.txt

# Run as systemd service
sudo cp ha_listener.service /etc/systemd/system/
sudo systemctl enable --now ha_listener
sudo journalctl -u ha_listener -f
```

Routine cooldown: 5 minutes (same routine won't fire twice within 300s).

**Bug fixed (2026-03-26):** MySQL connection leak when HA is unreachable — `listen()` now wraps the DB connection in `try/finally: conn.close()`. Without this, the 10s reconnect loop exhausted MySQL's 151-connection limit, causing admin.php to return HTTP 0.

## Admin Console

`http://<host>/HA_Jarvis/admin.php` — password set in `config.php` (`ADMIN_PASSWORD`).

Features:
- Manage API keys (create, enable/disable)
- View audit log of all agent actions
- Global kill switch — blocks all API access instantly

## Jarvis Agent Config (OpenClaw)

Jarvis's workspace is at `/home/darren/.openclaw/workspace-jarvis/`. Key files:
- `IDENTITY.md` / `SOUL.md` — who Jarvis is
- `TOOLS.md` — HA_Jarvis API instructions (use this, not direct HA curl)
- `MEMORY.md` — persistent memory (entity IDs, preferences)
- `OPENCLAW_AGENT_GUIDE.md` — full HA gateway reference

**openclaw.json** must have `workspace` set on Jarvis's agent entry:
```json
{ "id": "jarvis", "model": {...}, "workspace": "/home/darren/.openclaw/workspace-jarvis" }
```

If Jarvis doesn't know who he is after a model change:
1. Check `openclaw.json` — ensure `workspace` points to `workspace-jarvis` not `workspace-friday`
2. Delete the stale session JSONL: `rm /home/darren/.openclaw/agents/jarvis/sessions/*.jsonl`
3. Restart gateway: `~/.openclaw/scripts/gateway_restart.sh`
