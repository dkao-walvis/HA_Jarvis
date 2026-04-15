#!/usr/bin/env python3
"""
HA Jarvis — Office Routine
On:  play today's music query on DK office speaker
Off: stop office music
"""

import logging
from routines import run_music_routine, run_stop_routine

log = logging.getLogger("office_routine")


def on_work_start(db_pass: str) -> None:
    log.info("DK in office — playing focus music on office speaker")
    run_music_routine(db_pass, room="office", context="office", fallback_query="focus instrumental playlist for work concentration")


def on_work_end(db_pass: str) -> None:
    log.info("DK left office — stopping office music")
    run_stop_routine(db_pass, room="office")
