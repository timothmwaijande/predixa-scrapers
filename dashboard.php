<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'config.php';
require_once 'auth.php';
require_once __DIR__ . '/includes/signals_engine.php';

function getBankerEVValue($pick) {
    $odds = (float)($pick['actual_odds'] ?? $pick['odds'] ?? 0);
    $pv = $pick['pick_value'] ?? '';
    if ($odds <= 0) return null;
    $h = (float)($pick['home_odds'] ?? 0); $d = (float)($pick['draw_odds'] ?? 0); $a = (float)($pick['away_odds'] ?? 0);
    $tp = stripMarginThreeWay($h, $d, $a);
    if (!$tp) return null;
    if (str_contains($pv, '(1)') || str_contains($pv, '(X)') || str_contains($pv, '(2)')) {
        if (str_contains($pv, '(1X)')) return calculateEV($tp['home'] + $tp['draw'], $odds);
        if (str_contains($pv, '(12)')) return calculateEV($tp['home'] + $tp['away'], $odds);
        if (str_contains($pv, '(X2)')) return calculateEV($tp['draw'] + $tp['away'], $odds);
        $k = str_contains($pv, '(1)') ? 'home' : (str_contains($pv, '(X)') ? 'draw' : 'away');
        return calculateEV($tp[$k], $odds);
    }
    if (str_contains($pv, '(1X)')) return calculateEV($tp['home'] + $tp['draw'], $odds);
    if (str_contains($pv, '(12)')) return calculateEV($tp['home'] + $tp['away'], $odds);
    if (str_contains($pv, '(X2)')) return calculateEV($tp['draw'] + $tp['away'], $odds);
    return null;
}

function getBestVerifiedStrength($verifiedAll) {
    $best = ['strength' => 0, 'count' => 0, 'total' => 0];
    if ($verifiedAll) {
        foreach ($verifiedAll as $vv) {
            if ($vv && ($vv['strength'] ?? 0) > $best['strength']) {
                $best = ['strength' => (int)($vv['strength'] ?? 0), 'count' => (int)($vv['count'] ?? 0), 'total' => (int)($vv['total'] ?? 0)];
            }
        }
    }
    return $best;
}

$db = getDB();
logPageVisit('dashboard.php');
requireLogin();

$user = getCurrentUser();
if (!$user) {
    session_destroy();
    header("Location: login");
    exit;
}
$rolloverPicks = fetchPicks('rollover');
$parlayPicks = fetchPicks('parlay');

$over15Picks = deduplicatePicks(fetchPicks('over15'));
$under25Picks = deduplicatePicks(fetchPicks('under_25'));
$uniqueParlayPicks = deduplicatePicks($parlayPicks);
$uniqueRolloverPicks = deduplicatePicks($rolloverPicks);

$hasParlay = false;
$hasRollover = false;
$trialActive = false;
$trialDays = 0;
$parlayDays = 0;
$rolloverDays = 0;
$computedParlayExpiry = null;
$computedRolloverExpiry = null;

if ($user['id'] == 1) {
    $hasParlay = true;
    $hasRollover = true;
} else {
    $premium = getPremiumStatus();
    $hasParlay = $premium['parlay'];
    $hasRollover = $premium['rollover'];
    $trialActive = $premium['trial'];
    $trialDays = $premium['trial_days'];
    $parlayDays = $premium['parlay_days'];
    $rolloverDays = $premium['rollover_days'];

    if (!$hasParlay || !$hasRollover) {
        $db = getDB();
        if ($db) {
            $stmt = $db->prepare("SELECT tier, duration, verified_at FROM payment_verifications WHERE user_id = ? AND status = 'approved' ORDER BY verified_at DESC");
            $stmt->execute([$user['id']]);
            $approvedPayments = $stmt->fetchAll();
            $durMap = ['daily' => 1, 'biweekly' => 14, 'monthly' => 30];
            foreach ($approvedPayments as $p) {
                $dur = $p['duration'] ?? 'monthly';
                $days = $durMap[$dur] ?? 30;
                $expiryDate = new DateTime($p['verified_at']);
                $expiryDate->modify('+' . $days . ' days');
                $now = new DateTime();
                if ($now < $expiryDate) {
                    if ($p['tier'] === 'rollover' || $p['tier'] === 'both') {
                        $hasRollover = true;
                        $computedRolloverExpiry = $expiryDate;
                        $rolloverDays = floor(($expiryDate->getTimestamp() - $now->getTimestamp()) / 86400);
                    }
                    if ($p['tier'] === 'parlay' || $p['tier'] === 'both') {
                        $hasParlay = true;
                        $computedParlayExpiry = $expiryDate;
                        $parlayDays = floor(($expiryDate->getTimestamp() - $now->getTimestamp()) / 86400);
                    }
                }
            }
        }
    }
}

$hasMostCorners = $hasRollover;
$hasTopPicks = $hasRollover && $hasParlay;

$mostCornersPicks = deduplicatePicks(fetchPicks('most_corners'));

// Banker of the Day: Bayesian picks (same logic as best-picks-view.php)
$topPicks = [];
try {
    require_once __DIR__ . '/classes/BayesianModel.php';
    $bmBanker = new BayesianModel();
    $dbBanker = getDB();
    if ($dbBanker) {
        // Today's source matches (identical to best-picks-view)
        $todaySourcesBanker = [];
        $wpB = $dbBanker->query("SELECT DISTINCT match_name, match_time FROM web_picks WHERE DATE(detected_at) = CURDATE()")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($wpB as $r) { $todaySourcesBanker[$r['match_name']] = $r['match_time'] ?: ''; }
        $srB = $dbBanker->query("SELECT DISTINCT match_name, match_time FROM scraper_results WHERE DATE(detected_at) = CURDATE()")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($srB as $r) { if (!isset($todaySourcesBanker[$r['match_name']])) $todaySourcesBanker[$r['match_name']] = $r['match_time'] ?: ''; }
        $afpB = $dbBanker->query("SELECT DISTINCT match_name, match_time FROM admin_featured_picks WHERE DATE(created_at) = CURDATE()")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($afpB as $r) { if (!isset($todaySourcesBanker[$r['match_name']])) $todaySourcesBanker[$r['match_name']] = $r['match_time'] ?: ''; }

        $normBanker = function($n) { return trim(preg_replace('/\s+(if|fk|sk|fc|sc|cf|ac|as)$/i', '', preg_replace('/^(if|fk|sk|fc|sc|cf|ac|as)\s+/i', '', strtolower(trim($n))))); };
        $resultedBanker = [];
        $mrB = $dbBanker->query("SELECT home_team, away_team FROM match_results WHERE match_date <= CURDATE()")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mrB as $r) { $resultedBanker[$normBanker($r['home_team']) . '|' . $normBanker($r['away_team'])] = true; }
        $yestPairBanker = [];
        $ypB = $dbBanker->query("SELECT home_team, away_team FROM bayesian_predictions WHERE match_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ypB as $r) { $yestPairBanker[$normBanker($r['home_team']) . '|' . $normBanker($r['away_team'])] = true; }

        $bankerPicks = $dbBanker->query("
            SELECT bp.match_name, bp.league, bp.confidence, bp.recommended_pick,
                   bp.value_pick, bp.home_team, bp.away_team, bp.match_date,
                   bp.market_odds_1, bp.market_odds_x, bp.market_odds_2
            FROM bayesian_predictions bp
            WHERE bp.match_date IN (CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY))
              AND bp.recommended_pick IS NOT NULL AND bp.recommended_pick != ''
            ORDER BY bp.confidence DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $nowBanker = new DateTime();
        $bankerCandidates = [];
        foreach ($bankerPicks as $bp) {
            if (!isset($todaySourcesBanker[$bp['match_name']])) continue;
            $normKey = $normBanker($bp['home_team']) . '|' . $normBanker($bp['away_team']);
            if (isset($resultedBanker[$normKey])) continue;
            if (isset($yestPairBanker[$normKey])) continue;
            $matchTime = $todaySourcesBanker[$bp['match_name']];
            if ($matchTime) {
                try {
                    $ko = new DateTime($matchTime);
                    $minsPast = ($nowBanker->getTimestamp() - $ko->getTimestamp()) / 60;
                    if ($minsPast > 0) continue;
                } catch (Exception $e) {}
            }
            $recs = explode(',', $bp['recommended_pick']);
            foreach ($recs as $rec) {
                $rec = trim($rec);
                $parts = explode(':', $rec);
                if (count($parts) !== 2) continue;
                $market = trim($parts[0]);
                $prob = (float)trim($parts[1]);
                if ($prob < 70) continue;
                $bestOdds = 0;
                $mv = strtoupper($market);
                if ($mv === '1') $bestOdds = (float)($bp['market_odds_1'] ?? 0);
                elseif ($mv === 'X') $bestOdds = (float)($bp['market_odds_x'] ?? 0);
                elseif ($mv === '2') $bestOdds = (float)($bp['market_odds_2'] ?? 0);
                $bankerCandidates[] = [
                    'match_name' => $bp['match_name'],
                    'home_team' => $bp['home_team'],
                    'away_team' => $bp['away_team'],
                    'league' => $bp['league'] ?? '',
                    'confidence' => $prob,
                    'pick_value' => $market,
                    'probability' => $prob,
                    'best_odds' => $bestOdds,
                ];
            }
        }
        usort($bankerCandidates, fn($a, $b) => $b['probability'] <=> $a['probability']);
        $seenBanker = [];
        foreach ($bankerCandidates as $bc) {
            $key = $bc['match_name'] . '|' . $bc['pick_value'];
            if (isset($seenBanker[$key])) continue;
            $seenBanker[$key] = true;
            $hasOdds = $bc['best_odds'] > 0;
            $ev = $hasOdds ? round($bc['probability'] / (1/$bc['best_odds']), 2) : 0;
            $topPicks[] = [
                'match_name' => $bc['match_name'],
                'pick_value' => $bc['pick_value'],
                'actual_odds' => $bc['best_odds'],
                'odds' => $bc['best_odds'],
                'pattern_badge' => ($hasOdds && $ev > 1) ? 'VALUE' : '',
                'match_time' => '',
                'league' => $bc['league'],
                'win_rate_low' => 0,
                'details' => '',
                'home_odds' => 0,
                'draw_odds' => 0,
                'away_odds' => 0,
                'safety_notes' => '',
                'risk_tier' => '',
                'web_pick_id' => 0,
                'banker_probability' => $bc['probability'],
                'banker_ev' => $ev,
                'banker_is_value' => $hasOdds && $ev > 1,
                'banker_data_conf' => $bc['confidence'],
            ];
        }
    }
} catch (Exception $e) {
    $topPicks = getAdminTopPicks();
}

// Betting code marketplace
$availableCodes = [];
$userCodes = [];
$purchasedCodes = [];
$userCommission = ['total_earned' => 0, 'withdrawn' => 0, 'balance' => 0];
try {
    $availableCodes = getAvailableCodes($user['id']);
    $userCodes = getUserCodes($user['id']);
    $purchasedCodes = getUserPurchasedCodes($user['id']);
    $userCommission = getUserCommission($user['id']);
    $totalCodesSold = 0;
    foreach ($userCodes as $uc) { $totalCodesSold += (int)($uc['sales_count'] ?? 0); }
} catch (Exception $e) {}

$hasCodes = count($userCodes) > 0 || count($purchasedCodes) > 0;
$publisherCredits = getPublisherCredits($user['id']);
$publisherRankings = getPublisherRankings(5);
$approvedSlips = getApprovedSlips(5);
$userSlips = getUserSlips($user['id']);
$sellerPendingPurchases = getSellerPendingPurchases($user['id']);
$buyerPendingPurchases = getBuyerPendingPurchases($user['id']);

// Handle winning slip upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_slip'])) {
    $dispName = trim($_POST['slip_display_name'] ?? '');
    $desc = trim($_POST['slip_description'] ?? '');
    if (isset($_FILES['slip_image']) && $_FILES['slip_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['slip_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp'])) {
            $fname = 'slip_' . $user['id'] . '_' . time() . '.' . $ext;
            $dest = __DIR__ . '/uploads/slips/' . $fname;
            if (move_uploaded_file($_FILES['slip_image']['tmp_name'], $dest)) {
                uploadWinningSlip($user['id'], $dispName ?: null, 'uploads/slips/' . $fname, $desc ?: null);
                $success = 'Slip submitted for review / Slip imetumwa kwa ukaguzi';
            } else { $error = 'Upload failed / Kupakia kumeshindwa'; }
        } else { $error = 'Invalid file type. Use jpg/png/webp / Aina ya faili si sahihi'; }
    } else { $error = 'Select an image / Chagua picha'; }
}

// Handle credit purchase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'buy_credits') {
    $credits = max(1, (int)($_POST['credits'] ?? 1));
    $amount = $credits * PUBLISHER_CREDIT_COST;
    $reference = trim($_POST['reference_number'] ?? '');
    if ($reference) {
        $result = submitCreditPurchase($user['id'], $credits, $amount, $reference);
        if ($result['success']) {
            $_SESSION['flash_success'] = $result['message'];
        } else {
            $_SESSION['flash_error'] = $result['message'];
        }
    } else {
        $_SESSION['flash_error'] = 'Please provide a payment reference number';
    }
    header("Location: dashboard");
    exit;
}

// Handle betting code creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_code') {
    $code = trim($_POST['code'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $matches = trim($_POST['matches'] ?? '');
    $odds = trim($_POST['odds'] ?? '');
    $kickoffTime = trim($_POST['kickoff_time'] ?? '');
    $lastMatchKickoff = trim($_POST['last_match_kickoff'] ?? '');
    $badge = trim($_POST['badge'] ?? '');
    if ($code && $desc && $matches) {
        $result = createBettingCode($user['id'], $code, $desc, $matches, $odds ?: null, $kickoffTime ?: null, $badge ?: null, $lastMatchKickoff ?: null);
        if ($result['success']) {
            $_SESSION['flash_success'] = 'Betting code published!';
        } else {
            $_SESSION['flash_error'] = $result['message'];
        }
    } else {
        $_SESSION['flash_error'] = 'Please fill all required fields';
    }
    header("Location: dashboard");
    exit;
}

// Handle code purchase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'buy_code') {
    $codeId = (int)($_POST['code_id'] ?? 0);
    $reference = trim($_POST['reference_number'] ?? '');
    if ($codeId && $reference) {
        $result = submitCodePayment($codeId, $user['id'], $reference, $user['phone']);
        if ($result['success']) {
            $_SESSION['flash_success'] = $result['message'];
        } else {
            $_SESSION['flash_error'] = $result['message'];
        }
    } else {
        $_SESSION['flash_error'] = 'Please provide a payment reference number';
    }
    header("Location: dashboard");
    exit;
}

// Handle seller code approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'seller_approve_code' && isset($_POST['purchase_id'])) {
        $result = sellerApproveCodePurchase((int)$_POST['purchase_id'], $user['id']);
        if ($result['success']) { $_SESSION['flash_success'] = $result['message']; }
        else { $_SESSION['flash_error'] = $result['message']; }
        header("Location: dashboard");
        exit;
    }
    if ($_POST['action'] === 'seller_reject_code' && isset($_POST['purchase_id'])) {
        $reason = trim($_POST['rejection_reason'] ?? 'Wrong reference number');
        $result = rejectCodePurchase((int)$_POST['purchase_id'], $reason);
        if ($result['success']) { $_SESSION['flash_success'] = $result['message']; }
        else { $_SESSION['flash_error'] = $result['message']; }
        header("Location: dashboard");
        exit;
    }
    if ($_POST['action'] === 'rate_seller' && isset($_POST['seller_id']) && isset($_POST['rating'])) {
        $result = rateSeller((int)$_POST['seller_id'], $user['id'], (int)$_POST['rating']);
        if ($result['success']) { $_SESSION['flash_success'] = $result['message']; }
        else { $_SESSION['flash_error'] = $result['message']; }
        header("Location: dashboard");
        exit;
    }
}

// Handle aviator access purchase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'buy_aviator') {
    $reference = trim($_POST['reference_number'] ?? '');
    if ($reference) {
        $result = submitAviatorPurchase($user['id'], $reference);
        if ($result['success']) {
            $_SESSION['flash_success'] = $result['message'];
        } else {
            $_SESSION['flash_error'] = $result['message'];
        }
    } else {
        $_SESSION['flash_error'] = 'Please provide a payment reference number';
    }
    header("Location: dashboard");
    exit;
}

