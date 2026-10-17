#!/usr/bin/env python3
"""
SportsGambler football injury scraper.

Ported from the standalone `sportsgambler_scraper.py` and aligned with the
other scrapers in this repo (see cron/scrape_standings.py):

  * `--json` prints the injury records (a JSON array) to stdout so the GitHub
    Actions workflow can POST them to https://predixa.co.tz/cron/fetch_injuries
  * console logging goes to stderr, so stdout stays valid JSON
  * `unique_id` is STABLE (league|team|player) so the receiver's
    `ON DUPLICATE KEY UPDATE` refreshes rows instead of piling up duplicates

Optional Google Sheets / CSV / SQLite exports are written when requested.
"""

import argparse
import csv
import hashlib
import json
import logging
import os
import re
import sqlite3
import sys
import time
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

import requests
from bs4 import BeautifulSoup

try:
    from googleapiclient.discovery import build
    from google.oauth2.service_account import Credentials

    GOOGLE_SHEETS_AVAILABLE = True
except ImportError:  # google libs are optional
    GOOGLE_SHEETS_AVAILABLE = False

BASE_DIR = Path(os.environ.get("SPORTSGAMBLER_BASE_DIR", str(Path(__file__).parent / "sportsgambler_data")))
BASE_DIR.mkdir(parents=True, exist_ok=True)
DB_PATH = BASE_DIR / "sportsgambler_injuries.db"
LOG_FILE = BASE_DIR / "scraper.log"

DEFAULT_SHEET_ID = os.environ.get("SPORTSGAMBLER_SHEET_ID", "1kgdy8cfFj1OLe-kSEM1D2r3lvm9BufGa3xJYyiI6t8A")
# Base name of the single, self-managed injuries tab. It is renamed to
# "<base>_<YYYYMMDD>" on every run so the tab always shows the current date
# without piling up a new tab per day.
SHEET_BASE_NAME = os.environ.get("SPORTSGAMBLER_SHEET_TAB", "Injuries")

DATE_STAMP = datetime.now(timezone.utc).strftime("%Y-%m-%d")
TIMESTAMP = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
SCRAPED_AT = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

# Log to stderr only — stdout must stay clean for --json output.
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(message)s",
    handlers=[logging.FileHandler(LOG_FILE, encoding="utf-8"), logging.StreamHandler(sys.stderr)],
)
logger = logging.getLogger("sportsgambler")

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                  "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
}

LEAGUE_URLS = [
    "https://www.sportsgambler.com/injuries/football/england-premier-league/",
    "https://www.sportsgambler.com/injuries/football/spain-la-liga/",
    "https://www.sportsgambler.com/injuries/football/germany-bundesliga/",
    "https://www.sportsgambler.com/injuries/football/italy-serie-a/",
    "https://www.sportsgambler.com/injuries/football/france-ligue-1/",
    "https://www.sportsgambler.com/injuries/football/belgium-first-division-a/",
    "https://www.sportsgambler.com/injuries/football/england-championship/",
    "https://www.sportsgambler.com/injuries/football/denmark-superliga/",
    "https://www.sportsgambler.com/injuries/football/germany-2-bundesliga/",
    "https://www.sportsgambler.com/injuries/football/portugal-primeira-liga/",
    "https://www.sportsgambler.com/injuries/football/uefa-champions-league/",
    "https://www.sportsgambler.com/injuries/football/uefa-europa-league/",
    "https://www.sportsgambler.com/injuries/football/switzerland-super-league/",
    "https://www.sportsgambler.com/injuries/football/usa-mls/",
    "https://www.sportsgambler.com/injuries/football/netherlands-eredivisie/",
    "https://www.sportsgambler.com/injuries/football/turkey-super-lig/",
    "https://www.sportsgambler.com/injuries/football/fifa-world-cup/",
]

