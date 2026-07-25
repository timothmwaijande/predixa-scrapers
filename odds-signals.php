<?php
session_start();
require_once 'config.php';
require_once 'auth.php';
requireLogin();

$user = getCurrentUser();
$premium = getPremiumStatus();
if (!($premium['is_super_admin'] || $user['id'] == 1 || hasAdminPermission('analysis') || hasAdminPermission('scraper'))) {
    header("Location: dashboard");
    exit;
}

$db = getDB();
$matches = [];
if ($db) {
    $stmt = $db->query("SELECT wp.* FROM web_picks wp INNER JOIN (SELECT MAX(id) as id FROM web_picks WHERE (pattern_badge LIKE '%FALLING ODDS%' OR pattern_badge LIKE '%RISING ODDS%' OR fav_delta <= -2 OR opp_delta <= -2 OR draw_delta <= -2 OR fav_delta >= 2 OR opp_delta >= 2 OR draw_delta >= 2) AND DATE(detected_at) = CURDATE() GROUP BY match_name) latest ON wp.id = latest.id ORDER BY LEAST(ABS(wp.fav_delta), ABS(wp.opp_delta), ABS(wp.draw_delta)) ASC");
    $matches = $stmt->fetchAll();
}

require_once __DIR__ . '/includes/signals_engine.php';

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

// Verified boost
foreach ($analyzed as &$a) {
    addVerifiedBoost($a['signals'], $a['match']);
}

// Load Bayesian picks for Best Picks section
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
        if (!isset($todaySrc[$bp['match_name']])) continue;
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
unset($a);

// Fetch multi-bookie movements once for verified display
$movements = null;
try {
    $movements = getMultiBookieSheetData();
} catch (Exception $e) {
    error_log("odds-signals movements fetch: " . $e->getMessage());
}

// Cross-verify all signals
foreach ($analyzed as &$a) {
    foreach ($a['signals'] as &$s) {
        $s['verification_score'] = crossVerifySignal($s, $a['match']);
    }
    unset($s);
}
unset($a);

// Signal quality assessment
$totalSignals = 0; $highCount = 0; $medCount = 0; $lowCount = 0;
foreach ($analyzed as $a) {
    $totalSignals += count($a['signals']);
    foreach ($a['signals'] as $s) {
        $vs = $s['verification_score'];
        if ($vs >= 70) $highCount++;
        elseif ($vs >= 50) $medCount++;
        else $lowCount++;
    }
}
if (count($analyzed) < 3) $signalQuality = 'low';
elseif (count($analyzed) >= 5 && $highCount >= 3) $signalQuality = 'high';
else $signalQuality = 'moderate';

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

$topPicks = [];
foreach ($analyzed as $a) {
    foreach ($a['signals'] as $s) {
        $mkt = $s['market'];
        $vs = $s['verification_score'];
        $est = estimateOdds($s, $a['match']);
        if (!isset($topPicks[$mkt]) || $vs > ($topPicks[$mkt]['score'] ?? 0)) {
            $topPicks[$mkt] = ['match' => $a['match'], 'signal' => $s, 'score' => $vs, 'estimated_odds' => $est > 0 ? $est : null];
        }
    }
}

// Build ACCA candidates (one signal per match, best cross-verified pick)
$accaSignals = [];
$seenAcca = [];
foreach ($analyzed as $a) {
    $matchName = $a['match']['match_name'];
    if (isset($seenAcca[$matchName])) continue;
    $matchTime = $a['match']['match_time'] ?? '';
    if ($matchTime && $matchTime !== 'TBD') {
        $t = explode(':', str_replace(' (GMT+3)', '', $matchTime));
        $kickoffMin = (int)($t[0] ?? 0) * 60 + (int)($t[1] ?? 0);
        $nowMin = (int)date('H') * 60 + (int)date('i');
        if ($kickoffMin + 105 < $nowMin) continue;
    }
    $best = null;
    $bestScore = 0;
    foreach ($a['signals'] as $s) {
        $vs = $s['verification_score'];
        if ($vs < 55) continue;
        $est = estimateOdds($s, $a['match']);
        if ($est <= 0) continue;
        if ($vs > $bestScore) {
            $best = $s;
            $bestScore = $vs;
            $best['estimated_odds'] = $est;
        }
    }
    if ($best) {
        $best['match_name'] = $matchName;
        $accaSignals[] = $best;
        $seenAcca[$matchName] = true;
    }
}
usort($accaSignals, fn($a, $b) => $b['verification_score'] <=> $a['verification_score']);