$defaultTab = 'subscribe';
if ($hasTopPicks && count($topPicks) > 0 && strlen($topPicks[0]['pick_value'] ?? '') > 0) $defaultTab = 'featured';
elseif ($hasRollover) $defaultTab = 'rollover';
elseif ($hasParlay) $defaultTab = 'parlay';
// Allow URL tab override (pagination, search across tabs)
if (!empty($_GET['tab'])) $defaultTab = $_GET['tab'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>Predixa Dashboard | Premium Football Analytics</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root { --primary: #06B6D4; --primary-dark: #0891B2; --primary-light: #22D3EE; --secondary: #0EA5E9; --accent: #8B5CF6; --bg-soft: #FAFAFA; --bg-white: #FFFFFF; --text-dark: #1F2937; --text-muted: #6B7280; --border-color: #E5E7EB; --shadow: 0 1px 3px rgba(0,0,0,0.1); --shadow-lg: 0 10px 25px rgba(0,0,0,0.1); }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Inter', sans-serif; background: var(--bg-soft); color: var(--text-dark); min-height: 100vh; display: flex; flex-direction: column; line-height: 1.6; }
.header { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; padding: 1rem 0; box-shadow: var(--shadow); position: sticky; top: 0; z-index: 1000; }
.header-content { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; display: flex; justify-content: space-between; align-items: center; }
.brand { font-size: 1.5rem; font-weight: 800; display: flex; align-items: center; gap: 0.5rem; }
.header-actions { display: flex; gap: 1rem; align-items: center; }
.badge-trial { background: #FBBF24; color: #000; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
.badge-parlay { background: #F59E0B; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
.badge-rollover { background: #06B6D4; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
.btn-logout { background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 0.5rem 1rem; border-radius: 8px; font-weight: 600; text-decoration: none; transition: all 0.3s; }
.btn-logout:hover { background: rgba(255,255,255,0.3); color: white; text-decoration: none; }
.header-link { color: white; font-weight: 500; text-decoration: none; font-size: 0.85rem; padding: 0.25rem 0; transition: opacity 0.2s; white-space: nowrap; }
.header-link:hover { opacity: 0.8; color: white; text-decoration: none; }
.header-link.dropdown-toggle { background: none; border: none; padding-right: 1.2rem; }
.header-link.dropdown-toggle::after { color: rgba(255,255,255,0.7); }
.header-actions .dropdown-item:hover { background: rgba(255,255,255,0.15); color: white !important; }
.header-badge { display: inline-block; font-size: 0.7rem; font-weight: 600; padding: 0.15rem 0.5rem; border-radius: 4px; margin-left: 0.25rem; }
.main-content { flex: 1; max-width: 1200px; margin: 0 auto; padding: 2rem 1.5rem; width: 100%; }
.trial-banner { background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%); color: #000; padding: 1rem 1.5rem; border-radius: 12px; margin-bottom: 2rem; font-weight: 600; box-shadow: var(--shadow); }
.trial-banner a { color: #000; text-decoration: underline; font-weight: 700; }
.nav-container { background: var(--bg-white); border-radius: 12px; padding: 0.5rem; margin-bottom: 2rem; box-shadow: var(--shadow); }
.nav-pills { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.nav-link { flex: 1; padding: 0.875rem 1rem; border-radius: 8px; border: none; background: transparent; color: var(--text-muted); font-weight: 600; cursor: pointer; transition: all 0.3s; text-align: center; font-size: 0.85rem; }
.nav-link:hover:not(.disabled) { background: var(--bg-soft); color: var(--primary); }
.nav-link.active { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%) !important; color: white !important; box-shadow: var(--shadow); }
.nav-link.disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
.nav-link .badge-count { background: linear-gradient(135deg, #F59E0B, #D97706); color: white; margin-left: 0.3rem; padding: 0.1rem 0.4rem; border-radius: 10px; font-size: 0.65rem; font-weight: 700; }
.card { background: var(--bg-white); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: var(--shadow); }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid var(--border-color); }
.card-title { font-size: 1.25rem; font-weight: 700; color: var(--text-dark); margin: 0; }
.pick-card { background: var(--bg-soft); border: 1px solid var(--border-color); border-radius: 10px; padding: 1.25rem; margin-bottom: 1rem; transition: all 0.3s; }
.pick-card:hover { box-shadow: var(--shadow-lg); transform: translateY(-2px); }
.pick-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
.pick-match { font-weight: 700; color: var(--text-dark); font-size: 1.1rem; }
.pick-odds { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 800; font-size: 1.1rem; }
.pick-value { color: var(--primary-dark); font-weight: 600; margin: 0.5rem 0; }
.pick-meta { display: flex; gap: 1rem; font-size: 0.875rem; color: var(--text-muted); flex-wrap: wrap; }
.locked-card { text-align: center; padding: 3rem 2rem; }
.locked-card .lock-icon { font-size: 3rem; margin-bottom: 1rem; }
.locked-card .lock-title { font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem; }
.btn-premium { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; border: none; padding: 0.75rem 2rem; border-radius: 8px; font-weight: 700; text-decoration: none; display: inline-block; transition: all 0.3s; box-shadow: var(--shadow); }
        .btn-premium:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); color: white; text-decoration: none; }
        .pricing-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-top: 2rem; }
.pricing-card { background: var(--bg-white); border: 2px solid var(--border-color); border-radius: 16px; padding: 2rem; text-align: center; transition: all 0.3s; }
.pricing-card:hover { border-color: var(--primary); box-shadow: var(--shadow-lg); transform: translateY(-4px); }
.pricing-card.popular { border-color: var(--primary); position: relative; }
.pricing-badge { position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; padding: 0.25rem 1rem; border-radius: 20px; font-size: 0.75rem; font-weight: 700; }
.pricing-icon { font-size: 3rem; margin-bottom: 1rem; }
.pricing-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem; }
.pricing-price { font-size: 2.5rem; font-weight: 800; color: var(--primary); margin: 1rem 0; }
.pricing-price small { font-size: 1rem; color: var(--text-muted); font-weight: 400; }
.pricing-features { list-style: none; padding: 0; margin: 1.5rem 0; text-align: left; }
.pricing-features li { padding: 0.5rem 0; border-bottom: 1px solid var(--border-color); }
.pricing-features li:last-child { border-bottom: none; }
.footer { background: var(--bg-white); border-top: 2px solid var(--border-color); padding: 2rem 0; margin-top: auto; }
.footer-content { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; text-align: center; }
.responsible-gambling { background: linear-gradient(135deg, #FEE2E2 0%, #FECACA 100%); border-left: 4px solid #EF4444; padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; }
.responsible-gambling h5 { color: #DC2626; font-weight: 700; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; }
.responsible-gambling p { color: #7F1D1D; margin: 0; font-size: 0.875rem; line-height: 1.6; }
.footer-links { display: flex; justify-content: center; gap: 2rem; margin-bottom: 1rem; flex-wrap: wrap; }
.footer-links a { color: var(--text-muted); text-decoration: none; font-weight: 500; transition: color 0.3s; }
.footer-links a:hover { color: var(--primary); }
.footer-copy { color: var(--text-muted); font-size: 0.875rem; margin: 0; }
.alert { border-radius: 12px; border: none; padding: 1rem 1.5rem; margin-bottom: 1.5rem; }
.alert-info { background: #E0F2FE; color: #0369A1; }
.alert-warning { background: #FEF3C7; color: #B45309; }
.alert-success { background: #D1FAE5; color: #047857; }
.hamburger-btn { display: none; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 0.5rem 0.8rem; border-radius: 8px; font-size: 1.5rem; cursor: pointer; transition: all 0.3s; }
.hamburger-btn:hover { background: rgba(255,255,255,0.3); }
@media (max-width: 768px) { .hamburger-btn { display: block; } .header-content { flex-direction: row; justify-content: space-between; text-align: left; } .header-actions { display: none; position: absolute; top: 100%; left: 0; right: 0; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); flex-direction: column; gap: 0.75rem; padding: 1rem 1.5rem; box-shadow: var(--shadow-lg); z-index: 999; } .header-actions.active { display: flex; } .header-actions .badge-trial, .header-actions .badge-parlay, .header-actions .badge-rollover { align-self: flex-start; } .header-actions .btn-logout { width: 100%; text-align: center; } .header-actions .dropdown-menu { position: static !important; background: transparent !important; border: none !important; padding: 0 0 0 1rem !important; } .header-actions .dropdown-item { color: rgba(255,255,255,0.85) !important; font-size:0.85rem; padding:0.25rem 0 !important; } .header-actions .dropdown-item:hover { background: transparent !important; color: white !important; } .nav-pills { flex-direction: column; } .pricing-grid { grid-template-columns: 1fr; } .footer-links { flex-direction: column; gap: 0.5rem; } }
        /* Icon spacing utility */
        .icon-gap { margin-right: 0.5rem; } i.fas, i.far, i.fab { vertical-align: -0.125em; }
        /* PRO tab mobile responsive */
        @media (max-width: 600px) {
            #toppredictions .tp-row {
                flex-direction: column;
                align-items: stretch;
                gap: 2px;
                padding: 0.5rem;
            }
            #toppredictions .tp-row > div:first-child {
                flex: none;
                min-width: 0;
            }
            #toppredictions .tp-match {
                white-space: normal !important;
                font-size: 0.85rem !important;
                line-height: 1.4;
                overflow: visible !important;
                margin-bottom: 2px;
            }
            #toppredictions .tp-match span {
                display: block;
                font-size: 0.65rem !important;
                margin-top: 1px;
            }
            #toppredictions .tp-row > div:nth-child(2) {
                flex: none;
                min-width: 0;
                margin: 2px 0;
            }
            #toppredictions .tp-row > div:last-child {
                flex: none;
                text-align: left;
                min-width: unset;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            #toppredictions .tp-row > div:last-child > div {
                font-size: 0.55rem;
                line-height: 1;
            }
            #toppredictions .tp-row > div:last-child > span {
                font-size: 0.8rem !important;
            }
            #toppredictions .card-header {
                gap: 4px;
            }
            #toppredictions .card-header h5 {
                font-size: 0.9rem;
                width: 100%;
            }
            #toppredictions .card-header select,
            #toppredictions .card-header input {
                max-width: 110px !important;
                font-size: 0.6rem !important;
            }
            #toppredictions .card-header span {
                font-size: 0.55rem !important;
            }
        }
</style>
</head>
<body>
<header class="header">
<div class="header-content">
<div class="brand"><i class="fas fa-futbol me-2" style="color: #fff;"></i><span>PREDIXA</span></div>
<button class="hamburger-btn" id="hamburgerBtn" aria-label="Toggle Menu"><i class="fas fa-bars"></i></button>
<div class="header-actions" id="headerMenu">
<?php $userPerms = getAdminPermissions($user['id']); $showAdmin = $user['id'] == 1 || $premium['is_super_admin'] || $userPerms === '*' || (is_array($userPerms) && count($userPerms) > 0); ?>
<?php if ($trialActive): ?><span class="header-badge" style="background:#F59E0B;color:#1a1a2e;">Trial <?= $trialDays ?>d</span><?php endif; ?>
<?php if ($hasParlay && !$trialActive): ?><span class="header-badge" style="background:#10B981;color:white;">Parlay</span><?php endif; ?>
<?php if ($hasRollover): ?><span class="header-badge" style="background:#8B5CF6;color:white;">Rollover</span><?php endif; ?>
<?php if (!isSectionHidden('aviator')): ?>
<a href="aviator" class="header-link"><i class="fas fa-plane me-1" style="color:#F59E0B;"></i>Aviator</a>
<?php endif; ?>
<a href="dropping-odds" class="header-link"><i class="fas fa-arrow-down me-1" style="color:#EF4444;"></i>Dropping Odds</a>
<a href="h2h" class="header-link"><i class="fas fa-exchange-alt me-1" style="color:#aa004f;"></i>H2H</a>
<a href="track-record" class="header-link"><i class="fas fa-chart-line me-1" style="color:#FBBF24;"></i>Performance</a>
<a href="betting-school" class="header-link"><i class="fas fa-book-open me-1"></i>Betting School</a>
<div class="dropdown d-inline-block">
    <a class="header-link" href="pikka?post=1" style="cursor:pointer;"><i class="fas fa-pen me-1"></i>Post Free Tips</a>
</div>
<?php if ($showAdmin): ?><a href="admin.php" class="header-link"><i class="fas fa-shield-halved me-1"></i>Admin</a><?php endif; ?>
<a href="account" class="header-link"><i class="fas fa-user-circle me-1"></i>Account</a>
<a href="logout" class="header-link"><i class="fas fa-right-from-bracket me-1"></i>Logout</a>
</div>
</div>
</header>

<main class="main-content">
<?php if (!$user['email_verified']): ?>
<div class="alert alert-warning" style="background: #FEF3C7; border: 1px solid #F59E0B; color: #B45309; border-radius: 12px; padding: 1rem 1.5rem; margin-bottom: 1.5rem;">
<div class="d-flex align-items-center">
<div class="flex-grow-1"><strong><i class="fas fa-envelope me-1"></i>Email Not Verified</strong><p class="mb-0 small mt-1">We sent a verification link to <strong><?= htmlspecialchars($user['email']) ?></strong>. Please check your inbox (and spam folder). <a href="resend-verification" class="fw-bold text-decoration-underline">Resend Verification Email</a></p></div>
</div>
</div>
<?php endif; ?>

<?php if ($trialActive): ?>
<div class="trial-banner">You're on a 30-day FREE Parlay trial! <?= $trialDays ?> days remaining. <a href="subscribe">Subscribe to unlock Rollover →</a></div>
<?php endif; ?>

<?php if (($hasParlay || $hasRollover) && $user['id'] != 1):
    $pe = null; $re = null;
    if ($hasParlay) {
        if ($user['parlay_expiry']) $pe = new DateTime($user['parlay_expiry']);
        elseif ($computedParlayExpiry) $pe = $computedParlayExpiry;
    }
    if ($hasRollover) {
        if ($user['rollover_expiry']) $re = new DateTime($user['rollover_expiry']);
        elseif ($computedRolloverExpiry) $re = $computedRolloverExpiry;
    }
?>
<?php if ($pe || $re): ?>
<div style="background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; padding: 1rem 1.5rem; margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: center;">
    <?php if ($pe): $rem = max(0, $pe->getTimestamp()-time()); $h = floor($rem/3600); $pl = $h >= 24 ? $parlayDays.'d' : $h.'h'; ?>
    <div><small class="text-muted">Parlay expires</small><br><strong style="color: #1F2937;"><?= $pe->format('M d, H:i') ?> (<?= $pl ?> left)</strong></div>
    <?php endif; ?>
    <?php if ($re): $rem = max(0, $re->getTimestamp()-time()); $h = floor($rem/3600); $rl = $h >= 24 ? $rolloverDays.'d' : $h.'h'; ?>
    <div><small class="text-muted">Rollover expires</small><br><strong style="color: #1F2937;"><?= $re->format('M d, H:i') ?> (<?= $rl ?> left)</strong></div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="nav-container">
<div class="nav-pills" role="tablist">
    <button class="nav-link <?= $hasRollover ? ($defaultTab === 'rollover' ? 'active' : '') : 'disabled' ?>" data-bs-toggle="pill" data-bs-target="#rollover" type="button" <?= !$hasRollover ? 'disabled' : '' ?>><i class="fas fa-shield-halved me-2"></i>Rollover</button>
    <button class="nav-link <?= $hasParlay ? ($defaultTab === 'parlay' ? 'active' : '') : 'disabled' ?>" data-bs-toggle="pill" data-bs-target="#parlay" type="button" <?= !$hasParlay ? 'disabled' : '' ?>><i class="fas fa-bullseye me-2"></i>Parlay</button>
    <button class="nav-link <?= $hasRollover ? ($defaultTab === 'goals' ? 'active' : '') : 'disabled' ?>" data-bs-toggle="pill" data-bs-target="#goals" type="button" <?= !$hasRollover ? 'disabled' : '' ?>><i class="fas fa-futbol me-2"></i>Goals</button>
    <button class="nav-link <?= $hasMostCorners ? ($defaultTab === 'mostcorners' ? 'active' : '') : 'disabled' ?>" data-bs-toggle="pill" data-bs-target="#mostcorners" type="button" <?= !$hasMostCorners ? 'disabled' : '' ?>><i class="fas fa-vector-square me-2"></i>Corners</button>
    <button class="nav-link <?= $hasTopPicks ? ($defaultTab === 'featured' ? 'active' : '') : 'disabled' ?>" data-bs-toggle="pill" data-bs-target="#featured" type="button" <?= !$hasTopPicks ? 'disabled' : '' ?>><i class="fas fa-gem me-2"></i>Banker</button>
    <button class="nav-link <?= ($hasParlay && $hasRollover) ? ($defaultTab === 'toppredictions' ? 'active' : '') : 'disabled' ?>" data-bs-toggle="pill" data-bs-target="#toppredictions" type="button" <?= !($hasParlay && $hasRollover) ? 'disabled' : '' ?>><i class="fas fa-crown me-2"></i>PRO</button>
    <?php if (!isSectionHidden('betting_codes')): ?>
    <button class="nav-link <?= $defaultTab === 'codes' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#codes" type="button"><i class="fas fa-ticket me-2"></i>Codes</button>
    <?php endif; ?>
    <button class="nav-link <?= $defaultTab === 'subscribe' ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#subscribe" type="button"><i class="fas fa-tags me-2"></i>Subscribe</button>
