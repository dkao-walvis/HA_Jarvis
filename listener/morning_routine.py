#!/usr/bin/env python3
"""
HA Jarvis — Morning Routine
Triggered when alarm is disarmed in the morning.
Runs the morning report when the alarm is disarmed during morning hours.
"""

import logging
import os
import shutil
import subprocess
from datetime import datetime
from pathlib import Path

log = logging.getLogger("morning_routine")

MORNING_START = 5
MORNING_END   = 11
MORNING_REPORT_DIR = Path(__file__).resolve().parent / "morning_reports"
MORNING_REPORT_SCRIPT = MORNING_REPORT_DIR / "morning_report.js"
MORNING_REPORT_LOG = MORNING_REPORT_DIR / "morning_report.log"


def launch_morning_report() -> bool:
    if not MORNING_REPORT_SCRIPT.exists():
        log.warning(f"Morning report script not found: {MORNING_REPORT_SCRIPT}")
        return False

    node_path = shutil.which("node")
    if not node_path:
        log.error("Morning report skipped — 'node' is not installed or not on PATH")
        return False

    MORNING_REPORT_DIR.mkdir(parents=True, exist_ok=True)
    with open(MORNING_REPORT_LOG, "a", encoding="utf-8") as log_file:
        log_file.write(f"\n[{datetime.now().isoformat()}] Launching morning_report.js\n")
        subprocess.Popen(
            [node_path, str(MORNING_REPORT_SCRIPT)],
            cwd=MORNING_REPORT_DIR,
            stdout=log_file,
            stderr=subprocess.STDOUT,
            start_new_session=True,
            env=os.environ.copy(),
        )

    log.info("Morning report launched")
    return True


def run(db_pass: str) -> None:
    now = datetime.now()
    if not (MORNING_START <= now.hour < MORNING_END):
        log.info(f"Morning routine skipped — not morning (hour={now.hour})")
        return
    log.info("Morning routine — launching morning report")
    launch_morning_report()
