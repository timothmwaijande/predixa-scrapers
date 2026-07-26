<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/signals_engine.php';
logPageVisit('dropping-odds.php');

$picks = [];
try {
    $db = getDB();
    if ($db) {
        $stmt = $db->query("SELECT wp.*
            FROM web_picks wp INNER JOIN (SELECT MAX(id) as id FROM web_picks WHERE (pattern_badge LIKE '%FALLING ODDS%' OR pattern_badge LIKE '%RISING ODDS%' OR fav_delta <= -2 OR opp_delta <= -2 OR draw_delta <= -2 OR fav_delta >= 2 OR opp_delta >= 2 OR draw_delta >= 2) AND DATE(detected_at) = CURDATE() GROUP BY match_name) latest ON wp.id = latest.id ORDER BY LEAST(ABS(wp.fav_delta), ABS(wp.opp_delta), ABS(wp.draw_delta)) ASC LIMIT 100");
        $picks = $stmt->fetchAll();
    }
} catch (Exception $e) {
    error_log("dropping-odds: " . $e->getMessage());
}

function getOutcomes($pick) {
    $isHomeFav = !empty($pick['is_home_fav']);
    $fav = (float)($pick['fav_delta'] ?? 0);
    $opp = (float)($pick['opp_delta'] ?? 0);
    $dx = (float)($pick['draw_delta'] ?? 0);
    $hOdds = (float)($pick['home_odds'] ?? 0);
    $xOdds = (float)($pick['draw_odds'] ?? 0);
    $aOdds = (float)($pick['away_odds'] ?? 0);
    return [
        'home' => ['label' => 'HOME', 'total' => $isHomeFav ? $fav : $opp, 'odds' => $hOdds > 0 ? $hOdds : null],
        'draw' => ['label' => 'DRAW', 'total' => $dx, 'odds' => $xOdds > 0 ? $xOdds : null],
        'away' => ['label' => 'AWAY', 'total' => $isHomeFav ? $opp : $fav, 'odds' => $aOdds > 0 ? $aOdds : null],
    ];
}

function maxAbsDelta($pick) {
    $o = getOutcomes($pick);
    return max(abs($o['home']['total']), abs($o['draw']['total']), abs($o['away']['total']));
}

$sort = $_GET['sort'] ?? 'drop';
if ($sort === 'newest') {
    usort($picks, fn($a, $b) => strtotime($b['detected_at']) <=> strtotime($a['detected_at']));
} elseif ($sort === 'name') {
    usort($picks, fn($a, $b) => strcmp($a['match_name'], $b['match_name']));
} else {
    usort($picks, fn($a, $b) => maxAbsDelta($b) <=> maxAbsDelta($a));
}

$movements = null;
try {
    $movements = getMultiBookieSheetData();
} catch (Exception $e) {
    error_log("dropping-odds movements fetch: " . $e->getMessage());
}

$pageTitle = 'Dropping & Rising Odds – Live Football Odds Movement | PREDIXA';
$pageDesc = 'Track live football odds movement across all three outcomes (1X2). Free tool to spot dropping and rising odds across multiple leagues.';
$canonical = (defined('SITE_URL') ? SITE_URL : 'https://predixa.co.tz') . '/dropping-odds';
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
.page-header { padding: 110px 0 30px; }
.page-header h1 { font-weight: 800; font-size: 2rem; }
.page-header p { color: var(--text-muted); font-size: 1rem; max-width: 600px; }
.search-box { background: rgba(22,27,34,0.55); border: 1px solid rgba(139,92,246,0.25); border-radius: 10px; padding: 12px 16px; color: var(--text-light); width: 100%; font-size: 0.9rem; transition: all .3s; }
.search-box:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(139,92,246,0.15); }
.search-box::placeholder { color: var(--text-muted); }
.drop-card { background: linear-gradient(135deg, rgba(139,92,246,0.2) 0%, rgba(6,182,212,0.1) 100%); border: 1px solid rgba(139,92,246,0.3); border-radius: 12px; padding: 16px; margin-bottom: 10px; transition: all 0.3s; }
.drop-card:hover { border-color: var(--primary); transform: translateY(-1px); }
.drop-card .match-info { flex: 1; min-width: 180px; }
.drop-card .match-name { font-weight: 700; font-size: 0.95rem; }
.drop-card .match-meta { font-size: 0.8rem; color: var(--text-muted); margin-top: 2px; }
.odds-group { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.odds-badge { padding: 4px 12px; border-radius: 14px; font-weight: 700; font-size: 0.78rem; white-space: nowrap; display: inline-flex; flex-direction: column; align-items: center; gap: 2px; min-width: 82px; text-align: center; line-height: 1.3; border: 1px solid transparent; }
.odds-badge.drop { background: rgba(239, 68, 68, 0.12); border-color: rgba(239, 68, 68, 0.25); color: #EF4444; }
.odds-badge.rise { background: rgba(34, 197, 94, 0.12); border-color: rgba(34, 197, 94, 0.25); color: #22C55E; }
.odds-badge.neutral { background: rgba(148, 163, 184, 0.1); border-color: rgba(148, 163, 184, 0.15); color: var(--text-muted); }
.odds-badge .b1 { font-size: 0.85rem; }
.odds-badge .b2 { font-size: 0.68rem; font-weight: 600; opacity: 0.85; }
.odds-label { color: var(--text-muted); font-size: 0.7rem; font-weight: 600; }
.odds-label { color: var(--text-muted); font-size: 0.7rem; font-weight: 600; margin-right: 2px; }
.drop-card .btn-details { border: 1px solid var(--primary); color: var(--primary); background: transparent; padding: 4px 14px; border-radius: 6px; font-weight: 600; font-size: 0.75rem; transition: all 0.3s; white-space: nowrap; }
.drop-card .btn-details:hover { background: var(--primary); color: white; }
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.empty-state i { font-size: 3rem; margin-bottom: 15px; color: var(--primary); }
.btn-outline-premium { background: transparent; color: var(--primary); border: 2px solid var(--primary); padding: 12px 30px; font-weight: 600; border-radius: 8px; transition: all 0.3s; text-decoration: none; display: inline-block; }
.btn-outline-premium:hover { background: var(--primary); color: white; transform: translateY(-2px); text-decoration: none; }
.timestamp { font-size: 0.78rem; color: var(--text-light); white-space: nowrap; background: rgba(255,255,255,0.05); padding: 3px 10px; border-radius: 5px; }
.verified-bar { display:flex; flex-wrap:wrap; gap:3px 12px; font-size:0.72rem; margin-top:6px; padding-top:6px; border-top:1px solid rgba(255,255,255,0.06); align-items:center; }
.verified-bar .v-label { color:var(--text-muted); font-weight:600; }
.verified-bar .v-cell { display:inline-flex; align-items:center; gap:2px; }
.verified-bar .v-arrow { font-weight:700; }
.verified-bar .v-pct { font-weight:600; }
.verified-bar .v-muted { color:var(--text-muted); }
footer { border-top: 1px solid var(--border-color); padding: 40px 0; margin-top: 60px; background: rgba(15, 17, 21, 0.5); color: var(--text-muted); font-size: 0.85rem; }
footer h5, footer h6 { color: var(--text-light); font-weight: 700; }
footer a { color: var(--text-muted); text-decoration: none; transition: all 0.3s; }
footer a:hover { color: var(--primary); }
@media (max-width: 768px) { .page-header { padding: 90px 0 20px; } .page-header h1 { font-size: 1.5rem; } }
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
                <li class="nav-item"><a class="nav-link" href="signals"><i class="fas fa-microchip me-1" style="color:#22C55E;"></i>Smart Picks</a></li>
                <li class="nav-item dropdown">
<a class="nav-link dropdown-toggle active" href="javascript:void(0)" role="button" data-bs-toggle="dropdown">Free Tools</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item active" href="dropping-odds"><i class="fas fa-arrow-down me-1" style="color:#EF4444;"></i> Dropping Odds</a></li>
                        <li><a class="dropdown-item" href="track-record"><i class="fas fa-chart-line me-1" style="color:#FBBF24;"></i> Performance</a></li>
                        <li><a class="dropdown-item" href="betting-school"><i class="fas fa-book-open me-1" style="color:#fff;"></i> Betting School</a></li>
                        <li><a class="dropdown-item" href="pikka"><i class="fas fa-futbol me-1" style="color:#6366F1;"></i> Pikka</a></li>
                    </ul>
                </li>
                <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                    <?php if (isset($_SESSION['user_id'])): ?>
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
        <h1><i class="fas fa-chart-line me-2" style="color:#EF4444;"></i>Odds Movement</h1>
        <p style="color:var(--text-muted);font-size:1rem;max-width:700px;">Track odds movement across all three outcomes (HOME/DRAW/AWAY). <span style="color:#EF4444;font-weight:600;">↓ Dropping</span> means the market is shifting that way — smart money moving. <span style="color:#22C55E;font-weight:600;">↑ Rising</span> means money is leaving. <span style="color:var(--text-muted);">— Stable</span> means no significant movement. Spot value before odds tighten. <a href="login" style="color:var(--accent);font-weight:600;">Sign in</a> for premium picks.</p>
    </div>
</div>

<div class="content-area">
<div class="container pb-4 pt-4">
    <?php if (empty($picks)): ?>
    <div class="empty-state">
        <i class="fas fa-chart-line"></i>
        <h5>Awaiting Market Movement</h5>
        <p class="mb-0">Our automated odds engine is actively scanning 1X2 markets across all major leagues in real-time — no significant movement detected yet. Data refreshes automatically as bookmakers update their odds. Check back shortly or browse <a href="./#preview" style="color:var(--accent);text-decoration:underline;">Today's Picks</a>.</p>
    </div>
    <?php else: ?>
    <div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <input type="text" id="searchInput" class="form-control form-control-sm search-box" placeholder="Search matches..." style="max-width:260px;font-size:0.82rem;background:rgba(22,27,34,0.7);border:1.5px solid rgba(139,92,246,0.45);color:var(--text-light);" oninput="filterMatches(this.value)">
            <span style="color:var(--text-muted);font-size:0.85rem;white-space:nowrap;">Showing <span id="matchCount"><?= count($picks) ?></span> matches</span>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span style="color:var(--text-muted);font-size:0.75rem;">Sort:</span>
            <select id="sortSelect" class="form-select form-select-sm" style="width:auto;background:var(--secondary);border-color:var(--border-color);color:var(--text-light);font-size:0.75rem;padding:4px 28px 4px 8px;" onchange="sortMatches(this.value)">
                <option value="drop-desc">Biggest movement first</option>
                <option value="time-desc">Most recent signal</option>
                <option value="time-asc">Oldest signal</option>
                <option value="kickoff-asc">Earliest kick off</option>
                <option value="kickoff-desc">Latest kick off</option>
            </select>
        </div>
    </div>
    <div id="matchesList">
    <?php foreach ($picks as $pick):
        $outcomes = getOutcomes($pick);
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', $pick['match_name'] ?? ''), '-'));
        $league = htmlspecialchars($pick['league'] ?? 'Unknown League');
        $matchName = htmlspecialchars($pick['match_name']);
        $matchTime = $pick['match_time'] && $pick['match_time'] !== 'TBD' ? htmlspecialchars($pick['match_time']) : '';
        $detectedTime = !empty($pick['detected_at']) ? date('H:i', strtotime($pick['detected_at'])) : '';
        $maxAbs = maxAbsDelta($pick);
        $kickoffTs = $pick['match_time'] && $pick['match_time'] !== 'TBD' ? (function($t){ $p=explode(':',$t); return (int)$p[0]*60+(int)($p[1]??0); })($pick['match_time']) : 0;
    ?>
    <div class="drop-card" data-search="<?= strtolower($matchName . ' ' . $league) ?>" data-maxdelta="<?= $maxAbs ?>" data-time="<?= strtotime($pick['detected_at'] ?? '0') ?>" data-kickoff="<?= $kickoffTs ?>">
        <?php
        $conHome = trim((preg_match('/^(.+?)\s+vs\s+(.+?)$/i', $pick['match_name'] ?? '', $cParts) ? $cParts[1] : ''));
        $conAway = trim($cParts[2] ?? '');
        $verified = ($movements && $conHome && $conAway) ? getMatchVerifiedAll($movements, $conHome, $conAway) : null;
        $isNoisy = isNoisy($pick);
        ?>
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;">
            <div class="match-info">
                <div class="match-name"><?= $matchName ?></div>
                <div class="match-meta">
                    <?= $league ?>
                    <?php if ($matchTime): ?> &middot; <i class="far fa-clock me-1"></i><?= $matchTime ?> (GMT+3)<?php endif; ?>
                </div>
            </div>
            <div class="odds-group">
                <?php foreach (['home','draw','away'] as $key):
                    $o = $outcomes[$key];
                    $total = $o['total'];
                    if ($total < 0) { $tcls = 'drop'; $tIcon = 'arrow-down'; $tSign = ''; $tLabel = 'Dropping'; }
                    elseif ($total > 0) { $tcls = 'rise'; $tIcon = 'arrow-up'; $tSign = '+'; $tLabel = 'Rising'; }
                    else { $tcls = 'neutral'; $tIcon = 'arrow-right'; $tSign = ''; $tLabel = 'Stable'; }
                ?>
                <span class="odds-badge <?= $tcls ?>">
                    <span><span class="odds-label"><?= $o['label'] ?></span><?= $o['odds'] !== null ? ' <span style="opacity:0.6;">@ ' . number_format($o['odds'], 2) . '</span>' : '' ?></span>
                    <span><i class="fas fa-<?= $tIcon ?>"></i> <?= $tSign ?><?= number_format(abs($total), 1) ?>% <?= $tLabel ?></span>
                </span>
                <?php endforeach; ?>
                <?php if ($detectedTime): ?>
                <span class="timestamp"><i class="far fa-clock me-1"></i>Last drop: <?= $detectedTime ?> (GMT+3)</span>
                <?php endif; ?>
            </div>
            <?php if ($slug): ?>
            <?php if (isset($_SESSION['user_id'])): ?>
            <a href="prediction/<?= $slug ?>-<?= (int)$pick['id'] ?>" class="btn-details">Match Details &rarr;</a>
            <?php else: ?>
            <a href="login?redirect=prediction/<?= $slug ?>-<?= (int)$pick['id'] ?>" class="btn-details"><i class="fas fa-lock me-1" style="font-size:0.7rem;"></i>Unlock Details &rarr;</a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
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
function filterMatches(q) {
    q = q.toLowerCase().trim();
    var cards = document.querySelectorAll('.drop-card');
    var count = 0;
    cards.forEach(function(c) {
        var match = c.getAttribute('data-search') || '';
        if (!q || match.indexOf(q) !== -1) {
            c.style.display = '';
            count++;
        } else {
            c.style.display = 'none';
        }
    });
    var el = document.getElementById('matchCount');
    if (el) el.textContent = count;
    sortMatches(document.getElementById('sortSelect').value);
}

function sortMatches(val) {
    var list = document.getElementById('matchesList');
    var cards = Array.from(list.querySelectorAll('.drop-card'));
    cards.sort(function(a, b) {
        if (val === 'drop-desc') return parseFloat(b.getAttribute('data-maxdelta')) - parseFloat(a.getAttribute('data-maxdelta'));
        if (val === 'time-desc') return parseInt(b.getAttribute('data-time')) - parseInt(a.getAttribute('data-time'));
        if (val === 'time-asc') return parseInt(a.getAttribute('data-time')) - parseInt(b.getAttribute('data-time'));
        if (val === 'kickoff-asc') return parseInt(a.getAttribute('data-kickoff')) - parseInt(b.getAttribute('data-kickoff'));
        if (val === 'kickoff-desc') return parseInt(b.getAttribute('data-kickoff')) - parseInt(a.getAttribute('data-kickoff'));
        return 0;
    });
    cards.forEach(function(c) { list.appendChild(c); });
}
</script>
</body>
</html>
