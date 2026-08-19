#!/usr/bin/env python3
"""
Sofascore Match Statistics Scraper
Scrapes match stats (xG, possession, shots, corners, fouls, cards, etc.)
from Sofascore API using curl_cffi for TLS fingerprint impersonation.

Usage:
    python scrape_sofascore_stats.py [YYYY-MM-DD]
    python scrape_sofascore_stats.py 2026-08-16
    python scrape_sofascore_stats.py --post https://predixa.co.tz/cron/receive_sofascore_stats.php --key stats_cron_pred_xxx
"""

import json
import sys
import time
import urllib.request
import urllib.error
from datetime import datetime, timezone
from pathlib import Path

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

try:
    from curl_cffi import requests as cffi_requests
    session = cffi_requests.Session(impersonate="chrome131")
except ImportError:
    print("ERROR: curl_cffi not installed. Run: pip install curl_cffi")
    sys.exit(1)

HEADERS = {
    "Referer": "https://www.sofascore.com/",
    "Accept": "application/json",
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
}

LEAGUES = [
    {"tid": 8,   "sid": 97268, "name": "La Liga"},
    {"tid": 34,  "sid": 96127, "name": "Ligue 1"},
    {"tid": 35,  "sid": 97464, "name": "Bundesliga"},
    {"tid": 37,  "sid": 96143, "name": "Eredivisie"},
    {"tid": 39,  "sid": 96186, "name": "Danish Superliga"},
    {"tid": 40,  "sid": 97020, "name": "Allsvenskan"},
    {"tid": 41,  "sid": 98748, "name": "Veikkausliiga"},
    {"tid": 52,  "sid": 98080, "name": "Turkish Super Lig"},
    {"tid": 170, "sid": 97616, "name": "Croatian HNL"},
    {"tid": 196, "sid": 10979, "name": "J1 League"},
    {"tid": 202, "sid": 71227, "name": "Ekstraklasa"},
    {"tid": 215, "sid": 97019, "name": "Swiss Super League"},
    {"tid": 238, "sid": 97470, "name": "Liga Portugal Betclic"},
    {"tid": 242, "sid": 56953, "name": "MLS"},
    {"tid": 247, "sid": 87768, "name": "Parva Liga"},
    {"tid": 325, "sid": 87678, "name": "Serie A (Brazil)"},
    {"tid": 352, "sid": 99996, "name": "Liga MX"},
    {"tid": 410, "sid": 96740, "name": "K League 1"},
]

SOFA_TO_DB_MAP = {
    "Ball possession": "ball_possession",
    "Expected goals": "expected_goals",
    "Total shots": "total_shots",
    "Shots on target": "shots_on_goal",
    "Shots off target": "shots_off_goal",
    "Blocked shots": "blocked_shots",
    "Shots inside box": "shots_inside_box",
    "Shots outside box": "shots_outside_box",
    "Corner kicks": "corner_kicks",
    "Fouls": "fouls",
    "Free kicks": "free_kicks",
    "Yellow cards": "yellow_cards",
    "Goalkeeper saves": "goalkeeper_saves",
    "Accurate passes": "passes_accurate",
    "Passes": "total_passes",
    "Goals prevented": "goals_prevented",
    "Expected goals on target": "xgot",
}

REQUEST_DELAY = 2.0
request_count = 0


def api_get(url, retries=2):
    global request_count
    for attempt in range(retries + 1):
        try:
            resp = session.get(url, headers=HEADERS, timeout=30)
            request_count += 1
            if resp.status_code == 200:
                return resp.json()
            elif resp.status_code == 429:
                wait = 30 * (attempt + 1)
                print(f"    Rate limited, waiting {wait}s...")
                time.sleep(wait)
            else:
                if attempt < retries:
                    time.sleep(REQUEST_DELAY)
                else:
                    return None
        except Exception as e:
            if attempt < retries:
                time.sleep(REQUEST_DELAY)
            else:
                print(f"    Request failed: {e}")
                return None
    return None


def parse_stat_int(val):
    if val is None:
        return None
    s = str(val).replace("%", "").strip()
    try:
        return int(float(s))
    except (ValueError, TypeError):
        return None


def parse_stat_float(val):
    if val is None:
        return None
    s = str(val).replace("%", "").strip()
    try:
        return float(s)
    except (ValueError, TypeError):
        return None


def parse_possession(val):
    if val is None:
        return None
    s = str(val).replace("%", "").strip()
    try:
        return s + "%"
    except:
        return None


def extract_stats_from_sofa(stats_data):
    """Extract stats from Sofascore statistics response."""
    result = {}
    periods = stats_data.get("statistics", [])
    for period in periods:
        if period.get("period") != "ALL":
            continue
        for group in period.get("groups", []):
            for item in group.get("statisticsItems", []):
                name = item.get("name", "")
                home = item.get("home", "")
                away = item.get("away", "")
                db_key = SOFA_TO_DB_MAP.get(name)
                if db_key:
                    if db_key == "ball_possession":
                        result[f"home_{db_key}"] = parse_possession(home)
                        result[f"away_{db_key}"] = parse_possession(away)
                    elif db_key in ("expected_goals", "goals_prevented"):
                        result[f"home_{db_key}"] = parse_stat_float(home)
                        result[f"away_{db_key}"] = parse_stat_float(away)
                    else:
                        result[f"home_{db_key}"] = parse_stat_int(home)
                        result[f"away_{db_key}"] = parse_stat_int(away)
    return result


