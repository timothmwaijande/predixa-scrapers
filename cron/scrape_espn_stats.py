#!/usr/bin/env python3
"""
ESPN Match Statistics Scraper (fallback source)

ESPN's public API (site.api.espn.com) is keyless and has no anti-bot WAF,
so it is a reliable source for match stats when Sofascore is blocking the
local IP (403 challenge/ban).

The output JSON is identical in shape to scrape_sofascore_stats.py and is
posted to the same receiver (receive_sofascore_stats.php).

Usage:
    python scrape_espn_stats.py [YYYY-MM-DD]
    python scrape_espn_stats.py 2026-09-29 --post https://predixa.co.tz/cron/receive_sofascore_stats.php --key stats_cron_pred_xxx
    python scrape_espn_stats.py --league 17
"""

import json
import random
import sys
import time
import urllib.request
import urllib.error
from datetime import datetime

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

try:
    from curl_cffi import requests as cffi_requests
except ImportError:
    print("ERROR: curl_cffi not installed. Run: pip install curl_cffi")
    sys.exit(1)

# slug -> (sofascore tid, display name)
LEAGUES = [
    ("eng.1", 17, "Premier League"),
    ("esp.1", 8, "La Liga"),
    ("ita.1", 23, "Serie A"),
    ("ger.1", 35, "Bundesliga"),
    ("fra.1", 34, "Ligue 1"),
    ("ned.1", 37, "Eredivisie"),
    ("por.1", 238, "Liga Portugal Betclic"),
    ("tur.1", 52, "Turkish Super Lig"),
    ("uefa.champions", 7, "UEFA Champions League"),
    ("uefa.europa", 679, "UEFA Europa League"),
    ("uefa.europa.conf", 17015, "UEFA Conference League"),
    ("uefa.nations", 10783, "UEFA Nations League"),
    ("usa.1", 242, "MLS"),
    ("jpn.1", 196, "J1 League"),
    ("mex.1", 11621, "Liga MX, Apertura"),
    ("caf.nations", 270, "Africa Cup of Nations"),
    ("caf.nations_qual", 1848, "Africa Cup of Nations Qualifiers"),
]

REQUEST_DELAY = 1.2
request_count = 0
TARGET_DATE = None


def http_get_json(url, retries=2):
    global request_count
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                      "(KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36",
        "Accept": "application/json,text/plain,*/*",
        "Referer": "https://www.espn.com/",
        "Accept-Language": "en-US,en;q=0.9",
    }
    last = None
    for attempt in range(retries + 1):
        try:
            resp = cffi_requests.Session(impersonate="chrome136").get(url, headers=headers, timeout=35)
            request_count += 1
            if resp.status_code == 200:
                return resp.json()
            last = f"HTTP {resp.status_code}"
        except Exception as e:
            last = type(e).__name__
        if attempt < retries:
            time.sleep(2.5 * (attempt + 1))
    print(f"    Request failed for {url[:110]} ({last})")
    return None


def get_stat(stats, name):
    for s in stats or []:
        if s.get("name") == name:
            v = s.get("displayValue")
            return v
    return None


def to_int(v):
    if v is None:
        return None
    try:
        return int(float(str(v)))
    except (ValueError, TypeError):
        return None


def to_float(v):
    if v is None:
        return None
    try:
        return float(str(v).replace("%", "").strip())
    except (ValueError, TypeError):
        return None


def sides(scoreboard_comp):
    """Return (home_competitor, away_competitor) or (None, None)."""
    comps = scoreboard_comp.get("competitors", [])
    home = next((c for c in comps if c.get("homeAway") == "home"), None)
    away = next((c for c in comps if c.get("homeAway") == "away"), None)
    return home, away


def team_stats_side(comp, box_team):
    """Merge scoreboard-team stats with summary-boxscore stats -> field dict."""
    sb = comp.get("statistics", [])
    bx = box_team.get("statistics", []) if box_team else []
    stats = {s.get("name"): s.get("displayValue") for s in (sb + bx)}
    tot = to_int(stats.get("totalShots"))
    sot = to_int(stats.get("shotsOnTarget"))
    return {
        "shots_on_goal": sot,
        "shots_off_goal": tot - sot if (tot is not None and sot is not None) else None,
        "total_shots": tot,
        "blocked_shots": to_int(stats.get("blockedShots")),
        "shots_inside_box": None,
        "shots_outside_box": None,
        "ball_possession": f"{to_int(stats.get('possessionPct'))}%" if stats.get("possessionPct") not in (None, "") else None,
        "corner_kicks": to_int(stats.get("wonCorners")),
        "offsides": to_int(stats.get("offsides")),
        "free_kicks": None,
        "fouls": to_int(stats.get("foulsCommitted")),
        "yellow_cards": to_int(stats.get("yellowCards")),
        "red_cards": to_int(stats.get("redCards")),
        "goalkeeper_saves": to_int(stats.get("saves")),
        "total_passes": to_int(stats.get("totalPasses")),
        "passes_accurate": to_int(stats.get("accuratePasses")),
        "expected_goals": None,
        "goals_prevented": None,
    }


