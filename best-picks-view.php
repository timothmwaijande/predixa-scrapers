<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
requireLogin();
if (!isAdmin()) { header("Location: dashboard?error=access_denied"); exit; }

$db = getDB();
if (!$db) { die("DB connection failed"); }

$todaySources = [];
$wp = $db->query("SELECT DISTINCT match_name, match_time FROM web_picks WHERE DATE(detected_at) >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchAll(PDO::FETCH_ASSOC);
foreach ($wp as $r) { $todaySources[$r['match_name']] = $r['match_time'] ?: ''; }
$sr = $db->query("SELECT DISTINCT match_name, match_time FROM scraper_results WHERE DATE(detected_at) >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchAll(PDO::FETCH_ASSOC);
foreach ($sr as $r) { if (!isset($todaySources[$r['match_name']])) $todaySources[$r['match_name']] = $r['match_time'] ?: ''; }
$afp = $db->query("SELECT DISTINCT match_name, match_time FROM admin_featured_picks WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchAll(PDO::FETCH_ASSOC);
foreach ($afp as $r) { if (!isset($todaySources[$r['match_name']])) $todaySources[$r['match_name']] = $r['match_time'] ?: ''; }

$resulted = [];
$mr = $db->query("SELECT home_team, away_team FROM match_results WHERE match_date <= CURDATE()")->fetchAll(PDO::FETCH_ASSOC);
foreach ($mr as $r) { $resulted[mb_strtolower($r['home_team']) . '|' . mb_strtolower($r['away_team'])] = true; }

$picks = $db->query("
    SELECT bp.match_name, bp.league, bp.confidence, bp.recommended_pick,
           bp.value_pick, bp.home_team, bp.away_team, bp.match_date, bp.match_time,
           bp.market_odds_1, bp.market_odds_x, bp.market_odds_2
    FROM bayesian_predictions bp
    WHERE bp.match_date IN (CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY))
      AND bp.recommended_pick IS NOT NULL AND bp.recommended_pick != ''
    ORDER BY bp.confidence DESC
")->fetchAll(PDO::FETCH_ASSOC);

$now = new DateTime();
$candidates = [];
foreach ($picks as $p) {
    $normKey = mb_strtolower($p['home_team']) . '|' . mb_strtolower($p['away_team']);
    if (isset($resulted[$normKey])) continue;
    $matchTime = $p['match_time'] ?: ($todaySources[$p['match_name']] ?? '');
    if ($matchTime) {
        try {
            $ko = new DateTime($matchTime);
            $minsPast = ($now->getTimestamp() - $ko->getTimestamp()) / 60;
            if ($minsPast > 105) continue;
        } catch (Exception $e) {}
    }
    $recs = explode(',', $p['recommended_pick']);
    foreach ($recs as $r) {
        $r = trim($r);
        $parts = explode(':', $r);
        if (count($parts) !== 2) continue;
        $market = trim($parts[0]);
        $prob = (float)trim($parts[1]);
        $bestOdds = 0;
        $mv = strtoupper($market);
        if ($mv === '1') $bestOdds = (float)($p['market_odds_1'] ?? 0);
        elseif ($mv === 'X') $bestOdds = (float)($p['market_odds_x'] ?? 0);
        elseif ($mv === '2') $bestOdds = (float)($p['market_odds_2'] ?? 0);
        $candidates[] = [
            'match_name' => $p['match_name'],
            'home_team' => $p['home_team'],
            'away_team' => $p['away_team'],
            'league' => $p['league'] ?? '',
            'confidence' => (float)$p['confidence'],
            'pick_value' => $market,
            'probability' => $prob,
            'best_odds' => $bestOdds,
        ];
    }
}

usort($candidates, fn($a, $b) => $b['probability'] <=> $a['probability']);
$seen = [];
$unique = [];
foreach ($candidates as $c) {
    $key = $c['match_name'] . '|' . $c['pick_value'];
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $unique[] = $c;
}

$pageTitle = 'Best Picks — PREDIXA';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title><?= $pageTitle ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root { --primary: #8B5CF6; --primary-dark: #7C3AED; --primary-light: #A78BFA; --accent: #06B6D4; --accent-dark: #0891B2; --secondary: #161b22; --text-light: #e0e0e0; --text-muted: #8b949e; --border-color: #2a2e35; }
body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #111318 0%, #1c2130 100%); color: var(--text-light); min-height: 100vh; }
.content-area { background: linear-gradient(135deg, rgba(239,68,68,0.06) 0%, rgba(139,92,246,0.04) 50%, rgba(6,182,212,0.03) 100%); border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); padding-bottom: 40px; }
.page-header { padding: 110px 0 30px; }
.page-header h1 { font-weight: 800; font-size: 2rem; }
a { color: var(--primary-light); text-decoration: none; }
a:hover { color: var(--accent); }
.container { max-width: 1400px; }
.pick-card { background: linear-gradient(135deg, rgba(139,92,246,0.2) 0%, rgba(6,182,212,0.1) 100%); border: 1px solid rgba(139,92,246,0.3); border-radius: 12px; padding: 16px; margin-bottom: 12px; transition: all .2s; }
.pick-card:hover { border-color: var(--primary); transform: translateY(-1px); }
.pick-card .match-name { font-weight: 700; font-size: 1rem; }
.pick-card .match-league { font-size: 0.78rem; color: var(--text-muted); }
.conf-ring { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.75rem; flex-shrink: 0; }
.conf-ring.high { background: rgba(34,197,94,0.15); color: #22C55E; }
.conf-ring.medium { background: rgba(251,191,36,0.15); color: #FBBF24; }
.conf-ring.low { background: rgba(148,163,184,0.1); color: var(--text-muted); }
.stats-row { font-size: 0.75rem; color: var(--text-muted); display: flex; flex-wrap: wrap; gap: 8px; }
.stats-row .stat { background: rgba(255,255,255,0.03); padding: 2px 8px; border-radius: 4px; }
.empty-state { text-align: center; padding: 80px 20px; color: var(--text-muted); }
.empty-state i { font-size: 3rem; color: var(--primary); margin-bottom: 16px; }
.badge-market { font-size: 0.65rem; padding: 2px 8px; border-radius: 4px; font-weight: 600; background: rgba(139,92,246,0.15); color: var(--primary); }
.pick-value-tag { background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.3); color: #22C55E; }
.bot-badge { background: rgba(6,182,212,0.15); border: 1px solid rgba(6,182,212,0.3); color: #06B6D4; font-size: 0.6rem; font-weight: 700; padding: 1px 6px; border-radius: 4px; }
.search-box { border-radius:10px; padding:8px 14px; transition:all .3s; }
.search-box:focus { outline:none; border-color:var(--primary) !important; box-shadow:0 0 0 3px rgba(139,92,246,0.15); }
.search-box::placeholder { color:var(--text-muted); font-size:0.82rem; }
</style>
</head>
<body>
<nav class="navbar navbar-expand navbar-dark fixed-top" style="background:rgba(15,17,21,0.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);padding:10px 0;">
    <div class="container">
        <a class="navbar-brand" href="./" style="font-weight:800;font-size:1.4rem;background:linear-gradient(135deg,var(--primary) 0%,var(--accent) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;text-decoration:none;"><i class="fas fa-robot me-2" style="-webkit-text-fill-color:var(--accent);"></i>PREDIXA</a>
        <div class="d-flex align-items-center gap-2">
            <a href="admin" class="btn btn-sm" style="border:1px solid var(--border);color:var(--muted);padding:4px 14px;border-radius:6px;text-decoration:none;font-size:0.8rem;"><i class="fas fa-cog me-1"></i>Admin</a>
            <a href="logout" class="btn btn-sm" style="border:1px solid var(--primary);color:var(--primary);padding:4px 14px;border-radius:6px;text-decoration:none;font-size:0.8rem;"><i class="fas fa-right-from-bracket me-1"></i>Logout</a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1><i class="fas fa-robot me-2" style="color:#22C55E;"></i>Value Picks</h1>
        <p style="color:var(--text-muted);font-size:1rem;max-width:700px;">Prediction model value picks · <?= count($unique) ?> picks · <?= date('jS F Y') ?> · EAT (GMT+3)</p>
    </div>
</div>

<div class="content-area">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);padding:3px 12px;border-radius:20px;font-size:0.72rem;font-weight:700;color:#EF4444;text-transform:uppercase;letter-spacing:0.5px;"><i class="fas fa-shield-halved me-1"></i>Admin — Restricted Access</span>
        </div>
        <div class="d-flex gap-1 flex-wrap">
            <input type="text" id="searchInput" class="form-control form-control-sm search-box" placeholder="Search matches..." style="max-width:200px;font-size:0.82rem;background:rgba(22,27,34,0.7);border:1.5px solid rgba(139,92,246,0.45);color:var(--text-light);">
            <span style="font-size:0.8rem;color:var(--text-muted);" id="pickCount"><?= count($unique) ?> picks</span>
        </div>
    </div>

    <?php if (empty($unique)): ?>
    <div class="empty-state">
        <i class="fas fa-robot"></i>
        <h5>No Picks Today</h5>
        <p class="mb-0">No model picks match today's detected matches. Check back once the prediction engine runs.</p>
    </div>
    <?php else: ?>
    <div id="picksList">
    <?php foreach ($unique as $c):
        $dataConf = $c['confidence'];
        $prob = $c['probability'];
        $probClass = $prob >= 70 ? 'high' : ($prob >= 50 ? 'medium' : 'low');
        $dataConfClass = $dataConf >= 60 ? 'high' : ($dataConf >= 30 ? 'medium' : 'low');
        $hasOdds = $c['best_odds'] > 0;
        $ev = $hasOdds ? round(($c['probability'] / 100) / (1/$c['best_odds']), 2) : 0;
        $isValue = $hasOdds && $ev > 1;
    ?>
    <div class="pick-card" data-match="<?= htmlspecialchars($c['match_name']) ?>">
        <div class="d-flex align-items-start flex-wrap gap-2 mb-2">
            <div class="flex-grow-1">
                <div class="match-name"><?= htmlspecialchars($c['match_name']) ?></div>
                <div class="match-league">League: <?= htmlspecialchars($c['league']) ?></div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="conf-ring <?= $probClass ?>" title="Market Probability: <?= $prob ?>%"><?= $prob ?></span>
            </div>
        </div>

        <div class="stats-row mb-2">
            <span class="stat"><i class="fas fa-bullseye me-1" style="color:#22C55E;"></i>Pick: <strong style="color:#22C55E;"><?= htmlspecialchars($c['pick_value']) ?></strong></span>
            <span class="stat"><i class="fas fa-chart-line me-1" style="color:#6366F1;"></i>Probability: <strong style="color:#6366F1;"><?= $prob ?>%</strong></span>
            <span class="stat" style="cursor:help;" title="How much historical data the model had for this match">
                <i class="fas fa-database me-1" style="color:<?= $dataConf >= 60 ? '#22C55E' : ($dataConf >= 30 ? '#FBBF24' : '#EF4444') ?>;"></i>
                Data: <strong style="color:<?= $dataConf >= 60 ? '#22C55E' : ($dataConf >= 30 ? '#FBBF24' : '#EF4444') ?>;"><?= $dataConf ?>%</strong>
            </span>
            <?php if ($hasOdds): ?>
            <span class="stat"><i class="fas fa-coins me-1" style="color:#F59E0B;"></i>Odds: <strong style="color:#F59E0B;">@ <?= number_format($c['best_odds'], 2) ?></strong></span>
            <span class="stat"><i class="fas fa-tag me-1" style="color:<?= $isValue ? '#06B6D4' : 'var(--text-muted)' ?>;"></i>Value: <strong style="color:<?= $isValue ? '#06B6D4' : 'var(--text-muted)' ?>;"><?= $ev ?>x</strong></span>
            <?php else: ?>
            <span class="stat" style="color:#6b7280;"><i class="fas fa-coins me-1"></i>No odds available</span>
            <?php endif; ?>
            <?php if ($isValue): ?>
            <span class="bot-badge"><i class="fas fa-check-circle me-1"></i>BOT TARGET</span>
            <?php endif; ?>
            <?php if ($dataConf < 30): ?>
            <span class="bot-badge" style="background:rgba(239,68,68,0.12);border-color:rgba(239,68,68,0.3);color:#EF4444;"><i class="fas fa-triangle-exclamation me-1"></i>Limited Data</span>
            <?php elseif ($dataConf < 60): ?>
            <span class="bot-badge" style="background:rgba(251,191,36,0.12);border-color:rgba(251,191,36,0.3);color:#FBBF24;"><i class="fas fa-chart-line me-1"></i>Low Data</span>
            <?php endif; ?>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge-market"><i class="fas fa-tag me-1"></i><?= htmlspecialchars($c['pick_value']) ?></span>
            <?php if ($hasOdds): ?>
            <span style="font-size:0.7rem;color:var(--text-muted);">
                <?php if ($isValue): ?>
                <i class="fas fa-fire me-1" style="color:#FBBF24;"></i>Value bet — <?= round(($ev - 1) * 100) ?>% edge
                <?php else: ?>
                <i class="fas fa-info-circle me-1"></i>No significant edge
                <?php endif; ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('searchInput')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.pick-card').forEach(card => {
        const name = card.dataset.match.toLowerCase();
        card.style.display = name.includes(q) ? '' : 'none';
    });
    const visible = document.querySelectorAll('.pick-card[style*="display: none"]');
    const total = document.querySelectorAll('.pick-card').length;
    document.getElementById('pickCount').textContent = (total - visible.length) + ' picks';
});
</script>
</body>
</html>
