<?php
session_start();
require_once 'config.php';
require_once 'auth.php';
require_once __DIR__ . '/includes/signals_engine.php';
logPageVisit('signals');

$user = getCurrentUser();
$premium = $user ? getPremiumStatus() : null;
$hasAccess = $user && $premium && ($premium['trial'] || $premium['parlay'] || $premium['rollover']);

$db = getDB();
$matches = [];
if ($db) {
    $stmt = $db->query("SELECT wp.* FROM web_picks wp INNER JOIN (SELECT MAX(id) as id FROM web_picks WHERE (pattern_badge LIKE '%FALLING ODDS%' OR pattern_badge LIKE '%RISING ODDS%' OR fav_delta <= -2 OR opp_delta <= -2 OR draw_delta <= -2 OR fav_delta >= 2 OR opp_delta >= 2 OR draw_delta >= 2) AND DATE(detected_at) = CURDATE() GROUP BY match_name) latest ON wp.id = latest.id ORDER BY LEAST(ABS(wp.fav_delta), ABS(wp.opp_delta), ABS(wp.draw_delta)) ASC");
    $matches = $stmt->fetchAll();
}

$analyzed = [];
foreach ($matches as $m) {
    $signals = analyzeMatch($m);
    if (!empty($signals)) {
        $maxConf = max(array_column($signals, 'confidence'));
        $analyzed[] = ['match' => $m, 'signals' => $signals, 'maxConf' => $maxConf];
    }
}
usort($analyzed, fn($a, $b) => $b['maxConf'] <=> $a['maxConf']);
$analyzed = deduplicateSignals($analyzed);