def extract_match(league, event):
    comp = event.get("competitions", [])[0]
    status = comp.get("status", {}).get("type", {})
    if status.get("state") != "post" or not status.get("completed"):
        return None
    home, away = sides(comp)
    if not home or not away:
        return None

    event_id = comp.get("id") or event.get("id")
    slug = league[0]
    summary = http_get_json(
        f"https://site.api.espn.com/apis/site/v2/sports/soccer/{slug}/summary?event={event_id}"
    )
    time.sleep(REQUEST_DELAY * random.uniform(0.7, 1.3))

    box_teams = {}
    if summary:
        for bt in (summary.get("boxscore") or {}).get("teams", []):
            box_teams[(bt.get("team") or {}).get("id")] = bt

    h = team_stats_side(home, box_teams.get(home.get("id")))
    a = team_stats_side(away, box_teams.get(away.get("id")))

    referee = None
    venue = None
    if summary:
        gi = summary.get("gameInfo") or {}
        for off in gi.get("officials", []):
            if off.get("position", {}).get("name") == "Referee":
                referee = off.get("fullName")
                break
        venue = (gi.get("venue") or {}).get("fullName")
    if not venue:
        venue = (comp.get("venue") or {}).get("fullName")

    home_name = (home.get("team") or {}).get("displayName") or home.get("id")
    away_name = (away.get("team") or {}).get("displayName") or away.get("id")

    result = {
        "sofascore_event_id": int(event_id),
        "match_date": TARGET_DATE,
        "league_name": league[2],
        "league_id_sofa": league[1],
        "home_team": home_name,
        "away_team": away_name,
        "home_score": to_int(home.get("score")),
        "away_score": to_int(away.get("score")),
        "referee": referee,
        "venue": venue,
    }
    for prefix, side_stats in (("home", h), ("away", a)):
        for k, v in side_stats.items():
            result[f"{prefix}_{k}"] = v
    result["raw_statistics"] = json.dumps({"scoreboard": home.get("statistics"), "boxscore": [b.get("statistics") for b in box_teams.values()]})
    return result


def main():
    global request_count, TARGET_DATE

    target_date = sys.argv[1] if len(sys.argv) > 1 and not sys.argv[1].startswith("-") else datetime.now().strftime("%Y-%m-%d")
    post_url = None
    post_key = None
    league_filter = None

    args = sys.argv[1:]
    i = 0
    while i < len(args):
        if args[i] == "--post" and i + 1 < len(args):
            post_url = args[i + 1]
            i += 2
        elif args[i] == "--key" and i + 1 < len(args):
            post_key = args[i + 1]
            i += 2
        elif args[i] == "--league" and i + 1 < len(args):
            league_filter = int(args[i + 1])
            i += 2
        else:
            i += 1

    leagues = [l for l in LEAGUES if league_filter is None or l[1] == league_filter]
    ymd = target_date.replace("-", "")
    TARGET_DATE = target_date

    print("=== ESPN Stats Scraper ===")
    print(f"Date: {target_date}")
    print(f"Leagues: {len(leagues)}")
    print()

    all_results = []
    total_checked = 0

    for slug, tid, name in leagues:
        sb = http_get_json(f"https://site.api.espn.com/apis/site/v2/sports/soccer/{slug}/scoreboard?dates={ymd}")
        time.sleep(REQUEST_DELAY * random.uniform(0.7, 1.3))
        if not sb or not sb.get("events"):
            print(f"[{name}] no events on {target_date}")
            continue
        finished = [e for e in sb["events"] if e.get("competitions") and
                    e["competitions"][0].get("status", {}).get("type", {}).get("state") == "post"]
        if not finished:
            print(f"[{name}] {len(sb['events'])} events, none finished yet")
            continue
        print(f"[{name}] {len(finished)} finished events on {target_date}")
        for event in finished:
            total_checked += 1
            m = extract_match((slug, tid, name), event)
            if not m:
                continue
            print(f"  [{m['sofascore_event_id']}] {m['home_team']} {m['home_score']}-{m['away_score']} {m['away_team']}")
            all_results.append(m)

    output = {
        "date": target_date,
        "source": "espn",
        "total_events_checked": total_checked,
        "stats_collected": len(all_results),
        "requests_made": request_count,
        "matches": all_results,
    }

    import pathlib
    output_path = pathlib.Path(f"espn_stats_{target_date}.json")
    with open(output_path, "w", encoding="utf-8") as f:
        json.dump(output, f, indent=2, ensure_ascii=False)

    print(f"\n=== Done: {len(all_results)} stats collected ({request_count} requests) ===")
    print(f"Saved to {output_path}")

    if post_url and post_key:
        req = urllib.request.Request(
            post_url,
            data=json.dumps(output).encode("utf-8"),
            headers={"Content-Type": "application/json", "X-Stats-Key": post_key, "User-Agent": "ESPN-Stats-Scraper/1.0"},
        )
        try:
            with urllib.request.urlopen(req, timeout=180) as r:
                print("Posted to server:", r.read().decode())
        except urllib.error.HTTPError as e:
            print(f"Post failed: HTTP {e.code} {e.read().decode()}")
        except Exception as e:
            print(f"Post failed: {e}")


if __name__ == "__main__":
    main()