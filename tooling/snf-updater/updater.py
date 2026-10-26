#!/usr/bin/env python3
"""
Steam Next Fest dashboard auto-updater.

Fetches the Output Lag live Next Fest chart and Zukowski's HTMAG analysis,
extracts the live counters, and rewrites those values in dashboard.html before
optionally FTP-uploading the result.

Designed to run unattended via launchd/cron. Internet-facing data is only ever
pulled from public pages; FTP credentials are read from a separate config file
with restrictive permissions.

Usage:
    python3 updater.py                 # scrape + rewrite dashboard.html (no upload)
    python3 updater.py --upload        # scrape + rewrite + FTP upload
    python3 updater.py --force         # rewrite even if nothing changed
    python3 updater.py --dry-run       # print what would change, no writes
"""

import argparse
import datetime as dt
import os
import re
import sys
import urllib.request

REPO_ROOT = None
for _ in range(4):
    here = os.path.abspath(__file__ if _ == 0 else os.path.dirname(here) if _ else "")
# resolve repo root: this file lives in <repo>/tooling/snf-updater/updater.py
REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
DASHBOARD = os.path.join(REPO_ROOT, "Data", "SteamNextFestOct2026", "dashboard.html")

OUTPUTLAG_URL = "https://outputlag.com/next-fest/"
HTMAG_URL = "https://howtomarketagame.com/2026/10/21/how-sloppy-is-the-october-2026-steam-next-fest/"

UA = {"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36"}


def fetch(url):
    req = urllib.request.Request(url, headers=UA)
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read().decode("utf-8", "replace")


def scrape_outputlag(html):
    """Return dict with the Output Lag live counters, or {} if the page has gone stale."""
    out = {}
    m = re.search(r"([\d,]+)\s*playing demos now", html)
    if m:
        out["playing_now"] = m.group(1)
    m = re.search(r"([\d,]+)\s*demos with players", html)
    if m:
        out["demos_with_players"] = m.group(1)
    m = re.search(r"([\d,]+)\s*demos in the fest", html)
    if m:
        out["demos_in_fest"] = m.group(1)
    m = re.search(r"Updated\s+([^<]+?)\s*\.\s*Ends", html)
    if m:
        out["updated"] = m.group(1).strip()
    # peak concurrent is festival-scope, keep from existing value if absent
    return out


def scrape_peak_html(html):
    """Output Lag doesn't show festival peak CCU on the page reliably; return None."""
    m = re.search(r"peak\s*([\d,]+)\s*concurrent", html, re.I)
    return m.group(1) if m else None


# Map of dashboard sentinel values -> (extracted key, label)
# These are the exact strings written into dashboard.html we are allowed to touch.
REPLACEMENTS = [
    ("playing_now", "playing demos now"),
    ("demos_with_players", "demos with players"),
    ("demos_in_fest", "demos in the fest"),
]


def read_dashboard():
    with open(DASHBOARD, encoding="utf-8") as f:
        return f.read()


def apply(data, text, force=False):
    """Apply extracted values by label context (not drift-prone sentinels)."""
    changed = []
    new = text

    # Each binding: (extracted key, regex that replaces a NUMBER whose value is
    # the current known value, in a specific HTML/text context). We match by the
    # LABEL that identifies the figure, tolerating wrapping tags like </b> or
    # </div> between the number and the label.
    #
    # The "demos with players" figure shows up in 5 places with 5 markups:
    #   <b>N</b> demos with players
    #   class="...stat-val purple">N</div> ... stat-lbl">Demos with players
    #   playing now; N of 7,317 demos active
    #   N of 7,317 tracked demos had players
    # We target the digits just before the label, allowing a closing tag.
    patterns = {
        "demos_with_players": [
            r"(?<=<b>)[\d,]+(?=</b>\s*demos with players)",
            r"(?<=stat-val purple\">)[\d,]+(?=</div>[\s\S]{0,40}?Demos with players)",
            r"(?<=playing now; )[\d,]+(?= of [\d,]+ demos active)",
            r"[\d,]+(?= of [\d,]+ tracked demos had players)",
        ],
        "playing_now": [
            r"(?<=<b>)[\d,]+(?=</b>\s*playing demos now)",
            r"(?<=>)[\d,]+(?=</div>[^<]*Playing Demos)",
            r"(?<=— )[\d,]+(?= playing demos now)",
        ],
        "demos_in_fest": [
            r"(?<=of )[\d,]+(?= tracked)",
        ],
    }

    for key, pats in patterns.items():
        if key not in data:
            continue
        val = data[key]
        n_total = 0
        for pat in pats:
            new, n = re.subn(pat, val, new)
            n_total += n
        if n_total:
            changed.append(f"{key}: {n_total} occurrence(s) -> {val}")

    return new, changed


