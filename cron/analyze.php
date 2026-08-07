<?php
$isCLI = php_sapi_name() === 'cli';
$secretKey = 'pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580';
$providedKey = $isCLI ? ($argv[1] ?? '') : ($_GET['key'] ?? '');

if ($providedKey !== $secretKey) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid key']));
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/OddsAnalyzer.php';
require_once __DIR__ . '/../includes/signals_engine.php';

$db = getDB();
if (!$db) { die(json_encode(['status' => 'error', 'message' => 'DB failed'])); }

$analysisLog = [];
$analysisLog[] = '[' . date('H:i:s') . '] Starting pick analysis...';

try {
    $analyzer = new OddsAnalyzer();
    $analysisLog[] = '[' . date('H:i:s') . '] Fetching odds data from Google Sheets via OAuth2...';
    $tips = $analyzer->getTips();

    if (!empty($analyzer->guardLog)) {
        foreach ($analyzer->guardLog as $guardMsg) {
            $analysisLog[] = '[' . date('H:i:s') . '] ⚠️ ' . $guardMsg;
        }
    }

    if (empty($tips)) {
        $analysisLog[] = '[' . date('H:i:s') . '] No picks generated.';
        echo json_encode(['status' => 'ok', 'log' => $analysisLog, 'inserted' => 0]);
        exit;
    }

    $analysisLog[] = '[' . date('H:i:s') . '] Raw tips from analyzer: ' . count($tips);

    // --- Phase 0: Odds-signals post-processing (Win 1UP, Under 3.5) ---
    $under25Pool = [];
    foreach ($tips as &$tip) {
        $favOdds = (float)($tip['actual_odds'] ?? 0);
        $favDelta = (float)($tip['fav_delta'] ?? 0);
        $dx = (float)($tip['draw_delta'] ?? 0);
        $oppDelta = (float)($tip['opp_delta'] ?? 0);
        $pickType = $tip['pick_type'] ?? '';
        $balanced = ($tip['home_odds'] ?? 0) >= 2.0 && ($tip['home_odds'] ?? 0) < 3.0 && ($tip['draw_odds'] ?? 0) >= 2.0 && ($tip['draw_odds'] ?? 0) < 3.0 && ($tip['away_odds'] ?? 0) >= 2.0 && ($tip['away_odds'] ?? 0) < 3.0;
        $drawDeltaZero = abs($dx) < 0.5;
        $drawDropping = $dx < 0;
        $favStableRising = $favDelta >= -1;
        $oppStableRising = $oppDelta >= -1;
        $drawIsPick = $dx <= -1.5 && $favDelta >= -1 && $oppDelta >= -1;
        $underCondition = ($favOdds > 0 && $favOdds >= 1.10 && $favOdds < 1.20) || $balanced;
        $suggestUnder = ($drawDropping || $drawDeltaZero) && $favStableRising && $oppStableRising && $underCondition;
        if ($drawIsPick) $suggestUnder = true;

        $isWin1up = $favOdds > 0 && $favOdds < 1.20 && abs($favDelta) <= 1 && !in_array($pickType, ['over', 'gg', 'corners']);
        if ($isWin1up) {
            $tip['pick'] = ($tip['fav_team'] ?? '') . ' Win';
            $tip['pick_type'] = 'win';
            $tip['pattern_badge'] = ($tip['fav_team'] ?? '') . ' WIN 1UP';
            $tip['safety_notes'][] = 'Win 1UP — short-priced favorite with stable odds';
            $analysisLog[] = '[' . date('H:i:s') . '] Win 1UP: ' . ($tip['match'] ?? '');
        }

        if ($suggestUnder && $pickType !== 'over' && $pickType !== 'gg') {
            $leagueName = trim($tip['league'] ?? '');
            if (!isUnder35Blocked($leagueName)) {
                $under25Pool[] = $tip;
                $analysisLog[] = '[' . date('H:i:s') . '] Under 3.5: ' . ($tip['match'] ?? '');
            }
        }
    }
    unset($tip);
    $analysisLog[] = '[' . date('H:i:s') . '] Post-processed ' . count($tips) . ' tips';

    // --- Multi-bookie verified boost ---
    $verifiedCount = 0;
    foreach ($tips as &$tip) {
        $home = $tip['fav_team'] ?? ($tip['match'] ? explode(' vs ', $tip['match'])[0] ?? '' : '');
        $away = $tip['match'] ? explode(' vs ', $tip['match'])[1] ?? '' : '';
        if (!$home || !$away) continue;
        $pt = $tip['pick_type'] ?? '';
        $pv = $tip['pick'] ?? '';
        $marketMap = ['win' => '1X2', 'dc' => '1X2', 'over' => 'Over 2.5 Goals', 'gg' => 'GG (BTTS)'];
        $market = $marketMap[$pt] ?? null;
        if (!$market) {
            if (stripos($pv, 'Under') !== false) $market = 'Under 2.5 Goals';
            elseif (stripos($pv, 'Over') !== false) $market = 'Over 2.5 Goals';
            elseif (stripos($pv, 'BTTS') !== false || stripos($pv, 'GG') !== false) $market = 'GG (BTTS)';
            else $market = '1X2';
        }
        $verified = getVerified($home, $away, $market);
        if ($verified && $verified['agreement'] === 'down' && $verified['count'] >= 2) {
            $tip['win_rate_low'] = min(99, (int)($tip['win_rate_low'] ?? 0) + 10);
            $tip['win_rate_high'] = min(99, (int)($tip['win_rate_high'] ?? 0) + 8);
            $tip['safety_notes'][] = 'Verified: ' . implode('+', $verified['bookies']) . ' agree ' . $market;
            $tip['pattern_badge'] = ($tip['pattern_badge'] ?? '') . ' VERIFIED';
            $verifiedCount++;
        }
    }
    unset($tip);
    $analysisLog[] = '[' . date('H:i:s') . '] Verified boost: ' . $verifiedCount . ' tips';

    // --- Phase 0.5: Bayesian agreement boost ---
    $bayesianAgreeCount = 0;
    $bayesianDisagreeCount = 0;
    try {
        require_once __DIR__ . '/../classes/BayesianModel.php';
        $bmBoost = new BayesianModel();
        foreach ($tips as &$tip) {
            $matchName = $tip['match'] ?? '';
            $pickVal = $tip['pick'] ?? '';
            if (!$matchName || !$pickVal) continue;
            $agreement = $bmBoost->getAgreementScore($matchName, $pickVal);
            if ($agreement === null) continue;
            if ($agreement['strongly_agrees']) {
                $tip['win_rate_low'] = min(99, (int)($tip['win_rate_low'] ?? 0) + 8);
                $tip['win_rate_high'] = min(99, (int)($tip['win_rate_high'] ?? 0) + 6);
                if (!isset($tip['safety_notes'])) $tip['safety_notes'] = [];
                $tip['safety_notes'][] = '🤖 Bayesian agrees (' . $agreement['probability'] . '%) +' . $agreement['agreement'] . '%';
                $bayesianAgreeCount++;
            } elseif ($agreement['disagrees']) {
                $tip['win_rate_low'] = max(30, (int)($tip['win_rate_low'] ?? 50) - 12);
                $tip['win_rate_high'] = max(35, (int)($tip['win_rate_high'] ?? 60) - 10);
                if (!isset($tip['safety_notes'])) $tip['safety_notes'] = [];
                $tip['safety_notes'][] = '⚠️ Bayesian disagrees (' . $agreement['probability'] . '%) — model conflict';
                $bayesianDisagreeCount++;
            }
        }
        unset($tip);
        if ($bayesianAgreeCount > 0 || $bayesianDisagreeCount > 0) {
            $analysisLog[] = '[' . date('H:i:s') . '] <i class="fas fa-chart-bar me-1" style="color:#059669;"></i>Bayesian boost: ' . $bayesianAgreeCount . ' agreed ↑, ' . $bayesianDisagreeCount . ' disagreed ↓';
        }
    } catch (Exception $e) {
        $analysisLog[] = '[' . date('H:i:s') . '] <i class="fas fa-exclamation-triangle me-1" style="color:#F59E0B;"></i>Bayesian boost skipped: ' . $e->getMessage();
    }

    // --- Phase 1: Categorize tips ---
    $rolloverPool = [];
    $parlayPool = [];
    $resolvedOver15 = [];
    $resolvedCorners = [];
    $rolloverPoolSaved = [];
    $resolvedOver15Saved = [];
    $resolvedCornersSaved = [];
    $under25PoolSaved = [];
    $bestComboSaved = [];

    foreach ($tips as $tip) {
        $isCup = !empty($tip['is_cup']);
        $pickType = $tip['pick_type'] ?? '';
        $isOverType = ($pickType === 'over' || $pickType === 'gg');
        $favDelta = (float)($tip['fav_delta'] ?? 0);
        $oppDelta = (float)($tip['opp_delta'] ?? 0);
        $dx = (float)($tip['draw_delta'] ?? 0);
        $favOdds = (float)($tip['actual_odds'] ?? 0);
        $riskTier = strtoupper($tip['risk_tier'] ?? '');
        $notesStr = implode(' ', $tip['safety_notes'] ?? []);
        $pickText = $tip['pick'] ?? '';
        $leagueName = trim($tip['league'] ?? '');
        $isWin1up = str_contains($tip['pattern_badge'] ?? '', 'WIN 1UP');

        // Over 1.5
        if ($isOverType && !isOver15Blocked($leagueName) && isOver15League($leagueName)) {
            $isBalanced = abs($favDelta - $dx) <= 1.0 && abs($dx - $oppDelta) <= 1.0 && abs($favDelta - $oppDelta) <= 1.0;
            if (!$isBalanced) {
                $isPrimaryCond = $favDelta >= 0 && $favDelta <= 3.2 && $dx >= -2.5 && $dx <= 1.0 && $oppDelta < 0 && $dx >= $oppDelta;
                $isAltCond = $favDelta < -3.0 && $oppDelta >= 0 && $oppDelta <= 2.5 && $dx >= 0 && $dx <= 2.5;
                $isGoalFestCond = (abs($favDelta) > 6.0 || abs($oppDelta) > 6.0) && $dx >= 0 && $dx <= 1.5;
                if (($dx >= -2.5 && $dx <= 1.0) || $isAltCond || $isGoalFestCond) {
                    if (($isPrimaryCond || $isAltCond || $isGoalFestCond) && $favOdds >= 1.15 && $favOdds <= 2.15) {
                        if (($tip['fav_form']['form_rating'] ?? 0) >= 7.5) {
                            $tip['risk_tier'] = 'SAFE';
                            $tip['win_rate_low'] = max((int)($tip['win_rate_low'] ?? 0), 82);
                            $tip['win_rate_high'] = max((int)($tip['win_rate_high'] ?? 0), 90);
                        }
                        $resolvedOver15[] = $tip;
                    }
                }
            }
        }

        // Corners
        $cnLeague = trim($tip['league'] ?? '');
        if ($favOdds < 1.29 && $favDelta < -1 && ($oppDelta < 0 || $dx < 0) && !$isCup && isOver15League($cnLeague) && !isLeagueBlocked($cnLeague) && !isOver15Blocked($cnLeague)) {
            $cnScore = 0;
            if ($favOdds < 1.10) $cnScore += 25;
            elseif ($favOdds < 1.15) $cnScore += 20;
            elseif ($favOdds < 1.20) $cnScore += 15;
            else $cnScore += 10;
            $drop = abs($favDelta);
            if ($drop >= 5) $cnScore += 20;
            elseif ($drop >= 3) $cnScore += 15;
            elseif ($drop >= 1.5) $cnScore += 10;
            else $cnScore += 5;
            if ($cnScore >= 15) {
                $cnTip = $tip;
                $cnTip['pick'] = 'Most Corners ' . ($tip['fav_team'] ?? '');
                $cnTip['pick_type'] = 'corners';
                $cnTip['safety_notes'][] = 'Favorite corner hunt: odds ' . number_format($favOdds, 2) . ' dropping ' . number_format(abs($favDelta), 1) . '%';
                $resolvedCorners[] = $cnTip;
            }
        }

        // Rollover
        if (!$isCup && !$isOverType && in_array($riskTier, ['SAFE', 'MODERATE']) && ($isWin1up ? ($favOdds >= 1.15 && $favOdds <= 1.70) : ($favOdds >= 1.18 && $favOdds <= 1.70)) && abs($dx) <= 2) {
            if (strpos($pickText, '1X') !== false || strpos($pickText, 'X2') !== false || $isWin1up) {
                if (!isLeagueBlocked($tip['league'] ?? '')) {
                    if ($pickType === 'win' && !$isWin1up) {
                        $tip['pick'] = $tip['is_home_fav'] ? '1X' : 'X2';
                        $tip['pick_type'] = 'dc';
                        $tip['actual_odds'] = max(1.18, $favOdds * 0.75);
                    }
                    $rolloverPool[] = $tip;
                }
            }
        }

        // Parlay
        if (!$isCup) {
            $parlayNotesOk = strpos($notesStr, 'Flat DC with good win probability') !== false
                          || strpos($notesStr, 'Pattern fallback:') !== false
                          || (strpos($notesStr, 'Draw odds rising') !== false && strpos($notesStr, 'favorable for win pick') !== false)
                          || strpos($notesStr, 'Over 1.5 Goals - High Accuracy Filter') !== false;
            if ($parlayNotesOk) $parlayPool[] = $tip;
        }
    }

    // Suppress Over 1.5 that matches Under 3.5
    $under25MatchNames = array_map(fn($t) => $t['match'] ?? '', $under25Pool);
    $origOver15Count = count($resolvedOver15);
    $resolvedOver15 = array_values(array_filter($resolvedOver15, fn($t) => !in_array($t['match'] ?? '', $under25MatchNames)));
    $analysisLog[] = '[' . date('H:i:s') . '] Suppressed ' . ($origOver15Count - count($resolvedOver15)) . ' Over 1.5 picks (Under 3.5 conflict)';

    // Sort & limit rollover
    usort($rolloverPool, function ($a, $b) {
        $order = ['SAFE' => 0, 'MODERATE' => 1];
        $aOrd = $order[$a['risk_tier'] ?? ''] ?? 2;
        $bOrd = $order[$b['risk_tier'] ?? ''] ?? 2;
        if ($aOrd !== $bOrd) return $aOrd - $bOrd;
        $aOdds = (float)($a['actual_odds'] ?? 99);
        $bOdds = (float)($b['actual_odds'] ?? 99);
        if ($aOdds !== $bOdds) return $aOdds - $bOdds;
        return (int)($b['win_rate_low'] ?? 0) - (int)($a['win_rate_low'] ?? 0);
    });
    $rolloverPool = array_slice($rolloverPool, 0, 3);

    // Build parlay combo
    $overInParlay = array_slice(array_filter($parlayPool, fn($p) => ($p['pick_type'] ?? '') === 'over' || ($p['pick_type'] ?? '') === 'gg'), 0, 2);
    $dcInParlay = array_values(array_filter($parlayPool, fn($p) => ($p['pick_type'] ?? '') !== 'over' && ($p['pick_type'] ?? '') !== 'gg'));
    usort($dcInParlay, function ($a, $b) {
        $aWin = (int)($a['win_rate_low'] ?? 0);
        $bWin = (int)($b['win_rate_low'] ?? 0);
        if ($aWin !== $bWin) return $bWin - $aWin;
        return (float)($a['actual_odds'] ?? 99) - (float)($b['actual_odds'] ?? 99);
    });
    $oneX = array_values(array_filter($dcInParlay, fn($t) => strpos($t['pick'] ?? '', '1X') !== false));
    $nonOneX = array_values(array_filter($dcInParlay, fn($t) => strpos($t['pick'] ?? '', '1X') === false));
    $parlayOrdered = count($oneX) >= 2 ? array_merge($oneX, $nonOneX) : $dcInParlay;
    $parlayOrdered = array_merge($parlayOrdered, $overInParlay);

    $combinedParlay = [];
    $combinedOdds = 1.0;
    $bestCombo = null;
    $bestDiff = INF;
    foreach ($parlayOrdered as $pick) {
        if (count($combinedParlay) >= 19) break;
        $pickOdds = (float)($pick['actual_odds'] ?? 1.0);
        $testOdds = $combinedOdds * $pickOdds;
        if ($testOdds <= 40.0) {
            $combinedParlay[] = $pick;
            $combinedOdds = $testOdds;
            $diff = abs($combinedOdds - 20.0);
            if ($combinedOdds >= 10.0 && $diff < $bestDiff) {
                $bestCombo = $combinedParlay;
                $bestDiff = $diff;
            }
            if ($combinedOdds >= 10.0 && $combinedOdds <= 40.0 && $diff <= 3.0) break;
        }
    }
    if ($bestCombo === null) {
        $altCombo = [];
        $altOdds = 1.0;
        foreach ($parlayOrdered as $pick) {
            if (count($altCombo) >= 19) break;
            $pickOdds = (float)($pick['actual_odds'] ?? 1.0);
            $testOdds = $altOdds * $pickOdds;
            if ($testOdds <= 40.0) {
                $altCombo[] = $pick;
                $altOdds = $testOdds;
                if ($altOdds >= 8.0) break;
            }
        }
        if ($altOdds >= 8.0) $bestCombo = $altCombo;
    }

    // Convert DC → Win in parlay
    if (!empty($bestCombo)) {
        foreach ($bestCombo as &$cp) {
            $cpType = $cp['pick_type'] ?? '';
            if ($cpType === 'dc' && ($cp['win_rate_low'] ?? 0) >= 65 && ($cp['actual_odds'] ?? 0) >= 1.30) {
                if (strpos($cp['pick'] ?? '', '1X') !== false) {
                    $cp['pick'] = ($cp['fav_team'] ?? '') . ' Win';
                    $cp['pick_type'] = 'win';
                } elseif (strpos($cp['pick'] ?? '', 'X2') !== false) {
                    $cp['pick'] = ($cp['opp_team'] ?? '') . ' Win';
                    $cp['pick_type'] = 'win';
                }
            }
            if ($cp['pick_type'] === 'win' && (float)($cp['actual_odds'] ?? 0) > 0 && (float)($cp['actual_odds'] ?? 0) < 1.20 && abs((float)($cp['fav_delta'] ?? 0)) <= 1) {
                $cp['pattern_badge'] = ($cp['fav_team'] ?? '') . ' WIN 1UP';
                $cp['safety_notes'][] = 'Win 1UP — parlay leg';
            }
        }
        unset($cp);
    }

    // --- Phase 4: Filter existing + Save ---
    $existingToday = [];
    $existingStmt = $db->query("SELECT DISTINCT match_name FROM web_picks WHERE DATE(detected_at) = CURDATE()");
    if ($existingStmt) $existingToday = $existingStmt->fetchAll(PDO::FETCH_COLUMN);
    $filterExisting = function($pool) use ($existingToday) {
        return array_values(array_filter($pool, fn($p) => !in_array($p['match'], $existingToday)));
    };

    $rolloverPool = $filterExisting($rolloverPool);
    $resolvedOver15 = $filterExisting($resolvedOver15);
    $resolvedCorners = $filterExisting($resolvedCorners);
    $under25Pool = $filterExisting($under25Pool);
    $combinedParlay = $filterExisting($combinedParlay);
    if ($bestCombo) $bestCombo = $filterExisting($bestCombo);
    $allInserts = array_merge($rolloverPool, $resolvedOver15, $resolvedCorners, $under25Pool, $combinedParlay, $bestCombo ?? []);
    $replaceNames = array_unique(array_filter(array_map(fn($p) => $p['match'] ?? null, $allInserts)));
    if (!empty($replaceNames)) {
        $placeholders = implode(',', array_fill(0, count($replaceNames), '?'));
        $db->prepare("DELETE FROM web_picks WHERE DATE(detected_at) = CURDATE() AND match_name IN ($placeholders)")->execute(array_values($replaceNames));
    }

    $insStmt = $db->prepare("INSERT INTO web_picks (pick_type, match_name, pick_value, odds, risk_tier, win_rate_low, win_rate_high, league, match_time, details, safety_notes, pattern_badge, is_home_fav, fav_delta, opp_delta, draw_delta, home_odds, draw_odds, away_odds, detected_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $totalInserted = 0;

    $savePool = function($pool, $type) use ($insStmt, $db, &$totalInserted, $analysisLog) {
        foreach ($pool as $p) {
            try {
                $insStmt->execute([$type, $p['match'], $p['pick'], $p['actual_odds'], $p['risk_tier'], $p['win_rate_low'], $p['win_rate_high'], $p['league'], $p['details'] ? trim(explode('|', str_replace("\xF0\x9F\x95\x92", '', $p['details']))[0]) : '', $p['details'], implode('; ', $p['safety_notes'] ?? []), $p['pattern_badge'] ?? '', $p['is_home_fav'] ? 1 : 0, round($p['fav_delta'], 2), round($p['opp_delta'], 2), round($p['draw_delta'], 2), $p['home_odds'] ?? 0, $p['draw_odds'] ?? 0, $p['away_odds'] ?? 0]);
                $totalInserted++;
            } catch (Exception $e) { error_log("$type insert error: " . $e->getMessage()); }
        }
    };

    $savePool($rolloverPool, 'rollover');
    $analysisLog[] = '[' . date('H:i:s') . '] Saved ' . count($rolloverPool) . ' rollover picks';

    $savePool($resolvedOver15, 'over_15');
    $analysisLog[] = '[' . date('H:i:s') . '] Saved ' . count($resolvedOver15) . ' Over 1.5 picks';

    $savePool($resolvedCorners, 'most_corners');
    $analysisLog[] = '[' . date('H:i:s') . '] Saved ' . count($resolvedCorners) . ' corner picks';

    // Under 3.5 — exclude matches already saved as Over 1.5
    $over15MatchNames = array_map(fn($o) => $o['match'], $resolvedOver15);
    $under25Pool = array_values(array_filter($under25Pool, fn($u) => !in_array($u['match'], $over15MatchNames)));
    foreach ($under25Pool as $u25) {
        try {
            $u25Odds = (float)($u25['actual_odds'] ?? 0);
            $u25['safety_notes'][] = 'Under 3.5 Goals — draw stable conditions';
            $insStmt->execute(['under_25', $u25['match'], 'Under 3.5 Goals', $u25Odds, $u25['risk_tier'] ?? 'MODERATE', max((int)($u25['win_rate_low'] ?? 0), 65), max((int)($u25['win_rate_high'] ?? 0), 75), $u25['league'], $u25['details'] ? trim(explode('|', str_replace("\xF0\x9F\x95\x92", '', $u25['details']))[0]) : '', $u25['details'] ?? '', implode('; ', $u25['safety_notes']), $u25['pattern_badge'] ?? '', $u25['is_home_fav'] ? 1 : 0, round($u25['fav_delta'] ?? 0, 2), round($u25['opp_delta'] ?? 0, 2), round($u25['draw_delta'] ?? 0, 2), $u25['home_odds'] ?? 0, $u25['draw_odds'] ?? 0, $u25['away_odds'] ?? 0]);
            $totalInserted++;
        } catch (Exception $e) { error_log("Under25 insert error: " . $e->getMessage()); }
    }
    $analysisLog[] = '[' . date('H:i:s') . '] Saved ' . count($under25Pool) . ' Under 3.5 picks';

    // Parlay
    $comboPicks = $bestCombo ?? $combinedParlay;
    $savePool($comboPicks, 'parlay');
    $analysisLog[] = '[' . date('H:i:s') . '] Saved ' . (is_array($comboPicks) ? count($comboPicks) : 0) . ' parlay picks';

    $analysisLog[] = '[' . date('H:i:s') . '] Complete! Total saved: ' . $totalInserted;

    echo json_encode(['status' => 'ok', 'log' => $analysisLog, 'inserted' => $totalInserted]);
} catch (Exception $e) {
    $analysisLog[] = '[' . date('H:i:s') . '] ERROR: ' . $e->getMessage();
    echo json_encode(['status' => 'error', 'log' => $analysisLog, 'error' => $e->getMessage()]);
}
