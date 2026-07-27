/**
 * Fetch finished match results from football-data.co.uk CSVs
 * and POST to fetch_scores.php for match_results insertion.
 *
 * Usage:
 *   node fetch_football_data.js                         # current season, all leagues
 *   node fetch_football_data.js --season=2526           # specific season
 *   node fetch_football_data.js --recent                # only last 7 days
 *   node fetch_football_data.js --key=SECRET            # auth key
 */

const fetch = require('node-fetch');

const LEAGUE_MAP = {
  E0:  'England - Premier League',  E1: 'England - Championship',
  E2:  'England - League One',      E3: 'England - League Two',
  EC:  'England - National League',
  SP1: 'Spain - La Liga',           SP2: 'Spain - La Liga 2',
  D1:  'Germany - Bundesliga',      D2: 'Germany - 2. Bundesliga',
  I1:  'Italy - Serie A',           I2: 'Italy - Serie B',
  F1:  'France - Ligue 1',          F2: 'France - Ligue 2',
  N1:  'Netherlands - Eredivisie',  N2: 'Netherlands - Eerste Divisie',
  P1:  'Portugal - Primeira Liga',  P2: 'Portugal - Liga Portugal 2',
  T1:  'Turkey - Super Lig',
  B1:  'Belgium - Pro League',      B2: 'Belgium - Challenger Pro League',
  SC1: 'Scotland - Premiership',    SC2: 'Scotland - Championship',
  G1:  'Greece - Super League',
  A1:  'Austria - Bundesliga',
  SW1: 'Switzerland - Super League',
  DK1: 'Denmark - Superliga',
  S1:  'Sweden - Allsvenskan',
  NOR1:'Norway - Eliteserien',
  PL1: 'Poland - Ekstraklasa',
  CZ1: 'Czech Republic - First League',
  HR1: 'Croatia - HNL',
  RO1: 'Romania - Liga I',
  SER1:'Serbia - Super Liga',
  U1:  'Ukraine - Premier League',
  HU1: 'Hungary - NB I',
};

const CSV_BASE = 'https://www.football-data.co.uk/mmz4281';

function getSeasonCode() {
  const arg = process.argv.find(a => a.startsWith('--season='));
  if (arg) return arg.split('=')[1];
  const now = new Date();
  const y = now.getMonth() >= 7 ? now.getFullYear() : now.getFullYear() - 1;
  return String(y).slice(2) + String(y + 1).slice(2);
}

function parseCSV(text) {
  const lines = text.trim().split('\n');
  if (lines.length < 2) return [];
  const headers = lines[0].split(',').map(h => h.trim());
  const results = [];
  for (let i = 1; i < lines.length; i++) {
    const vals = lines[i].split(',').map(v => v.trim());
    if (vals.length < headers.length) continue;
    const row = {};
    headers.forEach((h, idx) => row[h] = vals[idx]);
    results.push(row);
  }
  return results;
}

async function fetchCSV(leagueCode, season) {
  const url = `${CSV_BASE}/${season}/${leagueCode}.csv`;
  try {
    const res = await fetch(url, {
      headers: { 'User-Agent': 'Mozilla/5.0 (compatible; PREDIXA-Bot)' },
      timeout: 15000,
    });
    if (!res.ok) return [];
    const text = await res.text();
    if (!text || text.length < 50) return [];

    const rows = parseCSV(text);
    const matches = [];
    for (const row of rows) {
      const home = (row.HomeTeam || '').trim();
      const away = (row.AwayTeam || '').trim();
      const hs = parseInt(row.FTHG);
      const as = parseInt(row.FTAG);
      const ft = (row.FTR || '').trim();
      const date = (row.Date || '').trim();

      if (!home || !away || isNaN(hs) || isNaN(as) || !ft || ft === 'DNS') continue;

      let matchDate = '';
      if (date) {
        const parts = date.split('/');
        if (parts.length === 3) {
          matchDate = `${parts[2].padStart(4, '20')}-${parts[1].padStart(2, '0')}-${parts[0].padStart(2, '0')}`;
        }
      }
      if (!matchDate) continue;

      matches.push({
        home_team: home,
        away_team: away,
        home_score: hs,
        away_score: as,
        match_date: matchDate,
        league: LEAGUE_MAP[leagueCode] || leagueCode,
      });
    }
    return matches;
  } catch (e) {
    console.error(`[${leagueCode}] Error: ${e.message}`);
    return [];
  }
}

async function main() {
  const season = getSeasonCode();
  const daysArg = process.argv.find(a => a === '--recent');
  const daysAgo = daysArg ? 7 : null;
  const keyArg = process.argv.find(a => a.startsWith('--key='));
  const key = keyArg ? keyArg.split('=')[1] : '';

  console.error(`Season: ${season}, leagues: ${Object.keys(LEAGUE_MAP).length}`);

  const allMatches = [];
  for (const [code, name] of Object.entries(LEAGUE_MAP)) {
    const matches = await fetchCSV(code, season);
    const filtered = daysAgo
      ? matches.filter(m => {
          const d = new Date(m.match_date);
          const cutoff = new Date();
          cutoff.setDate(cutoff.getDate() - daysAgo);
          return d >= cutoff;
        })
      : matches;
    if (filtered.length > 0) {
      console.error(`[${code}] ${name}: ${filtered.length} matches`);
      allMatches.push(...filtered);
    }
  }

  console.error(`Total: ${allMatches.length} matches`);

  if (allMatches.length === 0) {
    console.log(JSON.stringify({ status: 'ok', inserted: 0, skipped: 0 }));
    return;
  }

  const url = `https://predixa.co.tz/cron/fetch_scores.php${key ? '?key=' + key : ''}`;
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'User-Agent': 'Mozilla/5.0 (compatible; PREDIXA-Bot)' },
      body: JSON.stringify({ matches: allMatches }),
      timeout: 30000,
    });
    const body = await res.text();
    console.log(body);
  } catch (e) {
    console.error('POST failed:', e.message);
    process.exit(1);
  }
}

main();
