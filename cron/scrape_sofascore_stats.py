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
import os
import random
import re
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
except ImportError:
    print("ERROR: curl_cffi not installed. Run: pip install curl_cffi")
    sys.exit(1)

# ---------------------------------------------------------------------------
# Browser impersonation. curl_cffi rebuilds the TLS + HTTP2 fingerprint of the
# named browser, which is what SofaScore's WAF keys on. The WAF flags legacy
# fingerprints (chrome131 / firefox133 / safari17_0) with a 403 challenge, so
# only CURRENT browser versions are used. A session is warmed on the public
# site first so the API calls arrive with the WAF's cookies already in the jar.
# ---------------------------------------------------------------------------
DEFAULT_IMPERSONATION = "chrome136"
IMPERSONATIONS = ["chrome136", "chrome145", "safari260", "firefox144"]
_session = None
_imp_index = 0
REFERER = "https://www.sofascore.com/"
SOFA_BASE = os.environ.get("SOFA_BASE", "https://api.sofascore.com").rstrip("/")
PROXY = os.environ.get("SOFA_PROXY", "").strip()


def sofa_url(path):
    return f"{SOFA_BASE}/api/v1/{path.lstrip('/')}"


def is_ip_block(resp):
    """Sofascore's edge (Varnish) answers an IP-level ban with a JSON
    Forbidden body and no challenge page — critically, this hits even the
    homepage and every fingerprint, so no client tweak can fix it."""
    server = resp.headers.get("server", "")
    if "varnish" not in server.lower():
        return False
    try:
        return resp.text.strip().startswith('{"error"') and '"reason": "Forbidden"' in resp.text
    except Exception:
        return False


def is_challenge(resp):
    """A 403 whose JSON reason is 'challenge' means the current IP/node is
    JS-challenged on the API tier only. It is transient and node-specific
    (a VPN rotation, or waiting a few minutes, usually clears it)."""
    try:
        return resp.text.strip().startswith('{"error"') and '"reason": "challenge"' in resp.text
    except Exception:
        return False


def _headers_for(impersonation):
    """Mutually consistent header bundle for the given fingerprint. The
    User-Agent / sec-ch-ua / sec-fetch-* trio is what the WAF cross-checks
    hardest, so every value must match the impersonated browser."""
    imp = impersonation
    accept = "application/json, text/plain, */*"
    accept_lang = "en-US,en;q=0.9"
    encoding = "gzip, deflate, br"
    fetch = {
        "Sec-Fetch-Dest": "empty",
        "Sec-Fetch-Mode": "cors",
        "Sec-Fetch-Site": "same-site",
        "Referer": REFERER,
    }
    if imp.startswith("chrome"):
        major = re.sub(r"\D", "", imp) or "136"
        ua = f"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 " \
             f"(KHTML, like Gecko) Chrome/{major}.0.0.0 Safari/537.36"
        return {
            "User-Agent": ua,
            "Accept": accept,
            "Accept-Language": accept_lang,
            "Accept-Encoding": encoding,
            "sec-ch-ua": f'"Chromium";v="{major}", "Not.A/Brand";v="24", "Google Chrome";v="{major}"',
            "sec-ch-ua-mobile": "?0",
            "sec-ch-ua-platform": '"Windows"',
            **fetch,
        }
    if imp.startswith("firefox"):
        major = re.sub(r"\D", "", imp) or "144"
        ua = f"Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:{major}.0) " \
             f"Gecko/20100101 Firefox/{major}.0"
        return {
            "User-Agent": ua,
            "Accept": accept,
            "Accept-Language": accept_lang,
            "Accept-Encoding": encoding,
            **fetch,
        }
    return {
        "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
                      "AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15",
        "Accept": accept,
        "Accept-Language": accept_lang,
        "Accept-Encoding": encoding,
        **fetch,
    }


def warm_session(session, imp):
    """Visit the public site once so the session picks up the WAF cookies
    before any API call. API requests arriving without a cookie jar (and
    without this first-party hop) are the fastest way to earn a 403."""
    try:
        session.get(REFERER, headers=_headers_for(imp), timeout=35)
    except Exception:
        pass


def get_session():
    global _session
    if _session is None:
        imp = IMPERSONATIONS[_imp_index % len(IMPERSONATIONS)]
        kwargs = {}
        if PROXY:
            kwargs["proxies"] = {"http": PROXY, "https": PROXY}
        _session = cffi_requests.Session(impersonate=imp, **kwargs)
        warm_session(_session, imp)
    return _session


def rotate_session():
    global _session, _imp_index
    try:
        if _session is not None:
            _session.close()
    except Exception:
        pass
    _session = None
    _imp_index = (_imp_index + 1) % len(IMPERSONATIONS)
    return get_session()

