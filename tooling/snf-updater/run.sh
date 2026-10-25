#!/bin/bash
# Wrapper for the SNF dashboard auto-updater. Logs to a timestamped file.
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
LOG="$DIR/run.log"
{
  echo "=== $(date '+%Y-%m-%d %H:%M:%S %Z') ==="
  /usr/bin/python3 "$DIR/updater.py" --upload
  echo
} >> "$LOG" 2>&1
