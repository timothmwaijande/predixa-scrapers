import asyncio
import json
import os
import sys
import time
from datetime import datetime
from pathlib import Path
from playwright.async_api import async_playwright
from googleapiclient.discovery import build
from google.oauth2 import service_account

# --- SkySports Scraper ---
async def scrape_skysports_league(page_url, retries=2):
    for attempt in range(retries + 1):
        try:
            return await _do_scrape(page_url)
        except Exception as e:
            if attempt < retries:
                print(f"  Retry {attempt+1}/{retries} for {page_url}: {e}")
                await asyncio.sleep(3)
            else:
                raise

async def _do_scrape(page_url):
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True)
        page = await browser.new_page(
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
        )
        print(f"➡️ Loading {page_url}...")
        await page.goto(page_url.strip(), timeout=60000)
        await page.wait_for_selector("table", timeout=30000)

        rows = await page.query_selector_all("table tbody tr")
        data = []

        for row in rows:
            tds = await row.query_selector_all("td")
            if len(tds) < 10:
                continue

            texts = [await td.inner_text() for td in tds]
            texts = [t.strip() for t in texts]

            if not texts[0].isdigit():
                continue

            team = texts[1]
            if len(team) < 2:
                continue

            stats = texts[2:10]  # Pl, W, D, L, F, A, GD, Pts
            if len(stats) == 8:
                data.append([team] + stats)
                print(f"✅ {team} → {stats}")

        await browser.close()
        return data


# --- Google Sheets Writer (FIXED: use parameter name 'spreadsheet_id') ---
def save_to_json(data, sheet_name):
    out_dir = Path(os.environ.get("SKYSPORTS_BASE_DIR", "skysports_data"))
    out_dir.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    path = out_dir / f"{sheet_name.replace(' ', '_')}_{stamp}.json"
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2)
    print(f"💾 JSON backup saved: {path}")


def write_to_google_sheet(data, spreadsheet_id, sheet_name):
    if not data:
        print(f"❌ No data for {sheet_name}. Skipping.")
        return

    cred_file = os.environ.get("GOOGLE_CREDENTIALS_PATH", "google_credentials.json")
    if not os.path.exists(cred_file):
        print(f"⚠️ Credentials file '{cred_file}' not found. Skipping Google Sheets.")
        return

    # ✅ FIXED: Removed extra spaces in SCOPES
    SCOPES = ["https://www.googleapis.com/auth/spreadsheets"]
    creds = service_account.Credentials.from_service_account_file(cred_file, scopes=SCOPES)
    service = build("sheets", "v4", credentials=creds)

    headers = ["Team", "GP", "W", "D", "L", "F", "A", "GD", "P"]
    values = [headers] + data
    range_name = f"'{sheet_name}'!A1"

    # ✅ FIXED: Use parameter 'spreadsheet_id' (not global SPREADSHEET_ID)
    request = service.spreadsheets().values().update(
        spreadsheetId=spreadsheet_id,  # ← CORRECT PARAMETER NAME
        range=range_name,
        valueInputOption="RAW",
        body={"values": values}
    )
    response = request.execute()
    print(f"✅ Updated {response.get('updatedCells')} cells in sheet '{sheet_name}'.")


# --- Main Orchestrator ---
async def main(json_mode=False):
    SPREADSHEET_ID = os.environ.get("SKYSPREADSHEET_ID", "1ZA908IKNvqoI2zu7HPLHgggyEvJCuMlULtGW_DGMpng")
    now = datetime.now()

    if not json_mode:
        print("Starting SkySports Multi-League Scraper...\n")

    skysports_leagues = {
        "Premier League": "https://www.skysports.com/premier-league-table",
        "Bundesliga": "https://www.skysports.com/bundesliga-table",
        "La Liga": "https://www.skysports.com/la-liga-table",
        "Serie A": "https://www.skysports.com/serie-a-table",
        "Ligue 1": "https://www.skysports.com/ligue-1-table",
        "Eredivisie": "https://www.skysports.com/eredivisie-table",
        "Liga Portugal": "https://www.skysports.com/portuguese-liga-table",
        "Jupiler League": "https://www.skysports.com/jupiler-league-table",
        "Danish Superligaen": "https://www.skysports.com/football/competitions/danish-superligaen/table",
        "Turkish Super Lig": "https://www.skysports.com/football/competitions/turkish-super-lig/table",
        "Swiss Super League": "https://www.skysports.com/football/competitions/swiss-super-league/table",
        "Serie B": "https://www.skysports.com/football/competitions/italian-serie-b/table",
        "Greek Super League": "https://www.skysports.com/football/competitions/greek-super-league/table",
        "Bundesliga 2": "https://www.skysports.com/football/competitions/german-2-bundesliga/table",
    }

    if now.month in [9, 10, 11, 12, 1]:
        skysports_leagues["Champions League"] = "https://www.skysports.com/champions-league-table"
        skysports_leagues["Europa League"] = "https://www.skysports.com/europa-league-table"

    all_standings = []
    league_list = list(skysports_leagues.items())
    for idx, (sheet_name, url) in enumerate(league_list):
        if not json_mode:
            print(f"\n--- [{idx+1}/{len(league_list)}] Scraping {sheet_name} ---")
        try:
            data = await scrape_skysports_league(url)
            if not json_mode:
                print(f"Scraped {len(data)} teams from {sheet_name}.")

            for pos, row in enumerate(data, 1):
                all_standings.append({
                    "league": sheet_name,
                    "team": row[0],
                    "position": pos,
                    "played": int(row[1]) if row[1].isdigit() else 0,
                    "wins": int(row[2]) if row[2].isdigit() else 0,
                    "draws": int(row[3]) if row[3].isdigit() else 0,
                    "losses": int(row[4]) if row[4].isdigit() else 0,
                    "goals_for": int(row[5]) if row[5].isdigit() else 0,
                    "goals_against": int(row[6]) if row[6].isdigit() else 0,
                    "goal_diff": int(row[7]) if row[7].isdigit() else 0,
                    "points": int(row[8]) if row[8].isdigit() else 0,
                })

            if not json_mode:
                save_to_json(data, sheet_name)
            if not json_mode:
                try:
                    write_to_google_sheet(data, SPREADSHEET_ID, sheet_name)
                except Exception as sheet_err:
                    print(f"Sheet write failed for {sheet_name}: {sheet_err}")
        except Exception as e:
            if not json_mode:
                print(f"Failed to scrape {sheet_name}: {e}")

        if idx < len(league_list) - 1:
            time.sleep(2)

    if json_mode:
        print(json.dumps({"standings": all_standings, "generated_at": now.isoformat()}, indent=2))
    else:
        print("\nAll leagues processed!")


if __name__ == "__main__":
    json_mode = "--json" in sys.argv
    asyncio.run(main(json_mode))