</div>
</div>

<?php if (isset($_SESSION['flash_success'])): ?>
<div class="alert alert-success" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['flash_error'])): ?>
<div class="alert alert-danger" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>

<?php $pendingCount = count($sellerPendingPurchases); if ($pendingCount > 0): ?>
<div class="notif-bar" style="display:flex;gap:8px;flex-wrap:wrap;padding:12px 20px;margin-bottom:16px;background:#fff;border-radius:10px;border:1px solid var(--border-color);justify-content:center;">
    <span style="font-weight:600;color:var(--text-dark);font-size:0.85rem;display:flex;align-items:center;gap:4px;">
        <i class="fas fa-bell" style="color:#F59E0B;"></i> You have <strong style="color:#B45309;"><?= $pendingCount ?> pending approval<?= $pendingCount > 1 ? 's' : '' ?></strong>
    </span>
    <a href="#" onclick="event.preventDefault();var t=document.querySelector('[data-bs-target=&quot;#codes&quot;]');if(t){t.click();setTimeout(function(){var el=document.getElementById('pendingApprovalsSection');if(el)el.scrollIntoView({behavior:'smooth'});},300);}" style="display:inline-flex;align-items:center;gap:4px;background:#FEF3C7;color:#B45309;padding:4px 12px;border-radius:6px;text-decoration:none;font-size:0.8rem;font-weight:600;">
        <i class="fas fa-clock"></i> Review Now
    </a>
</div>
<?php endif; ?>





<div class="tab-content">

<?php
function renderPickCard($pick, $hasAccess, $lockTitle, $lockDesc, $lockTier, $pickLabel = null, $dateOnly = false, $showTz = true) {
    // Verified check
    $hasVerified = false;
    $verifiedAll = null;
    $mn = $pick['match_name'] ?? '';
    if ($mn) {
        $parts = explode(' vs ', $mn);
        if (count($parts) === 2) {
            $home = trim($parts[0]);
            $away = trim($parts[1]);
            $movements = getMultiBookieSheetData();
            if ($movements) {
                $verifiedAll = getMatchVerifiedAll($movements, $home, $away);
                $hasVerified = $verifiedAll && count(array_filter($verifiedAll)) > 0;
            }
        }
    }
    $isNoisy = isNoisy($pick);
    if (!$hasAccess): ?>
    <div class="pick-card locked-card">
        <div class="lock-icon"><i class="fas fa-lock fa-3x" style="color: var(--accent);"></i></div>
        <div class="lock-title"><?= htmlspecialchars($lockTitle) ?></div>
        <p class="text-muted mb-3"><?= htmlspecialchars($lockDesc) ?></p>
        <a href="subscribe?tier=<?= htmlspecialchars($lockTier) ?>" class="btn btn-premium">Unlock from <?= number_format(getPlanPrice($lockTier, 'daily')) ?> TZS/day</a>
    </div>
    <?php return;
    endif; ?>
    <div class="pick-card">
        <div class="pick-header">
            <div class="pick-match">
                <div><?= htmlspecialchars($pick['match_name'] ?? 'Unknown') ?></div>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;"><?= htmlspecialchars($pick['pick_value'] ?? '') ?> <?php if (str_contains($pick['pattern_badge'] ?? '', 'WIN 1UP')): ?><span style="display:inline-flex;align-items:center;gap:3px;background:rgba(251,191,36,0.15);color:#FBBF24;padding:1px 8px;border-radius:4px;font-size:0.65rem;font-weight:700;margin-left:4px;"><i class="fas fa-crown"></i> <?= htmlspecialchars($pick['pattern_badge']) ?></span><?php endif; ?><?php $bankerEv = getBankerEVValue($pick); $bv = getBestVerifiedStrength($verifiedAll); ?><?php if ($bankerEv !== null && $bankerEv >= 0.05 && !$isNoisy): $bCol = '#06B6D4'; $bBg = 'rgba(6,182,212,0.3)'; ?><span style="display:inline-flex;align-items:center;gap:3px;background:<?= $bBg ?>;color:<?= $bCol ?>;padding:1px 8px;border-radius:4px;font-size:0.65rem;font-weight:700;margin-left:4px;cursor:help;" title="+<?= round($bankerEv * 100) ?>% edge over true probability"><i class="fas fa-dollar-sign"></i> BANKER +<?= round($bankerEv * 100) ?>%</span><?php endif; ?><?php if ($hasVerified && !$isNoisy && $bv['strength'] >= 50): $vCol = $bv['strength'] >= 75 ? '#10B981' : '#FBBF24'; $vBg = $bv['strength'] >= 75 ? 'rgba(16,185,129,0.15)' : 'rgba(251,191,36,0.15)'; $vLabel = $bv['strength'] >= 75 ? 'Strong consensus' : 'Moderate agreement'; ?><span style="display:inline-flex;align-items:center;gap:3px;background:<?= $vBg ?>;color:<?= $vCol ?>;padding:1px 8px;border-radius:4px;font-size:0.65rem;font-weight:700;margin-left:4px;cursor:help;" title="<?= $vLabel ?> — <?= $bv['strength'] ?>% across <?= $bv['count'] ?>/<?= $bv['total'] ?> bookies<?= $bv['strength'] < 75 ? '. Use caution.' : '' ?>"><i class="fas fa-check-circle"></i> VERIFIED <?= $bv['strength'] ?>% (<?= $bv['count'] ?>/<?= $bv['total'] ?>)</span><?php endif; ?><?php if ($isNoisy): ?><span style="display:inline-flex;align-items:center;gap:3px;background:rgba(239,68,68,0.15);color:#EF4444;padding:1px 8px;border-radius:4px;font-size:0.65rem;font-weight:700;margin-left:4px;cursor:help;" title="Bookies disagree — unreliable. Avoid."><i class="fas fa-wave-square"></i> NOISY</span><?php endif; ?><?php $ixSites = $pick['intersection_sites'] ?? null; if ($ixSites && $ixSites >= 2): $ixCol = $ixSites >= 4 ? '#7C3AED' : ($ixSites >= 3 ? '#8B5CF6' : '#A78BFA'); $ixBg = $ixSites >= 4 ? 'rgba(124,58,237,0.25)' : ($ixSites >= 3 ? 'rgba(139,92,246,0.2)' : 'rgba(167,139,250,0.15)'); $ixLabel = $ixSites >= 4 ? 'Very strong — 4-5 of 5 sources agree' : ($ixSites >= 3 ? 'Strong — 3 of 5 sources agree' : 'Moderate — 2 of 5 sources agree'); ?><span style="display:inline-flex;align-items:center;gap:3px;background:<?= $ixBg ?>;color:<?= $ixCol ?>;padding:1px 8px;border-radius:4px;font-size:0.65rem;font-weight:700;margin-left:4px;cursor:help;" title="<?= $ixLabel ?>"><i class="fas fa-crosshairs"></i> <?= $ixSites ?>/5</span><?php endif; ?></div>
            <?php $conLabels = ['Home','Draw','Away','Over2.5','Under2.5','BTTS-Yes','BTTS-No'];
            $conKeys = ['1','X','2','Ov2.5','Und2.5','GG','NG'];
            $hasVer = $verifiedAll && count(array_filter($verifiedAll)) > 0; if ($hasVer): ?>
            <div style="display:flex;flex-wrap:wrap;gap:2px 8px;font-size:0.65rem;margin-top:4px;align-items:center;">
                <span style="color:#10B981;font-weight:600;"><i class="fas fa-check-circle me-1"></i></span>
                <?php foreach ($conKeys as $i => $ck):
                    $vv = $verifiedAll[$ck] ?? null;
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
            <div style="margin-top:4px;">
                <span class="badge" style="background:#FEF3C7;color:#92400E;font-size:0.6rem;font-weight:700;padding:1px 6px;border-radius:3px;cursor:help;" title="Bookies disagree — unreliable. Avoid.">⚡ NOISY</span>
            </div>
            <?php endif; ?>
            </div>
<?php
$slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', $pick['match_name'] ?? ''), '-'));
$linkId = (int)($pick['web_pick_id'] ?? $pick['id']);
if ($slug): ?>
            <a href="prediction/<?= $slug ?>-<?= $linkId ?>" style="display:inline-flex;align-items:center;gap:4px;border:1px solid var(--primary);background:transparent;color:var(--primary);padding:3px 10px;border-radius:5px;font-weight:600;font-size:0.7rem;text-decoration:none;white-space:nowrap;transition:all .2s;align-self:flex-start;" target="_blank" onmouseover="this.style.borderColor='var(--primary-light)';this.style.color='var(--primary-light)'" onmouseout="this.style.borderColor='var(--primary)';this.style.color='var(--primary)'">Match Details</a>
<?php endif; ?>
        </div>
        <div class="pick-meta">
            <span><?= htmlspecialchars($pick['league'] ?? 'Unknown League') ?></span>
<span><i class="fas fa-clock me-1"></i><?php
$fmt = $dateOnly ? 'jS F' : 'jS F, H:i';
$mt = trim($pick['match_time'] ?? '');
if (!empty($mt) && !str_starts_with($mt, '0000') && strtolower($mt) !== 'tbd') {
    $t = strtotime($mt);
    echo $t !== false ? htmlspecialchars(date($fmt, $t)) . ($showTz ? ' (GMT+3)' : '') : htmlspecialchars($mt);
 } elseif (!empty($pick['created_at'])) {
     $t = strtotime($pick['created_at']);
     echo $t !== false ? htmlspecialchars(date($fmt, $t)) . ($showTz ? ' (GMT+3)' : '') : 'TBD';
 } else {
     echo $dateOnly ? 'Today' : 'TBD';
 }
?></span>
        </div>
    </div>
<?php
}
?>

<div class="tab-pane fade <?= $defaultTab === 'rollover' ? 'show active' : '' ?>" id="rollover" role="tabpanel">
<div class="card">
<div class="card-header"><h5 class="card-title">Daily Safety Picks</h5></div>
<?php if (empty($uniqueRolloverPicks)): ?>
<div class="alert alert-info text-center"><div style="font-size: 3rem; margin-bottom: 1rem;"><i class="fas fa-shield-halved" style="color: var(--primary);"></i></div><h6>No Rollover Picks Today</h6><p class="mb-0 text-muted">Our safety filters found no qualifying matches. Check back later!</p></div>
<?php else: ?>
<?php foreach($uniqueRolloverPicks as $pick): ?>
<?php renderPickCard($pick, $hasRollover, 'Rollover Premium Required', 'Access safe, curated daily picks with 7-day rollover protection', 'rollover'); ?>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>

<div class="tab-pane fade <?= $defaultTab === 'parlay' ? 'show active' : '' ?>" id="parlay" role="tabpanel">
<div class="card">
<div class="card-header"><h5 class="card-title">High-Odds Parlay</h5></div>
<?php if (empty($uniqueParlayPicks)): ?>
<div class="alert alert-info text-center"><div style="font-size: 3rem; margin-bottom: 1rem;"><i class="fas fa-bullseye" style="color: var(--accent);"></i></div><h6>No Parlay Picks Today</h6><p class="mb-0 text-muted">Not enough qualifying picks to build a parlay. Check back later!</p></div>
<?php else: ?>
<?php $i = 1; foreach($uniqueParlayPicks as $pick):
    $con = null;
    $verifiedAll = null;
    $isNoisy = isNoisy($pick);
    $parts = explode(' vs ', $pick['match_name'] ?? '');
    if (count($parts) === 2) {
        $h = trim($parts[0]); $aw = trim($parts[1]);
        $movements = getMultiBookieSheetData();
        if ($movements) {
            $verifiedAll = getMatchVerifiedAll($movements, $h, $aw);
            $con = $verifiedAll && count(array_filter($verifiedAll)) > 0;
        }
    }
?>
<div class="pick-card">
    <div class="pick-header">
        <div><span style="background: var(--bg-soft); padding: 0.25rem 0.75rem; border-radius: 6px; font-weight: 700; margin-right: 0.5rem;">#<?= $i++ ?></span><span class="pick-match"><?= htmlspecialchars($pick['match_name'] ?? 'Unknown') ?></span></div>
<?php
$slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', $pick['match_name'] ?? ''), '-'));
if ($slug): ?>
        <a href="prediction/<?= $slug ?>-<?= (int)$pick['id'] ?>" style="display:inline-flex;align-items:center;gap:4px;border:1px solid var(--primary);background:transparent;color:var(--primary);padding:3px 10px;border-radius:5px;font-weight:600;font-size:0.7rem;text-decoration:none;white-space:nowrap;transition:all .2s;align-self:flex-start;" target="_blank" onmouseover="this.style.borderColor='var(--primary-light)';this.style.color='var(--primary-light)'" onmouseout="this.style.borderColor='var(--primary)';this.style.color='var(--primary)'">Match Details</a>
<?php endif; ?>
    </div>
    <div class="pick-value"><?= htmlspecialchars($pick['pick_value'] ?? $pick['pick'] ?? '') ?><?php $bankerEv = getBankerEVValue($pick); $bv = getBestVerifiedStrength($verifiedAll); ?><?php if ($bankerEv !== null && $bankerEv >= 0.05 && !$isNoisy): $bCol = '#06B6D4'; $bBg = 'rgba(6,182,212,0.3)'; ?><span style="display:inline-flex;align-items:center;gap:3px;background:<?= $bBg ?>;color:<?= $bCol ?>;padding:1px 8px;border-radius:4px;font-size:0.65rem;font-weight:700;margin-left:4px;cursor:help;" title="+<?= round($bankerEv * 100) ?>% edge over true probability"><i class="fas fa-dollar-sign"></i> BANKER +<?= round($bankerEv * 100) ?>%</span><?php endif; ?><?php if ($con && !$isNoisy && $bv['strength'] >= 50): $vCol = $bv['strength'] >= 75 ? '#10B981' : '#FBBF24'; $vBg = $bv['strength'] >= 75 ? 'rgba(16,185,129,0.15)' : 'rgba(251,191,36,0.15)'; $vLabel = $bv['strength'] >= 75 ? 'Strong consensus' : 'Moderate agreement'; ?><span style="display:inline-flex;align-items:center;gap:3px;background:<?= $vBg ?>;color:<?= $vCol ?>;padding:1px 8px;border-radius:4px;font-size:0.65rem;font-weight:700;margin-left:6px;cursor:help;" title="<?= $vLabel ?> — <?= $bv['strength'] ?>% across <?= $bv['count'] ?>/<?= $bv['total'] ?> bookies<?= $bv['strength'] < 75 ? '. Use caution.' : '' ?>"><i class="fas fa-check-circle"></i> VERIFIED <?= $bv['strength'] ?>% (<?= $bv['count'] ?>/<?= $bv['total'] ?>)</span><?php endif; ?><?php if ($isNoisy): ?><span style="display:inline-flex;align-items:center;gap:3px;background:rgba(239,68,68,0.15);color:#EF4444;padding:1px 8px;border-radius:4px;font-size:0.65rem;font-weight:700;margin-left:4px;cursor:help;" title="Bookies disagree — unreliable. Avoid."><i class="fas fa-wave-square"></i> NOISY</span><?php endif; ?><?php $ixSites = $pick['intersection_sites'] ?? null; if ($ixSites && $ixSites >= 2): $ixCol = $ixSites >= 4 ? '#7C3AED' : ($ixSites >= 3 ? '#8B5CF6' : '#A78BFA'); $ixBg = $ixSites >= 4 ? 'rgba(124,58,237,0.25)' : ($ixSites >= 3 ? 'rgba(139,92,246,0.2)' : 'rgba(167,139,250,0.15)'); $ixLabel = $ixSites >= 4 ? 'Very strong — 4-5 of 5 sources agree' : ($ixSites >= 3 ? 'Strong — 3 of 5 sources agree' : 'Moderate — 2 of 5 sources agree'); ?><span style="display:inline-flex;align-items:center;gap:3px;background:<?= $ixBg ?>;color:<?= $ixCol ?>;padding:1px 8px;border-radius:4px;font-size:0.65rem;font-weight:700;margin-left:4px;cursor:help;" title="<?= $ixLabel ?>"><i class="fas fa-crosshairs"></i> <?= $ixSites ?>/5</span><?php endif; ?></div>
    <?php $hasVer = $verifiedAll && count(array_filter($verifiedAll)) > 0; if ($hasVer): ?>
    <div style="display:flex;flex-wrap:wrap;gap:2px 8px;font-size:0.65rem;margin-bottom:6px;align-items:center;">
        <span style="color:#10B981;font-weight:600;"><i class="fas fa-check-circle me-1"></i></span>
        <?php $conLabels = ['Home','Draw','Away','Over2.5','Under2.5','BTTS-Yes','BTTS-No'];
        $conKeys = ['1','X','2','Ov2.5','Und2.5','GG','NG'];
        foreach ($conKeys as $i => $ck):
            $vv = $verifiedAll[$ck] ?? null;
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
    <div class="pick-meta">
        <span><?= htmlspecialchars($pick['league'] ?? 'Unknown League') ?></span>
        <span><i class="fas fa-clock me-1"></i><?php