LEAGUE_MAPPING = {
    "england-premier-league": "Premier League",
    "spain-la-liga": "La Liga",
    "germany-bundesliga": "Bundesliga",
    "italy-serie-a": "Serie A",
    "france-ligue-1": "Ligue 1",
    "belgium-first-division-a": "First Division A",
    "england-championship": "Championship",
    "denmark-superliga": "Superliga",
    "germany-2-bundesliga": "2. Bundesliga",
    "portugal-primeira-liga": "Primeira Liga",
    "uefa-champions-league": "Champions League",
    "uefa-europa-league": "Europa League",
    "switzerland-super-league": "Super League",
    "usa-mls": "MLS",
    "netherlands-eredivisie": "Eredivisie",
    "turkey-super-lig": "Super Lig",
    "fifa-world-cup": "World Cup",
}

POSITIONS = {"F": "Forward", "M": "Midfielder", "D": "Defender", "G": "Goalkeeper"}


def stable_uid(league: str, team: str, name: str) -> str:
    """Deterministic id so re-runs update the same row instead of duplicating."""
    raw = f"SG_{league}_{team}_{name}"
    uid = re.sub(r"[^A-Za-z0-9_]+", "_", raw).strip("_")
    if len(uid) > 190:
        uid = uid[:170] + "_" + hashlib.md5(raw.encode("utf-8")).hexdigest()[:16]
    return uid


def fetch_page(url: str, retries: int = 2):
    for attempt in range(retries + 1):
        try:
            logger.info("Fetching: %s", url)
            resp = requests.get(url, headers=HEADERS, timeout=30)
            resp.raise_for_status()
            return resp.text
        except requests.RequestException as e:
            if attempt < retries:
                logger.warning("Retry %d/%d for %s: %s", attempt + 1, retries, url, e)
                time.sleep(3)
            else:
                logger.error("Fetch failed for %s: %s", url, e)
                return None


def _num(span):
    if span is None:
        return None
    text = span.get_text().strip()
    return int(text) if text.isdigit() else None


def parse_injuries(html: str, league: str) -> list:
    """Parse SportsGambler's injury tables.

    Structure: an ``h3.injuries-title`` team heading followed by sibling
    ``div.inj-row`` blocks (each holding a ``div.inj-container`` with player
    spans) until the next team heading.
    """
    soup = BeautifulSoup(html, "html.parser")
    injuries = []
    scraped_at = datetime.now(timezone.utc).isoformat()
    scraped_date = datetime.now(timezone.utc).strftime("%Y-%m-%d")

    for heading in soup.find_all("h3", class_="injuries-title"):
        team = heading.get_text().strip()
        if not team:
            continue

        rows = []
        node = heading
        while True:
            node = node.find_next_sibling()
            if node is None:
                break
            classes = node.get("class") or []
            if node.name == "h3" and "injuries-title" in classes:
                break
            if node.name == "div" and "inj-row" in classes:
                rows.append(node)

        for row in rows:
            container = row.find("div", class_="inj-container")
            if not container:
                continue
            player_span = container.find("span", class_="inj-player")
            if not player_span:
                continue
            name = player_span.get_text().strip()
            if not name:
                continue

            position_span = container.find("span", class_="inj-position")
            pos_code = position_span.get_text().strip() if position_span else ""
            info_span = container.find("span", class_="inj-info")
            return_span = container.find("span", class_="inj-return")
            info = info_span.get_text().strip() if info_span else ""
            expected = return_span.get_text().strip() if return_span else ""
            expected = expected if expected and expected != "-" else None

            injuries.append({
                "league": league,
                "team": team,
                "type": info,
                "name": name,
                "position": POSITIONS.get(pos_code, pos_code),
                "position_code": pos_code,
                "matches": _num(container.find("span", class_="inj-game")),
                "goals": _num(container.find("span", class_="inj-goals")),
                "assists": _num(container.find("span", class_="inj-assist")),
                "info": info,
                "expected_return": expected,
                "scraped_at": scraped_at,
                "scraped_date": scraped_date,
                "unique_id": stable_uid(league, team, name),
            })

    logger.info("Parsed %d records for %s", len(injuries), league)
    return injuries