// Load Bayesian picks for "Best Picks" section
$bayesianPicks = [];
if ($db) {
    $norm = function($n) { return trim(preg_replace('/\s+(if|fk|sk|fc|sc|cf|ac|as)$/i', '', preg_replace('/^(if|fk|sk|fc|sc|cf|ac|as)\s+/i', '', strtolower(trim($n))))); };
    $todaySrc = [];
    foreach (['web_picks' => 'detected_at', 'scraper_results' => 'detected_at', 'admin_featured_picks' => 'created_at'] as $tbl => $col) {
        $q = $db->query("SELECT DISTINCT match_name FROM $tbl WHERE DATE($col) = CURDATE()");
        if ($q) foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $todaySrc[$r['match_name']] = true;
    }
    $yestPair = [];
    $q = $db->query("SELECT home_team, away_team FROM bayesian_predictions WHERE match_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
    if ($q) foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $yestPair[$norm($r['home_team']) . '|' . $norm($r['away_team'])] = true;

    $allBp = $db->query("SELECT bp.match_name, bp.recommended_pick, bp.confidence, bp.league, bp.home_team, bp.away_team, bp.market_odds_1, bp.market_odds_x, bp.market_odds_2 FROM bayesian_predictions bp WHERE bp.match_date = CURDATE() AND bp.recommended_pick IS NOT NULL AND bp.recommended_pick != '' ORDER BY bp.confidence DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allBp as $bp) {
        if (isset($yestPair[$norm($bp['home_team']) . '|' . $norm($bp['away_team'])])) continue;
        $recs = explode(',', $bp['recommended_pick']);
        foreach ($recs as $rec) {
            $rec = trim($rec);
            $parts = explode(':', $rec);
            if (count($parts) !== 2) continue;
            $bestOdds = 0;
            $mv = strtoupper(trim($parts[0]));
            if ($mv === '1') $bestOdds = (float)($bp['market_odds_1'] ?? 0);
            elseif ($mv === 'X') $bestOdds = (float)($bp['market_odds_x'] ?? 0);
            elseif ($mv === '2') $bestOdds = (float)($bp['market_odds_2'] ?? 0);
            $bayesianPicks[] = [
                'match_name' => $bp['match_name'],
                'pick_value' => trim($parts[0]),
                'probability' => (float)trim($parts[1]),
                'confidence' => (float)$bp['confidence'],
                'best_odds' => $bestOdds,
                'league' => $bp['league'] ?? '',
            ];
        }
    }
}

// Verified boost
foreach ($analyzed as &$a) {
    addVerifiedBoost($a['signals'], $a['match'], false);
}
unset($a);

// Cross-verification: multi-bookie consensus + noise + odds sanity
foreach ($analyzed as &$a) {
    foreach ($a['signals'] as &$s) {
        $s['verification_score'] = crossVerifySignal($s, $a['match']);
    }
}
unset($s);

// Helper: estimate odds for a signal
function estimateOdds($signal, $match) {
    $h = (float)($match['home_odds'] ?? 0);
    $d = (float)($match['draw_odds'] ?? 0);
    $aOdds = (float)($match['away_odds'] ?? 0);
    $pick = $signal['pick'];
    $mkt = $signal['market'];
    if ($mkt === '1X2') {
        if (strpos($pick, '(1)') !== false) return $h;
        if (strpos($pick, '(X)') !== false) return $d;
        if (strpos($pick, '(2)') !== false) return $aOdds;
    } elseif ($mkt === 'Double Chance') {
        if (strpos($pick, '1X') !== false && $h && $d) return round(1 / (1/$h + 1/$d), 2);
        if (strpos($pick, 'X2') !== false && $d && $aOdds) return round(1 / (1/$d + 1/$aOdds), 2);
        if (strpos($pick, '12') !== false && $h && $aOdds) return round(1 / (1/$h + 1/$aOdds), 2);
    } elseif ($mkt === 'Over 1.5 Goals' && $h && $d && $aOdds) {
        return round(1 / min(max(1/$h + 1/$d + 1/$aOdds - 0.3, 0.01), 0.92), 2);
    } elseif ($mkt === 'Under 3.5 Goals' && $h && $d && $aOdds) {
        return round(1 / max(1/$h + 1/$d + 1/$aOdds + 0.15, 0.3), 2);
    }
    return 0;
}

// Fetch multi-bookie movements once for verified display
$movements = null;
try {
    $movements = getMultiBookieSheetData();
} catch (Exception $e) {
    error_log("signals movements fetch: " . $e->getMessage());
}

$totalSignals = 0;
$highCount = 0; $medCount = 0; $lowCount = 0;
foreach ($analyzed as $a) {
    $totalSignals += count($a['signals']);
    foreach ($a['signals'] as $s) {
        $vs = $s['verification_score'] ?? $s['confidence'];
        if ($vs >= 75) $highCount++;
        elseif ($vs >= 55) $medCount++;
        else $lowCount++;
    }
}

// Signal quality assessment
if (count($analyzed) < 3) $signalQuality = 'low';
elseif (count($analyzed) >= 5 && $highCount >= 3) $signalQuality = 'high';
else $signalQuality = 'moderate';

$marketIcons = ['1X2' => 'fas fa-flag-checkered', 'Double Chance' => 'fas fa-shield-alt', 'Over 1.5 Goals' => 'fas fa-futbol', 'Under 3.5 Goals' => 'fas fa-futbol', 'GG (BTTS)' => 'fas fa-exchange-alt', 'Team to Score' => 'fas fa-bullseye'];

$pageTitle = 'Smart Picks — Signal Recommender | PREDIXA';
$pageDesc = 'AI-powered odds movement analysis. Get confidence-rated picks across 1X2, Double Chance, Over/Under, BTTS, and Team to Score markets. Updated live.';
$canonical = (defined('SITE_URL') ? SITE_URL : 'https://predixa.co.tz') . '/signals';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<meta name="description" content="<?= htmlspecialchars($pageDesc) ?>">
<meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($pageDesc) ?>">
<meta property="og:url" content="<?= htmlspecialchars($canonical) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="PREDIXA">
<link rel="canonical" href="<?= htmlspecialchars($canonical) ?>">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root { --primary: #8B5CF6; --primary-dark: #7C3AED; --primary-light: #A78BFA; --accent: #06B6D4; --accent-dark: #0891B2; --secondary: #161b22; --text-light: #e0e0e0; --text-muted: #8b949e; --border-color: #2a2e35; }
body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #111318 0%, #1c2130 100%); color: var(--text-light); min-height: 100vh; }
.content-area { background: rgba(6, 182, 212, 0.06); border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); padding-bottom: 40px; }
a { color: var(--primary-light); text-decoration: none; transition: all 0.3s; }
a:hover { color: var(--accent); }
.navbar { background: rgba(15, 17, 21, 0.95); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border-color); padding: 12px 0; }
.navbar-brand { font-size: 1.5rem; font-weight: 800; background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
.nav-link { color: var(--text-muted) !important; font-weight: 600; padding: 8px 16px; border-radius: 6px; transition: all 0.3s; }
.nav-link:hover { color: var(--primary) !important; }
.dropdown-item.active { background: var(--primary) !important; }
.page-header { padding: 110px 0 30px; }
.page-header h1 { font-weight: 800; font-size: 2rem; }
.btn-outline-premium { background: transparent; color: var(--primary); border: 2px solid var(--primary); padding: 12px 30px; font-weight: 600; border-radius: 8px; transition: all 0.3s; text-decoration: none; display: inline-block; }
.btn-outline-premium:hover { background: var(--primary); color: white; transform: translateY(-2px); text-decoration: none; }
.btn-premium { background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); color: white; border: none; font-weight: 700; padding: 10px 24px; border-radius: 8px; cursor: pointer; transition: all 0.3s; font-size: 0.85rem; text-decoration: none; display: inline-block; }
.btn-premium:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--accent-dark) 100%); color: white; transform: translateY(-2px); box-shadow: 0 5px 20px rgba(139,92,246,0.4); text-decoration: none; }
.signal-card { background: linear-gradient(135deg, rgba(139,92,246,0.2) 0%, rgba(6,182,212,0.1) 100%); border: 1px solid rgba(139,92,246,0.3); border-radius: 12px; padding: 16px; margin-bottom: 12px; transition: all .2s; }
.signal-card:hover { border-color: var(--primary); transform: translateY(-1px); }
.signal-item { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 20px; font-size: 0.78rem; font-weight: 600; white-space: nowrap; border: 1px solid transparent; }
.signal-item.high { background: rgba(34,197,94,0.12); border-color: rgba(34,197,94,0.3); color: #22C55E; }
.signal-item.medium { background: rgba(251,191,36,0.12); border-color: rgba(251,191,36,0.3); color: #FBBF24; }
.signal-item.low { background: rgba(148,163,184,0.1); border-color: rgba(148,163,184,0.15); color: var(--text-muted); }
.conf-ring { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.75rem; flex-shrink: 0; }
.conf-ring.high { background: rgba(34,197,94,0.15); color: #22C55E; }
.conf-ring.medium { background: rgba(251,191,36,0.15); color: #FBBF24; }
.conf-ring.low { background: rgba(148,163,184,0.1); color: var(--text-muted); }
.stats-row { font-size: 0.75rem; color: var(--text-muted); display: flex; flex-wrap: wrap; gap: 8px; }
.stats-row .stat { background: rgba(255,255,255,0.03); padding: 2px 8px; border-radius: 4px; }
.badge-market { font-size: 0.65rem; padding: 2px 8px; border-radius: 4px; font-weight: 600; background: rgba(139,92,246,0.15); color: var(--primary); }
.locked-overlay { position: absolute; inset: 0; background: linear-gradient(135deg, rgba(139,92,246,0.25) 0%, rgba(6,182,212,0.15) 100%); backdrop-filter: blur(4px); border-radius: 12px; display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 2; }
.locked-overlay i { font-size: 1.5rem; color: #FBBF24; margin-bottom: 4px; }
.stats-badge { background: rgba(139,92,246,0.12); border: 1px solid rgba(139,92,246,0.2); border-radius: 10px; padding: 12px 18px; text-align: center; flex: 1; min-width: 100px; }
.stats-badge .num { font-size: 1.6rem; font-weight: 800; }
.stats-badge .lbl { font-size: 0.75rem; color: var(--text-muted); }
.pricing-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-top: 20px; }
.pricing-card { display: flex; flex-direction: column; background: linear-gradient(135deg, rgba(139,92,246,0.2) 0%, rgba(6,182,212,0.1) 100%); border: 1px solid rgba(139,92,246,0.3); border-radius: 16px; padding: 28px 24px; text-align: center; transition: all .3s; position: relative; }
.pricing-card:hover { border-color: var(--primary); transform: translateY(-2px); }
.pricing-card.popular { border-color: #FBBF24; box-shadow: 0 0 30px rgba(251,191,36,0.15); }
.pricing-badge { position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: #FBBF24; color: #000; font-size: 0.7rem; font-weight: 700; padding: 4px 16px; border-radius: 20px; }
.pricing-title { font-size: 1.1rem; font-weight: 700; margin-bottom: 4px; }
.pricing-price { font-size: 1.8rem; font-weight: 800; background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
.pricing-features { list-style: none; padding: 0; margin: 16px 0; font-size: 0.82rem; color: var(--text-muted); text-align: left; flex: 1; }
.pricing-features li { padding: 4px 0; }
.pricing-features li::before { content: '✓ '; color: #22C55E; font-weight: 700; }
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.empty-state i { font-size: 3rem; margin-bottom: 15px; color: var(--primary); }
footer { border-top: 1px solid var(--border-color); padding: 40px 0; margin-top: 60px; background: rgba(15, 17, 21, 0.5); color: var(--text-muted); font-size: 0.85rem; }
footer h5, footer h6 { color: var(--text-light); font-weight: 700; }
footer a { color: var(--text-muted); text-decoration: none; transition: all 0.3s; }
footer a:hover { color: var(--primary); }
@media (max-width: 768px) { .page-header { padding: 90px 0 20px; } .page-header h1 { font-size: 1.5rem; } .stats-badge { min-width: 80px; } .stats-badge .num { font-size: 1.2rem; } }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark fixed-top">
    <div class="container">
        <a class="navbar-brand" href="./"><i class="fas fa-futbol me-2"></i>PREDIXA</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
<ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="./">Home</a></li>
                <li class="nav-item dropdown">
<a class="nav-link dropdown-toggle" href="javascript:void(0)" role="button" data-bs-toggle="dropdown">Free Tools</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="dropping-odds"><i class="fas fa-arrow-down me-1" style="color:#EF4444;"></i> Dropping Odds</a></li>
                        <li><a class="dropdown-item" href="track-record"><i class="fas fa-chart-line me-1" style="color:#FBBF24;"></i> Performance</a></li>
                        <li><a class="dropdown-item" href="betting-school"><i class="fas fa-book-open me-1" style="color:#fff;"></i> Betting School</a></li>
                        <li><a class="dropdown-item" href="pikka"><i class="fas fa-futbol me-1" style="color:#6366F1;"></i> Pikka</a></li>
                    </ul>
                </li>
                <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                    <?php if ($user): ?>
                    <a class="btn btn-outline-premium btn-sm" href="dashboard" style="min-width: 100px; padding: 10px 24px; min-height: 44px;">Dashboard</a>
                    <a class="btn btn-sm" href="logout" style="border:1px solid var(--border-color);color:var(--text-muted);padding:10px 16px;border-radius:6px;text-decoration:none;font-size:0.8rem;margin-left:6px;">Logout</a>
                    <?php else: ?>
                    <a class="btn btn-outline-premium btn-sm" href="login" style="min-width: 100px; padding: 10px 24px; min-height: 44px;">Login</a>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1><i class="fas fa-microchip me-2" style="color:#22C55E;"></i>Smart Picks</h1>
        <p style="color:var(--text-muted);font-size:1rem;max-width:700px;">Live odds movement analysis across <strong style="color:var(--text-light);">6 markets</strong> — 1X2, Double Chance, Over 1.5 Goals, Under 3.5 Goals, BTTS, and Team to Score. Each pick is <strong style="color:#22C55E;">cross-verified</strong> across multiple bookies with noise filtering and odds sanity checks. Use the <strong style="color:#FBBF24;">verification score</strong> to decide which picks to back — the higher the score, the stronger the consensus.</p>
    </div>
</div>

<div class="content-area">
<div class="container pb-4 pt-4">
    <?php if (empty($analyzed)): ?>
    <div class="empty-state">
        <i class="fas fa-microchip"></i>
        <h5>Awaiting Market Movement</h5>
        <p class="mb-0">Our signal engine is scanning 1X2 markets in real time. No qualifying movement detected yet. Data refreshes automatically — check back shortly.</p>
    </div>
    <?php else: ?>

    <div class="d-flex flex-wrap gap-2 mb-4 justify-content-center">
        <div class="stats-badge"><div class="num" style="color:var(--accent);"><?= count($analyzed) ?></div><div class="lbl">Matches</div></div>
        <div class="stats-badge"><div class="num" style="color:#22C55E;"><?= $totalSignals ?></div><div class="lbl">Signals</div></div>
        <div class="stats-badge"><div class="num" style="color:#22C55E;"><?= $highCount ?></div><div class="lbl">High</div></div>
        <div class="stats-badge"><div class="num" style="color:#FBBF24;"><?= $medCount ?></div><div class="lbl">Medium</div></div>
        <div class="stats-badge"><div class="num" style="color:var(--text-muted);"><?= $lowCount ?></div><div class="lbl">Low</div></div>
    </div>

    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <div style="display:flex;flex-wrap:wrap;gap:4px 10px;font-size:0.65rem;padding:6px 10px;background:rgba(255,255,255,0.02);border:1px solid var(--border-color);border-radius:6px;align-items:center;">
            <span style="font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;">Guide</span>
            <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(16,185,129,0.15);color:#10B981;padding:1px 6px;border-radius:3px;font-weight:600;"><i class="fas fa-check-circle"></i> VERIFIED ≥75%</span>
            <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(251,191,36,0.15);color:#FBBF24;padding:1px 6px;border-radius:3px;font-weight:600;"><i class="fas fa-check-circle"></i> VERIFIED 50-74%</span>
            <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(6,182,212,0.3);color:#06B6D4;padding:1px 6px;border-radius:3px;font-weight:600;"><i class="fas fa-dollar-sign"></i> BANKER +5%+ (EV edge)</span>
            <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(239,68,68,0.15);color:#EF4444;padding:1px 6px;border-radius:3px;font-weight:600;"><i class="fas fa-wave-square"></i> NOISY</span>
            <span style="color:var(--text-muted);">hover for details</span>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <input type="text" id="searchInput" class="form-control form-control-sm search-box" placeholder="Search matches..." style="max-width:200px;font-size:0.82rem;background:rgba(22,27,34,0.7);border:1.5px solid rgba(139,92,246,0.45);color:var(--text-light);">
        <span style="font-size:0.8rem;color:var(--text-muted);" id="matchCount"><?= count($analyzed) ?> matches</span>
        <div class="d-flex gap-1 align-items-center ms-auto">
            <span style="font-size:0.75rem;color:var(--text-muted);white-space:nowrap;">Sort:</span>
            <select id="sortSelect" class="form-select form-select-sm" style="width:auto;background:var(--secondary);border-color:var(--border-color);color:var(--text-light);font-size:0.75rem;padding:4px 24px 4px 6px;" onchange="sortMatches(this.value)">
                <option value="time-desc">Recent signal</option>
                <option value="time-asc">Oldest signal</option>
                <option value="kickoff-asc">Earliest kick off</option>
                <option value="kickoff-desc">Latest kick off</option>
            </select>
        </div>
    </div>

    <div id="signalsList">

    <?php foreach ($analyzed as $aIdx => $a):
        $m = $a['match'];
        $signals = $a['signals'];
        $hDelta = getHomeDelta($m);
        $aDelta = getAwayDelta($m);
        $dDelta = (float)($m['draw_delta'] ?? 0);
        $hOdds = (float)($m['home_odds'] ?? 0);
        $dOdds = (float)($m['draw_odds'] ?? 0);
        $aOdds = (float)($m['away_odds'] ?? 0);
        $league = htmlspecialchars($m['league'] ?? 'Unknown');
        $matchName = htmlspecialchars($m['match_name']);
        $matchTime = $m['match_time'] && $m['match_time'] !== 'TBD' ? htmlspecialchars($m['match_time']) : '';
        $detected = !empty($m['detected_at']) ? date('H:i', strtotime($m['detected_at'])) : '';
        $detectedTs = !empty($m['detected_at']) ? (int)strtotime($m['detected_at']) : 0;
        $kickoffTs = 0;
        if ($matchTime && $matchTime !== 'TBD') {
            $parts = explode(':', $matchTime);
            $kickoffTs = (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
        }
        $hasLocked = false;
        foreach ($signals as $s) { $vs = $s['verification_score'] ?? $s['confidence']; if ($vs >= 75 && !$hasAccess) { $hasLocked = true; break; } }
        $conHome = trim((preg_match('/^(.+?)\s+vs\s+(.+?)$/i', $m['match_name'] ?? '', $cParts) ? $cParts[1] : ''));
        $conAway = trim($cParts[2] ?? '');
        $verified = ($movements && $conHome && $conAway) ? getMatchVerifiedAll($movements, $conHome, $conAway) : null;
        $isNoisy = isNoisy($m);
    ?>
    <div class="signal-card" style="position:relative;" data-match-name="<?= htmlspecialchars($m['match_name']) ?>" data-time="<?= $detectedTs ?>" data-kickoff="<?= $kickoffTs ?>" data-levels="<?php
        $lvls = [];
        foreach ($signals as $s) {
            $vs = $s['verification_score'] ?? $s['confidence'];
            $lvls[] = $vs >= 75 ? 'high' : ($vs >= 55 ? 'medium' : 'low');
        }
        echo implode(' ', array_unique($lvls));
    ?>">
        <?php if ($aIdx < 3 || $hasAccess): ?>
        <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
            <div style="flex:1;min-width:150px;">
                <div style="font-weight:700;font-size:0.95rem;"><?= $matchName ?></div>
                <div style="font-size:0.78rem;color:var(--text-muted);"><?= $league ?> · <?= $detected ? 'Detected '.$detected : '' ?> · <?= $matchTime ?: 'TBD' ?></div>
                <?php $hasVer = $verified && count(array_filter($verified)) > 0; if ($hasVer): ?>
                <div style="display:flex;flex-wrap:wrap;gap:2px 8px;font-size:0.68rem;margin-top:4px;align-items:center;">
                    <span style="color:#10B981;font-weight:600;"><i class="fas fa-check-circle me-1"></i></span>
                    <?php $conLabels = ['Home','Draw','Away','Over2.5','Under2.5','BTTS-Yes','BTTS-No'];
                    $conKeys = ['1','X','2','Ov2.5','Und2.5','GG','NG'];
                    foreach ($conKeys as $i => $ck):
                        $vv = $verified[$ck] ?? null;
                        if ($vv):
                            if ($vv['noisy']):
                                echo '<span style="display:inline-flex;align-items:center;gap:1px;"><span style="color:var(--text-muted);font-weight:500;margin-right:1px;">' . $conLabels[$i] . '</span><span class="badge" style="background:#FEF3C7;color:#92400E;font-size:0.6rem;font-weight:700;padding:1px 5px;border-radius:3px;">⚡</span></span>';
                            else:
                                $cArrow = $vv['agreement'] === 'down' ? '↑' : '↓';
                                $cColor = $vv['agreement'] === 'down' ? '#10B981' : '#EF4444';
                                echo '<span style="display:inline-flex;align-items:center;gap:1px;"><span style="color:var(--text-muted);font-weight:500;margin-right:1px;">' . $conLabels[$i] . '</span><span style="color:' . $cColor . ';font-weight:700;">' . $cArrow . '</span><span style="color:' . $cColor . ';font-weight:600;">' . $vv['strength'] . '%</span></span>';
                            endif;
                        endif;
                    endforeach; ?>
                </div>
                <?php elseif ($isNoisy): ?>
                <div style="margin-top:4px;">
                    <span class="badge" style="background:#FEF3C7;color:#92400E;font-size:0.6rem;font-weight:700;padding:1px 6px;border-radius:3px;cursor:help;" title="Bookies disagree — unreliable. Avoid.">⚡ NOISY</span>
                </div>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-1">
                <?php foreach ($signals as $s):
                    $vs = $s['verification_score'] ?? $s['confidence'];
                    $cls = $vs >= 75 ? 'high' : ($vs >= 55 ? 'medium' : 'low');
                    if ($vs >= 75 && !$hasAccess) {
                        echo '<div class="signal-item '.$cls.'" style="opacity:0.5;position:relative;"><i class="fas fa-lock me-1" style="font-size:0.6rem;"></i>'.htmlspecialchars($s['market']).'</div>';
                        continue;
                    }
                ?>
                <div class="signal-item <?= $cls ?>"><?= htmlspecialchars($s['pick']) ?> · <?= htmlspecialchars($s['market']) ?><?php if (isset($s['verified']) && !$isNoisy && ($s['verified']['strength'] ?? 0) >= 50): $vStr = (int)($s['verified']['strength'] ?? 0); $vCount = (int)($s['verified']['count'] ?? 0); $vTotal = (int)($s['verified']['total'] ?? 0); $vCol = $vStr >= 75 ? '#10B981' : '#FBBF24'; $vBg = $vStr >= 75 ? 'rgba(16,185,129,0.15)' : 'rgba(251,191,36,0.15)'; $vLabel = $vStr >= 75 ? 'Strong consensus' : 'Moderate agreement'; ?> <span style="display:inline-flex;align-items:center;gap:3px;background:<?= $vBg ?>;color:<?= $vCol ?>;padding:1px 6px;border-radius:4px;font-size:0.6rem;font-weight:700;vertical-align:middle;cursor:help;" title="<?= $vLabel ?> — <?= $vStr ?>% across <?= $vCount ?>/<?= $vTotal ?> bookies<?= $vStr < 75 ? '. Use caution.' : '' ?>"><i class="fas fa-check-circle"></i> VERIFIED <?= $vStr ?>% (<?= $vCount ?>/<?= $vTotal ?>)</span><?php endif; ?><?php if (($s['is_plus_ev'] ?? false) && !$isNoisy): $bankerEv = $s['ev_value'] ?? null; if ($bankerEv !== null && $bankerEv >= 0.05): $bCol = '#06B6D4'; $bBg = 'rgba(6,182,212,0.3)'; ?> <span style="display:inline-flex;align-items:center;gap:3px;background:<?= $bBg ?>;color:<?= $bCol ?>;padding:1px 5px;border-radius:3px;font-size:0.55rem;font-weight:700;vertical-align:middle;cursor:help;" title="+<?= round($bankerEv * 100) ?>% edge over true probability"><i class="fas fa-dollar-sign"></i> BANKER +<?= round($bankerEv * 100) ?>%</span><?php endif; endif; ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($signals as $s):
                $vs = $s['verification_score'] ?? $s['confidence'];
                $cls = $vs >= 75 ? 'high' : ($vs >= 55 ? 'medium' : 'low');
                if ($vs >= 75 && !$hasAccess) continue;
            ?>
            <div class="d-flex align-items-center gap-2" style="background:rgba(255,255,255,0.03);border:1px solid var(--border-color);border-radius:8px;padding:6px 10px;">
                <span class="conf-ring <?= $cls ?>" style="width:28px;height:28px;font-size:0.65rem;"><?= $vs ?></span>
                <div>
                    <div class="d-flex align-items-center gap-1">
                        <span class="badge-market"><?= htmlspecialchars($s['market']) ?></span>
                        <strong style="font-size:0.8rem;"><?= htmlspecialchars($s['pick']) ?></strong>
                        <?php if (isset($s['verified']) && !$isNoisy && ($s['verified']['strength'] ?? 0) >= 50): $vStr = (int)($s['verified']['strength'] ?? 0); $vCount = (int)($s['verified']['count'] ?? 0); $vTotal = (int)($s['verified']['total'] ?? 0); $vCol = $vStr >= 75 ? '#10B981' : '#FBBF24'; $vBg = $vStr >= 75 ? 'rgba(16,185,129,0.15)' : 'rgba(251,191,36,0.15)'; $vLabel = $vStr >= 75 ? 'Strong consensus' : 'Moderate agreement'; ?><span style="display:inline-flex;align-items:center;gap:3px;background:<?= $vBg ?>;color:<?= $vCol ?>;padding:1px 6px;border-radius:4px;font-size:0.6rem;font-weight:700;margin-left:4px;vertical-align:middle;cursor:help;" title="<?= $vLabel ?> — <?= $vStr ?>% across <?= $vCount ?>/<?= $vTotal ?> bookies<?= $vStr < 75 ? '. Use caution.' : '' ?>"><i class="fas fa-check-circle"></i> VERIFIED <?= $vStr ?>% (<?= $vCount ?>/<?= $vTotal ?>)</span><?php endif; ?>
                         <?php if (($s['is_plus_ev'] ?? false) && !$isNoisy): $bankerEv = $s['ev_value'] ?? null; if ($bankerEv !== null && $bankerEv >= 0.05): $bCol = '#06B6D4'; $bBg = 'rgba(6,182,212,0.3)'; ?><span style="display:inline-flex;align-items:center;gap:3px;background:<?= $bBg ?>;color:<?= $bCol ?>;padding:1px 5px;border-radius:3px;font-size:0.55rem;font-weight:700;vertical-align:middle;cursor:help;" title="+<?= round($bankerEv * 100) ?>% edge over true probability"><i class="fas fa-dollar-sign"></i> BANKER +<?= round($bankerEv * 100) ?>%</span><?php endif; endif; ?>
                    </div>
                    <div style="font-size:0.7rem;color:var(--text-muted);"><?= htmlspecialchars($s['reason']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($hasLocked): ?>
        <div class="locked-overlay">
            <i class="fas fa-crown"></i>
            <div style="font-size:0.85rem;font-weight:700;color:#FBBF24;">High-Confidence Picks Locked</div>
            <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:8px;">Subscribe to unlock all signals + Best Picks</div>
            <a href="<?= $user ? 'subscribe' : 'signup' ?>" class="btn-premium" style="font-size:0.75rem;padding:6px 16px;"><?= $user ? 'Subscribe Now' : 'Sign Up Free' ?></a>
        </div>
        <?php endif; ?>
        <?php if ($hasLocked): ?>
        <div class="stats-row mt-2">
            <span class="stat" style="<?= $hDelta < 0 ? 'color:#EF4444;' : ($hDelta > 0 ? 'color:#22C55E;' : '') ?>">H <?= $hDelta < 0 ? '↓' : ($hDelta > 0 ? '↑' : '–') ?> <?= number_format(abs($hDelta), 1) ?>%</span>
            <span class="stat" style="<?= $dDelta < 0 ? 'color:#EF4444;' : ($dDelta > 0 ? 'color:#22C55E;' : '') ?>">D <?= $dDelta < 0 ? '↓' : ($dDelta > 0 ? '↑' : '–') ?> <?= number_format(abs($dDelta), 1) ?>%</span>
            <span class="stat" style="<?= $aDelta < 0 ? 'color:#EF4444;' : ($aDelta > 0 ? 'color:#22C55E;' : '') ?>">A <?= $aDelta < 0 ? '↓' : ($aDelta > 0 ? '↑' : '–') ?> <?= number_format(abs($aDelta), 1) ?>%</span>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="d-flex align-items-center gap-3">
            <div style="flex:1;">
                <div style="font-weight:700;font-size:0.9rem;"><?= $matchName ?></div>
                <div style="font-size:0.75rem;color:var(--text-muted);"><?= $league ?> · <?= $matchTime ?: 'TBD' ?></div>
                <div class="stats-row mt-1">
                    <span class="stat" style="<?= $hDelta < 0 ? 'color:#EF4444;' : ($hDelta > 0 ? 'color:#22C55E;' : '') ?>">H <?= $hDelta < 0 ? '↓' : ($hDelta > 0 ? '↑' : '–') ?> <?= number_format(abs($hDelta), 1) ?>%</span>
                    <span class="stat" style="<?= $dDelta < 0 ? 'color:#EF4444;' : ($dDelta > 0 ? 'color:#22C55E;' : '') ?>">D <?= $dDelta < 0 ? '↓' : ($dDelta > 0 ? '↑' : '–') ?> <?= number_format(abs($dDelta), 1) ?>%</span>
                    <span class="stat" style="<?= $aDelta < 0 ? 'color:#EF4444;' : ($aDelta > 0 ? 'color:#22C55E;' : '') ?>">A <?= $aDelta < 0 ? '↓' : ($aDelta > 0 ? '↑' : '–') ?> <?= number_format(abs($aDelta), 1) ?>%</span>
                </div>
                <?php $hasVer = $verified && count(array_filter($verified)) > 0; if ($hasVer): ?>
                <div style="display:flex;flex-wrap:wrap;gap:2px 8px;font-size:0.68rem;margin-top:2px;align-items:center;">
                    <span style="color:#10B981;font-weight:600;"><i class="fas fa-check-circle me-1"></i></span>
                    <?php $conLabels = ['Home','Draw','Away','Over2.5','Under2.5','BTTS-Yes','BTTS-No'];
                    $conKeys = ['1','X','2','Ov2.5','Und2.5','GG','NG'];
                    foreach ($conKeys as $i => $ck):
                        $vv = $verified[$ck] ?? null;
                        if ($vv):
                            if ($vv['noisy']):
                                echo '<span style="display:inline-flex;align-items:center;gap:1px;"><span style="color:var(--text-muted);font-weight:500;margin-right:1px;">' . $conLabels[$i] . '</span><span class="badge" style="background:#FEF3C7;color:#92400E;font-size:0.6rem;font-weight:700;padding:1px 5px;border-radius:3px;">⚡</span></span>';
                            else:
                                $cArrow = $vv['agreement'] === 'down' ? '↑' : '↓';
                                $cColor = $vv['agreement'] === 'down' ? '#10B981' : '#EF4444';
                                echo '<span style="display:inline-flex;align-items:center;gap:1px;"><span style="color:var(--text-muted);font-weight:500;margin-right:1px;">' . $conLabels[$i] . '</span><span style="color:' . $cColor . ';font-weight:700;">' . $cArrow . '</span><span style="color:' . $cColor . ';font-weight:600;">' . $vv['strength'] . '%</span></span>';
                            endif;
                        endif;
                    endforeach; ?>
                </div>
                <?php elseif ($isNoisy): ?>
                <div style="margin-top:2px;">
                    <span class="badge" style="background:#FEF3C7;color:#92400E;font-size:0.6rem;font-weight:700;padding:1px 6px;border-radius:3px;cursor:help;" title="Bookies disagree — unreliable. Avoid.">⚡ NOISY</span>
                </div>
                <?php endif; ?>
            </div>
            <div style="text-align:center;">
                <span style="font-size:0.7rem;color:var(--text-muted);display:block;"><?= count($signals) ?> signals</span>
                <a href="<?= $user ? 'subscribe' : 'signup' ?>" class="btn-premium" style="font-size:0.7rem;padding:4px 12px;margin-top:4px;">Unlock</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>

    <div class="text-center my-4">
        <?php if (!$hasAccess): ?>
        <a href="<?= $user ? 'subscribe' : 'signup' ?>" class="btn-premium" style="font-size:0.9rem;padding:14px 40px;">
            <i class="fas fa-crown me-1"></i> <?= $user ? 'Subscribe for Full Access' : 'Sign Up Free — 30-Day Trial' ?>
        </a>
        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:6px;">Unlock high-confidence picks and Best Pick Per Market</div>
        <?php else: ?>
        <div style="font-size:0.85rem;color:#22C55E;"><i class="fas fa-check-circle me-1"></i> Premium access active — all signals unlocked</div>
        <?php endif; ?>
    </div>

    <div class="my-4" style="display:flex;flex-direction:column;gap:16px;">
        <?php if ($signalQuality === 'low'): ?>
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:8px 14px;font-size:0.78rem;color:#EF4444;"><i class="fas fa-triangle-exclamation me-1"></i>Low signal day — fewer matches qualify. Best Pick may be limited or less reliable.</div>
        <?php elseif ($signalQuality === 'moderate'): ?>
        <div style="background:rgba(251,191,36,0.1);border:1px solid rgba(251,191,36,0.2);border-radius:8px;padding:8px 14px;font-size:0.78rem;color:#FBBF24;"><i class="fas fa-circle-exclamation me-1"></i>Moderate signal volume — Best Picks available but limited.</div>
        <?php endif; ?>
        <div style="background:linear-gradient(135deg, rgba(139,92,246,0.2) 0%, rgba(6,182,212,0.1) 100%);border:1px solid rgba(139,92,246,0.3);border-radius:12px;padding:20px;">
            <h6 style="font-weight:700;color:#FBBF24;"><i class="fas fa-trophy me-1"></i>Best Pick Per Market</h6>
                <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:12px;">The safest high-confidence pick for each of the 6 markets.</p>
                <?php if ($hasAccess): ?>
                    <?php
                    $marketOrder = ['1X2', 'Double Chance', 'Over 1.5 Goals', 'Under 3.5 Goals', 'GG (BTTS)', 'Team to Score'];
                    $topPicks = [];
                    foreach ($analyzed as $a) {
                        if (isNoisy($a['match'])) continue;
                        $mm = $a['match'];
                        $hh = (float)($mm['home_odds'] ?? 0);
                        $dd = (float)($mm['draw_odds'] ?? 0);
                        $aa = (float)($mm['away_odds'] ?? 0);
                        foreach ($a['signals'] as $s) {
                            $mkt = $s['market'];
                            $vs = $s['verification_score'] ?? $s['confidence'];
                            $est = estimateOdds($s, $mm);
                            if (!isset($topPicks[$mkt]) || $vs > ($topPicks[$mkt]['score'] ?? 0)) {
                                $topPicks[$mkt] = ['match' => $a['match'], 'signal' => $s, 'score' => $vs, 'estimated_odds' => $est > 0 ? $est : null];
                            }
                        }
                    }
                    ?>
                    <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($marketOrder as $mkt):
                        if (!isset($topPicks[$mkt])) continue;
                        $tp = $topPicks[$mkt]['signal'];
                        $tm = $topPicks[$mkt]['match'];
                        $vs = $tp['verification_score'] ?? $tp['confidence'];
                        $tpCls = $vs >= 75 ? 'high' : ($vs >= 55 ? 'medium' : 'low');
                        $tpMatchTime = $tm['match_time'] && $tm['match_time'] !== 'TBD' ? htmlspecialchars($tm['match_time']) : '';
                    ?>
                        <div style="flex:1;min-width:170px;max-width:230px;background:linear-gradient(135deg, rgba(251,191,36,0.15) 0%, rgba(245,158,11,0.08) 100%);border:1px solid rgba(251,191,36,0.3);border-radius:10px;padding:8px 10px;">
                            <div class="d-flex align-items-center gap-1 mb-1">
                                <i class="<?= $marketIcons[$mkt] ?? 'fas fa-chart-line' ?>" style="font-size:0.65rem;color:var(--accent);"></i>
                                <span style="font-size:0.65rem;font-weight:600;color:var(--accent);"><?= htmlspecialchars($mkt) ?></span>
                                <span class="conf-ring <?= $tpCls ?>" style="width:22px;height:22px;font-size:0.55rem;margin-left:auto;"><?= $vs ?></span>
                            </div>
                            <div style="font-size:0.72rem;font-weight:600;line-height:1.2;"><?= htmlspecialchars($tm['match_name']) ?></div>
                             <div style="font-size:0.68rem;color:var(--text-muted);"><?= htmlspecialchars($tp['pick']) ?><?php $tpEv = $tp['ev_value'] ?? null; if ($tpEv !== null && $tpEv >= 0.05): $bCol = '#06B6D4'; $bBg = 'rgba(6,182,212,0.3)'; ?> <span class="badge" style="background:<?= $bBg ?>;color:<?= $bCol ?>;font-size:0.5rem;padding:1px 4px;border-radius:3px;cursor:help;vertical-align:middle;" title="+<?= round($tpEv * 100) ?>% edge over true probability"><i class="fas fa-dollar-sign"></i> BANKER +<?= round($tpEv * 100) ?>%</span><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align:center;padding:20px;background:linear-gradient(135deg, rgba(139,92,246,0.2) 0%, rgba(6,182,212,0.1) 100%);border:1px dashed rgba(139,92,246,0.3);border-radius:8px;">
                        <i class="fas fa-lock" style="font-size:1.2rem;color:var(--text-muted);margin-bottom:4px;"></i>
                        <div style="font-size:0.8rem;color:var(--text-muted);">Locked for free users</div>
                        <a href="<?= $user ? 'subscribe' : 'signup' ?>" class="btn-premium mt-2" style="font-size:0.7rem;padding:4px 14px;">Unlock</a>
                    </div>
                <?php endif; ?>
            </div>

    </div>

    <?php if (!empty($bayesianPicks)): ?>
    <div class="my-4" style="background:linear-gradient(135deg, rgba(34,197,94,0.08) 0%, rgba(139,92,246,0.06) 100%);border:1px solid rgba(34,197,94,0.25);border-radius:12px;padding:18px;">
        <h6 style="font-weight:700;color:#22C55E;"><i class="fas fa-robot me-1"></i>Value Picks</h6>
        <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:10px;">Statistical value bets identified by the prediction model — potentially undervalued by bookmakers.</p>
        <div class="d-flex flex-wrap gap-2">
        <?php foreach ($bayesianPicks as $bp):
            $bpConf = round($bp['probability']);
            $bpCls = $bpConf >= 60 ? 'high' : ($bpConf >= 40 ? 'medium' : 'low');
            $hasBpOdds = $bp['best_odds'] > 0;
        ?>
            <div style="flex:1;min-width:160px;max-width:220px;background:linear-gradient(135deg, rgba(34,197,94,0.12) 0%, rgba(139,92,246,0.08) 100%);border:1px solid rgba(34,197,94,0.3);border-radius:10px;padding:8px 10px;">
                <div class="d-flex align-items-center gap-1 mb-1">
                    <span style="font-size:0.65rem;font-weight:600;color:#22C55E;"><?= htmlspecialchars($bp['pick_value']) ?></span>
                    <span class="conf-ring <?= $bpCls ?>" style="width:22px;height:22px;font-size:0.55rem;margin-left:auto;"><?= $bpConf ?></span>
                </div>
                <div style="font-size:0.72rem;font-weight:600;line-height:1.2;"><?= htmlspecialchars($bp['match_name']) ?></div>
                <div style="font-size:0.68rem;color:var(--text-muted);">
                    <?= $bp['probability'] ?>% prob
                    <?php if ($hasBpOdds): ?> &middot; @<?= number_format($bp['best_odds'], 2) ?><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
</div>

<div class="container py-4">
    <h5 style="font-weight:700;margin-bottom:4px;"><i class="fas fa-tag me-1" style="color:#FBBF24;"></i>Plans &amp; Pricing</h5>
    <p style="font-size:0.85rem;color:var(--text-muted);">Every plan includes full signal access. Pick the duration that fits.</p>
    <div class="pricing-grid">
    <?php
    $tiers = [
        'parlay' => ['title' => 'Parlay', 'features' => ['Smart Picks Signal Access', 'Best Pick Per Market', 'High-Confidence Picks', 'Parlay Betting Picks'], 'locked' => ['Safety Rollover + PRO Predictions']],
        'rollover' => ['title' => 'Rollover', 'features' => ['Smart Picks Signal Access', 'Best Pick Per Market', 'High-Confidence Picks', '7-Day Safety Rollover', 'Core Leagues Only', 'Most Corners'], 'locked' => ['Parlay + PRO Predictions'], 'popular' => true],
        'both' => ['title' => 'Both', 'features' => ['Everything in Parlay', 'Everything in Rollover', 'PRO Predictions (Elevated accuracy)', 'Full Access to All Features', 'Priority Support', 'Best Value'], 'locked' => []],
    ];
    $durationOpts = ['daily' => ['label' => 'Daily'], 'biweekly' => ['label' => '14 Days'], 'monthly' => ['label' => 'Monthly']];
    foreach ($tiers as $tierKey => $tier):
        $firstPrice = getPlanPrice($tierKey, 'monthly');
        $firstDays = getPlanDays($tierKey, 'monthly');
        $perDay = round($firstPrice / $firstDays);
    ?>
    <div class="pricing-card <?= !empty($tier['popular']) ? 'popular' : '' ?>">
        <?php if (!empty($tier['popular'])): ?><span class="pricing-badge">MOST POPULAR</span><?php endif; ?>
        <div class="pricing-title"><?= $tier['title'] ?></div>
        <div class="pricing-price"><?= number_format($firstPrice) ?> <small style="font-size:0.7rem;-webkit-text-fill-color:var(--text-muted);color:var(--text-muted);">/mo</small></div>
        <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:8px;">~<?= $perDay ?> TZS/day</div>
        <ul class="pricing-features">
        <?php foreach ($tier['features'] as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?>
        <?php if (!empty($tier['locked'])): ?>
        <?php foreach ($tier['locked'] as $l): ?>
        <li style="color:var(--text-muted);text-decoration:line-through;"><?= htmlspecialchars($l) ?></li>
        <?php endforeach; ?>
        <?php endif; ?>
        </ul>
        <div class="d-flex flex-wrap gap-1 justify-content-center mb-3">
        <?php foreach ($durationOpts as $dk => $dopt):
            $p = getPlanPrice($tierKey, $dk);
            $pd = round($p / getPlanDays($tierKey, $dk));
        ?>
        <a href="subscribe?tier=<?= $tierKey ?>&duration=<?= $dk ?>" class="btn btn-sm <?= $dk === 'monthly' ? 'btn-premium' : '' ?>" style="<?= $dk === 'monthly' ? '' : 'border:1px solid var(--border-color);color:var(--text-muted);padding:4px 8px;font-size:0.7rem;border-radius:6px;text-decoration:none;' ?>"><?= $dopt['label'] ?> <?= number_format($p) ?> TZS</a>
        <?php endforeach; ?>
        </div>
        <a href="<?= $user ? 'subscribe?tier='.$tierKey : 'signup' ?>" class="btn-premium w-100" style="font-size:0.8rem;padding:10px;">
            <?= $user ? 'Subscribe' : ($tierKey === 'parlay' ? 'Start Free Trial' : 'Subscribe') ?>
        </a>
    </div>
    <?php endforeach; ?>
    </div>
    <div style="background:linear-gradient(135deg, rgba(251,191,36,0.12) 0%, rgba(245,158,11,0.06) 100%);border:1px solid rgba(251,191,36,0.25);border-radius:10px;padding:16px;margin-top:16px;">
        <div style="font-size:0.8rem;font-weight:600;margin-bottom:4px;"><i class="fas fa-gift me-1" style="color:#FBBF24;"></i> Free Trial Available</div>
        <div style="font-size:0.75rem;color:var(--text-muted);">New users get <strong style="color:var(--text-light);">30 days free Parlay</strong> access — includes full signals, Best Pick, and premium features. No payment needed.</div>
    </div>

    <div class="mt-5 p-4" style="background:linear-gradient(135deg, rgba(139,92,246,0.2) 0%, rgba(6,182,212,0.1) 100%);border:1px solid rgba(139,92,246,0.3);border-radius:12px;">
        <h6 style="font-weight:700;color:var(--accent);margin-bottom:12px;"><i class="fas fa-info-circle me-1"></i>How Smart Picks Work</h6>
        <div class="row g-3">
            <div class="col-md-4">
                <div style="font-size:2rem;font-weight:800;color:var(--primary);">1</div>
                <div style="font-weight:600;font-size:0.9rem;">Scan</div>
                <div style="font-size:0.78rem;color:var(--text-muted);">Real-time 1X2 odds movement tracked across all major leagues. Drops, rises, and stability logged per outcome.</div>
            </div>
            <div class="col-md-4">
                <div style="font-size:2rem;font-weight:800;color:var(--primary);">2</div>
                <div style="font-weight:600;font-size:0.9rem;">Analyze</div>
                <div style="font-size:0.78rem;color:var(--text-muted);">6 market signals generated: 1X2, Double Chance, Over/Under 3.5, BTTS, Team to Score. Each gets a confidence score (30-95).</div>
            </div>
            <div class="col-md-4">
                <div style="font-size:2rem;font-weight:800;color:var(--primary);">3</div>
                <div style="font-weight:600;font-size:0.9rem;">Recommend</div>
                <div style="font-size:0.78rem;color:var(--text-muted);">Balancing guard filters out bookmaker noise. Safety scoring prioritizes realistic odds. Best picks assembled.</div>
            </div>
        </div>
    </div>
</div>

<footer style="background:var(--secondary);border-top:1px solid var(--border);padding:40px 0;margin-top:60px;">
    <div class="container">
        <div class="row">
            <div class="col-md-4">
                <h5 class="mb-3" style="font-weight:700;color:var(--text);"><i class="fas fa-futbol me-1" style="color:var(--accent);"></i>PREDIXA</h5>
                <p style="font-size:0.85rem;color:var(--muted);">AI-powered football analytics, daily picks, and a tipster marketplace. Subscribe, bet, publish codes, and earn.</p>
            </div>
            <div class="col-md-2">
                <h6 class="mb-3" style="font-weight:700;color:var(--text);">Quick Links</h6>
                <ul class="list-unstyled" style="font-size:0.85rem;">
                    <li class="mb-2"><a href="./#pricing" style="color:var(--muted);text-decoration:none;"><i class="fas fa-tag me-1" style="color:var(--accent);"></i> Plans</a></li>
                    <li class="mb-2"><?php if (isset($_SESSION['user_id'])): ?><a href="dashboard" style="color:var(--muted);text-decoration:none;"><i class="fas fa-gauge-high me-1" style="color:var(--accent);"></i> Dashboard</a><?php else: ?><a href="login" style="color:var(--muted);text-decoration:none;"><i class="fas fa-right-to-bracket me-1" style="color:var(--primary);"></i> Login</a><?php endif; ?></li>
                    <?php if (!isset($_SESSION['user_id'])): ?><li class="mb-2"><a href="signup" style="color:var(--muted);text-decoration:none;"><i class="fas fa-user-plus me-1" style="color:#22C55E;"></i> Sign Up</a></li><?php endif; ?>
                    <li class="mb-2"><a href="./#codes-faq" style="color:var(--muted);text-decoration:none;"><i class="fas fa-circle-question me-1" style="color:#FBBF24;"></i> FAQ</a></li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6 class="mb-3" style="font-weight:700;color:var(--text);">Free Tools</h6>
                <ul class="list-unstyled" style="font-size:0.85rem;">
<li class="mb-2"><a href="dropping-odds" style="color:var(--muted);text-decoration:none;"><i class="fas fa-arrow-trend-down me-1" style="color:#EF4444;"></i> Dropping Odds</a></li>
                    <li class="mb-2"><a href="track-record" style="color:var(--muted);text-decoration:none;"><i class="fas fa-chart-line me-1" style="color:#FBBF24;"></i> Performance</a></li>
                    <li class="mb-2"><a href="betting-school" style="color:var(--muted);text-decoration:none;"><i class="fas fa-book me-1" style="color:#8B5CF6;"></i> Betting School</a></li>
                    <li class="mb-2"><a href="pikka" style="color:var(--muted);text-decoration:none;"><i class="fas fa-futbol me-1" style="color:#6366F1;"></i> Pikka</a></li>
                    <li class="mb-2"><a href="https://www.begambleaware.org/" target="_blank" rel="noopener noreferrer" style="color:var(--muted);text-decoration:none;"><i class="fas fa-shield-halved me-1" style="color:#10B981;"></i> Responsible Gambling</a></li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6 class="mb-3" style="font-weight:700;color:var(--text);">Support</h6>
                <ul class="list-unstyled" style="font-size:0.85rem;">
                    <li class="mb-2"><a href="https://wa.me/255713348298" target="_blank" style="color:#25D366;text-decoration:none;"><i class="fab fa-whatsapp me-1"></i> WhatsApp</a></li>
                    <li class="mb-2"><a href="mailto:support@predixa.co.tz" style="color:var(--muted);text-decoration:none;"><i class="fas fa-envelope me-1" style="color:var(--primary);"></i> Email Us</a></li>
                    <li class="mb-2" style="color:var(--muted);"><i class="fas fa-clock me-1" style="color:var(--accent);"></i> 24/7 Support</li>
                    <li class="mb-2"><a href="terms" style="color:var(--muted);text-decoration:none;"><i class="fas fa-file-lines me-1" style="color:var(--muted);"></i> Terms of Service</a></li>
                </ul>
            </div>
        </div>
        <div class="border-top border-secondary mt-4 pt-4 text-center" style="color:var(--muted);font-size:0.8rem;">
            <small>&copy; <?= date('Y') ?> Predixa. All rights reserved. | 18+ | Bet Responsibly</small>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function sortMatches(val) {
    var list = document.getElementById('signalsList');
    if (!list) return;
    var cards = Array.from(list.querySelectorAll('.signal-card'));
    cards.sort(function(a, b) {
        if (val === 'time-desc') return parseInt(b.getAttribute('data-time')) - parseInt(a.getAttribute('data-time'));
        if (val === 'time-asc') return parseInt(a.getAttribute('data-time')) - parseInt(b.getAttribute('data-time'));
        if (val === 'kickoff-asc') return parseInt(a.getAttribute('data-kickoff')) - parseInt(b.getAttribute('data-kickoff'));
        if (val === 'kickoff-desc') return parseInt(b.getAttribute('data-kickoff')) - parseInt(a.getAttribute('data-kickoff'));
        return 0;
    });
    cards.forEach(function(c) { list.appendChild(c); });
}
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('searchInput');
    if (!searchInput) return;
    searchInput.addEventListener('input', doFilter);
    function doFilter() {
        var q = searchInput.value.toLowerCase().trim();
        var cards = document.querySelectorAll('.signal-card');
        var visible = 0;
        cards.forEach(function(c) {
            var name = (c.getAttribute('data-match-name') || '').toLowerCase();
            var match = !q || name.includes(q);
            c.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        var countEl = document.getElementById('matchCount');
        if (countEl) countEl.textContent = visible + ' matches with signals';
        var sortEl = document.getElementById('sortSelect');
        if (sortEl) sortMatches(sortEl.value);
    }
});
</script>
</body>
</html>