$mt = trim($pick['match_time'] ?? '');
if (!empty($mt) && strtolower($mt) !== 'tbd') {
    $t = strtotime($mt);
    echo $t !== false ? htmlspecialchars(date('jS F, H:i', $t)) . ' (GMT+3)' : htmlspecialchars($mt);
} else { echo 'TBD'; }
?></span>
    </div>
</div>
<?php endforeach; ?>
<?php if ($hasParlay || $trialActive): ?><div class="alert alert-info mt-3"><strong>Parlay Strategy:</strong><span class="text-muted"> Stake 0.25% per parlay (1/4 of single pick stake). Win rate is multiplicative.</span></div><?php endif; ?>
<?php endif; ?>
</div>
</div>

<div class="tab-pane fade <?= $defaultTab === 'goals' ? 'show active' : '' ?>" id="goals" role="tabpanel">
<div class="card">
<div class="card-header"><h5 class="card-title">Goals</h5></div>
<?php $hasOver15 = $hasRollover; $hasUnder25 = $hasRollover; ?>
<?php if (!empty($over15Picks) || !empty($under25Picks)): ?>
<div style="padding:0.5rem 0.75rem;">
<h6 style="font-size:0.78rem;font-weight:700;color:#22c55e;margin:0 0 0.25rem;">Over 1.5 Goals</h6>
<?php if (!empty($over15Picks)): ?>
<?php foreach($over15Picks as $pick): ?>
<?php renderPickCard($pick, $hasOver15, 'Goals Access Required', 'Subscribe to Rollover or Both to unlock Goals picks', 'rollover', 'Over 1.5 Goals - High Accuracy Filter'); ?>
<?php endforeach; ?>
<?php else: ?>
<div class="alert alert-info text-center py-2"><small>No Over 1.5 Goals picks today</small></div>
<?php endif; ?>
<h6 style="font-size:0.78rem;font-weight:700;color:#64748B;margin:0.75rem 0 0.25rem;">Under 3.5 Goals</h6>
<?php if (!empty($under25Picks)): ?>
<?php foreach($under25Picks as $pick): ?>
<?php renderPickCard($pick, $hasUnder25, 'Goals Access Required', 'Subscribe to Rollover or Both to unlock Goals picks', 'rollover', 'Under 3.5 Goals — Low Scoring Filter'); ?>
<?php endforeach; ?>
<?php else: ?>
<div class="alert alert-info text-center py-2"><small>No Under 3.5 Goals picks today</small></div>
<?php endif; ?>
</div>
<?php else: ?>
<div class="alert alert-info text-center"><div style="font-size: 3rem; margin-bottom: 1rem;"><i class="fas fa-futbol" style="color: #22c55e;"></i></div><h6>No Goals Picks Today</h6><p class="mb-0 text-muted">Check back later!</p></div>
<?php endif; ?>
</div>
</div>

<div class="tab-pane fade <?= $defaultTab === 'mostcorners' ? 'show active' : '' ?>" id="mostcorners" role="tabpanel">
<div class="card">
<div class="card-header"><h5 class="card-title">Corners</h5></div>
<?php if (empty($mostCornersPicks)): ?>
<div class="alert alert-info text-center"><div style="font-size: 3rem; margin-bottom: 1rem;"><i class="fas fa-vector-square" style="color: var(--primary);"></i></div><h6>No Most Corners Signals Today</h6><p class="mb-0 text-muted">Our corner dominance filters found no qualifying matches. Check back later!</p></div>
<?php else: ?>
<?php foreach($mostCornersPicks as $pick): ?>
<?php renderPickCard($pick, $hasMostCorners, 'Most Corners Access Required', 'Subscribe to Rollover or Both to unlock Most Corners predictions', 'rollover', 'Most Corners'); ?>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>

<div class="tab-pane fade <?= $defaultTab === 'featured' ? 'show active' : '' ?>" id="featured" role="tabpanel">
<div class="card">
<div class="card-header" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
    <h5 class="card-title" style="margin:0;"><i class="fas fa-gem me-2" style="color:#22C55E;"></i>Banker of the Day</h5>
    <span style="font-size:0.65rem;background:rgba(139,92,246,0.15);color:var(--primary);padding:2px 8px;border-radius:4px;font-weight:700;"><?= count($topPicks) ?> picks</span>
    <span style="font-size:0.65rem;color:var(--text-muted);margin-left:auto;">Powered by Bayesian model</span>
</div>
<?php if (empty($topPicks)): ?>
<div class="alert alert-info text-center"><div style="font-size: 3rem; margin-bottom: 1rem;"><i class="fas fa-gem" style="color: #22C55E;"></i></div><h6>No picks detected today</h6><p class="mb-0 text-muted">Picks appear once the prediction engine runs and detects today's matches.</p></div>
<?php else: ?>
<div style="padding:0.5rem 0;">
<?php foreach($topPicks as $i => $pick): ?>
<?php
    $bvProb = $pick['banker_probability'] ?? 0;
    $bvOdds = $pick['best_odds'] ?? $pick['actual_odds'] ?? 0;
    $bvEv = $pick['banker_ev'] ?? 0;
    $isValue = $pick['banker_is_value'] ?? false;
    $timeStr = '';
    $mtRaw = trim($pick['match_time'] ?? '');
    if (!empty($mtRaw) && !str_starts_with($mtRaw, '0000') && strtolower($mtRaw) !== 'tbd') {
        $t = strtotime($mtRaw);
        $timeStr = $t !== false ? date('j M, H:i', $t) . ' (GMT+3)' : '';
    }
?>
<div style="display:flex;align-items:center;gap:10px;padding:0.6rem 0.75rem;border-bottom:<?= $i < count($topPicks)-1 ? '1px solid #F3F4F6' : 'none' ?>;">
    <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:0.82rem;color:#1F2937;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <?= htmlspecialchars($pick['match_name'] ?? '') ?>
            <?php if ($timeStr): ?> <span style="font-weight:400;color:#9CA3AF;font-size:0.7rem;"><?= $timeStr ?></span><?php endif; ?>
        </div>
        <div style="font-size:0.65rem;color:var(--text-muted);display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:2px;">
            <span style="font-weight:700;color:#8B5CF6;font-size:0.72rem;"><?= htmlspecialchars($pick['pick_value'] ?? '') ?></span>
            <?php if ($bvOdds > 0): ?><span style="color:#F59E0B;">@ <?= number_format($bvOdds, 2) ?></span><?php endif; ?>
            <span style="color:#6366F1;"><?= $bvProb ?>%</span>
            <?php if ($isValue): ?>
            <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.3);color:#22C55E;padding:1px 6px;border-radius:4px;font-weight:700;font-size:0.6rem;"><i class="fas fa-check-circle"></i> VALUE</span>
            <?php endif; ?>
            <?php if (!empty($pick['league'])): ?> <span style="color:#9CA3AF;">· <?= htmlspecialchars($pick['league']) ?></span><?php endif; ?>
        </div>
    </div>
    <div style="text-align:right;min-width:36px;">
        <div style="font-size:0.55rem;color:var(--text-muted);font-weight:500;line-height:1;">Confidence</div>
        <span style="font-weight:700;font-size:0.85rem;color:<?= ($pick['banker_data_conf'] ?? 0) >= 80 ? '#059669' : (($pick['banker_data_conf'] ?? 0) >= 70 ? '#22C55E' : '#D97706') ?>;"><?= $pick['banker_data_conf'] ?? 0 ?>%</span>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
    </div>
</div>

<div class="tab-pane fade <?= $defaultTab === 'toppredictions' ? 'show active' : '' ?>" id="toppredictions" role="tabpanel">
<?php
$allAnalysisPicks = array_merge($uniqueRolloverPicks, $uniqueParlayPicks, $over15Picks, $under25Picks);
$merged = [];
$seenKeys = [];
foreach ($allAnalysisPicks as $p) {
    $key = ($p['match_name'] ?? '') . '|' . ($p['pick_value'] ?? '');
    if (!in_array($key, $seenKeys)) {
        $seenKeys[] = $key;
        $merged[] = $p;
    }
}

// Load Bayesian predictions batch for blending
$bayesianMap = [];
try {
    require_once __DIR__ . '/classes/BayesianModel.php';
    $bm = new BayesianModel();
    $db2 = getDB();
    if ($db2) {
        $bStmt = $db2->query("SELECT match_name, prob_1, prob_x, prob_2, prob_1x, prob_x2, prob_12, over_25, under_25, btts_yes, confidence FROM bayesian_predictions WHERE match_date = CURDATE()");
        while ($row = $bStmt->fetch()) {
            $bayesianMap[$row['match_name']] = $row;
        }
    }
} catch (Exception $e) {}

// Compute blended confidence for each pick
foreach ($merged as &$p) {
    $base = (float)($p['win_rate_low'] ?? 0);
    $score = $base;

    // EV edge boost
    $ev = getBankerEVValue($p);
    if ($ev !== null && $ev > 5) {
        $score += min($ev - 5, 10);
    }

    // Bayesian agreement boost
    $mn = $p['match_name'] ?? '';
    $pv = $p['pick_value'] ?? '';
    if ($mn && isset($bayesianMap[$mn])) {
        $bp = $bayesianMap[$mn];
        $bayesProb = null;
        $pvUp = strtoupper(trim($pv));
        switch ($pvUp) {
            case '1': $bayesProb = (float)$bp['prob_1']; break;
            case 'X': case 'DRAW': $bayesProb = (float)$bp['prob_x']; break;
            case '2': $bayesProb = (float)$bp['prob_2']; break;
            case '1X': $bayesProb = (float)$bp['prob_1x']; break;
            case 'X2': $bayesProb = (float)$bp['prob_x2']; break;
            case '12': $bayesProb = (float)$bp['prob_12']; break;
        }
        if (in_array($pvUp, ['GG', 'BTS', 'BTTS'])) $bayesProb = (float)$bp['btts_yes'];
        if (stripos($pv, 'OVER') !== false && (float)$bp['over_25'] > 50) $bayesProb = (float)$bp['over_25'];
        if (stripos($pv, 'UNDER') !== false) $bayesProb = (float)$bp['under_25'];
        if ($bayesProb !== null && $bayesProb > 50) {
            $agreement = ($bayesProb - 50) * 2;
            $score += min($agreement / 3, 8);
        }
    }

    $p['_blended_conf'] = min(100, max(0, round($score)));
}
unset($p);