def get_league_name_from_url(url: str) -> str:
    for slug, name in LEAGUE_MAPPING.items():
        if slug in url:
            return name
    return url.rstrip("/").split("/")[-1].replace("-", " ").title()


# ── sinks ────────────────────────────────────────────────────────────
def save_to_json(data, stamp):
    path = BASE_DIR / f"sportsgambler_injuries_{stamp}.json"
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2, ensure_ascii=False)
    logger.info("JSON backup saved: %s", path)


def save_to_csv(data, stamp):
    if not data:
        return
    path = BASE_DIR / f"sportsgambler_injuries_{stamp}.csv"
    fields = ["league", "team", "name", "position", "position_code", "matches", "goals",
              "assists", "type", "info", "expected_return", "scraped_at", "scraped_date", "unique_id"]
    with open(path, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=fields, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(data)
    logger.info("CSV saved: %s", path)


def save_to_sqlite(data):
    conn = sqlite3.connect(DB_PATH)
    conn.execute(
        "CREATE TABLE IF NOT EXISTS injuries ("
        "unique_id TEXT PRIMARY KEY, league TEXT, team TEXT, name TEXT, position TEXT,"
        "position_code TEXT, type TEXT, info TEXT, expected_return TEXT, scraped_date TEXT)"
    )
    conn.executemany(
        "INSERT INTO injuries (unique_id, league, team, name, position, position_code, type, info,"
        "expected_return, scraped_date) VALUES (:unique_id, :league, :team, :name, :position,"
        ":position_code, :type, :info, :expected_return, :scraped_date) "
        "ON CONFLICT(unique_id) DO UPDATE SET league=excluded.league, team=excluded.team,"
        "name=excluded.name, position=excluded.position, position_code=excluded.position_code,"
        "type=excluded.type, info=excluded.info, expected_return=excluded.expected_return,"
        "scraped_date=excluded.scraped_date",
        data,
    )
    conn.commit()
    conn.close()
    logger.info("SQLite saved: %s", DB_PATH)


def ensure_daily_sheet(service, spreadsheet_id, base_name, date_str):
    """Return the name of a single injuries tab tagged with today's date.

    Instead of adding a new tab every run, this reuses ONE managed tab
    (any tab named ``<base>_*``): it renames the existing tab to
    ``<base>_<date_str>`` and deletes any other managed tabs so the
    spreadsheet never gets crowded.
    """
    target = f"{base_name}_{date_str}"
    meta = service.spreadsheets().get(spreadsheetId=spreadsheet_id).execute()
    props = [(s["properties"]["sheetId"], s["properties"]["title"]) for s in meta.get("sheets", [])]
    managed = [(sid, t) for sid, t in props if t == target or t.startswith(base_name + "_")]

    requests_batch = []
    keep_id = None
    if any(t == target for _, t in managed):
        keep_id = next(sid for sid, t in managed if t == target)
    elif managed:
        keep_id = managed[0][0]
        requests_batch.append({"updateSheetProperties": {
            "properties": {"sheetId": keep_id, "title": target}, "fields": "title"}})
        logger.info("Renamed tab '%s' -> '%s'", managed[0][1], target)
    else:
        requests_batch.append({"addSheet": {"properties": {"title": target}}})

    removed = []
    for sid, title in managed:
        if sid != keep_id and title != target:
            requests_batch.append({"deleteSheet": {"sheetId": sid}})
            removed.append(title)

    if requests_batch:
        service.spreadsheets().batchUpdate(
            spreadsheetId=spreadsheet_id, body={"requests": requests_batch}).execute()
    if removed:
        logger.info("Removed stale injury tab(s): %s", ", ".join(removed))
    return target


def write_to_google_sheets(service, spreadsheet_id, data, sheet_name):
    if not data:
        logger.warning("No data for %s, skipping Sheets", sheet_name)
        return
    headers = ["League", "Team", "Name", "Position", "Position Code", "Matches", "Goals",
               "Assists", "Injury Type", "Info", "Expected Return", "Scraped At",
               "Scraped Date", "Unique ID"]
    values = [headers]
    for r in data:
        values.append([r["league"], r["team"], r["name"], r["position"], r["position_code"],
                       r["matches"] if r["matches"] is not None else "",
                       r["goals"] if r["goals"] is not None else "",
                       r["assists"] if r["assists"] is not None else "",
                       r["type"], r["info"], r["expected_return"] or "",
                       r["scraped_at"], r["scraped_date"], r["unique_id"]])

    service.spreadsheets().values().clear(spreadsheetId=spreadsheet_id, range=f"'{sheet_name}'!A:Z").execute()
    resp = service.spreadsheets().values().update(
        spreadsheetId=spreadsheet_id, range=f"'{sheet_name}'!A1",
        valueInputOption="RAW", body={"values": values},
    ).execute()
    logger.info("Sheets '%s' updated (%s cells)", sheet_name, resp.get("updatedCells", 0))


def run(urls, formats, sheet_name=None):
    all_data = []
    for i, url in enumerate(urls, 1):
        league = get_league_name_from_url(url)
        logger.info("[%d/%d] %s", i, len(urls), league)
        html = fetch_page(url)
        if not html:
            continue
        rows = parse_injuries(html, league)
        all_data.extend(rows)
        if i < len(urls):
            time.sleep(1)

    if "json" in formats:
        save_to_json(all_data, TIMESTAMP)
    if "csv" in formats:
        save_to_csv(all_data, TIMESTAMP)
    if "sqlite" in formats:
        try:
            save_to_sqlite(all_data)
        except Exception as e:  # noqa: BLE001
            logger.warning("SQLite save failed: %s", e)
    if "google_sheets" in formats:
        cred = os.getenv("GOOGLE_CREDENTIALS_PATH", str(Path(__file__).parent / "google_credentials.json"))
        if GOOGLE_SHEETS_AVAILABLE and os.path.isfile(cred):
            try:
                creds = Credentials.from_service_account_file(
                    cred, scopes=["https://www.googleapis.com/auth/spreadsheets"])
                service = build("sheets", "v4", credentials=creds)
                tab = ensure_daily_sheet(service, DEFAULT_SHEET_ID,
                                         sheet_name or SHEET_BASE_NAME, DATE_STAMP.replace("-", ""))
                write_to_google_sheets(service, DEFAULT_SHEET_ID, all_data, tab)
            except Exception as e:  # noqa: BLE001
                logger.warning("Google Sheets save failed: %s", e)
        else:
            logger.info("Google Sheets skipped (no credentials / library)")

    if all_data:
        for league, count in sorted(Counter(r["league"] for r in all_data).items(), key=lambda x: -x[1]):
            logger.info("  - %s: %d", league, count)
    logger.info("Total: %d injury records", len(all_data))
    return all_data


def main(argv=None):
    parser = argparse.ArgumentParser(description="Scrape football injury data from SportsGambler")
    parser.add_argument("--json", action="store_true", help="Print records as a JSON array to stdout")
    parser.add_argument("--leagues", type=int, default=None, help="Limit to the first N leagues")
    parser.add_argument("--formats", nargs="+", default=["json", "google_sheets"],
                        choices=["json", "csv", "sqlite", "google_sheets"], help="Output sinks")
    parser.add_argument("--sheet-name", default=None,
                        help=f"Base name of the injuries tab (default: {SHEET_BASE_NAME})")
    args = parser.parse_args(argv)

    urls = LEAGUE_URLS[: args.leagues] if args.leagues else LEAGUE_URLS
    data = run(urls, args.formats, sheet_name=args.sheet_name)

    if args.json:
        sys.stdout.write(json.dumps(data))
        sys.stdout.write("\n")

    return 0 if data else 1


if __name__ == "__main__":
    raise SystemExit(main())
