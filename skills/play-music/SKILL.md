---
name: play-music
description: Use this skill when a bot needs to play music on Home Assistant speakers through HA_Jarvis. It covers how to discover available speakers, choose the right target, start playback with a search query or YouTube URL, and stop playback without calling Home Assistant directly.
---

# Play Music

Use HA_Jarvis as the control layer. Do not call Home Assistant media services directly when this gateway is available.

## Workflow

1. List allowed entities first.
2. Pick a media player target by friendly name.
3. Start playback with HA_Jarvis `action=play`.
4. Use a short search query unless the user gave an exact URL.
5. If the user wants music stopped, call `media_stop` through HA_Jarvis.

## Start Playback

Send a `POST` to `api.php` with:

- `action`: `play`
- `entity_id`: friendly speaker name or raw HA entity id
- `query`: song name, artist, playlist query, or YouTube URL
- auth: `X-API-Key` header or `api_key` field

Example:

```bash
curl -s -X POST http://<host>/HA_Jarvis/api.php \
  -H 'Content-Type: application/json' \
  -H 'X-API-Key: <key>' \
  -d '{
    "action": "play",
    "entity_id": "Kitchen speaker",
    "query": "upbeat instrumental jazz playlist"
  }'
```

Notes:

- `action=play` resolves the query through the yt-dlp service, then casts the resulting audio stream to the speaker.
- The best result is usually a playlist query, not a single long ambience video.

## Stop Playback

Use `action=call` with `service=media_stop`.

```bash
curl -s -X POST http://<host>/HA_Jarvis/api.php \
  -H 'Content-Type: application/json' \
  -H 'X-API-Key: <key>' \
  -d '{
    "action": "call",
    "entity_id": "Kitchen speaker",
    "service": "media_stop"
  }'
```

## Discover Speakers

List allowed entities first if the target speaker is unclear:

```bash
curl -s 'http://<host>/HA_Jarvis/api.php?action=list&api_key=<key>'
```

Prefer friendly names returned by the API. Only use speakers that are explicitly allowed.

## Query Guidance

- Good: `focus instrumental playlist`
- Good: `cozy dinner jazz playlist`
- Good: exact YouTube URL supplied by the user
- Avoid: vague `play something`
- Avoid: direct Home Assistant `media_player.play_media` calls when HA_Jarvis can do it

## Response Pattern

After a successful call, tell the user what is playing and where.

Examples:

- `Playing calm piano on Kitchen speaker.`
- `Stopped music on Darren office speaker.`

If playback fails, surface the API error instead of guessing.