// Add Bayesian predictions as independent entries (no confidence threshold)
try {
    if (!isset($bm)) {
        require_once __DIR__ . '/classes/BayesianModel.php';
        $bm = new BayesianModel();
    }
    if (!isset($db2) || !$db2) $db2 = getDB();
    if (!$db2) throw new Exception('No DB');

    // Load today's source matches for intersection filter
    $todaySrc = [];
    $todaySrcTime = [];
    foreach (['web_picks' => 'detected_at', 'scraper_results' => 'detected_at', 'admin_featured_picks' => 'created_at'] as $tbl => $col) {
        $q = $db2->query("SELECT DISTINCT match_name, match_time FROM $tbl WHERE DATE($col) = CURDATE()");
        if ($q) foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $todaySrc[$r['match_name']] = true;
            if (!isset($todaySrcTime[$r['match_name']])) $todaySrcTime[$r['match_name']] = $r['match_time'] ?: '';
        }
    }

    // Normalize helper
    $norm = function($n) { return trim(preg_replace('/\s+(if|fk|sk|fc|sc|cf|ac|as)$/i', '', preg_replace('/^(if|fk|sk|fc|sc|cf|ac|as)\s+/i', '', strtolower(trim($n))))); };

    // Flag pairs predicted yesterday (already played)
    $yestPair = [];
    $q = $db2->query("SELECT home_team, away_team FROM bayesian_predictions WHERE match_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
    if ($q) foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $yestPair[$norm($r['home_team']) . '|' . $norm($r['away_team'])] = true;

    $bayesianPicks = $db2->query("
        SELECT bp.match_name, bp.recommended_pick, bp.confidence, bp.league,
               bp.home_team, bp.away_team, bp.market_odds_1, bp.market_odds_x, bp.market_odds_2
        FROM bayesian_predictions bp
        WHERE bp.match_date = CURDATE()
          AND bp.recommended_pick IS NOT NULL
          AND bp.recommended_pick != ''
        ORDER BY bp.confidence DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($bayesianPicks as $bp) {
        if (!isset($todaySrc[$bp['match_name']])) continue;
        if (isset($yestPair[$norm($bp['home_team']) . '|' . $norm($bp['away_team'])])) continue;
        $bpMatchTime = $todaySrcTime[$bp['match_name']] ?? '';
        if ($bpMatchTime) {
            try {
                $bpKo = new DateTime($bpMatchTime);
                $bpMinsPast = (new DateTime())->getTimestamp() - $bpKo->getTimestamp();
                if ($bpMinsPast > 0) continue;
            } catch (Exception $e) {}
        }

        $recs = explode(',', $bp['recommended_pick']);
        foreach ($recs as $rec) {
            $rec = trim($rec);
            $parts = explode(':', $rec);
            if (count($parts) !== 2) continue;
            $pickType = $parts[0];
            $pickProb = (float)$parts[1];

            $key = $bp['match_name'] . '|' . $pickType;
            if (in_array($key, $seenKeys)) continue;

            $bestOdds = 0;
            $mv = strtoupper($pickType);
            if ($mv === '1') $bestOdds = (float)($bp['market_odds_1'] ?? 0);
            elseif ($mv === 'X') $bestOdds = (float)($bp['market_odds_x'] ?? 0);
            elseif ($mv === '2') $bestOdds = (float)($bp['market_odds_2'] ?? 0);

            $merged[] = [
                'match_name' => $bp['match_name'],
                'pick_value' => $pickType,
                'actual_odds' => $bestOdds,
                'odds' => $bestOdds,
                'pattern_badge' => 'VALUE',
                'match_time' => '',
                '_blended_conf' => round($pickProb),
                'is_bayesian' => true,
                'home_odds' => (float)($bp['market_odds_1'] ?? 0),
                'draw_odds' => (float)($bp['market_odds_x'] ?? 0),
                'away_odds' => (float)($bp['market_odds_2'] ?? 0),
                'fav_delta' => 0,
                'opp_delta' => 0,
                'draw_delta' => 0,
                'is_home_fav' => true,
                'league' => $bp['league'] ?? '',
                'win_rate_low' => 0,
                'details' => 'Bayesian Model Prediction',
                'safety_notes' => '',
                'risk_tier' => '',
            ];
            $seenKeys[] = $key;
        }
    }
} catch (Exception $e) {}

usort($merged, fn($a, $b) => ($b['_blended_conf'] ?? 0) <=> ($a['_blended_conf'] ?? 0));
$tpPerPage = 20;
$tpPage = max(1, (int)($_GET['tp_page'] ?? 1));
$tpSort = $_GET['tp_sort'] ?? 'conf';
$tpTotal = count($merged);
$tpTotalPages = max(1, ceil($tpTotal / $tpPerPage));
// Sort before pagination
usort($merged, function($a, $b) use ($tpSort) {
    if ($tpSort === 'time') {
        $ta = strtotime($a['match_time'] ?? '');
        $tb = strtotime($b['match_time'] ?? '');
        if ($ta === false && $tb === false) return 0;
        if ($ta === false) return 1;
        if ($tb === false) return -1;
        return $ta <=> $tb;
    }
    // default: confidence desc
    return ($b['_blended_conf'] ?? 0) <=> ($a['_blended_conf'] ?? 0);
});
$merged = array_slice($merged, ($tpPage - 1) * $tpPerPage, $tpPerPage);
$tpShowPagination = $tpTotalPages > 1;
// Sort by kick-off time if requested
$tpSort = $_GET['tp_sort'] ?? 'conf';
if ($tpSort === 'time') {
    usort($merged, function($a, $b) {
        $ta = strtotime($a['match_time'] ?? '');
        $tb = strtotime($b['match_time'] ?? '');
        if ($ta === false && $tb === false) return 0;
        if ($ta === false) return 1;
        if ($tb === false) return -1;
        return $ta - $tb;
    });
}
?>
<div class="card">
<div class="card-header" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
    <h5 class="card-title" style="margin:0;"><i class="fas fa-crown me-2" style="color:#FFD700;"></i>PRO Predictions</h5>
    <?php if (!empty($merged)): ?>
    <select id="tpSort" class="form-select form-select-sm" style="width:auto;max-width:140px;font-size:0.72rem;margin-left:auto;" onchange="window.location.href='?tab=toppredictions&tp_page=<?= $tpPage ?>&tp_sort='+this.value">
        <option value="conf" <?= $tpSort === 'conf' ? 'selected' : '' ?>>Sort: Confidence</option>
        <option value="time" <?= $tpSort === 'time' ? 'selected' : '' ?>>Sort: Kick-off</option>
    </select>
    <input type="text" id="tpSearch" class="form-control form-control-sm" placeholder="Search team..." style="max-width:180px;font-size:0.72rem;margin-left:8px;">
    <span style="font-size:0.7rem;color:var(--text-muted);" id="tpCount"><?= count($merged) ?> picks</span>
    <?php endif; ?>
</div>
<?php if (empty($merged)): ?>
<div class="alert alert-info text-center"><div style="font-size: 3rem; margin-bottom: 1rem;"><i class="fas fa-chart-line" style="color: var(--primary);"></i></div><h6>No predictions available yet</h6><p class="mb-0 text-muted">Picks will appear once analysis is complete.</p></div>
<?php else:
?>
<div id="tpContainer" style="padding:0.5rem 0;">
<?php foreach ($merged as $i => $p):
    $pv = $p['pick_value'] ?? '';
    $pickOdds = number_format((float)($p['actual_odds'] ?? $p['odds'] ?? 0), 2);
    $badges = $p['pattern_badge'] ?? '';
    $conf = $p['_blended_conf'] ?? 0;
    $confColor = $conf >= 60 ? '#059669' : ($conf >= 40 ? '#D97706' : '#9CA3AF');
    // Match time
    $mtRaw = trim($p['match_time'] ?? '');
    $timeStr = '';
    if (!empty($mtRaw) && !str_starts_with($mtRaw, '0000') && strtolower($mtRaw) !== 'tbd') {
        $t = strtotime($mtRaw);
        $timeStr = $t !== false ? date('j M, H:i', $t) . ' (GMT+3)' : '';
    }
    // Signal-style movements with colored arrows — identical to odds-signals.php
    $movementHtml = '';
    if ($p['is_bayesian'] ?? false) {
        $pickOdds = 'Model';
    } else {
        $hDelta = (float)($p['fav_delta'] ?? 0);
        $aDelta = (float)($p['opp_delta'] ?? 0);
        $dDelta = (float)($p['draw_delta'] ?? 0);
        $isHomeFav = (bool)($p['is_home_fav'] ?? true);
        if ($isHomeFav) {
            $hDisplayDelta = $hDelta;
            $aDisplayDelta = $aDelta;
        } else {
            $hDisplayDelta = $aDelta;
            $aDisplayDelta = $hDelta;
        }
        // Match odds-signals.php: delta > 0 (odds up) = ↑ green, delta < 0 (odds down) = ↓ red
        $hArrow = $hDisplayDelta > 0 ? '↑' : ($hDisplayDelta < 0 ? '↓' : '–');
        $hColor = $hDisplayDelta > 0 ? '#22C55E' : ($hDisplayDelta < 0 ? '#EF4444' : '#FBBF24');
        $dArrow = $dDelta > 0 ? '↑' : ($dDelta < 0 ? '↓' : '–');
        $dColor = $dDelta > 0 ? '#22C55E' : ($dDelta < 0 ? '#EF4444' : '#FBBF24');
        $aArrow = $aDisplayDelta > 0 ? '↑' : ($aDisplayDelta < 0 ? '↓' : '–');
        $aColor = $aDisplayDelta > 0 ? '#22C55E' : ($aDisplayDelta < 0 ? '#EF4444' : '#FBBF24');
        $movementHtml = sprintf(
            '<span style="color:%s;font-weight:600;">H: %.1f%% %s</span> <span style="color:%s;font-weight:600;">D: %.1f%% %s</span> <span style="color:%s;font-weight:600;">A: %.1f%% %s</span>',
            $hColor, abs($hDisplayDelta), $hArrow,
            $dColor, abs($dDelta), $dArrow,
            $aColor, abs($aDisplayDelta), $aArrow
        );
        // Odds from web_picks
        $tpOdds1 = number_format((float)($p['home_odds'] ?? 0), 2);
        $tpOddsX = number_format((float)($p['draw_odds'] ?? 0), 2);
        $tpOdds2 = number_format((float)($p['away_odds'] ?? 0), 2);
        if ($tpOdds1 > 0 && $tpOddsX > 0 && $tpOdds2 > 0) {
            $movementHtml .= sprintf(' <span style="color:#9CA3AF;">@ %s / %s / %s</span>', $tpOdds1, $tpOddsX, $tpOdds2);
        }
    }
?>
<div class="tp-row" style="display:flex;align-items:center;gap:8px;padding:0.5rem 0.75rem;border-bottom:<?= $i < count($merged)-1 ? '1px solid #F3F4F6' : 'none' ?>;">
    <div style="flex:1;min-width:0;">
        <div class="tp-match" style="font-weight:600;font-size:0.82rem;color:#1F2937;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($p['match_name'] ?? '') ?><?php if ($timeStr): ?> <span style="font-weight:400;color:#9CA3AF;font-size:0.7rem;"><?= $timeStr ?></span><?php endif; ?><?php if (!empty($p['league'])): ?> <span style="font-weight:400;color:#6B7280;font-size:0.65rem;">· <?= htmlspecialchars($p['league']) ?></span><?php endif; ?></div>
        <div style="font-size:0.65rem;color:var(--text-muted);display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
            <span style="font-weight:700;color:#8B5CF6;"><?= htmlspecialchars($pv) ?></span>
            <?php if ($pickOdds !== 'Model'): ?><span style="color:#9CA3AF;">· <?= $pickOdds ?>x</span><?php endif; ?>
            <?php if ($movementHtml): ?>
            <span style="color:#D1D5DB;">|</span>
            <span><?= $movementHtml ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
        <?php if ($badges): $bp2 = explode(' ', trim($badges)); foreach ($bp2 as $b): $b = trim($b); if (!$b) continue;
            $bc = $b === 'VERIFIED' ? '#10B981' : ($b === 'BANKER' ? '#06B6D4' : ($b === 'NOISY' ? '#EF4444' : ($b === 'VALUE' ? '#22C55E' : '#6B7280')));
        ?>
        <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(<?= $b === 'VERIFIED' ? '16,185,129' : ($b === 'BANKER' ? '6,182,212' : ($b === 'NOISY' ? '239,68,68' : ($b === 'VALUE' ? '34,197,94' : '107,114,128'))) ?>,0.15);color:<?= $bc ?>;padding:1px 6px;border-radius:4px;font-weight:600;font-size:0.65rem;"><?= htmlspecialchars($b) ?></span>
        <?php endforeach; endif; ?>
    </div>
    <div style="text-align:right;min-width:36px;">
        <div style="font-size:0.55rem;color:var(--text-muted);font-weight:500;line-height:1;">Confidence</div>
        <span style="font-weight:700;font-size:0.85rem;color:<?= $confColor ?>;"><?= $conf ?>%</span>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php if ($tpShowPagination): ?>
<div style="display:flex;align-items:center;justify-content:center;gap:4px;padding:0.5rem 0.75rem;border-top:1px solid var(--border-color);font-size:0.75rem;flex-wrap:wrap;">
    <?php if ($tpPage > 1): ?>
    <a href="?tab=toppredictions&tp_page=<?= $tpPage - 1 ?>&tp_sort=<?= $tpSort ?>" style="padding:4px 8px;background:#F3F4F6;border-radius:6px;color:var(--primary);font-weight:600;text-decoration:none;" title="Previous"><i class="fas fa-chevron-left"></i></a>
    <?php endif; ?>
    <?php
    $window = 5;
    $start = max(1, $tpPage - $window);
    $end = min($tpTotalPages, $tpPage + $window);
    if ($start > 1): ?>
        <a href="?tab=toppredictions&tp_page=1&tp_sort=<?= $tpSort ?>" style="padding:4px 8px;border-radius:6px;color:var(--primary);font-weight:600;text-decoration:none;">1</a>
        <?php if ($start > 2): ?><span style="color:var(--text-muted);">…</span><?php endif; ?>
    <?php endif; ?>
    <?php for ($i = $start; $i <= $end; $i++): ?>
        <?php if ($i === $tpPage): ?>
        <span style="padding:4px 8px;border-radius:6px;background:var(--primary);color:#fff;font-weight:700;text-decoration:none;"><?= $i ?></span>
        <?php else: ?>
        <a href="?tab=toppredictions&tp_page=<?= $i ?>&tp_sort=<?= $tpSort ?>" style="padding:4px 8px;border-radius:6px;color:var(--primary);font-weight:600;text-decoration:none;"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>
    <?php if ($end < $tpTotalPages): ?>
        <?php if ($end < $tpTotalPages - 1): ?><span style="color:var(--text-muted);">…</span><?php endif; ?>
        <a href="?tab=toppredictions&tp_page=<?= $tpTotalPages ?>&tp_sort=<?= $tpSort ?>" style="padding:4px 8px;border-radius:6px;color:var(--primary);font-weight:600;text-decoration:none;"><?= $tpTotalPages ?></a>
    <?php endif; ?>
    <?php if ($tpPage < $tpTotalPages): ?>
    <a href="?tab=toppredictions&tp_page=<?= $tpPage + 1 ?>&tp_sort=<?= $tpSort ?>" style="padding:4px 8px;background:#F3F4F6;border-radius:6px;color:var(--primary);font-weight:600;text-decoration:none;" title="Next"><i class="fas fa-chevron-right"></i></a>
    <?php endif; ?>
    <span style="color:var(--text-muted);margin-left:4px;">(<?= $tpTotal ?> picks)</span>
</div>
<?php endif; ?>
<div style="padding:0.4rem 0.75rem;background:#F9FAFB;border-top:1px solid var(--border-color);font-size:0.65rem;color:var(--text-muted);text-align:center;">
    <i class="fas fa-chart-line me-1"></i> Confidence = blended score from analysis + EV + model agreement
</div>
<?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var tpSearch = document.getElementById('tpSearch');
    var tpContainer = document.getElementById('tpContainer');
    var tpCount = document.getElementById('tpCount');
    if (tpSearch && tpContainer) {
        tpSearch.addEventListener('input', function() {
            var q = this.value.toLowerCase().trim();
            var rows = tpContainer.querySelectorAll('.tp-row');
            var visible = 0;
            rows.forEach(function(r) {
                var match = !q || r.textContent.toLowerCase().includes(q);
                r.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            if (tpCount) tpCount.textContent = visible + ' picks';
        });
    }
});
</script>
</div>

<div class="tab-pane fade <?= $defaultTab === 'subscribe' ? 'show active' : '' ?>" id="subscribe" role="tabpanel">
<?php if (hasRejectedPaymentWithoutResubmit($user['id'])): ?>
<div class="alert alert-danger mb-4">
    <i class="fas fa-times-circle me-1"></i><strong>Your recent payment was rejected</strong>
    <div class="mt-1 small">Your payment reference number was incorrect or could not be verified. Please send your payment again and make sure to enter the correct reference number you received after payment.</div>
    <div class="mt-2 small">If you need help finding your reference number or have any questions, <a href="https://wa.me/255713348298" target="_blank" class="fw-bold text-decoration-underline"><i class="fab fa-whatsapp me-1"></i>Contact support on WhatsApp</a> or email <a href="mailto:support@predixa.co.tz" class="fw-bold text-decoration-underline">support@predixa.co.tz</a>.</div>
</div>
<?php endif; ?>
<div class="text-center mb-4"><h2 style="font-size: 2rem; font-weight: 800; margin-bottom: 0.5rem;">Choose Your Premium Plan</h2><p class="text-muted">Flexible durations — the longer you pick, the less per day</p></div>
<div class="pricing-grid">
<?php
$tiers = ['parlay' => ['icon' => '', 'title' => 'Parlay Premium', 'features' => ['High-Odds Parlay Picks', '~30x Combined Odds', '2-19 Legs Auto-Selected', 'Include Speculative picks', 'All Leagues (Cups Inclusive)'], 'locked' => 'Safety Rollover & PRO Predictions'],
          'rollover' => ['icon' => '', 'title' => 'Rollover Premium', 'features' => ['7-Day Safety Cycle', '1-5 Curated Picks Daily', 'SAFE/MODERATE picks Only', 'Over 1.5 Goals picks', 'Core Leagues (No Cups)', 'Most Corners'], 'locked' => 'Parlay & PRO Predictions', 'popular' => true],
           'both' => ['icon' => '', 'title' => 'Both Premium', 'features' => ['Everything in Parlay', 'Everything in Rollover', 'PRO Predictions (Elevated accuracy)', 'Full Access to All Features', 'Priority Support', 'Best Value - Save 10%', 'Recommended for Serious Punters'], 'locked' => null]];
$durationOpts = getDurationOptions();
foreach ($tiers as $tierKey => $tier):
    $firstPrice = getPlanPrice($tierKey, 'monthly');
    $firstDays = getPlanDays($tierKey, 'monthly');
    $perDayMonthly = round($firstPrice / $firstDays);
?>
<div class="pricing-card <?= !empty($tier['popular']) ? 'popular' : '' ?>">
<?php if (!empty($tier['popular'])): ?><span class="pricing-badge">MOST POPULAR</span><?php endif; ?>
<div class="pricing-icon"><?= $tier['icon'] ?></div>
<h3 class="pricing-title"><?= $tier['title'] ?></h3>
<div class="pricing-price"><?= number_format($firstPrice) ?> <small>TZS/mo</small></div>
<div class="per-day" style="margin-bottom: 0.5rem;">~<?= $perDayMonthly ?> TZS/day</div>
<ul class="pricing-features">
<?php foreach ($tier['features'] as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?>
<?php if ($tier['locked']): ?><li style="color: var(--text-muted); text-decoration: line-through;"><?= $tier['locked'] ?></li><?php endif; ?>
</ul>
<div class="d-flex flex-wrap gap-1 justify-content-center mb-3">
<?php foreach ($durationOpts as $dk => $dopt):
    $p = getPlanPrice($tierKey, $dk);
    $pd = round($p / getPlanDays($tierKey, $dk));
?>
<a href="subscribe?tier=<?= $tierKey ?>&duration=<?= $dk ?>" class="btn btn-sm <?= $dk === 'monthly' ? 'btn-premium' : 'btn-outline' ?>" style="<?= $dk === 'monthly' ? '' : 'padding: 0.3rem 0.6rem; font-size: 0.75rem;' ?>"><?= $dopt['label'] ?> <?= number_format($p) ?> TZS</a>
<?php endforeach; ?>
</div>
<a href="subscribe?tier=<?= $tierKey ?>" class="btn btn-premium w-100">Subscribe <?= $tier['title'] ?></a>
</div>
<?php endforeach; ?>
</div>
<div class="alert alert-info mt-4"><h6 style="font-weight: 700; margin-bottom: 0.5rem;">How Payment Works:</h6><ol class="mb-0" style="padding-left: 1.25rem;"><li>Choose your plan & duration above</li><li>Send payment via M-Pesa, Mixx, Airtel, HaloPesa, or Bank Transfer</li><li>Copy the reference number from SMS</li><li>Submit reference for instant activation (1-5 minutes)</li></ol></div>
</div>

<!-- Betting Codes Tab -->
<?php if (!isSectionHidden('betting_codes')): ?>
<div class="tab-pane fade <?= $defaultTab === 'codes' ? 'show active' : '' ?>" id="codes" role="tabpanel">


<!-- Available Codes Marketplace -->
<?php $acLimit = 6; $acTotal = count($availableCodes); ?>
<div class="card">
    <div class="card-header"><h6 class="card-title">Available Codes (<?= $acTotal ?>)</h6></div>
    <?php if (empty($availableCodes)): ?>
    <div class="text-center text-muted py-4"><div style="font-size: 2rem;"></div><p class="mb-0 small">No codes available for purchase</p></div>
    <?php else: ?>
    <div class="p-3">
        <div class="mb-3">
            <input type="text" id="codeSearchInput" class="form-control form-control-sm" placeholder="🔍 Search codes by top seller..." oninput="filterCodesBySeller(this.value)">
        </div>
        <div class="position-relative">
            <div id="availableCodesSlider" class="d-flex gap-3 overflow-hidden" style="scroll-behavior: smooth; overflow-x: auto; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; padding-bottom: 8px;">
                <?php foreach ($availableCodes as $acIdx => $ac):
                    $alreadyOwned = false;
                    foreach ($purchasedCodes as $pc) { if ((int)$pc['code_id'] === (int)$ac['id']) { $alreadyOwned = true; break; } }
                    $sales = (int)($ac['sales_count'] ?? 0);
                    $sellerName = htmlspecialchars($ac['display_name'] ?: (substr($ac['phone'], 0, 8) . '***'));
                ?>
                <div class="flex-shrink-0 code-slide" data-index="<?= $acIdx ?>" style="width: 280px; scroll-snap-align: start;<?= $acIdx >= $acLimit ? 'display:none;' : '' ?>" data-seller="<?= strtolower($sellerName) ?>">
                    <div class="pick-card h-100" style="padding: 0.75rem; margin-bottom: 0; display:flex; flex-direction:column; <?= $sales > 0 ? 'border-color: #F59E0B; border-width: 2px;' : '' ?>">
                        <?php if (!empty($ac['badge']) && isset(CODE_BADGES[$ac['badge']])): $bd = CODE_BADGES[$ac['badge']]; ?>
                        <div style="margin-bottom:0.35rem;"><span style="display:inline-block;background:<?=$bd['bg']?>;color:<?=$bd['color']?>;padding:0.06rem 0.45rem;border-radius:4px;font-size:0.6rem;font-weight:700;text-transform:uppercase;">🏆 <?=htmlspecialchars($bd['label'])?></span></div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between align-items-start">
                            <strong style="font-size: 0.85rem;"><?= htmlspecialchars($ac['description']) ?></strong>
                            <span style="background: var(--primary); color: white; padding: 0.2rem 0.5rem; border-radius: 4px; font-weight: 700; font-size: 0.75rem; white-space:nowrap;"><?= number_format($ac['price']) ?> TZS</span>
                        </div>
                        <?php if ($sales > 0): ?>
                        <div class="mt-2 p-2" style="background: #FEF3C7; border-radius: 6px; border: 1px solid #FDE68A;">
                            <div class="d-flex align-items-center gap-2">
                                <span style="font-size: 0.9rem;">🟢</span>
                                <span style="color: #92400E; font-weight: 600; font-size: 0.75rem;"><strong><?= $sales ?> person<?= $sales > 1 ? 's' : '' ?> bought this</strong></span>
                                <?php if ($sales >= 3): ?>
                                <span class="badge" style="background: #10B981; color: #fff; font-size: 0.6rem; margin-left: auto;"><i class="fas fa-crown me-1"></i>Top Seller</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="text-muted small mt-2" style="font-size:0.75rem;">Markets: <?= htmlspecialchars($ac['matches']) ?></div>
                        <?php if ($ac['odds']): ?><div class="text-muted small" style="font-size:0.75rem;">Total Odds: <strong><?= $ac['odds'] ?>x</strong></div><?php endif; ?>
                        <?php $ko = $ac['kickoff_time'] ?? null; if ($ko && strtotime($ko) > time()): ?>
                        <div class="mt-1 countdown-wrap" style="font-size:0.7rem;color:var(--primary);font-weight:600;"><i class="far fa-clock me-1"></i>Kick off in: <span class="countdown-timer" data-kickoff="<?= strtotime($ko) ?>"></span></div>
                        <?php endif; ?>
                        <div class="text-muted small" style="font-size:0.75rem;">
                            By: <?= $sellerName ?>
                            <?php $sr = getSellerRating($ac['user_id']); if ($sr['count'] > 0): ?>
                            <span style="color:#F59E0B;">
                                <?php $full = floor($sr['avg']); for ($i = 0; $i < $full; $i++): ?>★<?php endfor; if ($sr['avg'] - $full >= 0.5): ?>½<?php endif; ?>
                                <?= number_format($sr['avg'], 1) ?>
                            </span>
                            <span class="text-muted" style="font-size:0.65rem;">(<?= $sr['count'] ?>)</span>
                            <?php endif; ?>
                            • <?= date('M d', strtotime($ac['created_at'])) ?>
                        </div>
                        <div style="margin-top:auto;">
                        <?php if ($alreadyOwned): ?>
                        <span class="badge mt-2" style="background: #10B981; color: white;">Unlocked</span>
                        <?php elseif ((int)($ac['publisher_credits'] ?? 0) < 1): ?>
                        <div class="mt-2 p-2" style="background:#FEF2F2;border:1px solid #FECACA;border-radius:6px;text-align:center;">
                            <small style="color:#991B1B;font-size:0.65rem;"><i class="fas fa-pause-circle me-1"></i>Seller at capacity — check back later</small>
                        </div>
                        <?php else: ?>
                        <button class="btn btn-premium mt-2 w-100" style="padding:0.25rem 0.8rem;font-size:0.75rem;" onclick="openCodePayment(<?= $ac['id'] ?>, '<?= htmlspecialchars(addslashes($ac['description'])) ?>', <?= $ac['price'] ?>, '<?= htmlspecialchars(addslashes($sellerName)) ?>', <?= $sales ?>, '<?= htmlspecialchars(addslashes($ac['publisher_account_number'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($ac['publisher_bank'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($ac['publisher_account_name'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($ac['publisher_payment_methods'] ?? '')) ?>', <?= $sr['avg'] ?? 0 ?>, <?= $sr['count'] ?? 0 ?>, '<?= htmlspecialchars(addslashes($ac['publisher_whatsapp'] ?? '')) ?>')">Unlock Now</button>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="availCodesPrev" class="btn btn-dark position-absolute top-50 start-0 translate-middle-y" style="border-radius:50%;width:36px;height:36px;padding:0;opacity:0.8;z-index:2;display:none;margin-left:4px;"><i class="fas fa-chevron-left" style="font-size:0.8rem;"></i></button>
            <button type="button" id="availCodesNext" class="btn btn-dark position-absolute top-50 end-0 translate-middle-y" style="border-radius:50%;width:36px;height:36px;padding:0;opacity:0.8;z-index:2;margin-right:4px;"><i class="fas fa-chevron-right" style="font-size:0.8rem;"></i></button>
        </div>
        <?php if ($acTotal > $acLimit): ?>
        <div class="text-center mt-2">
            <button class="btn btn-sm load-more-btn" data-section="availableCodes" data-current="<?= $acLimit ?>" data-step="<?= $acLimit ?>" data-total="<?= $acTotal ?>" style="background:var(--primary);color:white;border-radius:6px;padding:4px 16px;font-size:0.75rem;font-weight:600;">Show <?= $acTotal - $acLimit ?> more</button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="card mt-3">
<div class="card-header"><h5 class="card-title">Betting Code Marketplace</h5><span class="badge" style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white;"><?= BETTING_CODE_PRICE ?> TZS each</span></div>

<div class="p-3" style="background: #F8F6FF; border-bottom: 1px solid var(--border-color);">
    <div class="d-flex align-items-center gap-2 mb-2">
        <span style="font-size:1.2rem;">📖</span>
        <strong style="font-size:0.85rem;">How to use the marketplace</strong>
        <button type="button" class="btn btn-sm ms-auto" style="background:none;border:none;color:var(--primary);font-size:0.75rem;" onclick="this.closest('.p-3').querySelector('.guide-steps').classList.toggle('d-none');this.textContent=this.textContent==='Show'?'Hide':'Show'">Show</button>
    </div>
    <div class="guide-steps d-none" style="font-size:0.8rem;">
        <div class="row g-2">
            <div class="col-md-6" style="border-right:1px solid var(--border-color);">
                <strong style="color:var(--primary);">📤 To Publish a Code:</strong>
                <ol class="mb-0 mt-1" style="padding-left:1.2rem;">
                    <li>Fill in <strong>Betting Code</strong>, <strong>Bookmaker</strong> &amp; <strong>Markets</strong></li>
                    <li>Publishing is <strong>free</strong> — no credits needed</li>
                    <li>Click <strong>"Publish Code"</strong></li>
                    <li>Your code appears in <strong>"Your Published Codes"</strong> below</li>
                    <li>Set <strong>"Last Match Kick-off"</strong> to keep your code visible until the final match starts</li>
                    <li>When a buyer purchases, <strong>1 credit</strong> is deducted from your balance</li>
                </ol>
            </div>
            <div class="col-md-6">
                <strong style="color:var(--primary);">🔍 To Browse &amp; Buy Codes:</strong>
                <ol class="mb-0 mt-1" style="padding-left:1.2rem;">
                    <li>Browse <strong>"Available Codes"</strong> at the top</li>
                    <li>Search by seller name using the search box</li>
                    <li>Click <strong>"Unlock Now"</strong> on any code</li>
                    <li>Send payment directly to the seller via M-Pesa/Airtel/Mixx &amp; submit the reference</li>
                    <li>Seller confirms payment → code is unlocked in your dashboard</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Create Code (for tipsters) -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="card-title">Sell a Betting Code</h6>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_code">
                <div class="mb-2">
                    <label class="form-label text-muted small">Betting Code *</label>
                    <input type="text" name="code" class="form-control form-control-sm" placeholder="e.g., PRD-2405-X9K2" required>
                </div>
                <div class="mb-2">
                    <label class="form-label text-muted small">Bookmaker *</label>
                    <input type="text" name="description" class="form-control form-control-sm" placeholder="e.g. Betika, SportPesa, Betway etc" required>
                </div>
                <div class="mb-2">
                    <label class="form-label text-muted small">Markets *</label>
                    <input type="text" name="matches" class="form-control form-control-sm" placeholder="e.g. 1x2, Over 1.5 Goals, DC, Total Corners etc" required>
                </div>
                <div class="mb-2">
                    <label class="form-label text-muted small">Odds (optional)</label>
                    <input type="text" name="odds" class="form-control form-control-sm" placeholder="e.g., 12.50">
                </div>
                <div class="mb-2">
                    <label class="form-label text-muted small">Kick-off Time (optional) <span class="text-muted" style="font-size:0.65rem;">— shows countdown on card</span></label>
                    <input type="datetime-local" name="kickoff_time" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label text-muted small">Tournament Badge (optional) <span class="text-muted" style="font-size:0.65rem;">— makes your code stand out</span></label>
                    <select name="badge" class="form-select form-select-sm">
                        <option value="">No badge</option>
                        <?php foreach (CODE_BADGES as $key => $bdg): ?>
                        <option value="<?= $key ?>"><?= htmlspecialchars($bdg['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label text-muted small">Last Match Kick-off (optional) <span class="text-muted" style="font-size:0.65rem;">— keeps code visible until this time</span></label>
                    <input type="datetime-local" name="last_match_kickoff" class="form-control form-control-sm">
                </div>
                <button type="submit" class="btn btn-premium w-100" style="font-size: 0.85rem;"><i class="fas fa-cloud-upload-alt me-1"></i>Publish Code</button>
                <p class="text-muted small mt-2 mb-0">Publishing is free. You earn the full <?= BETTING_CODE_PRICE ?> TZS per sale. The <?= BETTING_CODE_COMMISSION ?> TZS office share is deducted from your credits when a buyer purchases your code.</p>
            </form>
        </div>
    </div>

    <!-- Your Codes & Top Seller -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="card-title">Your Published Codes (<?= count($userCodes) ?>)</h6></div>
            <?php if (empty($userCodes)): ?>
            <div class="text-center text-muted py-3" style="border-bottom:1px solid var(--border-color);"><p class="mb-0 small">No codes published yet</p></div>
            <?php else: ?>
            <div style="max-height: 180px; overflow-y: auto;">
            <?php foreach ($userCodes as $uc): ?>
            <div class="pick-card" style="padding: 0.5rem 0.75rem; margin-bottom: 0; border-radius:0; border-left:none; border-right:none;">
                <?php if (!empty($uc['badge']) && isset(CODE_BADGES[$uc['badge']])): $bd = CODE_BADGES[$uc['badge']]; ?>
                <div style="margin-bottom:0.25rem;"><span style="display:inline-block;background:<?=$bd['bg']?>;color:<?=$bd['color']?>;padding:0.06rem 0.45rem;border-radius:4px;font-size:0.6rem;font-weight:700;text-transform:uppercase;">🏆 <?=htmlspecialchars($bd['label'])?></span></div>
                <?php endif; ?>
                <div class="d-flex justify-content-between align-items-start">
                    <strong style="font-size: 0.85rem;"><?= htmlspecialchars($uc['description']) ?></strong>
                    <span class="badge <?= $uc['status'] === 'active' ? 'badge-rollover' : 'badge-pending' ?>" style="font-size:0.65rem;"><?= $uc['status'] ?></span>
                </div>
                <div class="text-muted small" style="font-size:0.75rem;">Code: <code><?= htmlspecialchars($uc['code']) ?></code> • <?= (int)$uc['sales_count'] ?> sale(s) • <?= date('M d', strtotime($uc['created_at'])) ?></div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="p-3" style="border-top:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                <span style="font-size:0.8rem;color:var(--text-muted);"><i class="fas fa-coins me-1" style="color:#F59E0B;"></i>Your Credits: <strong style="color:<?= $publisherCredits > 2 ? '#10B981' : ($publisherCredits > 0 ? '#F59E0B' : '#EF4444') ?>;"><?= $publisherCredits ?></strong> <span style="font-size:0.65rem;">(1 credit used per sale)</span></span>
                <button type="button" class="btn btn-sm" style="background: var(--primary); color: white; border: none; padding: 2px 12px; font-size: 0.75rem;" data-bs-toggle="modal" data-bs-target="#buyCreditsModal"><i class="fas fa-plus me-1"></i>Buy Credits</button>
            </div>

            <?php if ($publisherCredits < 1 && !empty($userCodes)): ?>
            <div class="p-2" style="background:#FEF2F2;border-bottom:1px solid #FECACA;text-align:center;">
                <small style="color:#991B1B;"><i class="fas fa-exclamation-circle me-1"></i>You have <strong>0 credits</strong>. Buy credits to approve new sales.</small>
            </div>
            <?php endif; ?>
        </div>

<?php $topSellerMax = !empty($publisherRankings) ? max(array_column($publisherRankings, 'total_sales')) : 0; ?>
            <?php if ($topSellerMax >= 6): ?>
            <div class="p-3" style="border-top:1px solid var(--border-color);">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span style="font-size:1rem;">🏆</span>
                    <span style="font-weight:700; font-size:0.85rem; color: var(--text-light);">Top Selling Sellers</span>
                </div>
                <?php $maxSalesPb = max(array_column($publisherRankings, 'total_sales')); $r = 0; ?>
                <?php foreach ($publisherRankings as $pr): $r++;
                    $pn = $pr['display_name'] ? htmlspecialchars($pr['display_name']) : 'Seller #' . $pr['id'];
                    $pc = $maxSalesPb > 0 ? round(((int)$pr['total_sales'] / $maxSalesPb) * 100) : 0;
                    $icon = $r === 1 ? '🥇' : ($r === 2 ? '🥈' : ($r === 3 ? '🥉' : $r));
                ?>
                <div class="d-flex align-items-center gap-2 py-1">
                    <span style="font-size:0.9rem;min-width:22px;text-align:center;"><?= $icon ?></span>
                    <span style="font-size:0.78rem;font-weight:600;color:var(--text-light);flex:1;">
                        <?= $pn ?>
                        <?php $sr2 = getSellerRating($pr['id']); if ($sr2['count'] > 0): ?>
                        <span style="color:#F59E0B;font-size:0.7rem;">★ <?= number_format($sr2['avg'], 1) ?> (<?= $sr2['count'] ?>)</span>
                        <?php endif; ?>
                    </span>
                    <div style="width:50px;height:4px;background:rgba(255,255,255,0.1);border-radius:3px;overflow:hidden;">
                        <div style="height:100%;width:<?= $pc ?>%;background:linear-gradient(90deg,#F59E0B,#F97316);border-radius:3px;"></div>
                    </div>
                    <span style="font-size:0.7rem;font-weight:700;color:#F59E0B;min-width:32px;text-align:right;"><?= $pr['total_sales'] ?> sold</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
    </div>
</div>

<?php if (!empty($sellerPendingPurchases)): ?>
<div class="card mt-3" id="pendingApprovalsSection" style="border-left: 4px solid #F59E0B;">
    <div class="card-header">
        <h6 class="card-title"><i class="fas fa-clock me-1" style="color:#F59E0B;"></i>Pending Buyer Approvals</h6>
        <span class="badge" style="background:#F59E0B;color:#000;"><?= count($sellerPendingPurchases) ?> pending</span>
    </div>
    <div class="p-2 small text-muted" style="border-bottom:1px solid var(--border-color);">
        <i class="fas fa-info-circle me-1"></i>These buyers have sent payment to your number. Confirm you received the money, then approve to unlock the code.
    </div>
    <div class="p-2" style="border-bottom:1px solid var(--border-color);">
        <input type="text" id="pendingRefSearch" class="form-control form-control-sm" placeholder="🔍 Search by reference number..." oninput="filterPendingByRef(this.value)">
    </div>
    <?php foreach ($sellerPendingPurchases as $sp): ?>
    <div class="p-3 pending-ref-item" style="border-bottom:1px solid var(--border-color);" data-ref="<?= strtolower(htmlspecialchars($sp['payment_reference'])) ?>">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <?php if (!empty($sp['badge']) && isset(CODE_BADGES[$sp['badge']])): $bd = CODE_BADGES[$sp['badge']]; ?>
                <div style="margin-bottom:0.25rem;"><span style="display:inline-block;background:<?=$bd['bg']?>;color:<?=$bd['color']?>;padding:0.06rem 0.45rem;border-radius:4px;font-size:0.6rem;font-weight:700;text-transform:uppercase;">🏆 <?=htmlspecialchars($bd['label'])?></span></div>
                <?php endif; ?>
                <strong style="font-size:0.85rem;"><?= htmlspecialchars($sp['description']) ?></strong>
                <div class="text-muted small">Buyer: <code><?= htmlspecialchars($sp['buyer_name'] ?: $sp['buyer_phone']) ?></code> • <?= htmlspecialchars($sp['buyer_phone']) ?></div>
                <div class="text-muted small">Ref: <code><?= htmlspecialchars($sp['payment_reference']) ?></code> • <?= number_format($sp['amount']) ?> TZS</div>
                <div class="text-muted small">Purchased: <?= date('M d, H:i', strtotime($sp['purchased_at'])) ?></div>
            </div>
            <div class="d-flex gap-1">
                <form method="POST">
                    <input type="hidden" name="action" value="seller_approve_code">
                    <input type="hidden" name="purchase_id" value="<?= $sp['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background:#10B981;color:white;border:none;padding:0.25rem 0.6rem;border-radius:4px;font-size:0.75rem;" onclick="return confirm('Confirm you received the payment from <?= htmlspecialchars($sp['buyer_phone']) ?>?')">Approve</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="action" value="seller_reject_code">
                    <input type="hidden" name="purchase_id" value="<?= $sp['id'] ?>">
                    <input type="hidden" name="rejection_reason" value="">
                    <button type="button" class="btn btn-sm" style="background:#EF4444;color:white;border:none;padding:0.25rem 0.6rem;border-radius:4px;font-size:0.75rem;" onclick="var r=prompt('Rejection reason:','Wrong reference number');if(r){this.form.rejection_reason.value=r;this.form.submit();}">Reject</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<script>
function filterPendingByRef(val) {
    var q = val.toLowerCase().trim();
    document.querySelectorAll('.pending-ref-item').forEach(function(el) {
        el.style.display = !q || el.dataset.ref.indexOf(q) !== -1 ? '' : 'none';
    });
}
</script>
<?php endif; ?>

<!-- Your Sales Summary -->
<?php if ($userCommission['total_earned'] > 0): ?>
<div class="card mt-3">
    <div class="card-header"><h6 class="card-title">Your Sales Summary</h6></div>
    <div class="row g-2 text-center py-2">
        <div class="col-4"><div class="stat-number" style="font-size: 1.2rem;"><?= number_format($userCommission['total_earned']) ?> TZS</div><div class="text-muted small">Total Paid by Buyers</div></div>
        <div class="col-4"><div class="stat-number" style="font-size: 1.2rem; <?= $totalCodesSold < 1 ? 'color: #DC2626;' : '' ?>"><?= $totalCodesSold ?></div><div class="text-muted small">Codes Sold</div></div>
        <div class="col-4"><div class="stat-number" style="font-size: 1.2rem; <?= $publisherCredits < 1 ? 'color: #DC2626;' : 'color: #10B981;' ?>"><?= $publisherCredits ?></div><div class="text-muted small">Credits Remaining</div></div>
    </div>
    <div class="px-3 pb-2">
        <div class="p-2" style="background:#F0FDF4;border-radius:6px;border:1px solid #D1FAE5;">
            <small style="color:#065F46;"><i class="fas fa-info-circle me-1"></i>Buyers send payment directly to you. No withdrawal needed — the amount above reflects total confirmed sales.</small>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Buyer Pending Approvals -->
<?php if (!empty($buyerPendingPurchases)): ?>
<div class="card mt-3" style="border-left: 4px solid #3B82F6;">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="card-title mb-0"><i class="fas fa-clock me-1" style="color:#3B82F6;"></i>Pending Seller Approval</h6>
        <span class="badge" style="background:#3B82F6;color:#fff;"><?= count($buyerPendingPurchases) ?> pending</span>
    </div>
    <div class="p-3">
        <div class="d-flex gap-3 flex-wrap">
            <?php foreach ($buyerPendingPurchases as $bp):
                $sellerName = htmlspecialchars($bp['seller_name'] ?: substr($bp['seller_phone'], 0, 8) . '***');
            ?>
            <div style="width:280px;">
                <div class="pick-card h-100" style="padding:0.75rem;margin-bottom:0;display:flex;flex-direction:column;border-color:#93C5FD;border-width:2px;">
                    <?php if (!empty($bp['badge']) && isset(CODE_BADGES[$bp['badge']])): $bd = CODE_BADGES[$bp['badge']]; ?>
                    <div style="margin-bottom:0.35rem;"><span style="display:inline-block;background:<?=$bd['bg']?>;color:<?=$bd['color']?>;padding:0.06rem 0.45rem;border-radius:4px;font-size:0.6rem;font-weight:700;text-transform:uppercase;">🏆 <?=htmlspecialchars($bd['label'])?></span></div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-start">
                        <strong style="font-size:0.85rem;"><?= htmlspecialchars($bp['description']) ?></strong>
                        <span class="badge" style="background:#F59E0B;color:#000;font-size:0.65rem;">Pending</span>
                    </div>
                    <div class="text-muted small mt-2" style="font-size:0.72rem;">Seller: <?= $sellerName ?></div>
                    <div class="text-muted small" style="font-size:0.72rem;">Markets: <?= htmlspecialchars($bp['matches']) ?></div>
                    <?php if ($bp['odds']): ?><div class="text-muted small" style="font-size:0.72rem;">Total Odds: <strong><?= $bp['odds'] ?>x</strong></div><?php endif; ?>
                    <div class="text-muted small" style="font-size:0.72rem;">Amount: <?= number_format($bp['price']) ?> TZS</div>
                    <div class="text-muted small" style="font-size:0.72rem;">Reference: <?= htmlspecialchars($bp['payment_reference']) ?></div>
                    <div class="text-muted small" style="font-size:0.72rem;">Submitted: <?= date('M d, H:i', strtotime($bp['purchased_at'])) ?></div>
                    <div style="margin-top:auto;"><div class="mt-2 p-2" style="background:#EFF6FF;border-radius:6px;text-align:center;">
                        <small style="color:#1E40AF;font-size:0.65rem;"><i class="fas fa-info-circle me-1"></i>Awaiting seller approval</small>
                    </div></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var slider = document.getElementById('availableCodesSlider');
    var pb = document.getElementById('availCodesPrev');
    var nb = document.getElementById('availCodesNext');
    if (slider && pb && nb) {
        function ub() { pb.style.display = slider.scrollLeft > 10 ? '' : 'none'; nb.style.display = slider.scrollLeft < slider.scrollWidth - slider.clientWidth - 10 ? '' : 'none'; }
        pb.addEventListener('click', function() { slider.scrollBy({ left: -300, behavior: 'smooth' }); setTimeout(ub, 100); });
        nb.addEventListener('click', function() { slider.scrollBy({ left: 300, behavior: 'smooth' }); setTimeout(ub, 100); });
        slider.addEventListener('scroll', ub);
        ub();
        var autoInterval = setInterval(function() {
            if (slider.matches(':hover')) return;
            if (slider.scrollLeft + slider.clientWidth >= slider.scrollWidth - 10) {
                slider.scrollTo({ left: 0, behavior: 'smooth' });
            } else {
                slider.scrollBy({ left: 300, behavior: 'smooth' });
            }
            setTimeout(ub, 100);
        }, 4000);
        slider.addEventListener('mouseenter', function() { clearInterval(autoInterval); });
        slider.addEventListener('mouseleave', function() {
            autoInterval = setInterval(function() {
                if (slider.scrollLeft + slider.clientWidth >= slider.scrollWidth - 10) {
                    slider.scrollTo({ left: 0, behavior: 'smooth' });
                } else {
                    slider.scrollBy({ left: 300, behavior: 'smooth' });
                }
                setTimeout(ub, 100);
            }, 4000);
        });
    }
});
function filterCodesBySeller(val) {
    var q = val.toLowerCase().trim();
    document.querySelectorAll('.code-slide').forEach(function(el) {
        el.style.display = (!q || el.getAttribute('data-seller').indexOf(q) !== -1) ? '' : 'none';
    });
}
function updateCountdowns() {
    document.querySelectorAll('.countdown-timer').forEach(function(el) {
        var ts = parseInt(el.getAttribute('data-kickoff'));
        if (!ts || ts <= 0) { el.textContent = ''; return; }
        var now = Math.floor(Date.now() / 1000);
        var diff = ts - now;
        if (diff <= 0) { el.textContent = ''; el.parentElement.style.display = 'none'; return; }
        var text = '';
        if (diff < 60) {
            text = diff + 's';
        } else if (diff < 3600) {
            text = Math.floor(diff / 60) + 'm ' + (diff % 60) + 's';
        } else if (diff < 86400) {
            text = Math.floor(diff / 3600) + 'h ' + Math.floor((diff % 3600) / 60) + 'm';
        } else {
            text = Math.floor(diff / 86400) + 'd ' + Math.floor((diff % 86400) / 3600) + 'h';
        }
        el.textContent = text;
    });
}
document.addEventListener('DOMContentLoaded', function() { updateCountdowns(); setInterval(updateCountdowns, 1000); });
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.load-more-btn');
    if (!btn) return;
    var section = btn.dataset.section;
    var current = parseInt(btn.dataset.current);
    var step = parseInt(btn.dataset.step);
    var total = parseInt(btn.dataset.total);
    var next = Math.min(current + step, total);
    var items;
    if (section === 'availableCodes') {
        items = document.querySelectorAll('#availableCodesSlider .code-slide');
    } else if (section === 'purchasedCodes') {
        items = document.querySelectorAll('#purchasedCodesGrid .purchased-code-item');
    }
    if (items) {
        items.forEach(function(el) {
            var idx = parseInt(el.dataset.index);
            if (idx < next) el.style.display = '';
        });
    }
    btn.dataset.current = next;
    if (next >= total) {
        btn.style.display = 'none';
    } else {
        btn.textContent = 'Show ' + (total - next) + ' more';
    }
});
</script>

