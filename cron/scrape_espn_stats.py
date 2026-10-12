#!/usr/bin/env python3
"""
ESPN Match Statistics Scraper (fallback source)

ESPN's public API (site.api.espn.com) is keyless and has no anti-bot WAF,
so it is a reliable source for match stats when Sofascore is blocking the
local IP (403 challenge/ban).

The output JSON is identical in shape to scrape_sofascore_stats.py and is
posted to the same receiver (receive_sofascore_stats.php).

By default it scrapes the catch-all `soccer/all` scoreboard so that days with
no matches in the curated league list (midweek / international windows) are
still covered. Use `--listed` to restore the old behaviour of only the
curated 17 leagues.

Usage:
    python scrape_espn_stats.py [YYYY-MM-DD]
    python scrape_espn_stats.py 2026-09-29 --post https://predixa.co.tz/cron/receive_sofascore_stats --key stats_cron_pred_xxx
    python scrape_espn_stats.py 2026-10-07 --listed          # only curated leagues
    python scrape_espn_stats.py --league 17                  # only one curated league
"""

import json
import os
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
    # Mid-tier European leagues the "all" scoreboard omits (so their fixtures
    # would otherwise only appear sporadically and never settle). sofascore tid
    # left None — only ESPN's own slug is used here.
    ("nor.1", None, "Norwegian Eliteserien"),
    ("swe.1", None, "Swedish Allsvenskan"),
    ("den.1", None, "Danish Superliga"),
    ("aut.1", None, "Austrian Bundesliga"),
    ("gre.1", None, "Greek Super League"),
    ("sco.1", None, "Scottish Premiership"),
    ("bra.1", None, "Brazilian Serie A"),
]
LEAGUE_BY_SLUG = {slug: (tid, name) for slug, tid, name in LEAGUES}

# Competitions we never want (college / youth / reserve). Extra terms can be
# appended at run time with the ESPN_EXCLUDE env var (comma separated).
DEFAULT_EXCLUDE_KEYWORDS = [
    "ncaa", "college", "youth", "reserve", "academy",
    "u17", "u18", "u19", "u20", "u21", "u23",
]
EXCLUDE_KEYWORDS = DEFAULT_EXCLUDE_KEYWORDS + [
    k.strip().lower() for k in os.environ.get("ESPN_EXCLUDE", "").split(",") if k.strip()
]

DEFAULT_SLUG = "all"
SCOREBOARD_LIMIT = 1000
REQUEST_DELAY = 1.2
request_count = 0
TARGET_DATE = None


def is_excluded(text):
    if not text:
        return False
    t = text.lower()
    return any(k in t for k in EXCLUDE_KEYWORDS)


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


def resolve_league(summary, event):
    """Return (league_name, sofascore_tid) for a match."""
    lslug = None
    if summary:
        lg = (summary.get("header") or {}).get("league") or {}
        lslug = lg.get("slug")
        if lslug in LEAGUE_BY_SLUG:
            tid, name = LEAGUE_BY_SLUG[lslug]
            return name, tid
        name = lg.get("name") or lg.get("midsizeName")
        if name:
            return name, None
    # Fallback: derive from the event's season slug (e.g. "2026-brasileiro-serie-a").
    se = (event.get("season") or {}).get("slug") or ""
    if se:
        parts = [p for p in se.split("-") if not p.isdigit()]
        if parts:
            return " ".join(parts).title(), None
    return "Unknown", None


