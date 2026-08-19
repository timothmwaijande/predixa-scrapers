<?php
session_start();
require_once 'config.php';
require_once 'auth.php';
require_once 'includes/sportsbook_ads.php';
logPageVisit('index.php');

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard");
    exit;
}

function showOddsForPick($pv) {
    $pv = strtoupper(trim($pv));
    if (preg_match('/^(1|X|2|1X|X2|12|DC\s*1X|DC\s*X2|DC\s*12)$/', $pv)) return true;
    return false;
}

$previewPicks = [];
try {
    $rolloverPicks = fetchPicks('rollover');
    $rolloverPicks = deduplicatePicks($rolloverPicks);
    $over15Picks = fetchPicks('over15');
    $over15Picks = deduplicatePicks($over15Picks);
    $under25Picks = fetchPicks('under_25');
    $under25Picks = deduplicatePicks($under25Picks);
    $pick = !empty($rolloverPicks) ? $rolloverPicks[0] : null;
    if ($pick) $previewPicks[] = $pick;
    $pick = !empty($over15Picks) ? $over15Picks[0] : null;
    if ($pick) $previewPicks[] = $pick;
    $pick = !empty($under25Picks) ? $under25Picks[0] : null;
    if ($pick) $previewPicks[] = $pick;
} catch (Exception $e) {
    error_log("Index page: " . $e->getMessage());
}

// Stats for social proof
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

// Betting codes for marketplace preview
$homeCodes = [];
$homePublisherRankings = [];
try {
    $homeCodes = getAvailableCodes(null);
    $homePublisherRankings = getPublisherRankings(5);
} catch (Exception $e) {}

// Free daily pick (yesterday's result)
$freeDailyPick = null;
try { $freeDailyPick = getFreeDailyPick(); } catch (Exception $e) {}