<div class="row g-3 mt-3">
    <!-- Winning Slips Showcase -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="card-title"><span style="font-size:1rem;">🏆</span> Winning Slips / Slip Zilizoshinda</h6>
            </div>
            <div class="p-3">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-2">
                        <label class="form-label text-muted small">Upload your winning slip screenshot / Pakia picha ya slip uliyoshinda</label>
                        <input type="file" name="slip_image" accept="image/*" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <input type="text" name="slip_display_name" class="form-control form-control-sm" placeholder="Your display name (optional) / Jina lako (si lazima)">
                    </div>
                    <div class="mb-2">
                        <input type="text" name="slip_description" class="form-control form-control-sm" placeholder="e.g. Won 50k TZS from 2k stake / Nilishinda 50k kwa 2k">
                    </div>
                    <button type="submit" name="upload_slip" value="1" class="btn btn-sm" style="background:var(--primary);color:white;border:none;font-weight:700;">Submit / Wasilisha</button>
                    <small class="d-block text-muted mt-1" style="font-size:0.65rem;">Submitted slips are reviewed before publishing / Slip zinakaguliwa kabla ya kuchapishwa</small>
                </form>
                <?php if (!empty($userSlips)): ?>
                <div class="mt-3" style="border-top:1px solid var(--border-color);padding-top:0.5rem;">
                    <small style="font-weight:600;color:var(--text-light);">Your submissions / Mawasilisho yako:</small>
                    <?php foreach (array_slice($userSlips, 0, 3) as $us): ?>
                    <div class="d-flex align-items-center gap-2 mt-1" style="font-size:0.7rem;">
                        <span style="color:<?= $us['status']==='approved'?'#22c55e':'#f59e0b'?>;"><?= $us['status']==='approved'?'✅':'⏳'?></span>
                        <?= htmlspecialchars($us['description'] ?: 'Slip #'.$us['id']) ?>
                        <span style="color:var(--muted);font-size:0.6rem;">- <?= date('M d', strtotime($us['created_at'])) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Purchased Codes -->
    <div class="col-md-6">
        <?php if (!empty($purchasedCodes)): 
        $pcLimit = 6; $pcTotal = count($purchasedCodes);
        ?>
        <div class="card h-100">
            <div class="card-header"><h6 class="card-title">Your Purchased Codes (<?= $pcTotal ?>)</h6></div>
            <div class="p-3">
                <div id="purchasedCodesGrid" class="d-flex gap-3 flex-wrap">
                    <?php foreach ($purchasedCodes as $i => $pc): ?>
                    <div class="purchased-code-item" data-index="<?= $i ?>" style="width:280px;<?= $i >= $pcLimit ? 'display:none;' : '' ?>">
                        <div class="pick-card h-100" style="padding:0.75rem;margin-bottom:0;display:flex;flex-direction:column;">
                            <?php if (!empty($pc['badge']) && isset(CODE_BADGES[$pc['badge']])): $bd = CODE_BADGES[$pc['badge']]; ?>
                            <div style="margin-bottom:0.35rem;"><span style="display:inline-block;background:<?=$bd['bg']?>;color:<?=$bd['color']?>;padding:0.06rem 0.45rem;border-radius:4px;font-size:0.6rem;font-weight:700;text-transform:uppercase;">🏆 <?=htmlspecialchars($bd['label'])?></span></div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between align-items-start">
                                <strong style="font-size:0.85rem;"><?= htmlspecialchars($pc['description']) ?></strong>
                                <span class="badge" style="background:#10B981;color:white;font-size:0.65rem;">Unlocked</span>
                            </div>
                            <div style="font-size:1.2rem;font-weight:800;letter-spacing:3px;color:var(--primary);margin:0.5rem 0;display:flex;align-items:center;gap:0.4rem;word-break:break-all;">
                                <?= htmlspecialchars($pc['code']) ?>
                                <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($pc['code'], ENT_QUOTES) ?>').then(()=>{this.innerHTML='✓';setTimeout(()=>{this.innerHTML='<i class=\'fas fa-copy\'></i>'},2000)}).catch(()=>{alert('Failed to copy')})" style="background:var(--primary);color:white;border:none;padding:3px 8px;border-radius:5px;font-size:0.7rem;cursor:pointer;letter-spacing:0;white-space:nowrap;" title="Copy code"><i class="fas fa-copy"></i></button>
                            </div>
                            <div class="text-muted small" style="font-size:0.72rem;">Markets: <?= htmlspecialchars($pc['matches']) ?></div>
                            <?php if ($pc['odds']): ?><div class="text-muted small" style="font-size:0.72rem;">Total Odds: <strong><?= $pc['odds'] ?>x</strong></div><?php endif; ?>
                            <div class="text-muted small" style="font-size:0.72rem;">Seller: <?= htmlspecialchars($pc['seller_phone']) ?> • <?= date('M d, H:i', strtotime($pc['purchased_at'])) ?></div>
                            <div style="margin-top:auto;">
                            <?php $existingRating = getBuyerRating($pc['seller_id'], $user['id']); if ($existingRating > 0): ?>
                            <div class="mt-1 small" style="color:#F59E0B;font-size:0.75rem;">
                                Your rating: <?php for ($r = 1; $r <= 5; $r++): ?><?= $r <= $existingRating ? '★' : '☆' ?><?php endfor; ?>
                            </div>
                            <?php else: ?>
                            <form method="POST" class="mt-1 d-flex align-items-center gap-1" style="font-size:0.75rem;flex-wrap:wrap;">
                                <input type="hidden" name="action" value="rate_seller">
                                <input type="hidden" name="seller_id" value="<?= $pc['seller_id'] ?>">
                                <span class="text-muted small" style="font-size:0.65rem;">Rate:</span>
                                <?php for ($r = 1; $r <= 5; $r++): ?>
                                <button type="submit" name="rating" value="<?= $r ?>" class="btn p-0 border-0" style="background:none;color:#F59E0B;font-size:0.9rem;cursor:pointer;line-height:1;" title="<?= $r ?> star<?= $r > 1 ? 's' : '' ?>">☆</button>
                                <?php endfor; ?>
                            </form>
                            <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($pcTotal > $pcLimit): ?>
                <div class="text-center mt-2">
                    <button class="btn btn-sm load-more-btn" data-section="purchasedCodes" data-current="<?= $pcLimit ?>" data-step="<?= $pcLimit ?>" data-total="<?= $pcTotal ?>" style="background:var(--primary);color:white;border-radius:6px;padding:4px 16px;font-size:0.75rem;font-weight:600;">Show <?= $pcTotal - $pcLimit ?> more</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