def extract_match(league_tuple, event, slug):
    """league_tuple = (slug, tid, name) for curated mode, else None for all-mode."""
    comp = event.get("competitions", [])[0]
    status = comp.get("status", {}).get("type", {})
    if status.get("state") != "post" or not status.get("completed"):
        return None
    home, away = sides(comp)
    if not home or not away:
        return None

    event_id = comp.get("id") or event.get("id")
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

    if league_tuple:
        _, league_tid, league_name = league_tuple
    else:
        league_name, league_tid = resolve_league(summary, event)

    result = {
        "sofascore_event_id": int(event_id),
        "match_date": TARGET_DATE,
        "league_name": league_name,
        "league_id_sofa": league_tid,
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
    result["raw_statistics"] = json.dumps({
        "scoreboard": home.get("statistics"),
        "boxscore": [b.get("statistics") for b in box_teams.values()],
    })
    return result


def scrape_listed(ymd, target_date, league_filter, seen=None):
    leagues = [l for l in LEAGUES if league_filter is None or l[1] == league_filter]
    print(f"Leagues: {len(leagues)}")
    print()
    results = []
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
            if seen is not None:
                comp = event.get("competitions", [{}])[0]
                eid = comp.get("id") or event.get("id")
                if eid in seen:
                    continue
            m = extract_match((slug, tid, name), event, slug)
            if not m:
                continue
            print(f"  [{m['sofascore_event_id']}] {m['home_team']} {m['home_score']}-{m['away_score']} {m['away_team']} ({m['league_name']})")
            results.append(m)
    return results


def scrape_all(ymd, target_date, apply_filter=True):
    print(f"Fetching catch-all scoreboard (soccer/{DEFAULT_SLUG}, limit={SCOREBOARD_LIMIT})...")
    sb = http_get_json(
        f"https://site.api.espn.com/apis/site/v2/sports/soccer/{DEFAULT_SLUG}/scoreboard?dates={ymd}&limit={SCOREBOARD_LIMIT}"
    )
    time.sleep(REQUEST_DELAY * random.uniform(0.7, 1.3))
    events = (sb or {}).get("events") or []
    finished = [e for e in events if e.get("competitions") and
                e["competitions"][0].get("status", {}).get("type", {}).get("state") == "post"]
    print(f"Finished events on {target_date}: {len(finished)} / {len(events)}")
    print()
    results = []
    skipped = 0
    for event in finished:
        season_slug = (event.get("season") or {}).get("slug") or ""
        if apply_filter and is_excluded(season_slug):
            skipped += 1
            continue
        m = extract_match(None, event, DEFAULT_SLUG)
        if not m:
            continue
        if apply_filter and is_excluded(m["league_name"]):
            skipped += 1
            continue
        print(f"  [{m['sofascore_event_id']}] {m['home_team']} {m['home_score']}-{m['away_score']} {m['away_team']} ({m['league_name']})")
        results.append(m)
    if skipped:
        print(f"Skipped {skipped} excluded events (college/youth/reserve)")
    return results


def main():
    global request_count, TARGET_DATE

    target_date = sys.argv[1] if len(sys.argv) > 1 and not sys.argv[1].startswith("-") else datetime.now().strftime("%Y-%m-%d")
    post_url = None
    post_key = None
    league_filter = None
    listed_only = False
    no_filter = False

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
        elif args[i] == "--listed":
            listed_only = True
            i += 1
        elif args[i] == "--no-filter":
            no_filter = True
            i += 1
        else:
            i += 1

    ymd = target_date.replace("-", "")
    TARGET_DATE = target_date

    print("=== ESPN Stats Scraper ===")
    print(f"Date: {target_date}")
    print(f"Mode: {'listed (curated leagues)' if (listed_only or league_filter is not None) else 'all soccer competitions'}")
    print()

    if listed_only or league_filter is not None:
        all_results = scrape_listed(ymd, target_date, league_filter)
    elif no_filter:
        # Pure unfiltered scan (only used for diagnostics).
        all_results = scrape_all(ymd, target_date, apply_filter=False)
    else:
        # Default: catch-all scoreboard PLUS the curated league list. ESPN's
        # "all" tab omits mid-tier leagues (Eliteserien, Allsvenskan, Danish
        # Superliga, ...) so their fixtures would only appear sporadically and
        # the pending picks that depend on them would never settle.
        all_results = scrape_all(ymd, target_date, apply_filter=True)
        seen = {m["sofascore_event_id"] for m in all_results}
        extra = scrape_listed(ymd, target_date, None, seen=seen)
        if extra:
            print(f"\n>>> {len(extra)} extra matches from curated leagues not on the catch-all scoreboard")
            all_results.extend(extra)

    output = {
        "date": target_date,
        "source": "espn",
        "total_events_checked": len(all_results),
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