// Approved winning slips
$approvedSlips = [];
try { $approvedSlips = getApprovedSlips(); } catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <title>Predixa | AI-Powered Football Analytics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary: #8B5CF6; --primary-dark: #7C3AED; --primary-light: #A78BFA;
            --accent: #06B6D4; --accent-dark: #0891B2;
            --secondary: #161b22; --text-light: #e0e0e0; --text-muted: #8b949e; --border-color: #2a2e35;
        }
        body { font-family: 'Inter', sans-serif; background: #0f1115; background-image: radial-gradient(ellipse at 15% 10%, rgba(139,92,246,0.12) 0%, transparent 50%), radial-gradient(ellipse at 85% 20%, rgba(6,182,212,0.1) 0%, transparent 50%), radial-gradient(ellipse at 50% 80%, rgba(245,158,11,0.06) 0%, transparent 50%); background-attachment: fixed; color: var(--text-light); min-height: 100vh; }
        .navbar { background: rgba(15, 17, 21, 0.95); backdrop-filter: blur(10px); border-bottom: 1px solid var(--border-color); }
        .navbar-brand { font-size: 1.5rem; font-weight: 800; background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .nav-link { color: var(--text-muted) !important; transition: all 0.3s; }
        .nav-link:hover { color: var(--primary) !important; }
        .hero-section { padding: 120px 0 80px; background: linear-gradient(180deg, rgba(139, 92, 246, 0.1) 0%, transparent 100%); }
        .hero-title { font-size: 3rem; font-weight: 800; background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; line-height: 1.2; }
        .hero-description { font-size: 1.15rem; line-height: 1.6; color: var(--text-muted); }
        .stats-card { background: rgba(139, 92, 246, 0.1); border: 1px solid var(--primary); border-radius: 12px; padding: 25px; margin-bottom: 20px; }
        .stats-row { display: flex; justify-content: space-around; flex-wrap: wrap; gap: 20px; }
        .stat-item { text-align: center; flex: 1; min-width: 100px; }
        .stat-number { font-size: 2rem; font-weight: 800; background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; line-height: 1; }
        .stat-label { color: var(--text-muted); font-size: 0.9rem; margin-top: 8px; }
        .preview-card { background: var(--secondary); border: 1px solid var(--border-color); border-radius: 16px; padding: 30px; position: relative; overflow: hidden; min-height: 420px; }
        .preview-card .blur-content { filter: blur(5px); user-select: none; pointer-events: none; }
        .preview-card .lock-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(22, 27, 34, 0.85); backdrop-filter: blur(2px); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 10; text-align: center; padding: 2rem; border-radius: 16px; overflow-y: auto; }
        .btn-premium { background: var(--primary); color: white; border: none; padding: 12px 30px; font-weight: 600; border-radius: 8px; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-premium:hover { background: var(--primary-dark); color: white; transform: translateY(-2px); box-shadow: 0 5px 20px rgba(139, 92, 246, 0.4); text-decoration: none; }
        .btn-outline-premium { background: transparent; color: var(--primary); border: 2px solid var(--primary); padding: 12px 30px; font-weight: 600; border-radius: 8px; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-outline-premium:hover { background: var(--primary); color: white; transform: translateY(-2px); text-decoration: none; }
        .feature-card { background: var(--secondary); border: 1px solid var(--border-color); border-radius: 16px; padding: 30px; height: 100%; transition: transform 0.3s, box-shadow 0.3s; }
        .feature-card:hover { transform: translateY(-5px); box-shadow: 0 10px 40px rgba(139, 92, 246, 0.2); border-color: var(--primary); }
        .feature-icon { font-size: 2.5rem; margin-bottom: 15px; }
        .feature-list { list-style: none; padding: 0; margin: 0; }
        .feature-list li { margin-bottom: 12px; padding-left: 30px; position: relative; color: var(--text-muted); }
        .feature-list li::before { font-family: 'Font Awesome 6 Free'; font-weight: 900; content: '\f00c'; position: absolute; left: 0; top: 0; width: 22px; height: 22px; background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; }
        .feature-list li.text-muted::before { background: var(--border-color); color: var(--text-muted); }
        .pricing-card { background: linear-gradient(135deg, rgba(139,92,246,0.2) 0%, rgba(6,182,212,0.1) 100%); border: 1px solid rgba(139,92,246,0.3); border-radius: 16px; padding: 40px 30px; height: 100%; position: relative; transition: all 0.3s; display: flex; flex-direction: column; }
        .pricing-card .btn { margin-top: auto; }
        .pricing-card:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(139, 92, 246, 0.2); }
        .pricing-card.popular { border-color: var(--accent); box-shadow: 0 0 30px rgba(6, 182, 212, 0.3); }
        .pricing-card.popular::before { content: 'MOST POPULAR'; position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); color: #fff; font-size: 0.65rem; font-weight: 700; padding: 4px 16px; border-radius: 20px; z-index: 1; white-space: nowrap; }
        .price-tag { font-size: 2.5rem; font-weight: 800; background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .payment-section { background: var(--secondary); border: 1px solid var(--border-color); border-radius: 12px; padding: 25px; margin-top: 30px; }
        .payment-info-box { background: rgba(6, 182, 212, 0.1); border: 1px solid var(--accent); border-radius: 8px; padding: 20px; margin-top: 20px; }
        .payment-info-box strong { color: var(--accent); }
        .payment-logos { display: flex; gap: 15px; justify-content: flex-start; flex-wrap: wrap; margin-top: 20px; }
        .payment-logo { background: rgba(139, 92, 246, 0.1); padding: 8px 15px; border-radius: 6px; font-size: 0.85rem; color: var(--primary-light); border: 1px solid var(--primary); }
        .pick-card { background: rgba(139, 92, 246, 0.08); border: 1px solid rgba(139, 92, 246, 0.2); border-radius: 10px; padding: 1rem; margin-bottom: 0.75rem; }
        .pick-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .pick-match { font-weight: 700; font-size: 0.95rem; color: var(--text-light); }
        .pick-odds { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; padding: 0.3rem 0.8rem; border-radius: 6px; font-weight: 700; font-size: 0.9rem; }
        .pick-meta { font-size: 0.8rem; color: var(--text-muted); display: flex; gap: 1rem; }
        footer { border-top: 1px solid var(--border-color); padding: 40px 0; margin-top: 80px; background: rgba(15, 17, 21, 0.5); }
        footer h5, footer h6 { color: var(--text-light); font-weight: 700; }
        footer a { color: var(--text-muted); text-decoration: none; transition: all 0.3s; }
        footer a:hover { color: var(--primary); }
        .modal-content .form-control { background: rgba(22,27,34,0.7); border: 1.5px solid rgba(139,92,246,0.45); color: var(--text-light); padding: 12px 15px; border-radius: 8px; }
        .modal-content .form-control:focus { background: rgba(22,27,34,0.9); border-color: var(--primary); box-shadow: 0 0 0 0.25rem rgba(139, 92, 246, 0.2); color: var(--text-light); }
        .modal-content .btn-premium { background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); color: white; border: none; font-weight: 700; padding: 14px 30px; border-radius: 8px; width: 100%; transition: all 0.3s; }
        .modal-content .btn-premium:hover { background: linear-gradient(135deg, var(--primary-dark) 0%, #0891B2 100%); color: white; transform: translateY(-2px); box-shadow: 0 5px 20px rgba(139, 92, 246, 0.4); }
        .modal-content .login-link { color: var(--primary); text-decoration: none; font-weight: 600; }
        .modal-content .login-link:hover { color: var(--accent); text-decoration: underline; }
        @media (max-width: 768px) { .hero-title { font-size: 2rem; } .hero-section { padding: 80px 0 40px; } .stats-row { flex-direction: column; gap: 15px; } .stat-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border-color); } .stat-number { font-size: 1.75rem; } }
        /* Icon spacing: add me-2 (Bootstrap) or use this for non-Bootstrap contexts */
        .icon-gap { margin-right: 0.5rem; } .icon-gap-sm { margin-right: 0.35rem; } .icon-gap-lg { margin-right: 0.75rem; }
        i.fas, i.far, i.fab { vertical-align: -0.125em; }
        .code-card { background: var(--secondary); border: 1px solid var(--border-color); border-radius: 12px; padding: 1rem; transition: all 0.3s; position: relative; display:flex; flex-direction:column; }
        .code-card:hover { border-color: var(--primary); transform: translateY(-2px); }
        .code-price-badge { background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-weight: 700; font-size: 0.85rem; white-space: nowrap; }
        .code-seller { font-size: 0.8rem; color: var(--text-muted); }
        .codes-section { background: rgba(139, 92, 246, 0.03); border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top" id="mainNav">
        <div class="container">
            <a class="navbar-brand" href="./"><i class="fas fa-futbol me-2"></i>PREDIXA</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="#preview">Today's Picks</a></li>
                    <li class="nav-item"><a class="nav-link" href="signals">Smart Picks</a></li>
                    <li class="nav-item"><a class="nav-link" href="#how-it-works">How It Works</a></li>
                    <li class="nav-item"><a class="nav-link" href="#pricing">Plans</a></li>
                    <li class="nav-item"><a class="nav-link" href="#payment">Payment</a></li>
                    <?php if (!isSectionHidden('aviator')): ?>
                    <li class="nav-item"><a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#aviatorModal"><i class="fas fa-plane me-1" style="color: #F59E0B;"></i>Aviator</a></li>
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
                        <a class="btn btn-outline-premium btn-sm" href="login" style="min-width: 100px; padding: 10px 24px; min-height: 44px;">Login</a>
                    </li>
                    <li class="nav-item ms-lg-2 mt-2 mt-lg-0">
                        <a class="btn btn-premium btn-sm" href="#" data-bs-toggle="modal" data-bs-target="#signupModal" style="min-width: 120px; padding: 10px 24px; min-height: 44px;">Get Started</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <?php renderSportsbookAd('hp1', 'bar'); ?>

    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 mb-4 mb-lg-0">
                    <h1 class="hero-title mb-3">AI-Powered Football Analytics</h1>
                    <p class="hero-description mb-4">
                        Professional betting intelligence with <strong>7-Day Safety Rollover</strong>, 
                        <strong>High-Odds Parlay up to 30x</strong>, and a <strong>tipster marketplace</strong> 
                        where you can publish and sell your own winning codes. Data-driven picks from 50+ leagues worldwide.
                    </p>
                    <div class="d-flex gap-3 flex-wrap">
                        <a href="#" class="btn btn-premium btn-lg" data-bs-toggle="modal" data-bs-target="#signupModal">Create Free Account →</a>
                        <a href="#how-it-works" class="btn btn-outline-premium btn-lg">Learn More</a>
                    </div>
                    <div class="payment-logos mt-4">
                        <span class="payment-logo">M-Pesa</span>
                        <span class="payment-logo">Mixx by Yas</span>
                        <span class="payment-logo">Airtel Money</span>
                        <span class="payment-logo">HaloPesa</span>
                        <span class="payment-logo">Bank Transfer</span>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="stats-card">
                        <div class="stats-row">
                            <div class="stat-item">
                                <div class="stat-number">96%</div>
                                <div class="stat-label">Win Rate</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number">50+</div>
                                <div class="stat-label">Leagues</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number">24/7</div>
                                <div class="stat-label">Analysis</div>
                            </div>
                        </div>
                    </div>
                    <div class="stats-card" style="border-color: var(--accent); background: rgba(6, 182, 212, 0.08);">
                        <h5 class="fw-bold" style="color: var(--accent);">Premium Features</h5>
                        <ul class="feature-list mb-0">
                            <li><strong>7-Day Safety Rollover</strong> — Conservative picks (75-85% win rate)</li>
                            <li><strong>High-Odds Parlay</strong> — Combined up to 30x accumulator</li>
                            <li><strong>Betting Markets</strong> — Win 1UP, DC, Over 1.5, Under 3.5 Goals, Most Corners</li>
                            <li><strong>Top Picks (Banker of the Day)</strong> — Most Accurate bets of the day</li>
                            <li><strong>30 Days FREE Trial</strong> — Parlay package included</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section id="how-it-works" class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold text-white">How It Works</h2>
                <p class="text-white-50">Four ways to profit — subscribe, bet, publish, and earn</p>
            </div>
            <div class="row g-4 justify-content-center">
                <div class="col-lg-3 col-md-6">
                    <div class="feature-card text-center h-100">
                        <div class="feature-icon"><i class="fas fa-crown fa-3x" style="color: var(--accent);"></i></div>
                        <h4 class="text-white mb-3">1. Pick Your Plan</h4>
                        <p class="text-white-50 mb-0">Choose Rollover (75-85% win rate), Parlay (up to 30x), or Both. Daily, bi-weekly, or monthly — flexible to your budget.</p>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="feature-card text-center h-100">
                        <div class="feature-icon"><i class="fas fa-robot fa-3x" style="color: var(--primary);"></i></div>
                        <h4 class="text-white mb-3">2. Get AI Picks Daily</h4>
                        <p class="text-white-50 mb-0">Collect and analyze picks across multiple leagues, each with clear win rate predictions and risk tiers to guide your betting decisions.</p>
                    </div>
                </div>
                <?php if (!isSectionHidden('betting_codes')): ?>
                <div class="col-lg-3 col-md-6">
                    <div class="feature-card text-center h-100">
                        <div class="feature-icon"><i class="fas fa-ticket-alt fa-3x" style="color: var(--accent);"></i></div>
                        <h4 class="text-white mb-3">3. Publish & Sell Codes</h4>
                        <p class="text-white-50 mb-0">Create your own betting codes in the marketplace. Each code sells for 2,000 TZS — you keep the full amount, we collect 500 TZS per sale via credits.</p>
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-lg-3 col-md-6">
                    <div class="feature-card text-center h-100">
                        <div class="feature-icon"><i class="fas fa-chart-line fa-3x" style="color: var(--primary);"></i></div>
                        <h4 class="text-white mb-3">4. Earn & Track</h4>
                        <p class="text-white-50 mb-0">Watch your sales grow on the leaderboard. Top sellers earn bonus credits from admin. Weekly free credits keep you publishing.</p>
                    </div>
                </div>
            </div>
            <div class="text-center mt-5">
                <a href="#" class="btn btn-premium btn-lg" data-bs-toggle="modal" data-bs-target="#signupModal">Start Your 30-Day Free Trial</a>
                <p class="text-white-50 small mt-2">No credit card required. Cancel anytime.</p>
            </div>
        </div>
    </section>

    <?php if (!empty($previewPicks)): ?>
    <section id="preview" class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold text-white">Today's Premium Picks Preview</h2>
                <p class="text-white-50">Register to unlock full details — match names, odds, and pick values are blurred below</p>
            </div>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="preview-card">
                        <div class="blur-content">
                            <?php foreach ($previewPicks as $pick): ?>
                            <div class="pick-card">
                                <div class="pick-header">
                                    <div class="pick-match"><?= htmlspecialchars($pick['match_name'] ?? 'Match Name Hidden') ?></div>
                                    <?php if (showOddsForPick($pick['pick_value'] ?? '')): ?>
                                    <div class="pick-odds"><?= number_format($pick['odds'] ?? 1.00, 2) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="pick-meta">
<span><?= htmlspecialchars($pick['league'] ?? '') ?></span>
                        <span><?= (!empty($pick['match_time']) && strtolower(trim($pick['match_time'])) !== 'tbd') ? htmlspecialchars(trim($pick['match_time'])) : 'TBD' ?></span>
                                    <span class="badge" style="background: <?= $pick['pick_type'] === 'rollover' ? 'var(--primary)' : 'var(--accent)' ?>; color: #fff; font-size: 0.75rem;"><?php
                                        switch ($pick['pick_type']) {
                                            case 'rollover': echo 'Rollover'; break;
                                            case 'under_25': echo 'Under 3.5'; break;
                                            default: echo 'Over 1.5';
                                        }
                                    ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="lock-overlay">
                            <div style="margin-bottom: 1rem;"><i class="fas fa-lock fa-3x" style="color: var(--primary);"></i></div>
                            <h3 style="font-weight: 700; margin-bottom: 0.5rem; color: white;">Sign Up to See Picks</h3>
                            <p class="text-white-50 mb-4" style="max-width: 400px;">
                                Create a free account to unlock match names, pick values, odds, full analytics, and the tipster marketplace.
                                Start with a <strong>30-day FREE trial</strong>!
                            </p>
                            <div class="d-flex gap-3 flex-wrap justify-content-center">
                                <a href="#" class="btn btn-premium btn-lg" data-bs-toggle="modal" data-bs-target="#signupModal">Unlock Free Access</a>
                                <a href="login" class="btn btn-outline-premium btn-lg">I have an account</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section id="pricing" class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold text-white">Choose Your Plan</h2>
                <p class="text-white-50">Flexible durations — daily, bi-weekly, or monthly. The longer you pick, the less you pay per day.</p>
            </div>
            <div class="row g-4 justify-content-center">
                <?php
                $tiers = [
                    'parlay' => [
                        'title' => 'Parlay Premium',
                        'desc' => 'Combine 2-19 stable favorites to build up to 30x accumulator. Auto-selected based on accuracy patterns.',
                        'features' => ['Combined Odds: Up to 30x', 'Legs: 2-19 picks', 'Win Rate: 25-35%', 'Betting markets: Win, Win 1UP, DC', 'Market Movement Analysis'],
                        'locked' => ['Safety Rollover (not included)', 'PRO Predictions (not included)'],
                        'popular' => false
                    ],
                    'rollover' => [
                        'title' => 'Rollover Premium',
                        'desc' => 'Conservative 7-day cycle with 1-7 curated picks daily. Focuses on stable favorites with strong market confirmation.',
                        'features' => ['Odds Range: 1.18 - 1.30', 'Win Rate: 75-85%', 'Leagues: Core leagues only (no cups)', 'Betting markets: Win 1UP, DC (1X/X2)', 'Most Corners'],
                        'locked' => ['Parlay Premium (not included)', 'PRO Predictions (not included)'],
                        'popular' => true
                    ],
'both' => [
                        'title' => 'Both Premium',
                        'desc' => 'Get everything — Rollover + Parlay + PRO Predictions. Best value for serious punters who want full coverage.',
                        'features' => ['Everything in Rollover', 'Everything in Parlay', 'PRO Predictions (Elevated accuracy)', 'Priority Support', 'Best Value', 'Save 10% vs Separate'],
                        'locked' => [],
                        'popular' => false
                    ]
                ];
                $durationOpts = getDurationOptions();
                foreach ($tiers as $tierKey => $tier):
                    $showPrice = getPlanPrice($tierKey, 'monthly');
                    $showDays = getPlanDays($tierKey, 'monthly');
                    $showPerDay = round($showPrice / $showDays);
                ?>
                <div class="col-lg-4 col-md-6">
                    <div class="pricing-card <?= $tier['popular'] ? 'popular' : '' ?>">
                        <h4 class="text-white mb-3"><?= $tier['title'] ?></h4>
                        <div class="price-tag mb-1"><?= number_format($showPrice) ?> <small class="fs-6 text-white-50">TZS</small></div>
                        <p class="text-white-50 mb-3" style="font-size: 0.85rem;">~<?= $showPerDay ?> TZS/day • <?= $showDays ?> days access</p>
                        <p class="text-white-50" style="font-size: 0.9rem;"><?= $tier['desc'] ?></p>
                        <div class="d-flex gap-1 justify-content-center mb-3">
                            <?php foreach ($durationOpts as $dk => $dopt):
                                $p = getPlanPrice($tierKey, $dk);
                                $pd = round($p / getPlanDays($tierKey, $dk));
                            ?>
                            <span style="background: rgba(139,92,246,0.15); border: 1px solid rgba(139,92,246,0.3); border-radius: 6px; padding: 0.3rem 0.6rem; font-size: 0.7rem; text-align: center; color: var(--primary-light);">
                                <strong><?= $dopt['label'] ?></strong><br><?= number_format($p) ?> TZS
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <ul class="feature-list mb-4">
                            <?php foreach ($tier['features'] as $f): ?><li><?= $f ?></li><?php endforeach; ?>
                            <?php foreach ($tier['locked'] as $l): ?><li class="<?= strpos($l, '(not included)') !== false ? 'text-muted' : '' ?>"><?= $l ?></li><?php endforeach; ?>
                        </ul>
                        <a href="subscribe?tier=<?= $tierKey ?>" class="btn btn-outline-premium w-100 <?= $tier['popular'] ? 'btn-premium' : '' ?>">Choose <?= $tierKey === 'both' ? 'Both' : $tierKey ?></a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section id="payment" class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold text-white">Flexible Payment Options</h2>
                <p class="text-white-50">We accept multiple payment methods for your convenience</p>
            </div>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="payment-section">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <h5 class="text-white mb-3">Mobile Money</h5>
                                <ul class="feature-list">
                                    <li>M-Pesa (Vodacom)</li>
                                    <li>Mixx by Yas</li>
                                    <li>Airtel Money</li>
                                    <li>HaloPesa (Halotel)</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h5 class="text-white mb-3">Bank & Other</h5>
                                <ul class="feature-list">
                                    <li>Bank Transfer (NMB, CRDB, etc.)</li>
                                    <li>Selcom Microfinance</li>
                                    <li>Mixx by Yas</li>
                                    <li>E-Wallets</li>
                                </ul>
                            </div>
                        </div>
                        <div class="payment-info-box">
                            How It Works:</strong> After registration, send payment via your preferred method, then submit the reference number for instant activation (1-5 minutes).
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Social Proof Bar -->
    <section class="py-4" style="background: rgba(139, 92, 246, 0.05); border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color);">
        <div class="container">
            <div class="row text-center g-3">
                <div class="col-4 col-md-3">
                    <div class="stat-number" id="userCount" data-target="<?= $totalUsers ?>">0</div>
                    <div class="stat-label">Active Members</div>
                </div>
                <div class="col-4 col-md-3">
                    <div class="stat-number" id="pickCount" data-target="<?= $totalPicks ?>">0</div>
                    <div class="stat-label">Total Picks</div>
                </div>
                <div class="col-4 col-md-3">
                    <div class="stat-number">30</div>
                    <div class="stat-label">Day Free Trial</div>
                </div>
                <div class="col-md-3 d-none d-md-block">
                    <div class="stat-number" id="trialTimer">--:--:--</div>
                    <div class="stat-label">Trial Remaining Today</div>
                </div>
            </div>
        </div>
    </section>

    <?php if ($freeDailyPick): ?>
    <!-- Free Daily Pick Teaser -->
    <section class="py-4" style="background: rgba(6, 182, 212, 0.05);">
        <div class="container">
            <div class="row align-items-center g-3">
                <div class="col-md-8">
                    <span class="badge mb-2" style="background:#06B6D4;color:#000;"><i class="fas fa-star me-1"></i>Free Daily Pick / Chaguo la Bure la Leo</span>
                    <p class="text-white-50 small mb-1"><?= htmlspecialchars($freeDailyPick['match_name']) ?></p>
                    <p class="text-muted small mb-1"><?= htmlspecialchars($freeDailyPick['league']) ?> — <?= htmlspecialchars($freeDailyPick['match_time']) ?></p>
                    <p class="small mb-0" style="color:#06B6D4;"><i class="fas fa-check-circle me-1"></i>Pick: <strong><?= htmlspecialchars($freeDailyPick['pick_value']) ?></strong><?php if (showOddsForPick($freeDailyPick['pick_value'] ?? '')): ?> @ <?= htmlspecialchars($freeDailyPick['odds']) ?><?php endif; ?> <span class="text-muted">— <?= date('M d', strtotime($freeDailyPick['detected_at'])) ?></span></p>
                </div>
                <div class="col-md-4 text-md-end">
                    <p class="small text-muted mb-0">Today's free pick. <a href="#" data-bs-toggle="modal" data-bs-target="#signupModal" style="color:#06B6D4;font-weight:600;">Join free</a> for more.</p>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php renderSportsbookAdWidget('hp2', null, 4); ?>

    <?php if (!empty($approvedSlips)): ?>
    <!-- Winning Slips Carousel -->
    <section class="py-4" style="background: rgba(245, 158, 11, 0.04);">
        <div class="container">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold text-white mb-0"><i class="fas fa-trophy me-1" style="color:#F59E0B;"></i>Winning Slips / Slip Zilizoshinda</h5>
                <span class="small text-muted">Join members winning big — <a href="#" data-bs-toggle="modal" data-bs-target="#signupModal" style="color:#F59E0B;font-weight:600;">Join free</a></span>
            </div>
            <div class="d-flex gap-3 overflow-auto pb-2" style="scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch;">
                <?php foreach ($approvedSlips as $slip): ?>
                <div class="card" style="min-width:200px; flex:0 0 auto; background:var(--card-bg); border:1px solid var(--border-color); scroll-snap-align:start;">
                    <img src="<?= htmlspecialchars($slip['image_path']) ?>" class="card-img-top" alt="Winning slip" style="height:140px; object-fit:cover; border-radius:8px 8px 0 0;">
                    <div class="card-body p-2 small">
                        <p class="mb-0 text-white fw-bold"><?= htmlspecialchars($slip['display_name'] ?: 'Member') ?></p>
                        <?php if (!empty($slip['description'])): ?><p class="text-muted mb-0" style="font-size:0.7rem;"><?= htmlspecialchars($slip['description']) ?></p><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($homeCodes) && !isSectionHidden('betting_codes')): ?>
    <section id="codes" class="py-5 codes-section">
        <div class="container">
            <div class="text-center mb-4">
                <h2 class="fw-bold text-white"><i class="fas fa-ticket-alt me-2" style="color: var(--accent);"></i>Today's Betting Code Marketplace</h2>
                <p class="text-white-50">Buy winning codes from fellow tipsters — verified and ready to use. <strong><?= count($homeCodes) ?></strong> code<?= count($homeCodes) !== 1 ? 's' : '' ?> available today.</p>
            </div>
            <div class="row g-4">
                <div class="col-lg-12">
                    <?php if (count($homeCodes) > 1): ?>
                    <div class="position-relative">
                        <div id="codesSlider" class="d-flex gap-3 overflow-hidden" style="scroll-behavior: smooth; overflow-x: auto; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; padding-bottom: 8px;">
                            <?php foreach ($homeCodes as $hc):
                                $hname = $hc['display_name'] ? htmlspecialchars($hc['display_name']) : htmlspecialchars(substr($hc['phone'], 0, 8)) . '***';
                            ?>
                            <div class="flex-shrink-0" style="width: 280px; scroll-snap-align: start;">
                                <div class="code-card h-100">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div style="flex:1; min-width:0;">
                                            <?php if (!empty($hc['badge']) && isset(CODE_BADGES[$hc['badge']])): $bd=CODE_BADGES[$hc['badge']]; ?>
                                            <div style="margin-bottom:0.25rem;"><span style="display:inline-block;background:<?=$bd['bg']?>;color:<?=$bd['color']?>;padding:0.06rem 0.4rem;border-radius:4px;font-size:0.55rem;font-weight:700;text-transform:uppercase;">🏆 <?=htmlspecialchars($bd['label'])?></span></div>
                                            <?php endif; ?>
                                            <div class="fw-bold text-white" style="font-size: 0.9rem;"><?= htmlspecialchars($hc['matches']) ?></div>
                                            <div class="code-seller mt-1"><i class="fas fa-user me-1"></i><?= $hname ?></div>
                                        </div>
                                        <span class="code-price-badge ms-2"><?= number_format($hc['price'] ?? BETTING_CODE_PRICE) ?> TZS</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <?php if ((int)($hc['sales_count'] ?? 0) > 0): ?>
                                        <span class="badge" style="background: #F59E0B; color: #000; font-size: 0.7rem;"><i class="fas fa-fire me-1"></i><?= $hc['sales_count'] ?> sold</span>
                                        <?php endif; ?>
                                        <?php if ((int)($hc['sales_count'] ?? 0) >= 3): ?>
                                        <span class="badge" style="background: #10B981; color: #fff; font-size: 0.7rem;"><i class="fas fa-crown me-1"></i>Top Seller</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted mb-2"><?= htmlspecialchars($hc['description']) ?></div>
                                    <?php if (!empty($hc['odds'])): ?>
                                    <div class="small"><span class="text-muted">Odds:</span> <strong style="color: var(--accent);"><?= htmlspecialchars($hc['odds']) ?></strong></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="codesPrev" class="btn btn-dark position-absolute top-50 start-0 translate-middle-y ms-2" style="border-radius: 50%; width: 40px; height: 40px; padding: 0; opacity: 0.8; z-index: 2; display: none;"><i class="fas fa-chevron-left"></i></button>
                        <button type="button" id="codesNext" class="btn btn-dark position-absolute top-50 end-0 translate-middle-y me-2" style="border-radius: 50%; width: 40px; height: 40px; padding: 0; opacity: 0.8; z-index: 2;"><i class="fas fa-chevron-right"></i></button>
                    </div>
                    <?php else:
                        $hname = $homeCodes[0]['display_name'] ? htmlspecialchars($homeCodes[0]['display_name']) : htmlspecialchars(substr($homeCodes[0]['phone'], 0, 8)) . '***';
                    ?>
                    <div class="code-card">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div style="flex:1; min-width:0;">
                                <?php if (!empty($homeCodes[0]['badge']) && isset(CODE_BADGES[$homeCodes[0]['badge']])): $bd=CODE_BADGES[$homeCodes[0]['badge']]; ?>
                                <div style="margin-bottom:0.25rem;"><span style="display:inline-block;background:<?=$bd['bg']?>;color:<?=$bd['color']?>;padding:0.06rem 0.4rem;border-radius:4px;font-size:0.55rem;font-weight:700;text-transform:uppercase;">🏆 <?=htmlspecialchars($bd['label'])?></span></div>
                                <?php endif; ?>
                                <div class="fw-bold text-white" style="font-size: 0.9rem;"><?= htmlspecialchars($homeCodes[0]['matches']) ?></div>
                                <div class="code-seller mt-1"><i class="fas fa-user me-1"></i><?= $hname ?></div>
                            </div>
                            <span class="code-price-badge ms-2"><?= number_format($homeCodes[0]['price'] ?? BETTING_CODE_PRICE) ?> TZS</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <?php if ((int)($homeCodes[0]['sales_count'] ?? 0) > 0): ?>
                            <span class="badge" style="background: #F59E0B; color: #000; font-size: 0.7rem;"><i class="fas fa-fire me-1"></i><?= $homeCodes[0]['sales_count'] ?> sold</span>
                            <?php endif; ?>
                            <?php if ((int)($homeCodes[0]['sales_count'] ?? 0) >= 3): ?>
                            <span class="badge" style="background: #10B981; color: #fff; font-size: 0.7rem;"><i class="fas fa-crown me-1"></i>Top Seller</span>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted mb-2"><?= htmlspecialchars($homeCodes[0]['description']) ?></div>
                        <?php if (!empty($homeCodes[0]['odds'])): ?>
                        <div class="small"><span class="text-muted">Odds:</span> <strong style="color: var(--accent);"><?= htmlspecialchars($homeCodes[0]['odds']) ?></strong></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>


            </div>

            <div class="text-center mt-4">
                <a href="#" class="btn btn-premium btn-lg" data-bs-toggle="modal" data-bs-target="#signupModal"><i class="fas fa-cart-plus me-2"></i>Sign Up to Buy Codes</a>
                <p class="text-white-50 small mt-2">Already a member? <a href="login" style="color: var(--primary);">Login to browse all codes</a></p>
            </div>
        </div>
    </section>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var slider = document.getElementById('codesSlider');
        var prevBtn = document.getElementById('codesPrev');
        var nextBtn = document.getElementById('codesNext');
        if (slider && prevBtn && nextBtn) {
            function updateButtons() {
                prevBtn.style.display = slider.scrollLeft > 10 ? '' : 'none';
                nextBtn.style.display = slider.scrollLeft < slider.scrollWidth - slider.clientWidth - 10 ? '' : 'none';
            }
            prevBtn.addEventListener('click', function() { slider.scrollBy({ left: -300, behavior: 'smooth' }); setTimeout(updateButtons, 100); });
            nextBtn.addEventListener('click', function() { slider.scrollBy({ left: 300, behavior: 'smooth' }); setTimeout(updateButtons, 100); });
            slider.addEventListener('scroll', updateButtons);
            updateButtons();
            var autoInterval = setInterval(function() {
                if (slider.matches(':hover')) return;
                if (slider.scrollLeft + slider.clientWidth >= slider.scrollWidth - 10) {
                    slider.scrollTo({ left: 0, behavior: 'smooth' });
                } else {
                    slider.scrollBy({ left: 300, behavior: 'smooth' });
                }
                setTimeout(updateButtons, 100);
            }, 4000);
            slider.addEventListener('mouseenter', function() { clearInterval(autoInterval); });
            slider.addEventListener('mouseleave', function() {
                autoInterval = setInterval(function() {
                    if (slider.scrollLeft + slider.clientWidth >= slider.scrollWidth - 10) {
                        slider.scrollTo({ left: 0, behavior: 'smooth' });
                    } else {
                        slider.scrollBy({ left: 300, behavior: 'smooth' });
                    }
                    setTimeout(updateButtons, 100);
                }, 4000);
            });
        }
    });
    </script>
    <?php endif; ?>

    <!-- Betting Codes FAQ -->
    <section id="codes-faq" class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold text-white">Betting Code Marketplace — FAQ</h2>
                <p class="text-white-50">Everything you need to know about publishing and purchasing codes</p>
            </div>
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="accordion" id="codesFAQ" style="--bs-accordion-bg: var(--secondary); --bs-accordion-border-color: var(--border-color); --bs-accordion-btn-color: #fff; --bs-accordion-btn-focus-box-shadow: none; --bs-accordion-active-bg: rgba(139,92,246,0.15); --bs-accordion-active-color: #fff; --bs-accordion-body-color: var(--text-muted);">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqPublish">
                                    How to publish a betting code? (step-by-step)
                                </button>
                            </h2>
                            <div id="faqPublish" class="accordion-collapse collapse" data-bs-parent="#codesFAQ">
                                <div class="accordion-body">
                                    <ol style="padding-left:1.2rem;">
                                        <li>Log in and go to the <strong>Betting Codes</strong> tab in your dashboard.</li>
                                        <li>In the <strong>"Sell a Betting Code"</strong> card, fill in your <strong>Betting Code</strong> (e.g. PRD-2405-X9K2), <strong>Bookmaker</strong> (e.g. Betika, SportPesa), and <strong>Markets</strong> (e.g. 1x2, Over 1.5 Goals).</li>
                                        <li>Click <strong>"Publish Code"</strong>. Publishing is <strong>free</strong> — no credits needed.</li>
                                        <li>Your code is now live in the marketplace under <strong>"Available Codes"</strong>.</li>
                                        <li>Your published codes appear in the <strong>"Your Published Codes"</strong> section. Track how many times each is purchased.</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqBrowse">
                                    How to browse and buy codes? (step-by-step)
                                </button>
                            </h2>
                            <div id="faqBrowse" class="accordion-collapse collapse" data-bs-parent="#codesFAQ">
                                <div class="accordion-body">
                                    <ol style="padding-left:1.2rem;">
                                        <li>In the <strong>Betting Codes</strong> tab, scroll down to <strong>"Available Codes"</strong>.</li>
                                        <li>Use the search box to find codes by <strong>top seller name</strong>.</li>
                                        <li>Browse the code cards — each shows the bookmaker, markets, odds, seller name, rating, sales count, and the seller's payment methods.</li>
                                        <li>Click <strong>"Unlock Now"</strong> on any code you want.</li>
                                        <li>A modal appears showing the <strong>seller's payment details</strong>. Send <?= number_format(BETTING_CODE_PRICE) ?> TZS to the seller via M-Pesa, Mixx, Airtel, or their listed method.</li>
                                        <li>Paste your <strong>payment reference number</strong> from SMS in the modal and submit.</li>
                                        <li>The <strong>seller confirms</strong> they received the payment, then the code is unlocked in your dashboard.</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                    How do publishers earn money?
                                </button>
                            </h2>
                            <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#codesFAQ">
                                <div class="accordion-body">Each betting code sells for <strong><?= number_format(BETTING_CODE_PRICE) ?> TZS</strong> per purchase. You earn the <strong>full <?= number_format(BETTING_CODE_PRICE) ?> TZS</strong> from every sale. The platform fee (<?= number_format(BETTING_CODE_COMMISSION) ?> TZS per sale) is deducted from your credits when a buyer purchases your code — not upfront. Publishing is free. Top sellers also earn bonus credits from admin.</div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                    How do credits work?
                                </button>
                            </h2>
                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#codesFAQ">
                                <div class="accordion-body">Publishing codes is <strong>free</strong>. Credits are used when a buyer purchases your code — <strong>1 credit</strong> (<?= number_format(PUBLISHER_CREDIT_COST) ?> TZS) is deducted per sale as the office share. New members receive <strong><?= FREE_CREDITS_PER_WEEK ?> free credits</strong> on signup. When your balance reaches 0, buyers won't be able to purchase your codes until you top up. Buy more credits from your dashboard.</div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                    How can punters purchase a code?
                                </button>
                            </h2>
                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#codesFAQ">
                                <div class="accordion-body">Browse available codes in the marketplace, click <strong>"Unlock Now"</strong> on any code, send <?= number_format(BETTING_CODE_PRICE) ?> TZS directly to the seller via their listed payment methods, then submit your payment reference number. The seller confirms receipt and the code is unlocked in your dashboard.</div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                    How do I withdraw my earnings?
                                </button>
                            </h2>
                            <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#codesFAQ">
                                <div class="accordion-body">Payments go directly to <strong>you</strong>. When a buyer purchases your code, they send <?= number_format(BETTING_CODE_PRICE) ?> TZS directly to your registered mobile money number — the platform simply connects you with buyers. Your registered phone number (from signup) is shared with the buyer after purchase so they can send payment directly to you. No need to withdraw from the platform — your earnings arrive straight to your M-Pesa/Airtel/Mixx.</div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                                    Can I be a top seller?
                                </button>
                            </h2>
                            <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#codesFAQ">
                                <div class="accordion-body">Yes! Every month, the <strong>top 3 sellers</strong> (by highest approved sales) each earn <strong><?= number_format(FREE_CREDITS_PER_WEEK) ?> free credits</strong> as a reward. The admin awards these at month-end. Top sellers also get featured, earn bonus credits, and build a reputation. Consistent quality codes attract repeat buyers and boost your sales rank. 🏆</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php if (!isSectionHidden('aviator')): ?>
    <!-- Aviator Modal -->
    <div class="modal fade" id="aviatorModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background: var(--secondary); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 16px;">
                <div class="modal-header border-0 pb-0 justify-content-end">
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body px-4 pt-4">
                    <div class="text-center mb-4">
                        <div style="font-size: 3rem; margin-bottom: 0.5rem;">✈️</div>
                        <h4 class="fw-bold text-white mb-1">Aviator Predictor Tool</h4>
                        <div class="d-inline-block px-3 py-1 rounded-pill mb-3" style="background: rgba(245, 158, 11, 0.2); color: #F59E0B; font-weight: 700; font-size: 1.1rem;"><?= number_format(AVIATOR_ACCESS_PRICE) ?> TZS / Day</div>
                        <p class="text-white-50" style="font-size: 0.9rem;">Know when to cash out and when to hold. Our tool analyzes past rounds to spot winning patterns and tell you the best time to play.</p>
                    </div>
                    <ul class="feature-list mb-4" style="list-style: none; padding: 0;">
                        <li class="mb-2" style="color: #E2E8F0;"><i class="fas fa-check-circle me-2" style="color: #22C55E;"></i>Know when the game is safe to play vs. risky</li>
                        <li class="mb-2" style="color: #E2E8F0;"><i class="fas fa-check-circle me-2" style="color: #22C55E;"></i>Spot big crash opportunities before they happen</li>
                        <li class="mb-2" style="color: #E2E8F0;"><i class="fas fa-check-circle me-2" style="color: #22C55E;"></i>Get the best time window to place your bet</li>
                        <li class="mb-2" style="color: #E2E8F0;"><i class="fas fa-check-circle me-2" style="color: #22C55E;"></i>Track your game history and get instant analysis</li>
                        <li class="mb-2" style="color: #E2E8F0;"><i class="fas fa-check-circle me-2" style="color: #22C55E;"></i>Works in English and Kiswahili</li>
                    </ul>
                    <div class="d-grid gap-2">
                        <a href="#" class="btn btn-premium btn-lg w-100" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#signupModal"><i class="fas fa-rocket me-2"></i>Sign Up to Access Aviator</a>
                        <button type="button" class="btn btn-outline-secondary w-100" data-bs-dismiss="modal" style="border-color: var(--border-color); color: var(--text-muted);">Maybe Later</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Signup Modal -->
    <div class="modal fade" id="signupModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background: linear-gradient(135deg, rgba(30,20,50,0.95) 0%, rgba(20,30,40,0.95) 100%); border: 1px solid rgba(139,92,246,0.3); border-radius: 16px; box-shadow: 0 10px 40px rgba(139,92,246,0.12);">
                <div class="modal-header border-0 pb-0">
                    <div class="text-center w-100">
                        <h5 class="modal-title fw-bold" style="background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">Join Predixa</h5>
                        <p class="text-white-50 small">30 days FREE trial — football predictions, Aviator tool, and tipster marketplace. No credit card needed.</p>
                    </div>
                    <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 mt-3 me-3" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4">
                    <div id="modalAlert" class="alert d-none"></div>
                    <form id="modalSignupForm">
                        <input type="hidden" name="ajax" value="1">
                        <div class="mb-3">
                            <label class="form-label text-white-50 small">Phone Number *</label>
                            <input type="tel" name="phone" class="form-control" placeholder="Enter phone number" required>
                            <small class="text-muted">Include your country code (e.g. +255, +254, +256)</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-white-50 small">Display Name (optional)</label>
                            <input type="text" name="display_name" class="form-control" placeholder="e.g., KingPunter">
                            <small class="text-muted">Shown when you sell codes in marketplace</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-white-50 small">Email Address *</label>
                            <input type="email" name="email" class="form-control" placeholder="your@email.com" required>
                            <small class="text-muted">We'll send a verification link</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-white-50 small">Password *</label>
                            <input type="password" name="password" class="form-control" placeholder="Minimum 6 characters" required minlength="6">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-white-50 small">Confirm Password *</label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="Repeat password" required>
                        </div>
                        <div class="mb-3">
                            <label style="display:flex;align-items:flex-start;gap:10px;background:rgba(22,27,34,0.5);border:1px solid rgba(139,92,246,0.3);border-radius:8px;padding:12px 15px;cursor:pointer;">
                                <input type="checkbox" name="accept_terms" value="1" required
                                       style="margin-top:3px;accent-color:var(--primary);flex-shrink:0;width:18px;height:18px;cursor:pointer;">
                                <span style="color:var(--text-light);font-size:0.88rem;">
                                    I agree to the <a href="terms" target="_blank" style="color:var(--accent);text-decoration:underline;">Terms &amp; Conditions</a> and <a href="privacy" target="_blank" style="color:var(--accent);text-decoration:underline;">Privacy Policy</a>
                                </span>
                            </label>
                        </div>
                        <button type="submit" class="btn btn-premium w-100" id="modalSignupBtn">
                            Create Free Account
                        </button>
                    </form>
                    <div class="text-center mt-3">
                        <span class="text-white-50 small">Already have an account?</span>
                        <a href="login" class="login-link small" data-bs-dismiss="modal">Login here</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Exit-Intent Popup -->
    <div class="modal fade" id="exitModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background: linear-gradient(135deg, rgba(30,20,50,0.95) 0%, rgba(20,30,40,0.95) 100%); border: 2px solid var(--primary); border-radius: 16px;">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center px-4 pb-4">
                    <div style="font-size: 4rem; margin-bottom: 1rem;"><i class="fas fa-plane-up fa-3x" style="color: var(--primary);"></i></div>
                    <h3 class="fw-bold text-white mb-2">Your Winning Edge Starts Here</h3>
                    <p class="text-white-50 mb-3">Get <strong style="color: var(--accent);">free access</strong> to daily football predictions, the Aviator betting tool, and a marketplace where you can buy and sell winning codes. No credit card needed.</p>
                    <ul class="feature-list d-inline-block text-start mb-4" style="max-width: 340px;">
                        <li><i class="fas fa-robot me-1" style="color: var(--primary);"></i> Aviator Predictor — know when to cash out</li>
                        <li><i class="fas fa-tachometer-alt me-1" style="color: #F59E0B;"></i> High-odds parlay picks (up to 30x)</li>
                        <li><i class="fas fa-shield-alt me-1" style="color: #22C55E;"></i> Safe daily picks (75-85% win rate)</li>
                        <li><i class="fas fa-star me-1" style="color: var(--accent);"></i> Top picks picked by our experts</li>
                        <li><i class="fas fa-store me-1" style="color: #3B82F6;"></i> Buy & sell winning codes from other tipsters</li>
                        <li><i class="fas fa-gift me-1" style="color: #EC4899;"></i> 21 free credits + 30-day free trial</li>
                    </ul>
                    <a href="#" class="btn btn-premium btn-lg w-100" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#signupModal">Join Free Now</a>
                    <div class="mt-3">
                        <a href="#" class="text-white-50 small" data-bs-dismiss="modal">No thanks, I'll risk losing</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-4">
                    <h5 class="mb-3"><i class="fas fa-futbol me-1"></i>PREDIXA</h5>
                    <p class="text-white-50">AI-powered football analytics, daily picks, and a tipster marketplace. Subscribe, bet, publish codes, and earn.</p>
                </div>
                <div class="col-md-2">
                    <h6 class="mb-3">Quick Links</h6>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="#pricing"><i class="fas fa-tag me-1" style="color:var(--accent);"></i> Plans</a></li>
                        <li class="mb-2"><a href="login"><i class="fas fa-right-to-bracket me-1" style="color:var(--primary);"></i> Login</a></li>
                        <li class="mb-2"><a href="#" data-bs-toggle="modal" data-bs-target="#signupModal"><i class="fas fa-user-plus me-1" style="color:#22C55E;"></i> Sign Up</a></li>
                        <li class="mb-2"><a href="#codes-faq"><i class="fas fa-circle-question me-1" style="color:#FBBF24;"></i> FAQ</a></li>
                        <li class="mb-2"><a href="https://www.begambleaware.org/" target="_blank" rel="noopener noreferrer"><i class="fas fa-shield-halved me-1" style="color:#10B981;"></i> Responsible Gambling</a></li>
                    </ul>
                </div>
                <div class="col-md-3">
                    <h6 class="mb-3">Free Tools</h6>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="dropping-odds"><i class="fas fa-arrow-trend-down me-1" style="color:#EF4444;"></i> Dropping Odds</a></li>
                        <li class="mb-2"><a href="signals"><i class="fas fa-microchip me-1" style="color:#22C55E;"></i> Smart Picks</a></li>
                        <li class="mb-2"><a href="track-record"><i class="fas fa-chart-line me-1" style="color:#FBBF24;"></i> Performance</a></li>
                        <li class="mb-2"><a href="betting-school"><i class="fas fa-book me-1" style="color:#8B5CF6;"></i> Betting School</a></li>
                        <li class="mb-2"><a href="pikka"><i class="fas fa-futbol me-1" style="color:#6366F1;"></i> Pikka</a></li>
                    </ul>
                </div>
                <div class="col-md-3">
                    <h6 class="mb-3">Support</h6>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="https://wa.me/255713348298" target="_blank" style="color:#25D366;text-decoration:none;"><i class="fab fa-whatsapp me-1"></i> WhatsApp</a></li>
                        <li class="mb-2"><a href="mailto:support@predixa.co.tz" style="color:var(--text-muted);text-decoration:none;"><i class="fas fa-envelope me-1" style="color:var(--primary);"></i> Email Us</a></li>
                        <li class="mb-2"><i class="fas fa-clock me-1" style="color:var(--accent);"></i> 24/7 Support</li>
                        <li class="mb-2"><a href="terms"><i class="fas fa-file-lines me-1" style="color:var(--muted);"></i> Terms of Service</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-top border-secondary mt-4 pt-4 text-center text-white-50">
                <small>© <?= date('Y') ?> Predixa. All rights reserved. | 18+ | Bet Responsibly</small>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        // Modal signup form
        document.getElementById('modalSignupForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('modalSignupBtn');
            const alert = document.getElementById('modalAlert');
            btn.disabled = true; btn.innerHTML = 'Creating account...';
            alert.classList.add('d-none');
            try {
                const res = await fetch('signup', { method: 'POST', body: new FormData(this) });
                const data = await res.json();
                if (data.success) { window.location.href = data.redirect; }
                else {
                    alert.className = 'alert alert-danger';
                    alert.textContent = data.error || 'Registration failed';
                    alert.classList.remove('d-none');
                    btn.disabled = false; btn.innerHTML = 'Create Free Account';
                }
            } catch(e) {
                alert.className = 'alert alert-danger';
                alert.textContent = 'Connection error. Please try again.';
                alert.classList.remove('d-none');
                btn.disabled = false; btn.innerHTML = 'Create Free Account';
            }
        });

        // Animated counters with shorthand
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

        // Countdown timer (end of day)
        function updateTimer() {
            const el = document.getElementById('trialTimer');
            if (!el) return;
            const now = new Date();
            const end = new Date(now); end.setHours(23, 59, 59, 999);
            const diff = end - now;
            const h = Math.floor(diff / 3600000);
            const m = Math.floor((diff % 3600000) / 60000);
            const s = Math.floor((diff % 60000) / 1000);
            el.textContent = String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        }
        updateTimer(); setInterval(updateTimer, 1000);

        // Exit-intent popup (once per session)
        (function() {
            if (sessionStorage.getItem('exitShown')) return;
            let exitModal = null;
            document.addEventListener('mouseleave', function(e) {
                if (e.clientY > 0 || exitModal) return;
                exitModal = new bootstrap.Modal(document.getElementById('exitModal'));
                exitModal.show();
                sessionStorage.setItem('exitShown', '1');
            });
        })();
    </script>
<script>
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
</body>
</html>
