#!/usr/bin/env python3
"""
HA Jarvis — Dinner Routine
On:  play today's music query in kitchen
Off: stop kitchen music
"""

import logging
from routines import run_music_routine, run_stop_routine

log = logging.getLogger("dinner_routine")


def on_dinner_start(db_pass: str) -> None:
    log.info("Dinner time started — playing music in kitchen")
    run_music_routine(db_pass, room="kitchen", context="dinner", fallback_query="calm relaxing dinner instrumental playlist")


def on_dinner_end(db_pass: str) -> None:
    log.info("Dinner time ended — stopping kitchen music")
    run_stop_routine(db_pass, room="kitchen")
