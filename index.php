<?php
session_start();
require_once 'config.php';
require_once 'auth.php';
if (file_exists(__DIR__ . '/includes/sportsbook_ads.php')) {
    require_once __DIR__ . '/includes/sportsbook_ads.php';
} elseif (!function_exists('renderSportsbookAd')) {
    function renderSportsbookAd(...$args) {}
    function renderSportsbookAdWidget(...$args) {}
    function renderSportsbookAdBottomBar(...$args) {}
    function getSportsbookAds($slot) { return []; }
    function getSportsbookAd($slot) { return null; }
    function sportsbookAdUrl($url) { return $url; }
    function predixaAdsTrackScript() {}
}
logPageVisit('index.php');

// Today's free picks: parlay + over/under markets, all shown free.
$freePicks = [];
try {
    $parlayPicks = fetchPicks('parlay');
    $parlayPicks = deduplicatePicks($parlayPicks);
    $over15Picks = fetchPicks('over15');
    $over15Picks = deduplicatePicks($over15Picks);
    $under25Picks = fetchPicks('under_25');
    $under25Picks = deduplicatePicks($under25Picks);
    $freePicks = array_merge($parlayPicks, $over15Picks, $under25Picks);
    $freePicks = deduplicatePicks($freePicks);
    usort($freePicks, function ($a, $b) {
        $order = ['rollover' => 0, 'parlay' => 1, 'over_15' => 2, 'under_25' => 3];
        $ta = $order[$a['pick_type']] ?? 9;
        $tb = $order[$b['pick_type']] ?? 9;
        if ($ta !== $tb) return $ta <=> $tb;
        $ra = (int)($a['win_rate_low'] ?? 0);
        $rb = (int)($b['win_rate_low'] ?? 0);
        return $rb <=> $ra;
    });
    $marketCaps = ['parlay' => 5, 'over_15' => 3, 'under_25' => 1];
    $totalFreePicks = count($freePicks);
    $marketTotals = [];
    foreach ($freePicks as $pick) {
        $t = $pick['pick_type'] ?? 'parlay';
        $marketTotals[$t] = ($marketTotals[$t] ?? 0) + 1;
    }
    $preview = [];
    $typeCounts = [];
    $lockedCount = 0;
    foreach ($freePicks as $pick) {
        $t = $pick['pick_type'] ?? 'parlay';
        $cap = $marketCaps[$t] ?? 3;
        if (($typeCounts[$t] ?? 0) < $cap) {
            $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
            $preview[] = $pick;
        } else {
            $lockedCount++;
        }
    }
    $freePicks = $preview;
} catch (Exception $e) {
    error_log("Index page: " . $e->getMessage());
}

// Free daily pick (yesterday's result)
$freeDailyPick = null;
try { $freeDailyPick = getFreeDailyPick(); } catch (Exception $e) {}

// Social proof stats
$totalUsers = 0;
$totalPicks = 0;
try {
    $db = getDB();
    if ($db) {
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM web_users");
        $totalUsers = (int)$stmt->fetch()['cnt'];
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM web_picks");
        $totalPicks = (int)$stmt->fetch()['cnt'];
    }
} catch (Exception $e) {}

// Partner bookmakers for card CTAs (hp2)
$topBookies = [];
if (function_exists('getSportsbookAds')) {
    $allTop = getSportsbookAds('hp2');
    if (function_exists('sportsbookAdReady')) $topBookies = array_values(array_filter($allTop, 'sportsbookAdReady'));
    else $topBookies = $allTop;
}
$bookies = getSportsbookAds('hp1');
if (empty($topBookies) && function_exists('sportsbookAdReady')) {
    $topBookies = array_values(array_filter($bookies, 'sportsbookAdReady'));
}
$bestBookie = !empty($topBookies) ? $topBookies[0] : null;

// "Where to Place Your Bets" widget section (hp5)
$widgetBookies = [];
if (function_exists('getSportsbookAds')) {
    $allWidget = getSportsbookAds('hp5');
    if (function_exists('sportsbookAdReady')) $widgetBookies = array_values(array_filter($allWidget, 'sportsbookAdReady'));
    else $widgetBookies = $allWidget;
}