// Build tiered ACCA combos
$accaTargets = [2, 3, 5, 10, 20];
$accaCombos = [];
foreach ($accaTargets as $target) {
    $combo = []; $cumOdds = 1.0;
    foreach ($accaSignals as $s) {
        $nxt = $cumOdds * $s['estimated_odds'];
        if ($nxt <= $target + 0.3 && $nxt >= $cumOdds + 0.01) {
            $combo[] = $s; $cumOdds = $nxt;
        }
        if ($cumOdds >= $target * 0.85 && count($combo) >= 2) break;
    }
    if (count($combo) >= 2 && $cumOdds >= $target * 0.75) {
        $accaCombos[] = ['target' => $target, 'odds' => round($cumOdds, 2), 'picks' => $combo];
    }
}

// Auto-save top cross-verified picks to admin_featured_picks (append-only)
$autoAdminId = 0;
$pickInserted = 0;
$savedSignals = [];
try {
    $checkPick = $db->prepare("SELECT COUNT(*) FROM admin_featured_picks WHERE match_name = ? AND pick_value = ? AND admin_id = ? AND DATE(created_at) = CURDATE()");
} catch (Exception $e) {
    $checkPick = null;
}
foreach ($analyzed as $a) {
    foreach ($a['signals'] as $s) {
        if ($s['verification_score'] < 55) continue;
        $key = $a['match']['match_name'] . '||' . $s['market'] . '||' . $s['pick'];
        if (isset($savedSignals[$key])) continue;
        $savedSignals[$key] = true;
        $est = estimateOdds($s, $a['match']);
        $pickVal = in_array($s['market'], ['Over 1.5 Goals', 'Under 3.5 Goals']) ? $s['market'] : $s['pick'];
        try {
            if ($checkPick) {
                $checkPick->bindValue(1, $a['match']['match_name']);
                $checkPick->bindValue(2, $pickVal);
                $checkPick->bindValue(3, $autoAdminId, PDO::PARAM_INT);
                $checkPick->execute();
                if ((int)$checkPick->fetchColumn() > 0) continue;
            }
            $stmt = $db->prepare("INSERT INTO admin_featured_picks (web_pick_id, match_name, pick_value, odds, league, match_time, admin_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bindValue(1, $a['match']['id'] ?? 0, PDO::PARAM_INT);
            $stmt->bindValue(2, $a['match']['match_name']);
            $stmt->bindValue(3, $pickVal);
            $stmt->bindValue(4, $est > 0 ? round($est, 2) : null);
            $stmt->bindValue(5, $a['match']['league'] ?? '');
            $rawMt = trim($a['match']['match_time'] ?? '');
            $mtVal = (!empty($rawMt) && !str_starts_with($rawMt, '0000') && strtolower($rawMt) !== 'tbd')
                ? date('Y-m-d') . ' ' . $rawMt . ':00' : '';
            $stmt->bindValue(6, $mtVal);
            $stmt->bindValue(7, $autoAdminId, PDO::PARAM_INT);
            $stmt->execute();
            $pickInserted++;
        } catch (Exception $e) {
            error_log("auto-save featured pick: " . $e->getMessage());
        }
    }
}

$pageTitle = 'Odds Movement Signals — PREDIXA';
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
.signal-card { background: linear-gradient(135deg, rgba(139,92,246,0.2) 0%, rgba(6,182,212,0.1) 100%); border: 1px solid rgba(139,92,246,0.3); border-radius: 12px; padding: 16px; margin-bottom: 12px; transition: all .2s; }
.signal-card:hover { border-color: var(--primary); transform: translateY(-1px); }
.signal-card .match-name { font-weight: 700; font-size: 1rem; }
.signal-card .match-league { font-size: 0.78rem; color: var(--text-muted); }
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
.empty-state { text-align: center; padding: 80px 20px; color: var(--text-muted); }
.empty-state i { font-size: 3rem; color: var(--primary); margin-bottom: 16px; }
.badge-market { font-size: 0.65rem; padding: 2px 8px; border-radius: 4px; font-weight: 600; background: rgba(139,92,246,0.15); color: var(--primary); }
#filterBtns button { cursor:pointer; transition:all .15s; }
#filterBtns button.active.high { border-color:#22C55E; background:rgba(34,197,94,0.08); }
#filterBtns button.active.medium { border-color:#FBBF24; background:rgba(251,191,36,0.08); }
#filterBtns button.active.low { border-color:var(--text-muted); background:rgba(148,163,184,0.06); }
#filterBtns button:not(.active) { opacity:0.4; }
.search-box { border-radius:10px; padding:8px 14px; transition:all .3s; }
.search-box:focus { outline:none; border-color:var(--primary) !important; box-shadow:0 0 0 3px rgba(139,92,246,0.15); }
.search-box::placeholder { color:var(--text-muted); font-size:0.82rem; }
</style>
</head>
<body>
<nav class="navbar navbar-expand navbar-dark fixed-top" style="background:rgba(15,17,21,0.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);padding:10px 0;">
    <div class="container">
        <a class="navbar-brand" href="./" style="font-weight:800;font-size:1.4rem;background:linear-gradient(135deg,var(--primary) 0%,var(--accent) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;text-decoration:none;"><i class="fas fa-futbol me-2" style="-webkit-text-fill-color:var(--accent);"></i>PREDIXA</a>
        <div class="d-flex align-items-center gap-2">
            <a href="dashboard" class="btn btn-sm" style="border:1px solid var(--border);color:var(--muted);padding:4px 14px;border-radius:6px;text-decoration:none;font-size:0.8rem;"><i class="fas fa-home me-1"></i>Dashboard</a>
            <a href="logout" class="btn btn-sm" style="border:1px solid var(--primary);color:var(--primary);padding:4px 14px;border-radius:6px;text-decoration:none;font-size:0.8rem;"><i class="fas fa-right-from-bracket me-1"></i>Logout</a>
        </div>
    </div>
</nav>

<div class="page-header">
    <div class="container">
        <h1><i class="fas fa-chart-line me-2" style="color:#EF4444;"></i>Decision Signals</h1>
        <p style="color:var(--text-muted);font-size:1rem;max-width:700px;">Admin odds movement signals — based on today's data · <?= date('jS F Y') ?> · EAT (GMT+3)</p>
    </div>
</div>

<div class="content-area">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <span style="display:inline-flex;align-items:center;gap:5px;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);padding:3px 12px;border-radius:20px;font-size:0.72rem;font-weight:700;color:#EF4444;text-transform:uppercase;letter-spacing:0.5px;"><i class="fas fa-shield-halved me-1"></i>Admin — Restricted Access</span>
        </div>
        <div class="d-flex gap-1 flex-wrap" id="filterBtns">
            <button class="signal-item high active" data-level="high" onclick="toggleFilter('high')"><i class="fas fa-circle me-1" style="font-size:0.5rem;"></i>Strong (≥75)</button>
            <button class="signal-item medium active" data-level="medium" onclick="toggleFilter('medium')"><i class="fas fa-circle me-1" style="font-size:0.5rem;"></i>Medium (55-74)</button>
            <button class="signal-item low active" data-level="low" onclick="toggleFilter('low')"><i class="fas fa-circle me-1" style="font-size:0.5rem;"></i>Weak (10-54)</button>
        </div>
    </div>

    <?php if (empty($analyzed)): ?>
    <div class="empty-state">
        <i class="fas fa-chart-line"></i>
        <h5>No Signals Today</h5>
        <p class="mb-0">No qualifying odds movement detected yet. Check back once matches appear on the <a href="dropping-odds" style="color:var(--accent);">Dropping Odds</a> page.</p>
    </div>
    <?php else: ?>
    <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);margin-bottom:6px;"><i class="fas fa-thumbs-up me-1" style="color:#22C55E;"></i>Recommended Picks</div>
    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
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
    <?php foreach ($analyzed as $a):
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
        $detectedTs = !empty($m['detected_at']) ? strtotime($m['detected_at']) : 0;
        $kickoffTs = $matchTime ? (function($t){$p=explode(':',$t);return (int)$p[0]*60+(int)($p[1]??0);})(str_replace(' (GMT+3)', '', $m['match_time'])) : 0;
        $conHome = trim((preg_match('/^(.+?)\s+vs\s+(.+?)$/i', $m['match_name'] ?? '', $cParts) ? $cParts[1] : ''));
        $conAway = trim($cParts[2] ?? '');
        $verified = ($movements && $conHome && $conAway) ? getMatchVerifiedAll($movements, $conHome, $conAway) : null;
        $isNoisy = isNoisy($m);
    ?>
    <div class="signal-card" data-match-name="<?= htmlspecialchars($m['match_name']) ?>" data-time="<?= $detectedTs ?>" data-kickoff="<?= $kickoffTs ?>" data-levels="<?php
        $lvls = [];
        foreach ($signals as $s) {
            $lvls[] = $s['confidence'] >= 70 ? 'high' : ($s['confidence'] >= 50 ? 'medium' : 'low');
        }
        echo implode(' ', array_unique($lvls));
    ?>">
        <div class="d-flex align-items-start flex-wrap gap-2 mb-2">
            <div class="flex-grow-1">
                <div class="match-name"><?= $matchName ?></div>
                <div class="match-league">League: <?= $league ?> <?= $matchTime ? '· Kick off: ' . $matchTime . ' (GMT+3)' : '' ?> <?= $detected ? '· Recent signal: ' . $detected . ' (GMT+3)' : '' ?></div>
            </div>
        </div>

        <?php
            $hArrow = $hDelta > 0 ? '↑' : ($hDelta < 0 ? '↓' : '–');
            $hColor = $hDelta > 0 ? '#22C55E' : ($hDelta < 0 ? '#EF4444' : '#FBBF24');
            $dArrow = $dDelta > 0 ? '↑' : ($dDelta < 0 ? '↓' : '–');
            $dColor = $dDelta > 0 ? '#22C55E' : ($dDelta < 0 ? '#EF4444' : '#FBBF24');
            $aArrow = $aDelta > 0 ? '↑' : ($aDelta < 0 ? '↓' : '–');
            $aColor = $aDelta > 0 ? '#22C55E' : ($aDelta < 0 ? '#EF4444' : '#FBBF24');
        ?>
        <div class="stats-row mb-2">
            <span class="stat"><span style="color:<?= $hColor ?>;"><?= $hArrow ?></span> H: <?= number_format(abs($hDelta), 1) ?>%</span>
            <span class="stat"><span style="color:<?= $dColor ?>;"><?= $dArrow ?></span> D: <?= number_format(abs($dDelta), 1) ?>%</span>
            <span class="stat"><span style="color:<?= $aColor ?>;"><?= $aArrow ?></span> A: <?= number_format(abs($aDelta), 1) ?>%</span>
            <?php if ($hOdds > 0): ?><span class="stat">@ <?= number_format($hOdds, 2) ?> / <?= number_format($dOdds, 2) ?> / <?= number_format($aOdds, 2) ?></span><?php endif; ?>
        </div>
        <?php $hasVer = $verified && count(array_filter($verified)) > 0; if ($hasVer): ?>
        <div style="display:flex;flex-wrap:wrap;gap:2px 8px;font-size:0.68rem;margin-bottom:6px;align-items:center;">
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
            <?php if ($isNoisy): ?>
            <span class="badge" style="background:#FEF3C7;color:#92400E;font-size:0.6rem;font-weight:700;padding:1px 6px;border-radius:3px;cursor:help;" title="Bookies disagree — unreliable. Avoid.">⚡ NOISY</span>
            <?php endif; ?>
        </div>
        <?php elseif ($isNoisy): ?>
        <div style="margin-bottom:6px;">
            <span class="badge" style="background:#FEF3C7;color:#92400E;font-size:0.6rem;font-weight:700;padding:1px 6px;border-radius:3px;cursor:help;" title="Bookies disagree — unreliable. Avoid.">⚡ NOISY</span>
        </div>
    <?php endif; ?>

        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($signals as $s):
                $vs = (int)($s['verification_score'] ?? $s['confidence']);
                $cls = $vs >= 75 ? 'high' : ($vs >= 55 ? 'medium' : 'low');
            ?>
            <div class="signal-badge d-flex align-items-center gap-2" data-level="<?= $cls ?>" style="background:rgba(255,255,255,0.03);border:1px solid var(--border-color);border-radius:8px;padding:6px 10px;">
                <span class="conf-ring <?= $cls ?>" title="Confidence: <?= $s['confidence'] ?>"><?= $vs ?></span>
                <div>
                    <div class="d-flex align-items-center gap-1">
                        <span class="badge-market"><?= htmlspecialchars($s['market']) ?></span>
                        <strong style="font-size:0.82rem;"><?= htmlspecialchars($s['pick']) ?></strong>
                        <?php if (isset($s['verified']) && !$isNoisy && ($s['verified']['strength'] ?? 0) >= 50): $vStr = (int)($s['verified']['strength'] ?? 0); $vCount = (int)($s['verified']['count'] ?? 0); $vTotal = (int)($s['verified']['total'] ?? 0); $vCol = $vStr >= 75 ? '#10B981' : '#FBBF24'; $vBg = $vStr >= 75 ? 'rgba(16,185,129,0.15)' : 'rgba(251,191,36,0.15)'; $vLabel = $vStr >= 75 ? 'Strong consensus' : 'Moderate agreement'; ?><span style="display:inline-flex;align-items:center;gap:3px;background:<?= $vBg ?>;color:<?= $vCol ?>;padding:1px 6px;border-radius:4px;font-size:0.6rem;font-weight:700;margin-left:4px;vertical-align:middle;cursor:help;" title="<?= $vLabel ?> — <?= $vStr ?>% across <?= $vCount ?>/<?= $vTotal ?> bookies<?= $vStr < 75 ? '. Use caution.' : '' ?>"><i class="fas fa-check-circle"></i> VERIFIED <?= $vStr ?>% (<?= $vCount ?>/<?= $vTotal ?>)</span><?php endif; ?>
                        <?php if (($s['is_plus_ev'] ?? false) && !$isNoisy): $bankerEv = $s['ev_value'] ?? null; if ($bankerEv !== null && $bankerEv >= 0.05): $bCol = '#06B6D4'; $bBg = 'rgba(6,182,212,0.3)'; ?><span style="display:inline-flex;align-items:center;gap:3px;background:<?= $bBg ?>;color:<?= $bCol ?>;padding:1px 5px;border-radius:3px;font-size:0.55rem;font-weight:700;vertical-align:middle;cursor:help;" title="+<?= round($bankerEv * 100) ?>% edge over true probability"><i class="fas fa-dollar-sign"></i> BANKER +<?= round($bankerEv * 100) ?>%</span><?php endif; endif; ?>
                    </div>
                    <div style="font-size:0.7rem;color:var(--text-muted);"><?= htmlspecialchars($s['reason']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($topPicks)):
        $marketOrder = ['1X2', 'Double Chance', 'Over 1.5 Goals', 'Under 3.5 Goals', 'GG (BTTS)', 'Team to Score'];
        $marketIcons = ['1X2' => 'fas fa-flag-checkered', 'Double Chance' => 'fas fa-shield-alt', 'Over 1.5 Goals' => 'fas fa-futbol', 'Under 3.5 Goals' => 'fas fa-futbol', 'GG (BTTS)' => 'fas fa-exchange-alt', 'Team to Score' => 'fas fa-bullseye'];
    ?>
    <?php if ($signalQuality === 'low'): ?>
    <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:8px 14px;font-size:0.78rem;color:#EF4444;margin-bottom:12px;"><i class="fas fa-triangle-exclamation me-1"></i>Low signal day — fewer matches qualify. Best Pick and ACCA builds may be limited or less reliable.</div>
    <?php elseif ($signalQuality === 'moderate'): ?>
    <div style="background:rgba(251,191,36,0.1);border:1px solid rgba(251,191,36,0.2);border-radius:8px;padding:8px 14px;font-size:0.78rem;color:#FBBF24;margin-bottom:12px;"><i class="fas fa-circle-exclamation me-1"></i>Moderate signal volume — Best Picks available, ACCA builds may be limited.</div>
    <?php endif; ?>
    <div class="mb-3">
        <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);margin-bottom:8px;"><i class="fas fa-trophy me-1" style="color:#FBBF24;"></i>Best Pick Per Market</div>
        <div class="d-flex flex-wrap gap-2">
        <?php foreach ($marketOrder as $mkt):
            if (!isset($topPicks[$mkt])) continue;
            $tp = $topPicks[$mkt]['signal'];
            $tm = $topPicks[$mkt]['match'];
            $tpHDelta = getHomeDelta($tm);
            $tpADelta = getAwayDelta($tm);
            $tpDDelta = (float)($tm['draw_delta'] ?? 0);
            $tpVs = (int)($topPicks[$mkt]['score'] ?? $tp['confidence']);
            $tpCls = $tpVs >= 75 ? 'high' : ($tpVs >= 55 ? 'medium' : 'low');
            $tpMatchTime = $tm['match_time'] && $tm['match_time'] !== 'TBD' ? htmlspecialchars($tm['match_time']) : '';
        ?>
            <div style="flex:1;min-width:200px;max-width:280px;background:linear-gradient(135deg, rgba(251,191,36,0.15) 0%, rgba(245,158,11,0.08) 100%);border:1px solid rgba(251,191,36,0.3);border-radius:10px;padding:10px 12px;">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="<?= $marketIcons[$mkt] ?? 'fas fa-chart-line' ?>" style="font-size:0.7rem;color:var(--accent);"></i>
                    <span style="font-size:0.7rem;font-weight:600;color:var(--accent);"><?= htmlspecialchars($mkt) ?></span>
                    <span class="conf-ring <?= $tpCls ?>" style="width:26px;height:26px;font-size:0.6rem;margin-left:auto;" title="Confidence: <?= $tp['confidence'] ?>"><?= $tpVs ?></span>
                </div>
                <div style="font-size:0.82rem;font-weight:600;line-height:1.2;"><?= htmlspecialchars($tm['match_name']) ?></div>
                <div style="font-size:0.72rem;color:var(--text-muted);"><?= htmlspecialchars($tp['pick']) ?> · <?= $tpMatchTime ?: 'TBD' ?></div>
                <?php if (!empty($topPicks[$mkt]['estimated_odds'])): ?>
                    <?php $tpOdds = $topPicks[$mkt]['estimated_odds']; $tpSweet = $tp['is_plus_ev'] ?? ($tpOdds >= 1.36 && $tpOdds <= 1.70 && $tp['confidence'] >= 60); $tpEv = $tp['ev_value'] ?? null; ?>
                    <div style="font-size:0.65rem;color:#FBBF24;margin-top:1px;">~@ <?= number_format($topPicks[$mkt]['estimated_odds'], 2) ?><?php if ($tpEv !== null && $tpEv >= 0.05): $bCol = '#06B6D4'; $bBg = 'rgba(6,182,212,0.3)'; ?> <span class="badge" style="background:<?= $bBg ?>;color:<?= $bCol ?>;font-size:0.5rem;padding:1px 4px;border-radius:3px;cursor:help;vertical-align:middle;" title="+<?= round($tpEv * 100) ?>% edge over true probability"><i class="fas fa-dollar-sign"></i> BANKER +<?= round($tpEv * 100) ?>%</span><?php endif; ?></div>
                <?php endif; ?>
                <div style="font-size:0.7rem;color:var(--text-muted);margin-top:3px;"><?= htmlspecialchars($tp['reason']) ?></div>
                <div class="stats-row mt-1" style="font-size:0.65rem;">
                    <span class="stat" style="<?= $tpHDelta < 0 ? 'color:#EF4444;' : ($tpHDelta > 0 ? 'color:#22C55E;' : '') ?>">H <?= $tpHDelta < 0 ? '↓' : ($tpHDelta > 0 ? '↑' : '–') ?> <?= number_format(abs($tpHDelta), 1) ?>%</span>
                    <span class="stat" style="<?= $tpDDelta < 0 ? 'color:#EF4444;' : ($tpDDelta > 0 ? 'color:#22C55E;' : '') ?>">D <?= $tpDDelta < 0 ? '↓' : ($tpDDelta > 0 ? '↑' : '–') ?> <?= number_format(abs($tpDDelta), 1) ?>%</span>
                    <span class="stat" style="<?= $tpADelta < 0 ? 'color:#EF4444;' : ($tpADelta > 0 ? 'color:#22C55E;' : '') ?>">A <?= $tpADelta < 0 ? '↓' : ($tpADelta > 0 ? '↑' : '–') ?> <?= number_format(abs($tpADelta), 1) ?>%</span>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($bayesianPicks)): ?>
    <div class="mb-3">
        <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);margin-bottom:8px;"><i class="fas fa-robot me-1" style="color:#22C55E;"></i>Value Picks</div>
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

    <?php if (!empty($accaCombos)): ?>
    <div class="mb-3">
        <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);margin-bottom:8px;"><i class="fas fa-layer-group me-1" style="color:#FBBF24;"></i>Speculative ACCA Builds</div>
        <div class="d-flex flex-wrap gap-2">
        <?php foreach ($accaCombos as $cb): ?>
            <div style="flex:1;min-width:180px;max-width:240px;background:linear-gradient(135deg, rgba(139,92,246,0.15) 0%, rgba(6,182,212,0.08) 100%);border:1px solid rgba(139,92,246,0.3);border-radius:10px;padding:10px 12px;">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span style="font-size:0.7rem;font-weight:700;color:#FBBF24;">~<?= $cb['odds'] ?>x ACCA</span>
                    <span style="font-size:0.6rem;color:var(--text-muted);"><?= count($cb['picks']) ?> legs</span>
                </div>
                <?php foreach ($cb['picks'] as $pk): ?>
                <div style="font-size:0.7rem;padding:2px 0;border-top:1px solid rgba(255,255,255,0.04);">
                    <span style="color:var(--text-light);"><?= htmlspecialchars($pk['match_name']) ?></span>
                    <span style="color:var(--text-muted);float:right;">@ <?= number_format($pk['estimated_odds'], 2) ?></span>
                    <div style="color:var(--text-muted);font-size:0.65rem;"><?= htmlspecialchars($pk['market']) ?> · <?= htmlspecialchars($pk['pick']) ?> <span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;font-size:0.5rem;font-weight:800;background:<?= $pk['verification_score'] >= 75 ? 'rgba(34,197,94,0.15)' : ($pk['verification_score'] >= 55 ? 'rgba(251,191,36,0.15)' : 'rgba(148,163,184,0.1)') ?>;color:<?= $pk['verification_score'] >= 75 ? '#22C55E' : ($pk['verification_score'] >= 55 ? '#FBBF24' : 'var(--text-muted)') ?>;vertical-align:middle;cursor:help;" title="Cross-verify score: <?= $pk['verification_score'] ?? '?' ?>"><?= $pk['verification_score'] ?? '?' ?></span></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <div style="font-size:0.65rem;color:var(--text-muted);margin-top:6px;"><i class="fas fa-info-circle me-1"></i>Estimated odds based on 1X2/DC markets. Not all markets have available odds — unpriceable signals omitted.</div>
    </div>
    <?php endif; ?>

    <div class="mt-4 p-3" style="background:rgba(22,27,34,0.55);border:1px solid var(--border-color);border-radius:8px;">
        <h6 style="font-weight:700;color:var(--accent);"><i class="fas fa-info-circle me-1"></i>How Signals Work</h6>
        <ol style="font-size:0.8rem;color:var(--text-muted);padding-left:1.2rem;">
            <li><strong style="color:var(--text-light);">1X2</strong> — Back the outcome whose odds are dropping most, confirming smart money direction</li>
            <li><strong style="color:var(--text-light);">Double Chance</strong> — When one side drops, backing that side + draw gives safer coverage</li>
            <li><strong style="color:var(--text-light);">Over 1.5 Goals</strong> — High volatility across all three outcomes suggests open play and goals</li>
            <li><strong style="color:var(--text-light);">GG (BTTS)</strong> — Both teams odds dropping indicates both are expected to score</li>
            <li><strong style="color:var(--text-light);">Team to Score</strong> — A team whose odds are dropping is expected to find the net</li>
        </ol>
    </div>
</div>
</div>
<script>
function toggleFilter(level) {
    let btn = document.querySelector('#filterBtns button[data-level="'+level+'"]');
    btn.classList.toggle('active');
    document.getElementById('searchInput')?.dispatchEvent(new Event('input'));
}
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
        var activeBtns = document.querySelectorAll('#filterBtns button.active');
        var hasLevelFilter = activeBtns.length > 0 && activeBtns.length < 3;
        var levels = new Set();
        activeBtns.forEach(function(b) { levels.add(b.dataset.level); });
        var cards = document.querySelectorAll('.signal-card');
        var visible = 0;
        cards.forEach(function(c) {
            var name = (c.getAttribute('data-match-name') || '').toLowerCase();
            var matchSearch = !q || name.includes(q);
            var cardLevels = c.dataset.levels.split(' ');
            var matchLevel = !hasLevelFilter || cardLevels.some(function(l) { return levels.has(l); });
            c.style.display = (matchSearch && matchLevel) ? '' : 'none';
            if (matchSearch && matchLevel) visible++;
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