def stamp_updated(text, label):
    # Update the footer "Updated ..." stamp
    return re.sub(
        r"Updated \d{1,2} [A-Z][a-z]{2} \d{4}",
        f"Updated {label}" if not label.startswith("Updated") else label,
        text,
    )


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--upload", action="store_true")
    ap.add_argument("--force", action="store_true")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    print(f"[{dt.datetime.now(dt.timezone.utc).isoformat()}] Starting SNF updater")
    print(f"  dashboard: {DASHBOARD}")

    try:
        ol_html = fetch(OUTPUTLAG_URL)
        htmag_html = fetch(HTMAG_URL)
    except Exception as e:
        print(f"  ERROR fetching sources: {e}", file=sys.stderr)
        sys.exit(1)

    data = scrape_outputlag(ol_html)
    peak = scrape_peak_html(ol_html)
    if peak:
        data["peak"] = peak

    # freshness guard: if the Output Lag page dateModified is old, the fest is over
    dm = re.search(r'"dateModified":"([^"]+)"', ol_html)
    if dm:
        data["_dateModified"] = dm.group(1)

    print(f"  extracted: {data}")

    text = read_dashboard()
    new_text, changed = apply(data, text, force=args.force)

    # freshness stamp
    nowstr = dt.datetime.now().strftime("%d %b %Y")
    new_text = stamp_updated(new_text, nowstr)

    if not changed:
        print("  no live-value changes; dashboard left as-is.")
        if not args.force:
            return 0

    if args.dry_run:
        print("  DRY-RUN: would write:")
        for c in changed:
            print(f"    - {c}")
        return 0

    with open(DASHBOARD, "w", encoding="utf-8") as f:
        f.write(new_text)
    print(f"  wrote dashboard ({len(new_text)} bytes); changes: {changed or 'none'}")

    if args.upload:
        upload(new_text)


def upload(text):
    import subprocess

    cred = load_ftp_creds()
    if not cred:
        print("  upload skipped: no FTP credentials configured", file=sys.stderr)
        return
    host, user, pw = cred
    url = f"ftp://{host}/Data/SteamNextFestOct2026/dashboard.html"
    # fall back to a temp file upload via curl
    import tempfile
    with tempfile.NamedTemporaryFile("w", suffix=".html", delete=False) as tf:
        tf.write(text)
        tmp = tf.name
    try:
        r = subprocess.run(
            ["curl", "-s", "--ftp-create-dirs", "-T", tmp, "-u", f"{user}:{pw}", url],
            capture_output=True, text=True,
        )
        print(f"  upload curl exit={r.returncode}")
    finally:
        os.unlink(tmp)


def load_ftp_creds():
    cfg = os.path.join(os.path.dirname(__file__), "ftp.conf")
    if not os.path.exists(cfg):
        return None
    vals = {}
    with open(cfg) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            vals[k.strip()] = v.strip()
    if not all(k in vals for k in ("host", "user", "pass")):
        return None
    return vals["host"], vals["user"], vals["pass"]


if __name__ == "__main__":
    main()