</div>
<?php endif; ?>

<!-- Tipster Tab -->


</div>
</main>

<footer class="footer">
<div class="footer-content">
<div class="responsible-gambling"><h5>Gamble Responsibly</h5><p>Must be 18+ to use this service. Only bet what you can afford to lose. If you feel you have a gambling problem, seek help immediately. Predixa is a data driven analytical supportive tool for betting - past performance does not guarantee future results.</p></div>
<div class="footer-links"><a href="terms">Terms of Service</a><a href="privacy">Privacy Policy</a><a href="contact">Contact Support</a><a href="https://www.begambleaware.org/" target="_blank" rel="noopener noreferrer">Responsible Gambling</a></div>
<p class="footer-copy">© <?= date('Y') ?> Predixa. All rights reserved. | Made with <i class="fas fa-heart" style="color: var(--accent);"></i> for Smart Punters</p>
</div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>setTimeout(function() { location.reload(); }, 300000);</script>

<script>
document.getElementById('hamburgerBtn')?.addEventListener('click', function(e) {
    e.stopPropagation();
    document.getElementById('headerMenu').classList.toggle('active');
});
document.addEventListener('click', function(e) {
    const menu = document.getElementById('headerMenu');
    const btn = document.getElementById('hamburgerBtn');
    if (menu && btn && !menu.contains(e.target) && !btn.contains(e.target) && menu.classList.contains('active')) {
        menu.classList.remove('active');
    }
});
document.querySelectorAll('#headerMenu a').forEach(link => {
    link.addEventListener('click', () => {
        const menu = document.getElementById('headerMenu');
        if (menu) menu.classList.remove('active');
    });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const activeTab = document.querySelector('.nav-link.active[data-bs-target]');
    if (activeTab) {
        const tabId = activeTab.getAttribute('data-bs-target').replace('#', '');
        fetch('log_tab', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'tab=' + encodeURIComponent(tabId) });
    }
});
document.querySelectorAll('button[data-bs-toggle="pill"]').forEach(tabBtn => {
    tabBtn.addEventListener('click', function () {
        if (this.classList.contains('disabled')) return;
        const tabId = this.getAttribute('data-bs-target').replace('#', '');
        fetch('log_tab', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'tab=' + encodeURIComponent(tabId) });
    });
});
</script>

