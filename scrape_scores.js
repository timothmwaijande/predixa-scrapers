const fetch = require('node-fetch');
const cheerio = require('cheerio');

function deduplicate(matches) {
  const seen = new Set();
  const unique = [];
  for (const m of matches) {
    const key = (m.home_team + '||' + m.away_team).toLowerCase();
    if (!seen.has(key)) { seen.add(key); unique.push(m); }
  }
  return unique;
}

function parseScore(text) {
  const n = parseInt(text.trim());
  return isNaN(n) ? null : n;
}

// === API-Football (primary source - covers ALL leagues) ===
async function scrapeAPIFootballForDate(apiKey, dateStr) {
  const r = await fetch(`https://v3.football.api-sports.io/fixtures?date=${dateStr}`, {
    headers: { 'x-apisports-key': apiKey, 'User-Agent': 'Mozilla/5.0' },
    timeout: 30000,
  });

  if (r.status === 429 || r.status === 403) {
    console.error('[api-football] Rate limited or forbidden (' + r.status + ')');
    return [];
  }
  if (r.status !== 200) {
    console.error('[api-football] HTTP ' + r.status);
    return [];
  }

  const data = await r.json();
  if (data.errors && Object.keys(data.errors).length > 0) {
    console.error('[api-football] Errors:', JSON.stringify(data.errors));
    return [];
  }

  const matches = [];
  for (const fixture of (data.response || [])) {
    const status = fixture.fixture?.status?.short;
    if (status !== 'FT') continue;

    const home = fixture.teams?.home?.name;
    const away = fixture.teams?.away?.name;
    const homeScore = fixture.goals?.home;
    const awayScore = fixture.goals?.away;

    if (!home || !away || homeScore === null || awayScore === null) continue;
    if (typeof homeScore !== 'number' || typeof awayScore !== 'number') continue;

    matches.push({
      home_team: home,
      away_team: away,
      home_score: homeScore,
      away_score: awayScore,
      match_date: dateStr,
    });
  }
  return matches;
}

async function scrapeAPIFootball() {
  const apiKey = process.env.API_FOOTBALL_KEY || '';
  if (!apiKey) {
    console.error('[api-football] No API key set');
    return [];
  }

  const allMatches = [];
  const today = new Date();

  // Fetch today and yesterday (2 requests, covers the settlement window)
  for (let i = 0; i <= 1; i++) {
    const d = new Date(today);
    d.setDate(d.getDate() - i);
    const dateStr = d.toISOString().slice(0, 10);
    try {
      const matches = await scrapeAPIFootballForDate(apiKey, dateStr);
      allMatches.push(...matches);
      console.error(`[api-football] ${dateStr}: ${matches.length} finished matches`);
    } catch (e) {
      console.error(`[api-football] ${dateStr}: Error - ${e.message}`);
    }
  }

  return allMatches;
}

// === SportyBet (fallback - improved scraper) ===
async function scrapeSportyBet() {
  const res = await fetch('https://livescore.sportybet.com/', {
    headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' },
    timeout: 20000,
  });
  const html = await res.text();
  const $ = cheerio.load(html);
  const matches = [];

  $('[data-testid="matchList-common-match"]').each((i, card) => {
    const $card = $(card);

    const statusText = $card.find('.sh-match__status').text().trim();
    if (!statusText.includes('FT') && !statusText.includes('END') && !statusText.includes('Ended')) return;

    const teamsEl = $card.find('.sh-match__teams');
    if (!teamsEl.length) return;

    const teamDivs = teamsEl.find('.truncate');
    if (teamDivs.length < 2) return;

    const homeTeam = $(teamDivs[0]).text().trim();
    const awayTeam = $(teamDivs[1]).text().trim();
    if (!homeTeam || !awayTeam) return;

    const scoresEl = $card.find('.sh-match__scores');
    if (!scoresEl.length) return;

    const scoreDigits = [];
    scoresEl.find('[class*="rounded-match__score"], [class*="h-[1.125rem]"]').each((_, el) => {
      const text = $(el).text().replace(/[^\d]/g, '').trim();
      if (text.length > 0 && text.length <= 2) {
        const num = parseInt(text);
        if (!isNaN(num) && num <= 20) scoreDigits.push(num);
      }
    });

    if (scoreDigits.length < 2) {
      const scoreText = scoresEl.text().replace(/[^\d]/g, '');
      if (scoreText.length >= 2) {
        scoreDigits.push(parseInt(scoreText[0]));
        scoreDigits.push(parseInt(scoreText[1]));
      }
    }

    if (scoreDigits.length >= 2) {
      matches.push({
        home_team: homeTeam,
        away_team: awayTeam,
        home_score: scoreDigits[0],
        away_score: scoreDigits[1],
        match_date: new Date().toISOString().slice(0, 10),
      });
    }
  });

  console.error(`[sportybet] Found ${matches.length} finished matches`);
  return matches;
}

// === Soccer24 (client-side rendered, limited) ===
async function scrapeSoccer24() {
  const res = await fetch('https://www.soccer24.com/', {
    headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' },
    timeout: 20000,
  });
  const html = await res.text();
  const $ = cheerio.load(html);
  const matches = [];

  $('[class*=event__match], [class*=stage__content] .table__row').each((_, row) => {
    const status = $(row).find('[class*=event__status], [class*=status]').text().trim();
    if (!status.includes('Finished') && status !== 'FT' && !status.includes('After')) return;

    const homeEl = $(row).find('[class*=event__homeParticipant], [class*=home]').first();
    const awayEl = $(row).find('[class*=event__awayParticipant], [class*=away]').first();
    const homeTeam = homeEl.text().trim();
    const awayTeam = awayEl.text().trim();
    if (!homeTeam || !awayTeam) return;

    const scoreEl = $(row).find('[class*=event__scores]');
    const scoreParts = scoreEl.text().trim().split(/\s*[\u2013\u2014\-:]\s*/);
    if (scoreParts.length === 2) {
      const hs = parseScore(scoreParts[0]);
      const as = parseScore(scoreParts[1]);
      if (hs !== null && as !== null && hs <= 15 && as <= 15) {
        matches.push({ home_team: homeTeam, away_team: awayTeam, home_score: hs, away_score: as, match_date: new Date().toISOString().slice(0, 10) });
      }
    }
  });

  console.error(`[soccer24] Found ${matches.length} finished matches`);
  return matches;
}

async function scrape() {
  const allMatches = [];

  // 1. Try API-Football first (most comprehensive)
  try {
    const apiMatches = await scrapeAPIFootball();
    allMatches.push(...apiMatches);
  } catch (e) {
    console.error('[api-football] Error:', e.message);
  }

  // 2. Try SportyBet (good fallback, different team names)
  try {
    const sportyMatches = await scrapeSportyBet();
    allMatches.push(...sportyMatches);
  } catch (e) {
    console.error('[sportybet] Error:', e.message);
  }

  // 3. Try Soccer24 (limited but different names)
  try {
    const soccer24Matches = await scrapeSoccer24();
    allMatches.push(...soccer24Matches);
  } catch (e) {
    console.error('[soccer24] Error:', e.message);
  }

  const unique = deduplicate(allMatches);
  console.error(`[total] ${unique.length} unique finished matches from ${allMatches.length} raw`);
  return unique;
}

if (require.main === module) {
  scrape().then(m => {
    console.log(JSON.stringify({ matches: m }));
    console.error(`Total: ${m.length} finished matches`);
  }).catch(e => {
    console.error('Fatal Error:', e.message);
    process.exit(1);
  });
}

module.exports = { scrape, SOURCE: 'multi' };