LEAGUES = [
    # Top European leagues
    {"tid": 17, "sid": 96668, "name": "Premier League"},
    {"tid": 8,   "sid": 97268, "name": "La Liga"},
    {"tid": 23,  "sid": 95836, "name": "Serie A"},
    {"tid": 35,  "sid": 97464, "name": "Bundesliga"},
    {"tid": 34,  "sid": 96127, "name": "Ligue 1"},
    {"tid": 37,  "sid": 96143, "name": "Eredivisie"},
    {"tid": 238, "sid": 97436, "name": "Liga Portugal Betclic"},
    {"tid": 52,  "sid": 98080, "name": "Turkish Super Lig"},
    # UEFA competitions
    {"tid": 7,    "sid": 96518, "name": "UEFA Champions League"},
    {"tid": 679,  "sid": 96522, "name": "UEFA Europa League"},
    {"tid": 17015, "sid": 96529, "name": "UEFA Conference League"},
    {"tid": 10783, "sid": 89945, "name": "UEFA Nations League"},
    # Rest of the world
    {"tid": 242, "sid": 86668, "name": "MLS"},
    {"tid": 196, "sid": 96370, "name": "J1 League"},
    {"tid": 11621, "sid": 96191, "name": "Liga MX, Apertura"},
    # African competitions
    {"tid": 1054, "sid": 100698, "name": "CAF Champions League"},
    {"tid": 1115, "sid": 100699, "name": "CAF Confederation Cup"},
    {"tid": 270, "sid": 71636, "name": "Africa Cup of Nations"},
    {"tid": 1848, "sid": 90940, "name": "Africa Cup of Nations Qualifiers"},
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

REQUEST_DELAY = 4.0
request_count = 0
consecutive_failures = 0
MAX_CONSECUTIVE_FAILURES = 3


def gentle_sleep():
    """Human-ish delay with jitter; helps avoid tripping per-IP burst flags."""
    time.sleep(REQUEST_DELAY * random.uniform(0.8, 1.3))


def api_get(url, retries=5):
    global request_count, consecutive_failures
    backoff = 15
    # So a wake-up spot: these waits let a VPN/server rotation or a transient
    # WAF state clear before we give up on the URL.
    challenge_waits = [25, 45, 75, 120]
    for attempt in range(retries + 1):
        try:
            imp = IMPERSONATIONS[_imp_index % len(IMPERSONATIONS)]
            resp = get_session().get(url, headers=_headers_for(imp), timeout=35)
            request_count += 1
            if resp.status_code == 200:
                consecutive_failures = 0
                return resp.json()
            elif resp.status_code in (403, 429):
                if is_ip_block(resp):
                    # Pointless to rotate fingerprints or wait: the IP itself
                    # is rejected at Sofascore's edge. Fail fast and let
                    # abort_if_blocked() explain the remedy.
                    print(f"    IP-level block (Varnish 403, server-side) — fingerprint {imp} also rejected")
                    consecutive_failures += 1
                    return None
                if is_challenge(resp):
                    # Transient API-tier challenge: keep retrying with longer,
                    # jittered pauses (allowing a node/IP rotation to clear it)
                    # instead of aborting the whole run after a couple of tries.
                    if attempt < len(challenge_waits):
                        cool = challenge_waits[attempt] * random.uniform(0.8, 1.3)
                        print(f"    Challenge (403) attempt {attempt + 1} — cooling {cool:.0f}s (waiting for clean node)")
                        time.sleep(cool)
                        if attempt % 2 == 1:
                            rotate_session()
                    else:
                        consecutive_failures += 1
                        return None
                    continue
                # WAF rate limit: cool down, rotate fingerprint, retry
                rotate_session()
                print(f"    Blocked (403/429) — cooling {backoff}s, fingerprint -> {IMPERSONATIONS[_imp_index % len(IMPERSONATIONS)]}")
                time.sleep(backoff)
                backoff *= 2
            else:
                print(f"    HTTP {resp.status_code} for {url}")
                if attempt < retries:
                    gentle_sleep()
        except Exception as e:
            if attempt < retries:
                gentle_sleep()
            else:
                print(f"    Request failed: {e}")
    consecutive_failures += 1
    return None


def abort_if_blocked():
    """If the WAF is blocking nearly everything, stop instead of writing a
    misleading '0 stats collected' file."""
    if consecutive_failures >= MAX_CONSECUTIVE_FAILURES:
        print(f"\n!! {consecutive_failures} consecutive API calls failed (403/challenge). "
              f"The node currently reached via {SOFA_BASE} is being blocked.")
        print(f"   Remedy:")
        if PROXY:
            print(f"   - Rerun with a different proxy: --proxy {PROXY}")
        print(f"   - If you are on a VPN (e.g. HMA): switch to a different server/region and retry —")
        print(f"     the API-tier challenge is node-specific and clearing on another exit IP.")
        print(f"   - Or wait and rerun later (the block is transient, minutes to hours).")
        print(f"   - Diagnostic: python scrape_sofascore_stats.py --probe")
        print(f"   - Try one league first to confirm: --league 17")
        sys.exit(2)


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
    data = api_get(sofa_url(f"event/{event_id}"))
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
    r = api_get(sofa_url(f"unique-tournament/{tid}/seasons"))
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


def run_probe():
    """Diagnostic: hit the public site + one API endpoint with every
    fingerprint and classify the block type. No cooldowns, no writes."""
    print("=== Sofascore Probe ===")
    targets = [
        ("home", "https://www.sofascore.com/"),
        ("api-season", sofa_url("unique-tournament/17/seasons")),
    ]
    for label, url in targets:
        for imp in IMPERSONATIONS:
            try:
                s = cffi_requests.Session(impersonate=imp)
                r = s.get(url, headers=_headers_for(imp), timeout=35)
                server = r.headers.get("server", "?")
                ctype = r.headers.get("content-type", "?")
                head = (r.text or "").replace("\n", " ")[:90]
                if r.status_code == 200:
                    cls = "OK"
                elif is_ip_block(r):
                    cls = "IP-BAN (Varnish JSON Forbidden)"
                elif r.status_code == 403:
                    cls = f"CHALLENGE (403, {ctype})"
                else:
                    cls = f"HTTP {r.status_code}"
                print(f"  [{label}] {imp}: {cls} (server={server})")
                if cls != "OK":
                    print(f"       body> {head!r}")
            except Exception as e:
                print(f"  [{label}] {imp}: ERROR {type(e).__name__}: {e}")
    print("Probe done. IP-BAN = needs --proxy/VPN. CHALLENGE = needs cookie/JS.")
    sys.exit(0)


def main():
    global request_count, PROXY, SOFA_BASE

    target_date = sys.argv[1] if len(sys.argv) > 1 and not sys.argv[1].startswith("-") else datetime.now().strftime("%Y-%m-%d")
    post_url = None
    post_key = None
    fresh_seasons = False
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
        elif args[i] == "--proxy" and i + 1 < len(args):
            PROXY = args[i + 1]
            i += 2
        elif args[i] == "--base" and i + 1 < len(args):
            SOFA_BASE = args[i + 1].rstrip("/")
            i += 2
        elif args[i] == "--fresh":
            fresh_seasons = True
            i += 1
        elif args[i] == "--league" and i + 1 < len(args):
            league_filter = int(args[i + 1])
            i += 2
        elif args[i] == "--probe":
            run_probe()
        else:
            i += 1

    leagues = [l for l in LEAGUES if league_filter is None or l["tid"] == league_filter]

    print(f"=== Sofascore Stats Scraper ===")
    print(f"Date: {target_date}")
    print(f"Base: {SOFA_BASE}")
    print(f"Proxy: {'yes (' + PROXY + ')' if PROXY else 'no'}")
    print(f"Leagues: {len(leagues)}")
    print()

    target_dt = datetime.strptime(target_date, "%Y-%m-%d")
    target_ts_start = int(target_dt.replace(tzinfo=timezone.utc).timestamp())
    target_ts_end = target_ts_start + 86400

    all_results = []
    total_events_checked = 0

    for league in leagues:
        tid = league["tid"]
        sid = league.get("sid")
        name = league["name"]

        if fresh_seasons:
            sid = discover_seasons(league)
            gentle_sleep()

        gentle_sleep()
        # events/last/{page} only covers a rolling ~2-week window. For older target
        # dates, page backwards (10 pages ≈ several weeks) until we pass the window.
        events = []
        MAX_BACKFILL_PAGES = 10
        newest_ts = None
        for page in range(MAX_BACKFILL_PAGES + 1):
            data = api_get(sofa_url(f"unique-tournament/{tid}/season/{sid}/events/last/{page}"))
            abort_if_blocked()
            if not data:
                break
            page_events = data.get("events", [])
            if not page_events:
                break
            page_newest = max((e.get("startTimestamp", 0) for e in page_events), default=0)
            page_oldest = min((e.get("startTimestamp", 0) for e in page_events), default=0)
            newest_ts = page_newest if newest_ts is None else max(newest_ts, page_newest)
            known = {x.get("id") for x in events}
            events.extend(e for e in page_events if e.get("id") not in known)
            if page_newest < target_ts_start or not data.get("hasNextPage", False):
                break
            if page_oldest < target_ts_start and any(target_ts_start <= e.get("startTimestamp", 0) < target_ts_end for e in events):
                break
            gentle_sleep()

        day_events = [
            e for e in events
            if target_ts_start <= e.get("startTimestamp", 0) < target_ts_end
        ]

        if not day_events:
            if newest_ts is not None and newest_ts < target_ts_start:
                print(f"    [{name}] no events near {target_date} (newest available: ts {newest_ts})")
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

            gentle_sleep()
            stats_data = api_get(sofa_url(f"event/{eid}/statistics"))
            abort_if_blocked()
            if not stats_data:
                print(f"    No stats available")
                continue

            stats = extract_stats_from_sofa(stats_data)
            if not stats:
                print(f"    Could not extract stats")
                continue

            gentle_sleep()
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
            resp = urllib.request.urlopen(req, timeout=180)
            body = resp.read().decode()
            print(f"Posted to server: {body}")
        except urllib.error.HTTPError as e:
            print(f"Post failed: HTTP {e.code} {e.read().decode()}")
        except Exception as e:
            print(f"Post failed: {e}")

    return output


if __name__ == "__main__":
    main()