<!-- Buy Credits Modal -->
<div class="modal fade" id="buyCreditsModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="background: #fff; border: 1px solid #E5E7EB; border-radius: 16px;">
<div class="modal-header border-0 pb-0">
<h5 class="modal-title fw-bold" style="color: #1F2937;"><i class="fas fa-coins me-1" style="color: #F59E0B;"></i>Buy Publisher Credits</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body px-4">
<?php if (isset($_SESSION['flash_success'])): ?>
<div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['flash_error'])): ?>
<div class="alert alert-danger"><?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>
<div class="mb-3 p-3" style="background: #F9FAFB; border-radius: 8px;">
<div class="d-flex justify-content-between mb-2"><span class="text-muted">Your Balance:</span><strong id="creditBalanceDisplay" style="color: #1F2937;"><?= $publisherCredits ?> credits</strong></div>
<div class="d-flex justify-content-between mb-2"><span class="text-muted">Cost per Credit:</span><strong style="color: #059669;"><?= number_format(PUBLISHER_CREDIT_COST) ?> TZS</strong></div>
<div class="d-flex justify-content-between mb-2"><span class="text-muted">Free Welcome Credits:</span><strong style="color: #059669;"><?= FREE_CREDITS_PER_WEEK ?> credits</strong></div>
<hr style="border-color: #E5E7EB;">
<div class="small text-muted mb-0">Free welcome credits awarded on signup. Purchase more at <?= number_format(PUBLISHER_CREDIT_COST) ?> TZS each when you run out.</div>
</div>
<div class="mb-4 p-3" style="background: #F0FDF4; border-radius: 8px; border: 1px solid #D1FAE5;">
<div class="small fw-bold mb-2" style="color: #065F46;">Send payment to:</div>
<?php
$adminMethods = [];
$adminUser = null;
$admDb = getDB();
if ($admDb) {
    $admStmt = $admDb->prepare("SELECT publisher_payment_methods FROM web_users WHERE id = ?");
    $admStmt->execute([1]);
    $adminUser = $admStmt->fetch();
}
if ($adminUser && !empty($adminUser['publisher_payment_methods'])) {
    $adminMethods = json_decode($adminUser['publisher_payment_methods'], true) ?? [];
}
$mpesaNumber = '';
$snippeLabel = '';
$whatsappNumber = '';
foreach ($adminMethods as $am) {
    $m = strtolower($am['method'] ?? '');
    if (strpos($m, 'mpesa') !== false || strpos($m, 'm-pesa') !== false) {
        $mpesaNumber = $am['account_number'] ?? '';
    }
    if (strpos($m, 'snippe') !== false) {
        $snippeLabel = $am['method'];
        if (empty($whatsappNumber)) $whatsappNumber = $am['account_number'] ?? '';
    }
}
$fmtShortcode = substr(PAYMENT_SHORTCODE, 0, 4) . ' ' . substr(PAYMENT_SHORTCODE, 4);
$fmtMpesa = '';
if ($mpesaNumber) {
    $d = preg_replace('/[^0-9]/', '', $mpesaNumber);
    if (strlen($d) >= 7) $fmtMpesa = substr($d, 0, 4) . ' ' . substr($d, 4, 3) . ' ' . substr($d, 7);
    else $fmtMpesa = $mpesaNumber;
}
$waNum = $whatsappNumber ? preg_replace('/[^0-9]/', '', $whatsappNumber) : '';
if ($waNum) $waNum = '255' . ltrim($waNum, '0');
?>
<div class="small mb-1"><span class="text-muted">Name:</span> <strong style="color:#1F2937;"><?= htmlspecialchars(PAYMENT_COMPANY) ?></strong></div>
<div class="small mb-1"><span class="text-muted">Selcombank account:</span> <strong style="color:#1F2937;"><?= htmlspecialchars(SELCOM_BANK_ACCOUNT) ?></strong></div>
<div class="small mb-1"><span class="text-muted">Selcom Till Number:</span> <strong style="color:#1F2937;"><?= htmlspecialchars($fmtShortcode) ?></strong></div>
<?php if ($mpesaNumber): ?>
<div class="small mb-1"><span class="text-muted">M-PESA:</span> <strong style="color:#1F2937;"><?= htmlspecialchars($fmtMpesa) ?></strong></div>
<?php endif; ?>
<?php if ($snippeLabel): ?>
<div class="small mb-1"><span class="text-muted">Snippe:</span> <strong style="color:#1F2937;">Request a payment link via WhatsApp</strong></div>
<?php endif; ?>
<?php if ($waNum): ?>
<div class="mt-2 pt-2" style="border-top:1px dashed #D1FAE5;">
    <a href="https://wa.me/<?= $waNum ?>?text=Hi%2C%20I%20need%20help%20paying%20for%20Predixa%20credits" target="_blank" rel="noopener" style="color:#065F46;font-weight:600;text-decoration:none;font-size:0.85rem;">
        <i class="fab fa-whatsapp me-1"></i>Request more payment details via WhatsApp
    </a>
</div>
<?php endif; ?>
</div>
<form method="POST" class="mt-3">
<input type="hidden" name="action" value="buy_credits">
<div class="mb-4">
<label class="form-label text-muted small fw-bold">Number of Credits</label>
<select name="credits" class="form-select" id="creditQty">
<option value="5">5 credits — <?= number_format(5 * PUBLISHER_CREDIT_COST) ?> TZS</option>
<option value="10" selected>10 credits — <?= number_format(10 * PUBLISHER_CREDIT_COST) ?> TZS</option>
<option value="20">20 credits — <?= number_format(20 * PUBLISHER_CREDIT_COST) ?> TZS</option>
<option value="30">30 credits — <?= number_format(30 * PUBLISHER_CREDIT_COST) ?> TZS</option>
<option value="50">50 credits — <?= number_format(50 * PUBLISHER_CREDIT_COST) ?> TZS</option>
</select>
</div>
<div class="mb-4">
<label class="form-label text-muted small fw-bold">Payment Reference Number *</label>
<input type="text" name="reference_number" class="form-control" placeholder="Enter the confirmation code from SMS" required>
<small class="text-muted">Copy the reference number from the M-Pesa/Mixx/Airtel confirmation message</small>
</div>
<button type="submit" class="btn btn-premium w-100 py-2"><i class="fas fa-check-circle me-1"></i>Submit Payment for Credits</button>
</form>
</div>
</div>
</div>
</div>

<!-- Payment Modal for Code Purchase -->
<div class="modal fade" id="codePaymentModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="background: #fff; border: 1px solid #E5E7EB; border-radius: 16px;">
<div class="modal-header border-0 pb-0">
<h5 class="modal-title fw-bold" style="color: #1F2937;"><i class="fas fa-ticket-alt me-1" style="color: #8B5CF6;"></i>Unlock Betting Code</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body px-4">
<div id="codePaymentAlert" class="alert d-none"></div>
<div class="mb-3 p-3" style="background: #F9FAFB; border-radius: 8px;">
<div class="d-flex justify-content-between mb-2"><span class="text-muted" style="font-size:0.9rem;">Code</span><strong id="modalCodeDesc" style="color: #1F2937; text-align:right;"></strong></div>
<div class="d-flex justify-content-between mb-2 align-items-center"><span class="text-muted" style="font-size:0.9rem;">Publisher</span><strong id="modalPublisher" style="color: #1F2937;"></strong></div>
<div id="modalRatingRow" class="d-flex justify-content-between mb-2" style="display:none;"><span class="text-muted" style="font-size:0.9rem;">Seller Rating</span><strong id="modalRating" style="color: #F59E0B;"></strong></div>
<div id="modalSalesRow" class="mb-2" style="display:none; background:#FEF3C7; border:1px solid #FDE68A; border-radius:6px; padding:0.4rem 0.6rem;"><span style="font-size:0.85rem;color:#92400E;"><span style="font-size:1rem;">🟢</span> <strong id="modalSalesBadge"></strong></span></div>
<div class="d-flex justify-content-between mb-2"><span class="text-muted" style="font-size:0.9rem;">Price</span><strong style="color: #059669;"><?= number_format(BETTING_CODE_PRICE) ?> TZS</strong></div>
</div>
<div class="mb-4 p-3" style="background: #F0FDF4; border-radius: 8px; border: 1px solid #D1FAE5;">
<div class="small fw-bold mb-2" style="color: #065F46;"><i class="fas fa-paper-plane me-1"></i>Send <?= number_format(BETTING_CODE_PRICE) ?> TZS to the seller via:</div>
<div id="modalPublisherPayment" style="font-size:0.9rem;word-break:break-word;">
<div class="mb-1"><span class="text-muted" style="font-size:0.85rem;">Name:</span> <strong style="color: #1F2937;" id="modalPubName">—</strong></div>
<div class="mb-1"><span class="text-muted" style="font-size:0.85rem;">Number:</span> <strong style="color: #1F2937;" id="modalPubNumber">—</strong></div>
<div><span class="text-muted" style="font-size:0.85rem;">Method:</span> <strong style="color: #1F2937;" id="modalPubBank">—</strong></div>
</div>
<div id="modalExtraMethods" class="mt-2" style="border-top: 1px dashed #D1FAE5; padding-top: 0.5rem; display: none;"></div>
<div id="modalWhatsAppRow" class="mt-2" style="display:none;"></div>
</div>
<form id="codePaymentForm" method="POST" class="mt-3">
<input type="hidden" name="action" value="buy_code">
<input type="hidden" name="code_id" id="modalCodeId" value="">
<div class="mb-4">
<label class="form-label text-muted small fw-bold">Payment Reference Number *</label>
<input type="text" name="reference_number" class="form-control" placeholder="Enter the confirmation code from SMS" required>
<small class="text-muted">Copy the reference from the M-Pesa/Mixx/Airtel confirmation message</small>
</div>
<button type="submit" class="btn btn-premium w-100 py-2"><i class="fas fa-check-circle me-1"></i>Submit Payment for Verification</button>
</form>
</div>
</div>
</div>
</div>

<script>
function openCodePayment(id, desc, price, phone, sales, pubNumber, pubBank, pubName, extraMethodsJson, ratingAvg, ratingCount, pubWhatsapp) {
    document.getElementById('modalCodeId').value = id;
    document.getElementById('modalCodeDesc').textContent = desc;
    document.getElementById('modalPublisher').textContent = phone;
    var rr = document.getElementById('modalRatingRow');
    var rl = document.getElementById('modalRating');
    if (ratingCount && parseInt(ratingCount) > 0) {
        rr.style.display = 'flex';
        var full = Math.floor(parseFloat(ratingAvg));
        var stars = '';
        for (var i = 0; i < full; i++) stars += '★';
        if (parseFloat(ratingAvg) - full >= 0.5) stars += '½';
        rl.innerHTML = stars + ' ' + parseFloat(ratingAvg).toFixed(1) + ' <span class="text-muted" style="font-size:0.75rem;">(' + ratingCount + ')</span>';
    } else { rr.style.display = 'none'; }
    var sr = document.getElementById('modalSalesRow');
    var sb = document.getElementById('modalSalesBadge');
    if (sales && parseInt(sales) > 0) {
        sr.style.display = 'block';
        var txt = '🟢 <strong>' + sales + ' person' + (parseInt(sales) > 1 ? 's' : '') + ' bought this</strong>';
        if (parseInt(sales) >= 3) txt += ' <span class="badge" style="background:#10B981;color:#fff;font-size:0.7rem;"><i class="fas fa-crown me-1"></i>Top Seller</span>';
        sb.innerHTML = txt;
    } else { sr.style.display = 'none'; }
    document.getElementById('modalPubName').textContent = pubName || '—';
    document.getElementById('modalPubNumber').textContent = pubNumber || '—';
    document.getElementById('modalPubBank').textContent = pubBank || '—';
    var extraDiv = document.getElementById('modalExtraMethods');
    if (extraMethodsJson) {
        try {
            var methods = JSON.parse(extraMethodsJson);
            if (methods && methods.length > 0) {
                var html = '<div class="small fw-bold mb-1" style="color: #065F46;">Also accepts payment via:</div>';
                methods.forEach(function(m) {
                    html += '<div class="mb-2" style="font-size:0.85rem;word-break:break-word;">';
                    html += '<span style="background:#D1FAE5;color:#065F46;font-weight:700;padding:0 0.4rem;border-radius:3px;display:inline-block;margin-bottom:2px;">' + escapeHtml(m.method || '?') + '</span>';
                    if (m.account_number) html += '<div style="color:#1F2937;font-weight:600;margin-top:1px;">' + escapeHtml(m.account_number) + '</div>';
                    if (m.account_name) html += '<div class="text-muted" style="font-size:0.8rem;">(' + escapeHtml(m.account_name) + ')</div>';
                    html += '</div>';
                });
                extraDiv.innerHTML = html;
                extraDiv.style.display = 'block';
            } else { extraDiv.style.display = 'none'; }
        } catch(e) { extraDiv.style.display = 'none'; }
    } else { extraDiv.style.display = 'none'; }
    var waRow = document.getElementById('modalWhatsAppRow');
    if (pubWhatsapp) {
        var waNum = pubWhatsapp.replace(/[^0-9]/g, '');
        if (waNum) {
            waNum = '255' + waNum.replace(/^0+/, '');
            waRow.innerHTML = '<a href="https://wa.me/' + waNum + '?text=Hi%2C%20I%27m%20interested%20in%20your%20betting%20code" target="_blank" rel="noopener" style="display:flex;align-items:center;justify-content:center;gap:0.5rem;padding:0.5rem;background:#E8F5E9;border-radius:8px;border:1px solid #C8E6C9;color:#2E7D32;font-weight:600;text-decoration:none;font-size:0.85rem;"><i class="fab fa-whatsapp" style="font-size:1.1rem;"></i> Contact seller via WhatsApp</a>';
            waRow.style.display = 'block';
        } else { waRow.style.display = 'none'; }
    } else { waRow.style.display = 'none'; }
    document.getElementById('codePaymentAlert').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('codePaymentModal')).show();
}
function escapeHtml(t) {
    var d = document.createElement('div');
    d.textContent = t;
    return d.innerHTML;
}
</script>
</body>
</html>
