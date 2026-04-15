#!/usr/bin/env python3
"""
HA Jarvis — yt-dlp Audio Service
Accepts a search query or YouTube URL, returns a direct audio stream URL.
Runs locally on Friday — not exposed externally.
"""

import logging
import sys
from flask import Flask, request, jsonify
import yt_dlp

app = Flask(__name__)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler("ytdlp_service.log"),
    ]
)
log = logging.getLogger("ytdlp")

YDL_OPTS = {
    'format':          'bestaudio/best',
    'noplaylist':      True,
    'quiet':           True,
    'no_warnings':     True,
    'extract_flat':    False,
    'default_search':  'ytsearch1',   # search YouTube if not a URL
}


def resolve_audio_url(query: str) -> dict:
    """Resolve a search query or URL to a direct audio stream URL."""
    with yt_dlp.YoutubeDL(YDL_OPTS) as ydl:
        info = ydl.extract_info(query, download=False)

        # If search result, pick first entry
        if 'entries' in info:
            info = info['entries'][0]

        # Find best audio-only format
        formats = info.get('formats', [])
        audio_formats = [f for f in formats if f.get('acodec') != 'none' and f.get('vcodec') == 'none']
        if not audio_formats:
            audio_formats = formats  # fallback to any format

        # Pick highest quality audio
        best = sorted(audio_formats, key=lambda f: f.get('abr') or 0, reverse=True)[0]

        return {
            'url':       best['url'],
            'title':     info.get('title', ''),
            'duration':  info.get('duration'),
            'thumbnail': info.get('thumbnail', ''),
            'webpage_url': info.get('webpage_url', ''),
        }


@app.route('/resolve', methods=['GET', 'POST'])
def resolve():
    if request.method == 'POST':
        data  = request.get_json() or {}
        query = data.get('query', '')
    else:
        query = request.args.get('query', '')

    if not query:
        return jsonify({'ok': False, 'message': 'Missing query'}), 400

    log.info(f"Resolving: {query}")
    try:
        result = resolve_audio_url(query)
        log.info(f"Resolved: {result['title']}")
        return jsonify({'ok': True, **result})
    except Exception as e:
        log.error(f"Failed to resolve '{query}': {e}")
        return jsonify({'ok': False, 'message': str(e)}), 500


@app.route('/health')
def health():
    return jsonify({'ok': True})


if __name__ == '__main__':
    log.info("yt-dlp service starting on port 18790")
    app.run(host='127.0.0.1', port=18790, debug=False)
