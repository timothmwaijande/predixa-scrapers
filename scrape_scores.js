const fetch = require('node-fetch');
const cheerio = require('cheerio');

function deduplicate(matches) {
  const seen = new Set();
  const unique = [];
  for (const m of matches) {
    const key = m.home_team + '||' + m.away_team;
    if (!seen.has(key)) { seen.add(key); unique.push(m); }
  }
  return unique;
}

function parseScore(text) {
  const n = parseInt(text.trim());
  return isNaN(n) ? null : n;
}

// --- SportyBet ---
async function scrapeSportyBet() {
  const res = await fetch('https://livescore.sportybet.com/', {
    headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' },
    timeout: 20000,
  });
  const html = await res.text();
  const $ = cheerio.load(html);
  const matches = [];

  const scoreSelectors = [
    '[class*=sh-match__scores]',
    '[class*=match__scores]',
    '[class*=scoreboard]',
    '[class*=live-score]',
  ];

  for (const sel of scoreSelectors) {
    const els = $(sel);
    if (els.length === 0) continue;
    els.each((_, scoresEl) => {
      const matchCard = $(scoresEl).closest('[class]').parent();
      if (!matchCard.length) return;

      const statusText = matchCard.text();
      if (!statusText.includes('Ended') && !statusText.includes('END') && !statusText.includes('FT')) return;

      const teamsEl = matchCard.find('[class*=sh-match__teams] .truncate, [class*=teams] .truncate, [class*=participant]');
      const homeTeam = $(teamsEl[0]).text().trim();
      const awayTeam = $(teamsEl[1]).text().trim();
      if (!homeTeam || !awayTeam) return;

      const scoreEls = $(scoresEl).find('[class*=rounded-match__score], [class*=score], [class*=digit]');
      const hs = parseScore($(scoreEls[0]).text());
      const as = parseScore($(scoreEls[1]).text());
      if (hs === null || as === null) return;
      if (hs > 15 || as > 15) return;

      matches.push({ home_team: homeTeam, away_team: awayTeam, home_score: hs, away_score: as, match_date: new Date().toISOString().slice(0, 10) });
    });
    if (matches.length > 0) break;
  }
  return deduplicate(matches);
}

// --- Soccer24 ---
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
    const scoreParts = scoreEl.text().trim().split(/\s*[–\-:]\s*/);
    if (scoreParts.length === 2) {
      const hs = parseScore(scoreParts[0]);
      const as = parseScore(scoreParts[1]);
      if (hs !== null && as !== null && hs <= 15 && as <= 15) {
        matches.push({ home_team: homeTeam, away_team: awayTeam, home_score: hs, away_score: as, match_date: new Date().toISOString().slice(0, 10) });
      }
    }
  });
  return deduplicate(matches);
}

// --- LiveScore.in ---
async function scrapeLiveScoreIn() {
  const res = await fetch('https://www.livescores.in/', {
    headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' },
    timeout: 20000,
  });
  const html = await res.text();
  const $ = cheerio.load(html);
  const matches = [];

  $('[class*=score-card], [class*=match-row], .msResult').each((_, card) => {
    const status = $(card).find('[class*=status], [class*=state]').text().trim().toLowerCase();
    if (!status.includes('ft') && !status.includes('full') && !status.includes('ended') && !status.includes('finished')) return;

    const teams = $(card).find('[class*=team]');
    const homeTeam = $(teams[0]).text().trim();
    const awayTeam = $(teams[teams.length - 1]).text().trim();
    if (!homeTeam || !awayTeam) return;

    const scores = $(card).find('[class*=score]');
    const scoresText = scores.first().text().trim();
    const parts = scoresText.split(/\s*[–\-:]\s*/);
    if (parts.length === 2) {
      const hs = parseScore(parts[0]);
      const as = parseScore(parts[1]);
      if (hs !== null && as !== null && hs <= 15 && as <= 15) {
        matches.push({ home_team: homeTeam, away_team: awayTeam, home_score: hs, away_score: as, match_date: new Date().toISOString().slice(0, 10) });
      }
    }
  });
  return deduplicate(matches);
}

async function scrape() {
  const errors = [];
  const sources = [
    { name: 'sportybet', fn: scrapeSportyBet },
    { name: 'soccer24', fn: scrapeSoccer24 },
    { name: 'livescore.in', fn: scrapeLiveScoreIn },
  ];

  for (const src of sources) {
    try {
      const matches = await src.fn();
      if (matches.length > 0) {
        console.error(`[${src.name}] Found ${matches.length} finished matches`);
        return matches;
      }
      console.error(`[${src.name}] 0 matches`);
    } catch (e) {
      console.error(`[${src.name}] Error: ${e.message}`);
      errors.push(`${src.name}: ${e.message}`);
    }
  }

  console.error('All sources failed:', errors.join('; '));
  return [];
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