def get_match_event_details(event_id):
    """Get referee, venue, score from event details."""
    data = api_get(f"https://api.sofascore.com/api/v1/event/{event_id}")
    if not data:
        return {}
    event = data.get("event", {})
    home_score = event.get("homeScore", {}).get("current")
    away_score = event.get("awayScore", {}).get("current")
    referee = event.get("referee", {}).get("name") if event.get("referee") else None
    venue = None
    if event.get("venue"):
        venue = event["venue"].get("stadium", {}).get("name") if event["venue"].get("stadium") else None
    return {
        "home_score": home_score,
        "away_score": away_score,
        "referee": referee,
        "venue": venue,
    }


def discover_seasons(league):
    """Try to find the best season for a league (most recent with finished events)."""
    tid = league["tid"]
    r = api_get(f"https://api.sofascore.com/api/v1/unique-tournament/{tid}/seasons")
    if not r:
        return league.get("sid")
    seasons = r.get("seasons", [])
    if not seasons:
        return league.get("sid")
    for s in seasons[:3]:
        sid = s.get("id")
        yr = s.get("year", "")
        if "25/26" in yr or "26/27" in yr:
            return sid
    return seasons[0].get("id") if seasons else league.get("sid")


def main():
    global request_count

    target_date = sys.argv[1] if len(sys.argv) > 1 and not sys.argv[1].startswith("-") else datetime.now().strftime("%Y-%m-%d")
    post_url = None
    post_key = None
    fresh_seasons = False

    args = sys.argv[1:]
    i = 0
    while i < len(args):
        if args[i] == "--post" and i + 1 < len(args):
            post_url = args[i + 1]
            i += 2
        elif args[i] == "--key" and i + 1 < len(args):
            post_key = args[i + 1]
            i += 2
        elif args[i] == "--fresh":
            fresh_seasons = True
            i += 1
        else:
            i += 1

    print(f"=== Sofascore Stats Scraper ===")
    print(f"Date: {target_date}")
    print(f"Leagues: {len(LEAGUES)}")
    print()

    target_dt = datetime.strptime(target_date, "%Y-%m-%d")
    target_ts_start = int(target_dt.replace(tzinfo=timezone.utc).timestamp())
    target_ts_end = target_ts_start + 86400

    all_results = []
    total_events_checked = 0

    for league in LEAGUES:
        tid = league["tid"]
        sid = league.get("sid")
        name = league["name"]

        if fresh_seasons:
            sid = discover_seasons(league)
            time.sleep(REQUEST_DELAY)

        time.sleep(REQUEST_DELAY)
        data = api_get(f"https://api.sofascore.com/api/v1/unique-tournament/{tid}/season/{sid}/events/last/0")
        if not data:
            continue

        events = data.get("events", [])
        day_events = [
            e for e in events
            if target_ts_start <= e.get("startTimestamp", 0) < target_ts_end
        ]

        if not day_events:
            continue

        print(f"\n[{name}] {len(day_events)} events on {target_date}")

        for event in day_events:
            eid = event.get("id")
            home_team = event.get("homeTeam", {}).get("name", "")
            away_team = event.get("awayTeam", {}).get("name", "")
            status = event.get("status", {})
            status_type = status.get("type", "")

            if status_type != "finished":
                continue

            total_events_checked += 1
            print(f"  [{eid}] {home_team} vs {away_team}")

            time.sleep(REQUEST_DELAY)
            stats_data = api_get(f"https://api.sofascore.com/api/v1/event/{eid}/statistics")
            if not stats_data:
                print(f"    No stats available")
                continue

            stats = extract_stats_from_sofa(stats_data)
            if not stats:
                print(f"    Could not extract stats")
                continue

            time.sleep(REQUEST_DELAY)
            details = get_match_event_details(eid)

            result = {
                "sofascore_event_id": eid,
                "match_date": target_date,
                "league_name": name,
                "league_id_sofa": tid,
                "home_team": home_team,
                "away_team": away_team,
                "home_score": details.get("home_score"),
                "away_score": details.get("away_score"),
                "referee": details.get("referee"),
                "venue": details.get("venue"),
                "raw_statistics": json.dumps(stats_data.get("statistics", [])),
            }
            result.update(stats)

            all_results.append(result)
            h_sot = stats.get("home_shots_on_goal", "?")
            a_sot = stats.get("away_shots_on_goal", "?")
            h_xg = stats.get("home_expected_goals", "?")
            a_xg = stats.get("away_expected_goals", "?")
            print(f"    SOT={h_sot}/{a_sot} xG={h_xg}/{a_xg} Poss={stats.get('home_ball_possession', '?')}")

    output = {
        "date": target_date,
        "source": "sofascore",
        "total_events_checked": total_events_checked,
        "stats_collected": len(all_results),
        "requests_made": request_count,
        "matches": all_results,
    }

    output_path = Path(f"sofascore_stats_{target_date}.json")
    with open(output_path, "w", encoding="utf-8") as f:
        json.dump(output, f, indent=2, ensure_ascii=False)

    print(f"\n=== Done: {len(all_results)} stats collected ({request_count} requests) ===")
    print(f"Saved to {output_path}")

    if post_url and post_key:
        post_data = json.dumps(output).encode("utf-8")
        req = urllib.request.Request(
            post_url,
            data=post_data,
            headers={
                "Content-Type": "application/json",
                "X-Stats-Key": post_key,
                "User-Agent": "Sofascore-Scraper/1.0",
            },
        )
        try:
            resp = urllib.request.urlopen(req, timeout=60)
            body = resp.read().decode()
            print(f"Posted to server: {body}")
        except urllib.error.HTTPError as e:
            print(f"Post failed: HTTP {e.code} {e.read().decode()}")
        except Exception as e:
            print(f"Post failed: {e}")

    return output


if __name__ == "__main__":
    main()