// Rollover teaser count for premium upsell
$rolloverCount = 0;
try { $rolloverCount = count(getAvailableRolloverPicks()); } catch (Exception $e) {}

function showOddsForPick($pv) {
    $pv = strtoupper(trim($pv));
    if (preg_match('/^(1|X|2|1X|X2|12|DC\s*1X|DC\s*X2|DC\s*12)$/', $pv)) return true;
    return false;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>Predixa | Free Betting Tips & Predictions</title>
    <meta name="description" content="Free daily football tips and predictions with AI. Compare bookmakers, check odds, and bet smarter. 100% free tips every day.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #8B5CF6; --primary-dark: #7C3AED; --primary-light: #A78BFA;
            --accent: #06B6D4; --accent-dark: #0891B2;
            --secondary: #161b22; --text-light: #e0e0e0; --text-muted: #8b949e; --border-color: #2a2e35;
        }
        body { font-family: 'Inter', sans-serif; background-color: #171a29; background-image: linear-gradient(160deg, #241d3f 0%, #1b2237 40%, #12263a 100%), radial-gradient(ellipse at 15% 10%, rgba(139,92,246,0.22) 0%, transparent 50%), radial-gradient(ellipse at 85% 20%, rgba(6,182,212,0.16) 0%, transparent 50%), radial-gradient(ellipse at 50% 85%, rgba(245,158,11,0.1) 0%, transparent 45%); background-attachment: fixed; color: var(--text-light); min-height: 100vh; }
        .navbar { background: rgba(24, 28, 44, 0.92); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border-color); position: relative; z-index: 1030; }
        .navbar-brand { font-size: 1.5rem; font-weight: 800; background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .nav-link { color: var(--text-muted) !important; transition: all 0.3s; }
        .nav-link:hover { color: var(--primary) !important; }
        .btn-premium { background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); color: white; border: none; padding: 12px 30px; font-weight: 700; border-radius: 10px; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-premium:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--accent-dark) 100%); color: white; transform: translateY(-2px); box-shadow: 0 5px 20px rgba(139, 92, 246, 0.4); text-decoration: none; }
        .btn-outline-premium { background: transparent; color: var(--primary-light); border: 2px solid var(--primary); padding: 12px 30px; font-weight: 700; border-radius: 10px; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-outline-premium:hover { background: var(--primary); color: white; transform: translateY(-2px); text-decoration: none; }
        .hero-section { padding: 40px 0 60px; position: relative; }
        .hero-title { font-size: 3rem; font-weight: 800; color: #fff; line-height: 1.15; }
        .hero-title .text-gradient { background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .hero-description { font-size: 1.1rem; line-height: 1.6; color: var(--text-muted); }
        .eyebrow { display: inline-block; background: rgba(139,92,246,0.15); border: 1px solid rgba(139,92,246,0.35); color: var(--primary-light); font-size: 0.75rem; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; padding: 6px 16px; border-radius: 30px; margin-bottom: 18px; }
        .sport-pill { background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); color: var(--text-light); border-radius: 30px; padding: 8px 20px; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all .25s; }
        .sport-pill:hover, .sport-pill.active { background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); color: #fff; border-color: transparent; }
        .section-title { font-size: 2.1rem; font-weight: 800; color: #fff; }
        .section-title .text-gradient { background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .section-sub { color: var(--text-muted); max-width: 640px; margin: 0 auto; }
        .tip-card { background: linear-gradient(135deg, rgba(139,92,246,0.2) 0%, rgba(6,182,212,0.1) 100%); border: 1px solid rgba(139,92,246,0.3); border-radius: 16px; padding: 20px; height: 100%; transition: all .3s; display: flex; flex-direction: column; }
        .tip-card:hover { transform: translateY(-4px); border-color: var(--primary); box-shadow: 0 12px 40px rgba(139, 92, 246, 0.18); }
        .tip-league { font-size: 0.72rem; font-weight: 700; color: var(--accent); text-transform: uppercase; letter-spacing: 1px; }
        .tip-type { font-size: 0.66rem; font-weight: 700; padding: 3px 10px; border-radius: 20px; white-space: nowrap; }
        .tip-match { font-size: 1rem; font-weight: 700; color: #fff; margin: 10px 0 6px; line-height: 1.3; }
        .tip-meta { font-size: 0.78rem; color: #fff; display: flex; gap: 14px; flex-wrap: wrap; }
        .tip-meta i { margin-right: 4px; color: var(--primary-light); }
        .tip-pick { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; padding-top: 12px; border-top: 1px dashed var(--border-color); }
        .tip-pick-value { font-weight: 700; color: #fff; font-size: 0.9rem; }
        .tip-pick-value i { color: #22C55E; margin-right: 6px; }
        .tip-odds { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: #fff; font-weight: 700; font-size: 0.85rem; padding: 4px 12px; border-radius: 8px; }
        .tip-winrate { font-size: 0.72rem; color: var(--text-muted); margin-top: 8px; }
        .tip-winrate strong { color: #22C55E; }
        .tip-cta { margin-top: 14px; display: block; text-align: center; background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); color: #fff; font-weight: 700; font-size: 0.85rem; padding: 10px 12px; border-radius: 10px; text-decoration: none; transition: all .25s; }
        .tip-cta:hover { background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); border-color: transparent; color: #fff; text-decoration: none; }
        .bookie-chip { display: inline-flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.06); border: 1px solid var(--border-color); color: var(--text-light); border-radius: 30px; padding: 7px 18px; font-size: 0.82rem; font-weight: 600; transition: all .25s; }
        .bookie-chip:hover { border-color: var(--accent); color: #fff; }
        .stats-card { background: linear-gradient(135deg, rgba(139,92,246,0.18) 0%, rgba(6,182,212,0.1) 100%); border: 1px solid rgba(139,92,246,0.25); border-radius: 14px; padding: 22px; height: 100%; text-align: center; transition: all .2s; }
        .stats-card:hover { border-color: var(--primary); transform: translateY(-1px); }
        .stat-number { font-size: 1.9rem; font-weight: 800; color: #fff; line-height: 1.1; }
        .stat-label { color: #E2E8F0; font-size: 0.85rem; margin-top: 6px; }
        .footer-links a { color: var(--text-muted); text-decoration: none; }
        .footer-links a:hover { color: var(--accent); }
        footer { border-top: 1px solid var(--border-color); padding: 40px 0; margin-top: 40px; background: rgba(24, 28, 44, 0.6); padding-bottom: 90px; }
        .badge-free { background: #10B981; color: #fff; font-weight: 700; font-size: 0.68rem; padding: 3px 10px; border-radius: 20px; }
        #goTopBtn { position: fixed; right: 20px; bottom: 76px; z-index: 1050; width: 46px; height: 46px; border-radius: 50%; border: none; background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); color: #fff; font-size: 1.1rem; box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4); opacity: 0; visibility: hidden; transform: translateY(10px); transition: all .3s; cursor: pointer; }
        #goTopBtn.show { opacity: 1; visibility: visible; transform: translateY(0); }
        @media (max-width: 768px) { .hero-title { font-size: 2.2rem; } .section-title { font-size: 1.6rem; } }
    </style>
</head>
<body>

    <!-- Sticky top bookie bar -->
    <?php renderSportsbookAd('hp1', 'bar'); ?>

    <nav class="navbar navbar-expand-lg navbar-dark" id="mainNav" style="margin-top:<?= !empty($bookies) ? '10px' : '0' ?>;">
        <div class="container">
            <a class="navbar-brand" href="./"><i class="fas fa-futbol me-2"></i>PREDIXA</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="#tips"><i class="fas fa-bolt me-1" style="color:#10B981;"></i>Free Tips</a></li>
                    <li class="nav-item"><a class="nav-link" href="#bookmakers">Bookmakers</a></li>
                    <?php if (isLoggedIn()): ?>
                    <li class="nav-item"><a class="nav-link" href="dashboard"><i class="fas fa-gauge me-1" style="color:#10B981;"></i>Dashboard</a></li>
                    <?php endif; ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"><i class="fas fa-tools me-1"></i>Free Tools</a>
                        <ul class="dropdown-menu dropdown-menu-dark" style="background:var(--secondary);border:1px solid var(--border-color);">
                            <li><a class="dropdown-item" href="dropping-odds"><i class="fas fa-arrow-trend-down me-2" style="color:#EF4444;"></i>Dropping Odds</a></li>
                            <li><a class="dropdown-item" href="signals"><i class="fas fa-microchip me-2" style="color:#22C55E;"></i>Smart Picks</a></li>
                            <li><a class="dropdown-item" href="track-record"><i class="fas fa-chart-line me-2" style="color:#FBBF24;"></i>Performance</a></li>
                            <li><a class="dropdown-item" href="betting-school"><i class="fas fa-book me-2" style="color:#8B5CF6;"></i>Betting School</a></li>
                            <li><a class="dropdown-item" href="pikka"><i class="fas fa-futbol me-2" style="color:#6366F1;"></i>Pikka</a></li>
                        </ul>
                    </li>
                    <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                        <?php if (isLoggedIn()): ?>
                        <a class="btn btn-outline-premium btn-sm" href="logout" style="min-width: 100px; padding: 10px 24px; min-height: 44px;">Logout</a>
                        <?php else: ?>
                        <a class="btn btn-outline-premium btn-sm" href="login" style="min-width: 100px; padding: 10px 24px; min-height: 44px;">Login</a>
                        <?php endif; ?>
                    </li>
                    <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                        <a class="btn btn-premium btn-sm" href="premium" style="min-width: 130px; padding: 10px 24px; min-height: 44px;">GO PREMIUM</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="hero-section">
        <div class="container">
            <div class="text-center mb-5">
                <span class="eyebrow"><i class="fas fa-star me-1" style="color:#FBBF24;"></i> 100% Free Tips — Updated Daily</span>
                <h1 class="hero-title mb-3">Bet Smarter. <span class="text-gradient">Win More. With Us.</span></h1>
                <p class="hero-description mb-4 mx-auto" style="max-width: 640px;">
                    Daily football picks and live odds from 50+ leagues, analysed by our own prediction
                    engine. Compare bookmakers, spot the best value and bet with more confidence.
                </p>
                <div class="d-flex gap-3 flex-wrap justify-content-center mb-4">
                    <?php $hp3Ad = getSportsbookAd('hp3'); $hp3Url = ($hp3Ad && sportsbookAdReady($hp3Ad)) ? sportsbookAdUrl($hp3Ad['url']) : ''; ?>
                    <a href="#tips" class="btn btn-premium btn-lg" id="freePicksBtn" data-affiliate="<?= htmlspecialchars($hp3Url) ?>">View Today's Free Picks <i class="fas fa-arrow-down ms-1"></i></a>
                    <a href="premium" class="btn btn-outline-premium btn-lg"><i class="fas fa-crown me-2"></i>GO PREMIUM</a>
                </div>
            </div>

            <!-- Trust stats -->
            <div class="row g-3 justify-content-center">
                <div class="col-6 col-md-3">
                    <div class="stats-card">
                        <div class="stat-number" id="userCount" data-target="<?= $totalUsers ?>">0</div>
                        <div class="stat-label">Active Members</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stats-card">
                        <div class="stat-number" id="pickCount" data-target="<?= $totalPicks ?>">0</div>
                        <div class="stat-label">Total Free Tips</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stats-card">
                        <div class="stat-number">96%</div>
                        <div class="stat-label">Win Rate Tracked</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stats-card">
                        <div class="stat-number">50+</div>
                        <div class="stat-label">Leagues Covered</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php if ($freeDailyPick): ?>
    <!-- Free daily pick banner -->
    <section class="py-4">
        <div class="container">
            <div class="row align-items-center g-3" style="background: rgba(6, 182, 212, 0.08); border: 1px solid rgba(6,182,212,0.3); border-radius: 14px; padding: 16px 20px;">
                <div class="col-md-8">
                    <span class="badge-free mb-2"><i class="fas fa-star me-1"></i>FREE PICK OF THE DAY</span>
                    <p class="text-white mb-1 fw-bold" style="font-size:0.95rem;"><?= htmlspecialchars($freeDailyPick['match_name']) ?></p>
                    <p class="text-muted small mb-1"><?= htmlspecialchars($freeDailyPick['league']) ?> — <?= htmlspecialchars($freeDailyPick['match_time']) ?></p>
                    <p class="small mb-0" style="color:#06B6D4;"><i class="fas fa-check-circle me-1"></i>Pick: <strong><?= htmlspecialchars($freeDailyPick['pick_value']) ?></strong><?php if (showOddsForPick($freeDailyPick['pick_value'] ?? '')): ?> @ <?= htmlspecialchars($freeDailyPick['odds']) ?><?php endif; ?></p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="premium" class="btn btn-outline-premium btn-sm">Get More Free Picks Daily <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Today's Free Tips -->
    <section id="tips" class="py-5">
        <div class="container">
            <div class="text-center mb-4">
                <span class="eyebrow"><i class="fas fa-bolt me-1"></i> Today's Tips</span>
                <h2 class="section-title">Free <span class="text-gradient">Betting Tips</span> Today</h2>
                <p class="section-sub"><?= count($freePicks) ?> free tips available — filter by market or open the premium page for the full set<?= $lockedCount > 0 ? ' of ' . ($totalFreePicks ?? count($freePicks)) . ' today' : '' ?>.</p>
            </div>

            <?php if (!empty($freePicks)): ?>
            <?php
                $marketLabels = ['parlay' => 'Parlay', 'over_15' => 'Over 1.5', 'under_25' => 'Under 3.5'];
                $pillShown = ['parlay' => $typeCounts['parlay'] ?? 0, 'over_15' => $typeCounts['over_15'] ?? 0, 'under_25' => $typeCounts['under_25'] ?? 0];
            ?>
            <div class="d-flex gap-2 flex-wrap justify-content-center mb-4" id="tipFilters">
                <span class="sport-pill active" data-filter="all">All (<?= count($freePicks) ?>/<?= $totalFreePicks ?? count($freePicks) ?>)</span>
                <?php foreach ($marketLabels as $t => $label): if (!isset($marketTotals[$t])) continue; ?>
                <span class="sport-pill" data-filter="<?= $t ?>"><?= $label ?> (<?= $pillShown[$t] ?>/<?= $marketTotals[$t] ?>)</span>
                <?php endforeach; ?>
            </div>

            <div class="row g-3" id="tipsGrid">
                <?php foreach ($freePicks as $pick):
                    $typeLabel = match($pick['pick_type']) {
                        'rollover' => 'Rollover', 'over_15' => 'Over 1.5', 'under_25' => 'Under 3.5',
                        default => 'Parlay'
                    };
                    $typeColor = match($pick['pick_type']) {
                        'rollover' => 'rgba(139,92,246,0.18);color:var(--primary-light)',
                        'over_15' => 'rgba(6,182,212,0.18);color:var(--accent)',
                        'under_25' => 'rgba(245,158,11,0.18);color:#FBBF24',
                        default => 'rgba(16,185,129,0.18);color:#34D399'
                    };
                ?>
                <div class="col-md-6 col-lg-4" data-type="<?= htmlspecialchars($pick['pick_type']) ?>">
                    <div class="tip-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="tip-league"><?= htmlspecialchars($pick['league'] ?? 'Football') ?></span>
                            <span class="tip-type" style="background: <?= $typeColor ?>;"><?= $typeLabel ?></span>
                        </div>
                        <div class="tip-match"><?= htmlspecialchars($pick['match_name'] ?? 'Match Hidden') ?></div>
                        <div class="tip-meta">
                            <span><i class="fas fa-clock"></i><?= (!empty($pick['match_time']) && strtolower(trim($pick['match_time'])) !== 'tbd') ? htmlspecialchars(trim($pick['match_time'])) : 'TBD' ?></span>
                            <span><i class="fas fa-calendar-day"></i><?= date('M d', strtotime($pick['detected_at'] ?? 'now')) ?></span>
                        </div>
                        <div class="tip-pick">
                            <span class="tip-pick-value"><i class="fas fa-check-circle"></i><?= htmlspecialchars($pick['pick_value'] ?? '') ?></span>
                            <?php if (showOddsForPick($pick['pick_value'] ?? '')): ?>
                            <span class="tip-odds"><?= number_format($pick['odds'] ?? 1.00, 2) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="tip-winrate">Win rate: <strong><?= (int)($pick['win_rate_low'] ?? 0) ?>%</strong></div>
                        <?php if ($bestBookie && function_exists('sportsbookAdReady') && sportsbookAdReady($bestBookie)):
                            $bbName = htmlspecialchars($bestBookie['bookie_name']);
                            $bbUrl = htmlspecialchars(sportsbookAdUrl($bestBookie['url']));
                            $bbCta = htmlspecialchars($bestBookie['cta'] ?: 'Bet Now');
                            $bbTrack = 'data-ad-id="' . (int)$bestBookie['id'] . '" data-ad-slot="hp2" data-bookie="' . $bbName . '"';
                        ?>
                        <a class="tip-cta" href="<?= $bbUrl ?>" <?= $bbTrack ?> target="_blank" rel="noopener nofollow sponsored"><?= $bbCta ?> at <?= $bbName ?> <i class="fas fa-arrow-right ms-1"></i></a>
                        <?php elseif ($bestBookie): ?>
                        <span class="tip-cta" style="background:rgba(255,255,255,0.06);border:1px dashed var(--border-color);color:var(--text-muted);cursor:default;">Coming Soon</span>
                        <?php else: ?>
                        <a class="tip-cta" href="premium">GO PREMIUM for More Picks <i class="fas fa-arrow-right ms-1"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="text-center mt-5">
                <a href="premium" class="btn btn-premium btn-lg"><i class="fas fa-crown me-2"></i>GO PREMIUM — Unlock Rollover &amp; More</a>
                <p class="text-white-50 small mt-2"><?php
                    $premiumTeaser = $lockedCount > 0
                        ? 'See all ' . ($totalFreePicks ?? count($freePicks) + $lockedCount) . " today's picks — " . $lockedCount . ' more available to premium members.'
                        : 'Premium unlocks safety rollover picks, full analytics and the tipster marketplace.';
                    $rolloverTeaser = $rolloverCount > 0
                        ? $rolloverCount . ' safe rollover pick' . ($rolloverCount === 1 ? '' : 's') . ' available for premium members today.'
                        : '';
                    echo $premiumTeaser;
                    if ($rolloverTeaser && $lockedCount > 0) echo ' ' . $rolloverTeaser;
                    if ($rolloverTeaser && $lockedCount === 0) echo $rolloverTeaser;
                ?></p>
            </div>
            <?php else: ?>
            <div class="text-center py-5">
                <div style="font-size:3rem; margin-bottom:1rem;"><i class="fas fa-calendar-day"></i></div>
                <h4 class="text-white fw-bold">No free tips published yet today</h4>
                <p class="text-white-50">Tips refresh daily. Check back soon or go premium for historical picks.</p>
                <a href="premium" class="btn btn-premium">GO PREMIUM</a>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Bookmaker offers strip -->
    <section id="bookmakers" class="py-5" style="border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); background: rgba(22,27,34,0.35);">
        <div class="container">
            <div class="text-center mb-4">
                <span class="eyebrow"><i class="fas fa-handshake me-1"></i> Partner Bookmakers</span>
                <h2 class="section-title">Where to <span class="text-gradient">Place Your Bets</span></h2>
                <p class="section-sub">Compare bookmaker offers and pick where to bet today.</p>
            </div>
            <?php
            $isOwner = function_exists('isAdmin') && isAdmin();
            $slotCap = 3;
            $shown = 0;
            ?>
            <div class="d-flex gap-3 flex-wrap justify-content-center">
                <?php
                foreach ($widgetBookies as $b) {
                    if ($shown >= $slotCap) break;
                    $shown++;
                    $bn = htmlspecialchars($b['bookie_name']);
                    $bu = htmlspecialchars(sportsbookAdUrl($b['url']));
                    $bt = 'data-ad-id="' . (int)$b['id'] . '" data-ad-slot="hp5" data-bookie="' . $bn . '"';
                ?>
                <a class="bookie-chip" href="<?= $bu ?>" <?= $bt ?> target="_blank" rel="noopener nofollow sponsored"><i class="fas fa-bolt" style="color:var(--accent);"></i><?= $bn ?></a>
                <?php
                }
                while ($shown < $slotCap) { $shown++; ?>
                <?php if ($isOwner): ?>
                <a class="bookie-chip" href="admin/sportsbook_ads" title="Add a bookmaker link (Admin → Sportsbook Ads)" style="border-style:dashed;border-color:rgba(255,255,255,0.35);opacity:0.9;text-decoration:none;"><i class="fas fa-plus" style="color:var(--text-muted);"></i>Slot <?= $shown ?> — Add Link</a>
                <?php else: ?>
                <span class="bookie-chip" style="opacity:0.7;cursor:default;"><i class="fas fa-hourglass-half" style="color:var(--text-muted);"></i>Coming Soon</span>
                <?php endif; ?>
                <?php } ?>
            </div>
        </div>
    </section>

    <!-- How it works -->
    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <span class="eyebrow"><i class="fas fa-route me-1"></i> How It Works</span>
                <h2 class="section-title">Bet Smarter in <span class="text-gradient">3 Simple Steps</span></h2>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="stats-card" style="text-align:left; height:100%;">
                        <div style="font-size:2rem; margin-bottom:12px;">📊</div>
                        <h5 class="text-white fw-bold">1. Pick a Market</h5>
                        <p class="text-white-50 mb-0" style="font-size:0.9rem;">Choose from free parlay, over/under tips or the premium safety rollover — all backed by data.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="text-align:left; height:100%;">
                        <div style="font-size:2rem; margin-bottom:12px;">🏆</div>
                        <h5 class="text-white fw-bold">2. Compare Bookies</h5>
                        <p class="text-white-50 mb-0" style="font-size:0.9rem;">Check our partner bookmaker offers and place your bet where the odds are best.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card" style="text-align:left; height:100%;">
                        <div style="font-size:2rem; margin-bottom:12px;">💎</div>
                        <h5 class="text-white fw-bold">3. GO PREMIUM for More</h5>
                        <p class="text-white-50 mb-0" style="font-size:0.9rem;">Unlock safe rollover picks, full analytics and the tipster marketplace — from just a few TZS a day.</p>
                    </div>
                </div>
            </div>
            <div class="text-center mt-4">
                <a href="premium" class="btn btn-premium btn-lg"><i class="fas fa-crown me-2"></i>GO PREMIUM</a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-4">
                    <h5 class="mb-3"><i class="fas fa-futbol me-1"></i>PREDIXA</h5>
                    <p class="text-white-50">Free daily betting tips and predictions backed by AI, plus a community marketplace. Compare bookmakers and bet smarter.</p>
                </div>
                <div class="col-md-2">
                    <h6 class="mb-3 text-white">Predixa</h6>
                    <ul class="list-unstyled footer-links">
                        <li class="mb-2"><a href="premium"><i class="fas fa-crown me-1" style="color:#FBBF24;"></i> GO PREMIUM</a></li>
                        <li class="mb-2"><a href="signals"><i class="fas fa-microchip me-1" style="color:#22C55E;"></i> Smart Picks</a></li>
                        <li class="mb-2"><a href="track-record"><i class="fas fa-chart-line me-1" style="color:#8B5CF6;"></i> Performance</a></li>
                        <li class="mb-2"><a href="pikka"><i class="fas fa-futbol me-1" style="color:#6366F1;"></i> Pikka</a></li>
                    </ul>
                </div>
                <div class="col-md-3">
                    <h6 class="mb-3 text-white">Free Tools</h6>
                    <ul class="list-unstyled footer-links">
                        <li class="mb-2"><a href="dropping-odds"><i class="fas fa-arrow-trend-down me-1" style="color:#EF4444;"></i> Dropping Odds</a></li>
                        <li class="mb-2"><a href="betting-school"><i class="fas fa-book me-1" style="color:#06B6D4;"></i> Betting School</a></li>
                        <li class="mb-2"><a href="h2h"><i class="fas fa-handshake me-1" style="color:#F59E0B;"></i> Head to Head</a></li>
                    </ul>
                </div>
                <div class="col-md-3">
                    <h6 class="mb-3 text-white">Support</h6>
                    <ul class="list-unstyled footer-links">
                        <li class="mb-2"><a href="https://wa.me/255713348298" target="_blank" style="color:#25D366;"><i class="fab fa-whatsapp me-1"></i> WhatsApp</a></li>
                        <li class="mb-2"><a href="mailto:support@predixa.co.tz"><i class="fas fa-envelope me-1"></i> Email Us</a></li>
                        <li class="mb-2"><a href="terms"><i class="fas fa-file-lines me-1"></i> Terms</a></li>
                        <li class="mb-2"><a href="https://www.begambleaware.org/" target="_blank" rel="noopener noreferrer" style="color:#10B981;"><i class="fas fa-shield-halved me-1"></i> 18+ Bet Responsibly</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-top border-secondary mt-4 pt-4 text-center text-white-50">
                <small>&copy; <?= date('Y') ?> Predixa. All rights reserved. | 18+ | Bet Responsibly</small>
            </div>
        </div>
    </footer>

    <!-- Sticky bottom bookie banner -->
    <?php if (function_exists('renderSportsbookAdBottomBar')) renderSportsbookAdBottomBar('hp1'); ?>

    <button type="button" id="goTopBtn" aria-label="Back to top"><i class="fas fa-arrow-up"></i></button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        function fmtNum(n) {
            if (n >= 1000000) return (n/1000000).toFixed(1).replace(/\.0$/,'')+'M';
            if (n >= 1000) return (n/1000).toFixed(1).replace(/\.0$/,'')+'k';
            return n.toLocaleString();
        }
        function animateCounter(el, target) {
            if (!el) return;
            const duration = 1500, steps = 30;
            const increment = target / steps;
            let current = 0, count = 0;
            const timer = setInterval(() => {
                count++;
                current += increment;
                if (count >= steps) { el.textContent = fmtNum(target); clearInterval(timer); }
                else el.textContent = Math.round(current) >= 1000 ? fmtNum(Math.round(current)) : Math.round(current).toLocaleString();
            }, duration / steps);
        }
        animateCounter(document.getElementById('userCount'), parseInt(document.getElementById('userCount')?.dataset?.target || 0));
        animateCounter(document.getElementById('pickCount'), parseInt(document.getElementById('pickCount')?.dataset?.target || 0));

        document.getElementById('tipFilters')?.addEventListener('click', function(e) {
            const pill = e.target.closest('.sport-pill');
            if (!pill) return;
            this.querySelectorAll('.sport-pill').forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            const filter = pill.dataset.filter;
            document.querySelectorAll('#tipsGrid [data-type]').forEach(card => {
                card.style.display = (filter === 'all' || card.dataset.type === filter) ? '' : 'none';
            });
        });

        var goTopBtn = document.getElementById('goTopBtn');
        window.addEventListener('scroll', function() {
            if (goTopBtn) goTopBtn.classList.toggle('show', window.scrollY > 300);
        }, { passive: true });
        if (goTopBtn) goTopBtn.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        document.querySelectorAll('#mainNav .nav-link:not(.dropdown-toggle)').forEach(function(el) {
            el.addEventListener('click', function() {
                var navbar = document.getElementById('navbarNav');
                if (navbar && navbar.classList.contains('show')) {
                    var bsCollapse = bootstrap.Collapse.getInstance(navbar);
                    if (bsCollapse) bsCollapse.hide();
                }
            });
        });
    </script>
    <script>
    document.getElementById('freePicksBtn').addEventListener('click', function(e) {
        var aff = this.getAttribute('data-affiliate');
        if (!aff) return;
        var now = Date.now();
        var last = parseInt(localStorage.getItem('fpLastClick') || '0', 10);
        if (now - last > 21600000) {
            e.preventDefault();
            localStorage.setItem('fpLastClick', now);
            window.open(aff, '_blank');
        }
    });
    </script>
    <?php predixaAdsTrackScript(); ?>
</body>
</html>
