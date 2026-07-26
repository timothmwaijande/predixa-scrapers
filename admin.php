<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'classes/OddsAnalyzer.php';
require_once 'includes/signals_engine.php';
requireLogin();

// Ensure is_demo column exists for Presentation Mode
$adb = getDB(); if ($adb) { try { $adb->exec("ALTER TABLE web_users ADD COLUMN is_demo TINYINT(1) DEFAULT 0 AFTER display_name"); } catch (PDOException $e) {} }

$user = getCurrentUser();
$premium = getPremiumStatus();
$isSuperAdmin = $premium['is_super_admin'];
$adminModules = $isSuperAdmin ? '*' : ($user ? getAdminPermissions($user['id']) : []);
if (!$isSuperAdmin && (is_array($adminModules) && empty($adminModules))) {
    header("Location: dashboard?error=access_denied");
    exit;
}

$msg = $error = '';
$analysisLog = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'approve' && isset($_POST['payment_id'])) {
            if (approvePayment($_POST['payment_id'], $_POST['tier'])) {
                $msg = "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i> Approved " . htmlspecialchars($_POST['tier']) . " successfully!";
            } else {
                $error = "Failed to approve.";
            }
        } elseif ($_POST['action'] === 'reject' && isset($_POST['payment_id'])) {
            $db = getDB();
            $reason = trim($_POST['rejection_reason'] ?? 'Wrong reference number');
            $stmt = $db->prepare("UPDATE payment_verifications SET status='rejected', rejection_reason=?, verified_at=NOW() WHERE id=?");
            $stmt->execute([$reason, $_POST['payment_id']]);
            $msg = "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>Payment rejected.";
        } elseif ($_POST['action'] === 'grant_admin' && isset($_POST['target_user_id'])) {
            $perms = isset($_POST['permissions']) ? $_POST['permissions'] : null;
            $res = grantAdminAccess((int)$_POST['target_user_id'], $user['id'], $_POST['reason'] ?? 'Granted by admin', $perms);
            $msg = $res['success'] ? "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>{$res['message']}" : "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>{$res['message']}";
        } elseif ($_POST['action'] === 'update_admin_perms' && isset($_POST['target_user_id'])) {
            $perms = isset($_POST['permissions']) ? $_POST['permissions'] : [];
            $res = updateAdminPermissions((int)$_POST['target_user_id'], $perms);
            $msg = $res['success'] ? "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>{$res['message']}" : "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>{$res['message']}";
        } elseif ($_POST['action'] === 'revoke_admin' && isset($_POST['target_user_id'])) {
            $res = revokeAdminAccess((int)$_POST['target_user_id']);
            $msg = $res['success'] ? "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>{$res['message']}" : "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>{$res['message']}";
        } elseif ($_POST['action'] === 'run_analysis') {
            $analysisLog = [];
            try {
                $analysisLog[] = '[' . date('H:i:s') . '] Starting pick analysis...';
                $analyzer = new OddsAnalyzer();
                $analysisLog[] = '[' . date('H:i:s') . '] Fetching odds data from Google Sheets via OAuth2...';
                $tips = $analyzer->getTips();
                if (empty($tips)) {
                    throw new Exception('No picks generated. Either no odds data in the lookback window or all matches were filtered out.');
                }
                $analysisLog[] = '[' . date('H:i:s') . '] Raw tips from analyzer: ' . count($tips);
                $db = getDB();
                if (!$db) throw new Exception('Database connection failed');
                $analysisLog[] = '[' . date('H:i:s') . '] Keeping existing picks (additive mode)';

                // --- Phase 0: Odds-signals post-processing ---
                // Apply Win 1UP, Under 3.5 conditions to raw tips
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
                    // When tips indicate Draw pick, always default to Under 3.5 (closed game)
                    if ($drawIsPick) $suggestUnder = true;

                    // Win 1UP — short-priced favorite, stable delta (only for win/dc picks)
                    $isWin1up = $favOdds > 0 && $favOdds < 1.20 && abs($favDelta) <= 1 && !in_array($pickType, ['over', 'gg', 'corners']);
                    if ($isWin1up) {
                        $tip['pick'] = ($tip['fav_team'] ?? '') . ' Win';
                        $tip['pick_type'] = 'win';
                        $tip['pattern_badge'] = ($tip['fav_team'] ?? '') . ' WIN 1UP';
                        $tip['safety_notes'][] = 'Win 1UP — short-priced favorite with stable odds';
                        $analysisLog[] = '[' . date('H:i:s') . '] Win 1UP: ' . ($tip['match'] ?? '');
                    }

                    // Under 3.5 Goals — draw dropping/0% + both sides stable/rising + under condition
                    if ($suggestUnder && $pickType !== 'over' && $pickType !== 'gg') {
                        $leagueName = trim($tip['league'] ?? '');
                        if (isUnder35Blocked($leagueName)) {
                            $analysisLog[] = '[' . date('H:i:s') . '] <i class="fas fa-ban me-1"></i>Blocked from Under 3.5: ' . htmlspecialchars($leagueName);
                        } else {
                            $under25Pool[] = $tip;
                            $analysisLog[] = '[' . date('H:i:s') . '] <i class="fas fa-arrow-down me-1" style="color:#FBBF24;"></i>Under 3.5: ' . ($tip['match'] ?? '');
                        }
                    }
                }
                unset($tip);
                $analysisLog[] = '[' . date('H:i:s') . '] Post-processed ' . count($tips) . ' tips (Win 1UP + Under 3.5)';

                // --- Multi-bookie verified boost ---
                $verifiedCount = 0;
                foreach ($tips as &$tip) {
                    $home = $tip['fav_team'] ?? ($tip['match'] ? explode(' vs ', $tip['match'])[0] ?? '' : '');
                    $away = $tip['match'] ? explode(' vs ', $tip['match'])[1] ?? '' : '';
                    if (!$home || !$away) continue;
                    $pickType = $tip['pick_type'] ?? '';
                    $pickVal = $tip['pick'] ?? '';
                    $marketMap = [
                        'win' => '1X2',
                        'dc' => '1X2',
                        'over' => 'Over 2.5 Goals',
                        'gg' => 'GG (BTTS)',
                    ];
                    $market = $marketMap[$pickType] ?? null;
                    if (!$market) {
                        if (stripos($pickVal, 'Under') !== false || stripos($pickVal, 'under') !== false) $market = 'Under 2.5 Goals';
                        elseif (stripos($pickVal, 'Over') !== false || stripos($pickVal, 'over') !== false) $market = 'Over 2.5 Goals';
                        elseif (stripos($pickVal, 'BTTS') !== false || stripos($pickVal, 'GG') !== false) $market = 'GG (BTTS)';
                        else $market = '1X2';
                    }
                    $verified = getVerified($home, $away, $market);
                    if ($verified && $verified['agreement'] === 'down' && $verified['count'] >= 2) {
                        $tip['win_rate_low'] = min(99, (int)($tip['win_rate_low'] ?? 0) + 10);
                        $tip['win_rate_high'] = min(99, (int)($tip['win_rate_high'] ?? 0) + 8);
                        if (!isset($tip['safety_notes'])) $tip['safety_notes'] = [];
                        $tip['safety_notes'][] = '✅ Verified: ' . implode('+', $verified['bookies']) . ' agree ' . $market . ' Δ' . $verified['avg_delta'] . '%';
                        $tip['pattern_badge'] = ($tip['pattern_badge'] ?? '') . ' VERIFIED';
                        $verifiedCount++;
                    }
                }
                if ($verifiedCount > 0) {
                    $analysisLog[] = '[' . date('H:i:s') . '] <i class="fas fa-check-circle me-1" style="color:#10B981;"></i>Verified boost applied to ' . $verifiedCount . ' tips (' . implode(', ', array_unique(array_map(fn($m) => $m, array_column(array_filter($tips, fn($t) => isset($t['pattern_badge']) && str_contains($t['pattern_badge'], 'VERIFIED')), 'pick_type'))) ?: ['?']) . ')';
                }

                // --- Phase 0.5: Bayesian agreement boost ---
                $bayesianAgreeCount = 0;
                $bayesianDisagreeCount = 0;
                try {
                    require_once __DIR__ . '/classes/BayesianModel.php';
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
                            // Downgrade confidence if Bayesian strongly disagrees
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

                // --- Phase 1: Categorize each tip (per predixa.py) ---
                // A tip can flow into multiple pools (e.g. Over 1.5 in both tab + parlay)
                $rolloverPool = [];
                $parlayPool = [];
                $resolvedOver15 = [];
                $resolvedCorners = [];

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

                    // ============================================
                    // OVER 1.5 GOALS — separate tab (all passers)
                    // ============================================
                    if ($isOverType) {
                        $isBalanced = abs($favDelta - $dx) <= 1.0 && abs($dx - $oppDelta) <= 1.0 && abs($favDelta - $oppDelta) <= 1.0;
                        if ($isBalanced) continue;

                        $isPrimaryCond = $favDelta >= 0 && $favDelta <= 3.2 && $dx >= -2.5 && $dx <= 1.0 && $oppDelta < 0 && $dx >= $oppDelta;
                        $isAltCond = $favDelta < -3.0 && $oppDelta >= 0 && $oppDelta <= 2.5 && $dx >= 0 && $dx <= 2.5;
                        $isGoalFestCond = (abs($favDelta) > 6.0 || abs($oppDelta) > 6.0) && $dx >= 0 && $dx <= 1.5;

                        if (!($dx >= -2.5 && $dx <= 1.0) && !$isAltCond && !$isGoalFestCond) continue;
                        if (!($isPrimaryCond || $isAltCond || $isGoalFestCond)) continue;
                        if ($favOdds < 1.15 || $favOdds > 2.15) continue;

                        if (($tip['fav_form']['form_rating'] ?? 0) >= 7.5) {
                            $tip['risk_tier'] = 'SAFE';
                            $tip['win_rate_low'] = max((int)($tip['win_rate_low'] ?? 0), 82);
                            $tip['win_rate_high'] = max((int)($tip['win_rate_high'] ?? 0), 90);
                        }

                        $leagueName = trim($tip['league'] ?? '');
                        if (isOver15League($leagueName)) {
                            if (isLeagueBlocked($leagueName) || isOver15Blocked($leagueName)) {
                                $analysisLog[] = '[' . date('H:i:s') . '] <i class="fas fa-ban me-1"></i>Blocked from Over 1.5: ' . htmlspecialchars($leagueName);
                            } else {
                                $resolvedOver15[] = $tip;
                            }
                        }
                    }

                    // ============================================
                    // MOST CORNERS — low-odds favorite dropping, underdog threatens
                    // ============================================
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
                            $notes = $cnTip['safety_notes'] ?? [];
                            $notes[] = '<i class="fas fa-flag me-1" style="color:#EF4444;"></i>Favorite corner hunt: odds ' . number_format($favOdds, 2) . ' dropping ' . number_format(abs($favDelta), 1) . '%';
                            $cnTip['safety_notes'] = $notes;
                            $resolvedCorners[] = $cnTip;
                            $analysisLog[] = '[' . date('H:i:s') . '] <i class="fas fa-arrow-right me-1"></i>Corners: ' . ($tip['match'] ?? '') . ' (fav odds ' . number_format($favOdds, 2) . ' dropping ' . number_format(abs($favDelta), 1) . '%)';
                        }
                    }

                    // ============================================
                    // ROLLOVER — most accurate picks for capital reinvestment
                    // ============================================
                    $isWin1up = str_contains($tip['pattern_badge'] ?? '', 'WIN 1UP');
                    $oddsOk = $isWin1up ? ($favOdds >= 1.15 && $favOdds <= 1.70) : ($favOdds >= 1.18 && $favOdds <= 1.70);
                    if (!$isCup && !$isOverType && in_array($riskTier, ['SAFE', 'MODERATE']) && $oddsOk && abs($dx) <= 2) {
                        if (strpos($pickText, '1X') !== false || strpos($pickText, 'X2') !== false || $isWin1up) {
                            if (isLeagueBlocked($tip['league'] ?? '')) {
                                $analysisLog[] = '[' . date('H:i:s') . '] <i class="fas fa-ban me-1"></i>Blocked from Rollover: ' . htmlspecialchars($tip['league'] ?? '');
                            } else {
                                if ($pickType === 'win' && !$isWin1up) {
                                    $tip['pick'] = $tip['is_home_fav'] ? '1X' : 'X2';
                                    $tip['pick_type'] = 'dc';
                                    $tip['actual_odds'] = max(1.18, $favOdds * 0.75);
                                }
                                $rolloverPool[] = $tip;
                            }
                        }
                    }

                    // ============================================
                    // PARLAY — per predixa.py parlay_handler
                    // ============================================
                    if (!$isCup) {
                        $parlayNotesOk = strpos($notesStr, 'Flat DC with good win probability') !== false
                                      || strpos($notesStr, 'Pattern fallback:') !== false
                                      || (strpos($notesStr, 'Draw odds rising') !== false && strpos($notesStr, 'favorable for win pick') !== false)
                                      || strpos($notesStr, 'Over 1.5 Goals - High Accuracy Filter') !== false;
                        if ($parlayNotesOk) {
                            $parlayPool[] = $tip;
                        }
                    }
                }

                // Suppress Over 1.5 picks that match Under 3.5 conditions
                $under25MatchNames = array_map(fn($t) => $t['match'] ?? '', $under25Pool);
                $origOver15Count = count($resolvedOver15);
                $resolvedOver15 = array_filter($resolvedOver15, fn($t) => !in_array($t['match'] ?? '', $under25MatchNames));
                $resolvedOver15 = array_values($resolvedOver15);
                $suppressedCount = $origOver15Count - count($resolvedOver15);
                if ($suppressedCount > 0) {
                    $analysisLog[] = '[' . date('H:i:s') . '] Suppressed ' . $suppressedCount . ' Over 1.5 picks (Under 3.5 conditions met)';
                }

                // --- Phase 2: Sort & Limit Rollover (max 3, per Python) ---
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
                $rolloverPicks = array_slice($rolloverPool, 0, 3);

                // --- Phase 3: Build Parlay Combination (~20x) ---
                // Separate Over 1.5 from DC/win picks (limit 2 over 1.5 in parlay)
                $overInParlay = [];
                $dcInParlay = [];
                foreach ($parlayPool as $p) {
                    $pt = $p['pick_type'] ?? '';
                    if ($pt === 'over' || $pt === 'gg') {
                        $overInParlay[] = $p;
                    } else {
                        $dcInParlay[] = $p;
                    }
                }
                $overInParlay = array_slice($overInParlay, 0, 2);

                // Sort DC picks by win_rate_low desc, odds asc (per Python)
                usort($dcInParlay, function ($a, $b) {
                    $aWin = (int)($a['win_rate_low'] ?? 0);
                    $bWin = (int)($b['win_rate_low'] ?? 0);
                    if ($aWin !== $bWin) return $bWin - $aWin;
                    return (float)($a['actual_odds'] ?? 99) - (float)($b['actual_odds'] ?? 99);
                });

                // Prefer 1X picks if >= 2 available (per Python: one_x_candidates)
                $oneX = array_filter($dcInParlay, function ($t) {
                    return strpos($t['pick'] ?? '', '1X') !== false;
                });
                $nonOneX = array_filter($dcInParlay, function ($t) {
                    return strpos($t['pick'] ?? '', '1X') === false;
                });
                $parlayOrdered = count($oneX) >= 2 ? array_merge(array_values($oneX), array_values($nonOneX)) : $dcInParlay;
                // Append limited over 1.5 at end
                $parlayOrdered = array_merge($parlayOrdered, $overInParlay);

                // Greedy algorithm: build to ~20x (per Python)
                $combinedParlay = [];
                $combinedOdds = 1.0;
                $maxPicks = 19;
                $bestCombo = null;
                $bestDiff = INF;

                foreach ($parlayOrdered as $pick) {
                    if (count($combinedParlay) >= $maxPicks) break;
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

                // Fallback: accept >= 8x if no combo in 10-40x range
                if ($bestCombo === null) {
                    $altCombo = [];
                    $altOdds = 1.0;
                    foreach ($parlayOrdered as $pick) {
                        if (count($altCombo) >= $maxPicks) break;
                        $pickOdds = (float)($pick['actual_odds'] ?? 1.0);
                        $testOdds = $altOdds * $pickOdds;
                        if ($testOdds <= 40.0) {
                            $altCombo[] = $pick;
                            $altOdds = $testOdds;
                            if ($altOdds >= 8.0) break;
                        }
                    }
                    if ($altOdds >= 8.0) {
                        $bestCombo = $altCombo;
                        $combinedOdds = $altOdds;
                    } else {
                        $analysisLog[] = '[' . date('H:i:s') . '] Parlay: insufficient picks for 8x+ combo (saving individual picks only)';
                    }
                }

                // Convert DC → Win predictions in final parlay (per Python)
                if (!empty($bestCombo)) {
                    foreach ($bestCombo as &$cp) {
                        $cpType = $cp['pick_type'] ?? '';
                        if ($cpType === 'dc' && ($cp['win_rate_low'] ?? 0) >= 65 && ($cp['actual_odds'] ?? 0) >= 1.30) {
                            $favTeam = $cp['fav_team'] ?? '';
                            $oppTeam = $cp['opp_team'] ?? '';
                            $pText = $cp['pick'] ?? '';
                            if (strpos($pText, '1X') !== false) {
                                $cp['pick'] = $favTeam . ' Win';
                                $cp['pick_type'] = 'win';
                                $notes = $cp['safety_notes'] ?? [];
                                $notes[] = 'Parlay: Win prediction (high confidence)';
                                $cp['safety_notes'] = $notes;
                            } elseif (strpos($pText, 'X2') !== false) {
                                $cp['pick'] = $oppTeam . ' Win';
                                $cp['pick_type'] = 'win';
                                $notes = $cp['safety_notes'] ?? [];
                                $notes[] = 'Parlay: Win prediction (high confidence)';
                                $cp['safety_notes'] = $notes;
                            }
                        }
                        // Re-apply Win 1UP to parlay win legs that qualify
                        if ($cp['pick_type'] === 'win' && (float)($cp['actual_odds'] ?? 0) > 0 && (float)($cp['actual_odds'] ?? 0) < 1.20 && abs((float)($cp['fav_delta'] ?? 0)) <= 1) {
                            $cp['pattern_badge'] = ($cp['fav_team'] ?? '') . ' WIN 1UP';
                            $notes = $cp['safety_notes'] ?? [];
                            $notes[] = 'Win 1UP — parlay leg';
                            $cp['safety_notes'] = $notes;
                        }
                    }
                    unset($cp);
                }

                // --- Phase 4: Save to database ---
                // First-signal locking: preserve the first entry per match per day
                $existingToday = [];
                $existingStmt = $db->query("SELECT DISTINCT match_name FROM web_picks WHERE DATE(detected_at) = CURDATE()");
                if ($existingStmt) { $existingToday = $existingStmt->fetchAll(\PDO::FETCH_COLUMN); }
                $filterExisting = function($pool) use ($existingToday) {
                    return array_values(array_filter($pool, fn($p) => !in_array($p['match'], $existingToday)));
                };
                $rolloverPicks = $filterExisting($rolloverPicks);
                $resolvedOver15 = $filterExisting($resolvedOver15);
                $resolvedCorners = $filterExisting($resolvedCorners);
                $under25Pool = $filterExisting($under25Pool);
                $combinedParlay = $filterExisting($combinedParlay);
                if ($bestCombo) $bestCombo = $filterExisting($bestCombo);
                // Delete today's rows that are being replaced (only matches we're about to re-insert)
                $allInserts = array_merge($rolloverPicks, $resolvedOver15, $resolvedCorners, $under25Pool, $combinedParlay, $bestCombo ?? []);
                $replaceNames = array_unique(array_filter(array_map(fn($p) => $p['match'] ?? null, $allInserts)));
                if (!empty($replaceNames)) {
                    $placeholders = implode(',', array_fill(0, count($replaceNames), '?'));
                    $delStmt = $db->prepare("DELETE FROM web_picks WHERE DATE(detected_at) = CURDATE() AND match_name IN ($placeholders)");
                    $delStmt->execute(array_values($replaceNames));
                }
                $insStmt = $db->prepare("INSERT INTO web_picks (pick_type, match_name, pick_value, odds, risk_tier, win_rate_low, win_rate_high, league, match_time, details, safety_notes, pattern_badge, is_home_fav, fav_delta, opp_delta, draw_delta, home_odds, draw_odds, away_odds, detected_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $totalInserted = 0;

                // 4a. Rollover picks (pick_type = "rollover")
                foreach ($rolloverPicks as $rp) {
                    try {
                        $insStmt->execute(['rollover', $rp['match'], $rp['pick'], $rp['actual_odds'], $rp['risk_tier'], $rp['win_rate_low'], $rp['win_rate_high'], $rp['league'], $rp['details'] ? trim(explode('|', str_replace("\xF0\x9F\x95\x92", '', $rp['details']))[0]) : '', $rp['details'], implode('; ', $rp['safety_notes'] ?? []), $rp['pattern_badge'] ?? '', $rp['is_home_fav'] ? 1 : 0, round($rp['fav_delta'], 2), round($rp['opp_delta'], 2), round($rp['draw_delta'], 2), $rp['home_odds'] ?? 0, $rp['draw_odds'] ?? 0, $rp['away_odds'] ?? 0]);
                        $totalInserted++;
                    } catch (Exception $e) { error_log("Rollover insert error: " . $e->getMessage()); }
                }
                $analysisLog[] = '[' . date('H:i:s') . '] Saved ' . count($rolloverPicks) . ' rollover picks';

                // 4b. Over 1.5 Goals (pick_type = "over_15") — ALL passers for the tab
                foreach ($resolvedOver15 as $ov) {
                    try {
                        $insStmt->execute(['over_15', $ov['match'], $ov['pick'], $ov['actual_odds'], $ov['risk_tier'], $ov['win_rate_low'], $ov['win_rate_high'], $ov['league'], $ov['details'] ? trim(explode('|', str_replace("\xF0\x9F\x95\x92", '', $ov['details']))[0]) : '', $ov['details'], implode('; ', $ov['safety_notes'] ?? []), $ov['pattern_badge'] ?? '', $ov['is_home_fav'] ? 1 : 0, round($ov['fav_delta'], 2), round($ov['opp_delta'], 2), round($ov['draw_delta'], 2), $ov['home_odds'] ?? 0, $ov['draw_odds'] ?? 0, $ov['away_odds'] ?? 0]);
                        $totalInserted++;
                    } catch (Exception $e) { error_log("Over15 insert error: " . $e->getMessage()); }
                }
                $analysisLog[] = '[' . date('H:i:s') . '] Saved ' . count($resolvedOver15) . ' Over 1.5 Goals picks';

                // 4c. Most Corners (pick_type = "most_corners")
                foreach ($resolvedCorners as $cr) {
                    try {
                        $insStmt->execute(['most_corners', $cr['match'], $cr['pick'], $cr['actual_odds'], $cr['risk_tier'], $cr['win_rate_low'], $cr['win_rate_high'], $cr['league'], $cr['details'] ? trim(explode('|', str_replace("\xF0\x9F\x95\x92", '', $cr['details']))[0]) : '', $cr['details'], implode('; ', $cr['safety_notes'] ?? []), $cr['pattern_badge'] ?? '', $cr['is_home_fav'] ? 1 : 0, round($cr['fav_delta'], 2), round($cr['opp_delta'], 2), round($cr['draw_delta'], 2), $cr['home_odds'] ?? 0, $cr['draw_odds'] ?? 0, $cr['away_odds'] ?? 0]);
                        $totalInserted++;
                    } catch (Exception $e) { error_log("Corners insert error: " . $e->getMessage()); }
                }
                $analysisLog[] = '[' . date('H:i:s') . '] Saved ' . count($resolvedCorners) . ' corner picks';

                // 4d. Under 3.5 Goals (pick_type = "under_25")
                // Exclude matches already saved as Over 1.5 (same match can't be both)
                $over15MatchNames = [];
                foreach ($resolvedOver15 as $o) { $over15MatchNames[] = $o['match']; }
                $under25Pool = array_values(array_filter($under25Pool, fn($u) => !in_array($u['match'], $over15MatchNames)));
                foreach ($under25Pool as $u25) {
                    try {
                        $u25Pick = $u25['pick'] ?? '';
                        $u25Odds = (float)($u25['actual_odds'] ?? 0);
                        // Determine the correct pick text and adjusted odds for Under 3.5
                        $pickText = 'Under 3.5 Goals';
                        $safetyNotes = $u25['safety_notes'] ?? [];
                        $safetyNotes[] = 'Under 3.5 Goals — draw stable conditions';
                        $insStmt->execute(['under_25', $u25['match'], $pickText, $u25Odds, $u25['risk_tier'] ?? 'MODERATE', max((int)($u25['win_rate_low'] ?? 0), 65), max((int)($u25['win_rate_high'] ?? 0), 75), $u25['league'], $u25['details'] ? trim(explode('|', str_replace("\xF0\x9F\x95\x92", '', $u25['details']))[0]) : '', $u25['details'] ?? '', implode('; ', $safetyNotes), $u25['pattern_badge'] ?? '', $u25['is_home_fav'] ? 1 : 0, round($u25['fav_delta'] ?? 0, 2), round($u25['opp_delta'] ?? 0, 2), round($u25['draw_delta'] ?? 0, 2), $u25['home_odds'] ?? 0, $u25['draw_odds'] ?? 0, $u25['away_odds'] ?? 0]);
                        $totalInserted++;
                    } catch (Exception $e) { error_log("Under25 insert error: " . $e->getMessage()); }
                }
                $analysisLog[] = '[' . date('H:i:s') . '] Saved ' . count($under25Pool) . ' Under 3.5 Goals picks';

                // 4e. Parlay combination (pick_type = "parlay")
                $comboPicks = $bestCombo ?? $combinedParlay;
                if (!empty($comboPicks) && is_array($comboPicks)) {
                    foreach ($comboPicks as $cp) {
                        try {
                            $insStmt->execute(['parlay', $cp['match'], $cp['pick'], $cp['actual_odds'], $cp['risk_tier'], $cp['win_rate_low'], $cp['win_rate_high'], $cp['league'], $cp['details'] ? trim(explode('|', str_replace("\xF0\x9F\x95\x92", '', $cp['details']))[0]) : '', $cp['details'], implode('; ', $cp['safety_notes'] ?? []), $cp['pattern_badge'] ?? '', $cp['is_home_fav'] ? 1 : 0, round($cp['fav_delta'], 2), round($cp['opp_delta'], 2), round($cp['draw_delta'], 2), $cp['home_odds'] ?? 0, $cp['draw_odds'] ?? 0, $cp['away_odds'] ?? 0]);
                            $totalInserted++;
                        } catch (Exception $e) { error_log("Parlay combo insert error: " . $e->getMessage()); }
                    }
                }
                $analysisLog[] = '[' . date('H:i:s') . '] Saved ' . (is_array($comboPicks) ? count($comboPicks) : 0) . ' parlay combination picks';

                $analysisLog[] = '[' . date('H:i:s') . '] Analysis complete! Total picks saved: ' . $totalInserted;
            } catch (Exception $e) {
                $error = "Analytics error: " . $e->getMessage();
            }
        } elseif ($_POST['action'] === 'approve_code' && isset($_POST['purchase_id'])) {
    $res = approveCodePurchase((int)$_POST['purchase_id'], $user['id']);
    $msg = $res['success'] ? "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>{$res['message']}" : "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>{$res['message']}";
} elseif ($_POST['action'] === 'reject_code' && isset($_POST['purchase_id'])) {
    $reason = trim($_POST['rejection_reason'] ?? 'Wrong reference number');
    $res = rejectCodePurchase((int)$_POST['purchase_id'], $reason);
    $msg = $res['success'] ? "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>{$res['message']}" : 'Failed to reject';
} elseif ($_POST['action'] === 'approve_credit' && isset($_POST['credit_id'])) {
    $res = approveCreditPurchase((int)$_POST['credit_id'], $user['id']);
    $msg = $res['success'] ? "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>{$res['message']}" : "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>{$res['message']}";
} elseif ($_POST['action'] === 'reject_credit' && isset($_POST['credit_id'])) {
    $reason = trim($_POST['rejection_reason'] ?? 'Wrong reference number');
    $res = rejectCreditPurchase((int)$_POST['credit_id'], $reason);
    $msg = $res['success'] ? "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>{$res['message']}" : 'Failed to reject';
} elseif ($_POST['action'] === 'award_bonus_credits' && isset($_POST['target_user_id'])) {
    $res = awardBonusCredits((int)$_POST['target_user_id'], 6, $user['id'], 'Performance bonus (2 days)');
    $msg = $res['success'] ? "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>{$res['message']}" : "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>{$res['message']}";
} elseif ($_POST['action'] === 'approve_aviator' && isset($_POST['aviator_id'])) {
    $res = approveAviatorPurchase((int)$_POST['aviator_id'], $user['id']);
    $msg = $res['success'] ? "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>{$res['message']}" : "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>{$res['message']}";
} elseif ($_POST['action'] === 'reject_aviator' && isset($_POST['aviator_id'])) {
    $reason = trim($_POST['rejection_reason'] ?? 'Wrong reference number');
    $res = rejectAviatorPurchase((int)$_POST['aviator_id'], $reason);
    $msg = $res['success'] ? "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>{$res['message']}" : 'Failed to reject';
} elseif ($_POST['action'] === 'award_top_sellers') {
    $res = awardTopSellers($user['id']);
    $msg = $res['success'] ? "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>{$res['message']}" : "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>{$res['message']}";
} elseif ($_POST['action'] === 'give_free_credits' && isset($_POST['target_user_id'])) {
    $credits = max(1, (int)($_POST['credits'] ?? 1));
    $reason = trim($_POST['reason'] ?? 'Free gift');
    $res = awardBonusCredits((int)$_POST['target_user_id'], $credits, $user['id'], $reason);
    $msg = $res['success'] ? "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>{$res['message']}" : "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>{$res['message']}";
} elseif ($_POST['action'] === 'approve_slip' && isset($_POST['slip_id'])) {
    $res = approveSlip((int)$_POST['slip_id']);
    $msg = $res ? "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>Slip approved" : 'Failed to approve';
} elseif ($_POST['action'] === 'reject_slip' && isset($_POST['slip_id'])) {
    $res = rejectSlip((int)$_POST['slip_id']);
    $msg = $res ? "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>Slip rejected" : 'Failed to reject';
} elseif ($_POST['action'] === 'set_demo' && isset($_POST['user_id'])) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE web_users SET is_demo = 1 WHERE id = ?");
    $stmt->execute([(int)$_POST['user_id']]);
    $msg = "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>User marked as demo.";
} elseif ($_POST['action'] === 'remove_demo' && isset($_POST['user_id'])) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE web_users SET is_demo = 0 WHERE id = ?");
    $stmt->execute([(int)$_POST['user_id']]);
    $msg = "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>Demo status removed.";
} elseif ($_POST['action'] === 'retune_bayesian') {
    require_once __DIR__ . '/classes/BayesianModel.php';
    $bmTune = new BayesianModel();
    $tuneResult = $bmTune->tunePriorStrength();
    if (is_array($tuneResult)) {
        $_SESSION['bayesian_k'] = $tuneResult['best_k'];
        $_SESSION['bayesian_k_err'] = $tuneResult['best_err'];
        try { $db->exec("CREATE TABLE IF NOT EXISTS bayesian_config (`key` VARCHAR(50) PRIMARY KEY, `value` TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)"); $db->prepare("REPLACE INTO bayesian_config (`key`, `value`) VALUES ('prior_k', ?)")->execute([$tuneResult['best_k']]); $db->prepare("REPLACE INTO bayesian_config (`key`, `value`) VALUES ('prior_k_err', ?)")->execute([$tuneResult['best_err']]); } catch (Exception $e) {}
        $msg = "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>Bayesian re-tuned: k = {$tuneResult['best_k']}, error rate = " . round($tuneResult['best_err']*100, 1) . "%";
    } else {
        $error = "<i class='fas fa-times-circle me-1' style='color:#EF4444;'></i>Bayesian tuning failed (need ≥20 settled picks in last 90 days)";
    }
} elseif ($_POST['action'] === 'bayesian_batch_predict') {
        require_once __DIR__ . '/classes/BayesianModel.php';
        $bm = new BayesianModel();
        $result = $bm->runBatchPredictions();
        $msg = "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>Bayesian batch: {$result['stored']} stored, {$result['skipped']} skipped, {$result['errors']} errors";
    } elseif ($_POST['action'] === 'bayesian_settle') {
        require_once __DIR__ . '/classes/BayesianModel.php';
        $bm = new BayesianModel();
        $result = $bm->settlePredictions();
        $msg = "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>Bayesian settle: {$result['settled']} settled, {$result['matched']} matched, {$result['unmatched']} unmatched";
    } elseif ($_POST['action'] === 'bayesian_clear_session') {
        unset($_SESSION['bayesian_k'], $_SESSION['bayesian_k_err'], $_SESSION['bayesian_err']);
        try { $db->exec("DELETE FROM bayesian_config WHERE `key` IN ('prior_k', 'prior_k_err')"); } catch (Exception $e) {}
        $msg = "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>Bayesian cache cleared";
    }
}
}

if (isset($_POST['save_presentation_mode'])) {
    $sections = isset($_POST['hidden_sections']) && is_array($_POST['hidden_sections']) ? $_POST['hidden_sections'] : [];
    $saved = setHiddenSections($sections);
    if ($saved) {
        $msg = "<i class='fas fa-check-circle me-1' style='color:#22C55E;'></i>Presentation mode updated for demo users.";
    } else {
        $error = "<i class='fas fa-exclamation-circle me-1' style='color:#EF4444;'></i>Failed to save presentation settings. Check error log.";
    }
}

$payments = getPendingPayments();
$pendingCreditPurchases = getPendingCreditPurchases();
$allCreditPurchases = getAllCreditPurchases();
$allUsersWithCredits = getAllUsersWithCredits();
$pendingAviatorPurchases = getPendingAviatorPurchases();
$topSellersThisMonth = getTopSellersForMonth();
$topSellerRewardAwarded = hasTopSellerRewardBeenAwarded();
$hasTopSellers = !$topSellerRewardAwarded && !empty($topSellersThisMonth);
$allAviatorPurchases = getAllAviatorPurchases();
$pendingSlips = getPendingSlips();
$allAdmins = getAllAdmins();
$db = getDB();

if (isset($_GET['action']) && $_GET['action'] === 'load_more_visitors') {
    header('Content-Type: application/json');
    $offset = max(0, (int)($_GET['offset'] ?? 20));
    $limit = 20;
    try {
        $stmt = $db->prepare("SELECT pv.*, wu.phone, wu.email FROM page_views pv LEFT JOIN web_users wu ON pv.user_id = wu.id ORDER BY pv.visited_at DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $visitors = $stmt->fetchAll();
        $html = '';
        foreach ($visitors as $v) {
            $dt = new DateTime($v['visited_at'], new DateTimeZone('Africa/Dar_es_Salaam'));
            $timeStr = $dt->format('H:i:s');
            $userHtml = $v['user_id'] ? '<a href="?tab=users" class="text-decoration-none">#'.$v['user_id'].'</a>' : '<span class="badge badge-pending">Guest</span>';
            $countryHtml = $v['country'] !== 'Unknown' ? '<i class="fas fa-globe me-1"></i>'.htmlspecialchars($v['country']) : '<i class="fas fa-globe me-1"></i>Unknown';
            $pageHtml = '<code style="color: var(--primary);">'.htmlspecialchars($v['page']).'</code>';
            $ipHtml = htmlspecialchars($v['ip_address']);
            $browserHtml = htmlspecialchars(substr($v['user_agent'], 0, 40)).'...';
            $html .= "<tr><td class='text-muted'>{$timeStr}</td><td>{$userHtml}</td><td>{$countryHtml}</td><td>{$pageHtml}</td><td>{$ipHtml}</td><td class='text-muted small'>{$browserHtml}</td></tr>";
        }
        echo json_encode(['html' => $html, 'count' => count($visitors)]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage(), 'count' => 0]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_credit_history') {
    header('Content-Type: text/html; charset=utf-8');
    $uid = (int)($_GET['user_id'] ?? 0);
    if (!$uid) { echo '<div class="text-center text-muted py-4"><i class="fas fa-user-slash me-2"></i>Invalid user ID</div>'; exit; }
    try {
        $uStmt = $db->prepare("SELECT id, phone, display_name, publisher_credits, free_credits_updated_at FROM web_users WHERE id = ?");
        $uStmt->execute([$uid]);
        $u = $uStmt->fetch();
        if (!$u) { echo '<div class="text-center text-muted py-4"><i class="fas fa-user-slash me-2"></i>User not found</div>'; exit; }
        echo '<div class="mb-3 p-3" style="background:var(--bg-soft);border-radius:6px;font-size:0.85rem;">';
        echo '<strong>Phone:</strong> <code>' . htmlspecialchars($u['phone']) . '</code> &bull; <strong>Current Credits:</strong> <span class="badge" style="background:#DCFCE7;color:#166534;">' . (int)$u['publisher_credits'] . '</span> &bull; <strong>Free Awarded:</strong> ' . ($u['free_credits_updated_at'] ? date('M d', strtotime($u['free_credits_updated_at'])) : 'Never');
        echo '</div>';

        $cpStmt = $db->prepare("SELECT id, credits_requested, amount_paid, payment_reference, status, created_at FROM credit_purchases WHERE user_id = ? ORDER BY created_at DESC");
        $cpStmt->execute([$uid]);
        $cpRows = $cpStmt->fetchAll();
        echo '<h6 style="margin:1rem 0 0.5rem;font-size:0.85rem;"><i class="fas fa-coins me-1" style="color:#F59E0B;"></i>Credit Purchases</h6>';
        echo '<div class="table-responsive"><table class="table" style="font-size:0.8rem;"><thead><tr><th>ID</th><th>Credits</th><th>Amount</th><th>Reference</th><th>Status</th><th>Date</th></tr></thead><tbody>';
        if (empty($cpRows)) {
            echo '<tr><td colspan="6" class="text-center text-muted py-3"><i class="fas fa-inbox me-2"></i>No credit purchases</td></tr>';
        } else {
            foreach ($cpRows as $r) {
                $badge = $r['status'] === 'approved' ? 'badge-rollover' : ($r['status'] === 'rejected' ? 'bg-danger' : 'bg-secondary');
                echo '<tr><td>#' . $r['id'] . '</td><td><strong>' . (int)$r['credits_requested'] . '</strong></td><td>' . number_format((float)$r['amount_paid']) . ' TZS</td><td><code>' . htmlspecialchars($r['payment_reference']) . '</code></td><td><span class="badge ' . $badge . '">' . ucfirst($r['status']) . '</span></td><td class="text-muted">' . date('M d, H:i', strtotime($r['created_at'])) . '</td></tr>';
            }
        }
        echo '</tbody></table></div>';

        $csStmt = $db->prepare("SELECT cp.id, cp.code_id, cp.amount, cp.status, cp.purchased_at, cp.buyer_id, bc.description, bc.code FROM code_purchases cp JOIN betting_codes bc ON cp.code_id = bc.id WHERE bc.user_id = ? ORDER BY cp.purchased_at DESC");
        $csStmt->execute([$uid]);
        $csRows = $csStmt->fetchAll();
        echo '<h6 style="margin:1rem 0 0.5rem;font-size:0.85rem;"><i class="fas fa-tag me-1"></i>Code Sales (as Seller — Credits Deducted on Approval)</h6>';
        echo '<div class="table-responsive"><table class="table" style="font-size:0.8rem;"><thead><tr><th>ID</th><th>Code</th><th>Markets</th><th>Price</th><th>Buyer</th><th>Status</th><th>Date</th></tr></thead><tbody>';
        if (empty($csRows)) {
            echo '<tr><td colspan="7" class="text-center text-muted py-3"><i class="fas fa-inbox me-2"></i>No code sales yet</td></tr>';
        } else {
            foreach ($csRows as $r) {
                $bStmt = $db->prepare("SELECT phone, display_name FROM web_users WHERE id = ?");
                $bStmt->execute([$r['buyer_id']]);
                $b = $bStmt->fetch();
                $badge = $r['status'] === 'approved' ? 'badge-rollover' : ($r['status'] === 'rejected' ? 'bg-danger' : 'bg-secondary');
                echo '<tr><td>#' . $r['id'] . '</td><td><code>' . htmlspecialchars($r['code']) . '</code></td><td>' . htmlspecialchars($r['description']) . '</td><td>' . number_format((float)$r['amount']) . ' TZS</td><td><code>' . htmlspecialchars($b['display_name'] ?: $b['phone']) . '</code></td><td><span class="badge ' . $badge . '">' . ucfirst($r['status']) . '</span></td><td class="text-muted">' . date('M d, H:i', strtotime($r['purchased_at'])) . '</td></tr>';
            }
        }
        echo '</tbody></table></div>';
    } catch (Exception $e) {
        echo '<div class="text-center text-danger py-4">Error loading history: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'load_more_users') {
    header('Content-Type: application/json');
    $offset = max(0, (int)($_GET['offset'] ?? 20));
    $limit = 20;
    try {
        $stmt = $db->prepare("SELECT id, phone, email, display_name, status, trial_expiry, parlay_expiry, rollover_expiry, join_date, last_login FROM web_users ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();
        $html = '';
        foreach ($users as $u) {
            $badgeClass = str_replace(['trial_parlay','premium_parlay','premium_rollover','premium_both'], ['badge-pending','badge-parlay','badge-rollover','badge-both'], $u['status']);
            $statusText = ucfirst(str_replace('_', ' ', $u['status']));
            $parlayExp = '-';
            $rolloverExp = '-';
            if ($u['parlay_expiry']) {
                $rem = max(0, strtotime($u['parlay_expiry'])-time());
                $h = floor($rem/3600);
                $pl = $h >= 24 ? floor($h/24).'d' : $h.'h';
                $parlayExp = (new DateTime($u['parlay_expiry']))->format('M d, H:i') . ' (' . $pl . ')';
            }
            if ($u['rollover_expiry']) {
                $rem = max(0, strtotime($u['rollover_expiry'])-time());
                $h = floor($rem/3600);
                $rl = $h >= 24 ? floor($h/24).'d' : $h.'h';
                $rolloverExp = (new DateTime($u['rollover_expiry']))->format('M d, H:i') . ' (' . $rl . ')';
            }
            $joinDate = date('M d', strtotime($u['join_date']));
            $uName = htmlspecialchars($u['display_name'] ?? '');
            $uEmail = htmlspecialchars($u['email'] ?? '');
            $html .= "<tr data-phone='" . strtolower(htmlspecialchars($u['phone'])) . "' data-name='" . strtolower($uName) . "' data-email='" . strtolower($uEmail) . "'>";
            $html .= "<td>#{$u['id']}</td>";
            $html .= "<td><code style='color: var(--primary);'>" . htmlspecialchars($u['phone']) . "</code></td>";
            $html .= "<td style='font-size:0.85rem;'>" . ($uName ?: '<span class="text-muted">—</span>') . "</td>";
            $html .= "<td><span class='badge {$badgeClass}'>{$statusText}</span></td>";
            $html .= "<td class='text-muted' style='white-space:nowrap'>{$parlayExp}</td>";
            $html .= "<td class='text-muted' style='white-space:nowrap'>{$rolloverExp}</td>";
            $html .= "<td class='text-muted'>{$joinDate}</td>";
            $html .= "</tr>";
        }
        echo json_encode(['html' => $html, 'count' => count($users)]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage(), 'count' => 0]);
    }
    exit;
}

$currentMonth = date('Y-m');
$totalUsers = $totalPremium = 0;
$rolloverCount = $parlayCount = $bothCount = 0;
$totalRevenue = 0;
$allUsers = [];

if ($db) {
    $totalUsers = $db->query("SELECT COUNT(*) FROM web_users")->fetchColumn();
    $stmt = $db->prepare("SELECT SUM(CASE WHEN status = 'premium_rollover' THEN 1 ELSE 0 END) AS `count_rollover`, SUM(CASE WHEN status = 'premium_parlay' THEN 1 ELSE 0 END) AS `count_parlay`, SUM(CASE WHEN status = 'premium_both' THEN 1 ELSE 0 END) AS `count_both` FROM web_users WHERE status IN ('premium_rollover', 'premium_parlay', 'premium_both') AND DATE_FORMAT(payment_date, '%Y-%m') = ?");
    $stmt->execute([$currentMonth]);
    $premiumStats = $stmt->fetch();
    $rolloverCount = (int)($premiumStats['count_rollover'] ?? 0);
    $parlayCount = (int)($premiumStats['count_parlay'] ?? 0);
    $bothCount = (int)($premiumStats['count_both'] ?? 0);
    $stmt = $db->prepare("SELECT SUM(pv.amount) as total_revenue FROM payment_verifications pv WHERE pv.status = 'approved' AND DATE_FORMAT(pv.verified_at, '%Y-%m') = ?");
    $stmt->execute([$currentMonth]);
    $totalRevenue = (float)(($stmt->fetch())['total_revenue'] ?? 0);
    $stmt = $db->prepare("SELECT id, phone, email, display_name, status, trial_expiry, parlay_expiry, rollover_expiry, join_date, last_login, is_demo FROM web_users ORDER BY id DESC LIMIT 20");
    $stmt->execute();
    $allUsers = $stmt->fetchAll();
}

// Defer heavy analytics queries — only run when visitors tab is active
$tabStats = []; $countryStats = []; $todayVisits = 0; $pageStats = []; $topIps = []; $recentVisits = []; $allPicks = [];
$tabOrder = ['scrape_analyze', 'approve', 'users', 'admins', 'code_purchases', 'visitors'];
$requestedTab = $_GET['tab'] ?? '';
if ($requestedTab) {
    $activeTab = $requestedTab;
    if ($activeTab === 'featured') { header('Location: ?tab=scrape_analyze&sub=featured'); exit; }
} else {
    $activeTab = 'approve';
    foreach ($tabOrder as $t) {
        $permCheck = match($t) {
            'scrape_analyze' => $isSuperAdmin || hasAdminPermission('analysis') || hasAdminPermission('scraper') || hasAdminPermission('picks'),
            'approve' => $isSuperAdmin || hasAdminPermission('payments'),
            'users' => $isSuperAdmin || hasAdminPermission('users'),
            'admins' => $isSuperAdmin || hasAdminPermission('admins'),
            'code_purchases' => $isSuperAdmin || hasAdminPermission('code_purchases'),
            'visitors' => $isSuperAdmin || hasAdminPermission('visitors'),
            default => false
        };
        if ($permCheck) { $activeTab = $t; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<title>Admin Panel - Predixa</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root { --primary: #06B6D4; --primary-dark: #0891B2; --primary-light: #22D3EE; --bg-soft: #FAFAFA; --bg-white: #FFFFFF; --text-dark: #1F2937; --text-muted: #6B7280; --border-color: #E5E7EB; --shadow: 0 1px 3px rgba(0,0,0,0.08); --shadow-lg: 0 4px 12px rgba(0,0,0,0.08); }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Inter', sans-serif; background: var(--bg-soft); color: var(--text-dark); min-height: 100vh; display: flex; flex-direction: column; line-height: 1.5; font-size: 14px; }
.header { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; padding: 0.75rem 0; box-shadow: var(--shadow); position: relative; z-index: 100; }
.header-content { max-width: 1200px; margin: 0 auto; padding: 0 1rem; display: flex; justify-content: space-between; align-items: center; }
.header h1 { font-size: 1.25rem; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
.header p { margin: 0.15rem 0 0 0; opacity: 0.9; font-size: 0.8rem; }
.btn-back { background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 0.4rem 0.8rem; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 0.8rem; transition: all 0.2s; }
.btn-back:hover { background: rgba(255,255,255,0.3); color: white; }
.hero-section { background: var(--bg-white); border-bottom: 1px solid var(--border-color); padding: 1rem 0; margin-bottom: 1.5rem; }
.hero-content { max-width: 1200px; margin: 0 auto; padding: 0 1rem; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 0.75rem; }
.stat-card { background: var(--bg-soft); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.75rem; text-align: center; transition: all 0.2s; }
.stat-card:hover { border-color: var(--primary); transform: translateY(-1px); box-shadow: var(--shadow-lg); }
.stat-icon { font-size: 1.5rem; margin-bottom: 0.25rem; }
.stat-number { font-size: 1.25rem; font-weight: 800; color: var(--primary); line-height: 1; }
.stat-label { font-size: 0.7rem; color: var(--text-muted); margin-top: 0.15rem; font-weight: 500; }
.revenue-card { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; border-radius: 8px; padding: 0.75rem; text-align: center; }
.revenue-label { font-size: 0.7rem; opacity: 0.9; margin-bottom: 0.25rem; }
.revenue-amount { font-size: 1.25rem; font-weight: 800; }
.nav-container { max-width: 1200px; margin: 0 auto 1rem auto; padding: 0 1rem; }
.nav-pills { display: flex; gap: 0.5rem; background: var(--bg-white); padding: 0.25rem; border-radius: 8px; box-shadow: var(--shadow); }
.nav-link { flex: 1; padding: 0.5rem 1rem; border-radius: 6px; border: none; background: transparent; color: var(--text-muted); font-weight: 600; cursor: pointer; transition: all 0.2s; text-align: center; text-decoration: none; font-size: 0.85rem; }
.nav-link:hover { background: var(--bg-soft); color: var(--primary); }
.nav-link.active { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; box-shadow: var(--shadow); }
.main-content { flex: 1; max-width: 1200px; margin: 0 auto; padding: 0 1rem 1.5rem 1rem; width: 100%; }
.card { background: var(--bg-white); border: 1px solid var(--border-color); border-radius: 8px; padding: 1rem; margin-bottom: 1rem; box-shadow: var(--shadow); }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color); }
.card-title { font-size: 1rem; font-weight: 700; color: var(--text-dark); margin: 0; }
.search-input { width: 100%; padding: 0.6rem 0.8rem; border: 1px solid var(--border-color); border-radius: 6px; font-size: 0.85rem; transition: all 0.2s; margin-bottom: 0.75rem; }
.search-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1); }
.table-responsive { border-radius: 6px; overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; }
.table thead th { background: var(--bg-soft); color: var(--text-dark); font-weight: 600; border-bottom: 1px solid var(--border-color); padding: 0.5rem; }
.table tbody td { padding: 0.5rem; border-bottom: 1px solid var(--border-color); vertical-align: middle; }
.table tbody tr:hover { background: var(--bg-soft); }
.badge { padding: 0.25rem 0.5rem; border-radius: 4px; font-weight: 600; font-size: 0.7rem; }
.badge-rollover { background: #06B6D4; color: white; }
.badge-parlay { background: #F59E0B; color: white; }
.badge-both { background: #8B5CF6; color: white; }
.badge-pending { background: #FBBF24; color: #000; }
.btn { padding: 0.35rem 0.75rem; border-radius: 6px; font-weight: 600; transition: all 0.2s; border: none; cursor: pointer; text-decoration: none; display: inline-block; font-size: 0.8rem; }
.btn-approve { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); color: white; }
.btn-approve:hover { transform: translateY(-1px); box-shadow: var(--shadow-lg); color: white; }
.btn-reject { background: #EF4444; color: white; }
.btn-reject:hover { background: #DC2626; color: white; }
.btn-grant { background: linear-gradient(135deg, #10B981 0%, #059669 100%); color: white; }
.btn-revoke { background: #EF4444; color: white; }
.alert { border-radius: 8px; border: none; padding: 0.6rem 0.8rem; margin-bottom: 0.75rem; font-size: 0.85rem; }
.alert-success { background: #D1FAE5; color: #047857; }
.alert-danger { background: #FEE2E2; color: #DC2626; }
.footer { background: var(--bg-white); border-top: 1px solid var(--border-color); padding: 1rem 0; margin-top: auto; }
.footer-content { max-width: 1200px; margin: 0 auto; padding: 0 1rem; text-align: center; }
.footer-copy { color: var(--text-muted); font-size: 0.75rem; margin: 0; }
.hamburger-btn { display: none; background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 0.5rem 0.8rem; border-radius: 8px; font-size: 1.5rem; cursor: pointer; transition: all 0.3s; }         .hamburger-btn:hover { background: rgba(255,255,255,0.3); } @media(max-width:768px) { .hamburger-btn { display: block; } .header-content { flex-direction: row; justify-content: space-between; text-align: left; } .header-actions { display: none; position: absolute; top: 100%; left: 0; right: 0; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); flex-direction: column; gap: 0.75rem; padding: 1rem; box-shadow: var(--shadow-lg); z-index: 999; } .header-actions.active { display: flex; } .stats-grid { grid-template-columns: repeat(2, 1fr); } .nav-pills { flex-direction: column; } }
        .icon-gap { margin-right: 0.5rem; } i.fas, i.far, i.fab { vertical-align: -0.125em; } .nav-pills .nav-link { display: inline-flex; align-items: center; justify-content: center; }
        </style>
</head>
<body>

<header class="header">
<div class="header-content">
<div style="display: flex; align-items: center; gap: 1rem;">
<a href="dashboard" class="btn-back">← Back</a>
<div>
<h1><i class="fas fa-cog me-2"></i>Admin Panel</h1>
<p>Manage payments, users, and system settings</p>
</div>
</div>
<button class="hamburger-btn" id="hamburgerBtn" aria-label="Toggle Menu"><i class="fas fa-bars"></i></button>
<div class="header-actions" id="headerMenu">
<?php if ($isSuperAdmin): ?>
<a href="best-picks-view" style="display:inline-flex;align-items:center;gap:4px;background:rgba(34,197,94,0.9);color:#fff;border:1px solid #22C55E;padding:4px 12px;border-radius:6px;text-decoration:none;font-size:0.8rem;font-weight:600;white-space:nowrap;" title="Best Picks for BetBot">
    <i class="fas fa-robot me-1"></i>Best Picks
</a>
<button type="button" onclick="document.getElementById('presentationModal').classList.add('show');document.getElementById('presentationModal').style.display='flex';" style="display:inline-flex;align-items:center;gap:4px;background:transparent;color:#fff;border:1px dashed rgba(255,255,255,0.5);padding:4px 12px;border-radius:6px;cursor:pointer;font-size:0.8rem;font-weight:600;white-space:nowrap;" title="Presentation Mode">
    <i class="fas fa-eye-slash"></i> Demo
</button>
<?php endif; ?>
<a href="logout" class="btn-back" style="background: rgba(239, 68, 68, 0.2); border-color: rgba(239, 68, 68, 0.3); width: 100%; text-align: center;"><i class="fas fa-right-from-bracket me-1"></i>Logout</a>
</div>
</div>
</header>

<section class="hero-section">
    <div class="hero-content">
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-icon"></div><div class="stat-number"><?= $rolloverCount ?></div><div class="stat-label">Rollover</div></div>
            <div class="stat-card"><div class="stat-icon"></div><div class="stat-number"><?= $parlayCount ?></div><div class="stat-label">Parlay</div></div>
            <div class="stat-card"><div class="stat-icon"></div><div class="stat-number"><?= $bothCount ?></div><div class="stat-label">Both</div></div>
            <div class="revenue-card"><div class="revenue-label">Monthly Revenue</div><div class="revenue-amount"><?= number_format($totalRevenue) ?> TZS</div></div>
        </div>
    </div>
</section>

<?php if ($msg): ?><div class="main-content" style="padding-top: 0;"><div class="alert alert-success"><?= $msg ?></div></div><?php endif; ?>
<?php if ($error): ?><div class="main-content" style="padding-top: 0;"><div class="alert alert-danger"><?= $error ?></div></div><?php endif; ?>

<div class="nav-container">
    <div class="nav-pills">
        <?php if ($isSuperAdmin || hasAdminPermission('analysis') || hasAdminPermission('scraper')): ?>
        <a href="?tab=scrape_analyze" class="nav-link <?= $activeTab === 'scrape_analyze' ? 'active' : '' ?>"><i class="fas fa-microchip me-1"></i>Operations</a>
        <?php endif; ?>
        <?php if ($isSuperAdmin || hasAdminPermission('payments')): ?>
        <a href="?tab=approve" class="nav-link <?= $activeTab === 'approve' ? 'active' : '' ?>"><i class="fas fa-check-circle me-1"></i>Payments</a>
        <?php endif; ?>
        <?php if ($isSuperAdmin || hasAdminPermission('users')): ?>
        <a href="?tab=users" class="nav-link <?= $activeTab === 'users' ? 'active' : '' ?>"><i class="fas fa-users me-1"></i>Users</a>
        <?php endif; ?>
        <?php if ($isSuperAdmin || hasAdminPermission('admins')): ?>
        <a href="?tab=admins" class="nav-link <?= $activeTab === 'admins' ? 'active' : '' ?>"><i class="fas fa-user-shield me-1"></i>Admins</a>
        <?php endif; ?>
        <?php if ($isSuperAdmin || hasAdminPermission('code_purchases')): ?>
        <a href="?tab=code_purchases" class="nav-link <?= $activeTab === 'code_purchases' ? 'active' : '' ?>"><i class="fas fa-coins me-1"></i>Credits <?= count($pendingCreditPurchases) ? '<span class="badge bg-danger ms-1">'.count($pendingCreditPurchases).'</span>' : '' ?></a>
        <?php endif; ?>
        <?php if ($isSuperAdmin || hasAdminPermission('rewards')): ?>
        <a href="?tab=top_sellers" class="nav-link <?= $activeTab === 'top_sellers' ? 'active' : '' ?>"><i class="fas fa-gift me-1"></i>Rewards <?= $hasTopSellers ? '<span class="badge bg-danger ms-1">'.count($topSellersThisMonth).'</span>' : '' ?></a>
        <?php endif; ?>
        <?php if ($isSuperAdmin): ?>
        <a href="?tab=visitors" class="nav-link <?= $activeTab === 'visitors' ? 'active' : '' ?>"><i class="fas fa-eye me-1"></i>Logs</a>
        <?php endif; ?>
    </div>
</div>

<div class="notif-bar" style="display: flex; gap: 8px; flex-wrap: wrap; padding: 12px 20px; margin-bottom: 16px; background: #fff; border-radius: 10px; border: 1px solid var(--border-color); justify-content: center;">
    <span style="font-weight: 600; color: var(--text-dark); font-size: 0.85rem; display: flex; align-items: center; gap: 4px;"><i class="fas fa-bell" style="color: var(--primary);"></i>Pending:</span>
    <a href="?tab=approve" style="display: inline-flex; align-items: center; gap: 4px; background: <?= count($payments) ? '#FEF3C7' : '#F9FAFB' ?>; color: <?= count($payments) ? '#B45309' : '#9CA3AF' ?>; padding: 4px 12px; border-radius: 6px; text-decoration: none; font-size: 0.8rem; font-weight: 600;">
        <i class="fas fa-credit-card"></i> Payments (<?= count($payments) ?>)
    </a>
    <a href="?tab=code_purchases" style="display: inline-flex; align-items: center; gap: 4px; background: <?= count($pendingCreditPurchases) ? '#FEF3C7' : '#F9FAFB' ?>; color: <?= count($pendingCreditPurchases) ? '#B45309' : '#9CA3AF' ?>; padding: 4px 12px; border-radius: 6px; text-decoration: none; font-size: 0.8rem; font-weight: 600;">
        <i class="fas fa-coins"></i> Credits (<?= count($pendingCreditPurchases) ?>)
    </a>
    <a href="?tab=code_purchases#aviator" style="display: inline-flex; align-items: center; gap: 4px; background: <?= count($pendingAviatorPurchases) ? '#FEF3C7' : '#F9FAFB' ?>; color: <?= count($pendingAviatorPurchases) ? '#B45309' : '#9CA3AF' ?>; padding: 4px 12px; border-radius: 6px; text-decoration: none; font-size: 0.8rem; font-weight: 600;">
    <i class="fas fa-plane"></i> Aviator (<?= count($pendingAviatorPurchases) ?>)
    </a>
    <?php if ($hasTopSellers): ?>
    <a href="?tab=top_sellers" style="display: inline-flex; align-items: center; gap: 4px; background: #FEF3C7; color: #B45309; padding: 4px 12px; border-radius: 6px; text-decoration: none; font-size: 0.8rem; font-weight: 600;">
        <i class="fas fa-trophy"></i> Top Sellers (<?= count($topSellersThisMonth) ?>)
    </a>
    <?php endif; ?>
    <span style="font-weight: 600; color: var(--text-dark); font-size: 0.85rem; display: flex; align-items: center; gap: 4px; margin-left: 8px; border-left: 1px solid var(--border-color); padding-left: 12px;"><i class="fas fa-wrench" style="color: #6366f1;"></i>Tools:</span>
    <a href="admin/h2h-test" target="_blank" style="display: inline-flex; align-items: center; gap: 4px; background: #EEF2FF; color: #4338CA; padding: 4px 12px; border-radius: 6px; text-decoration: none; font-size: 0.8rem; font-weight: 600;">
        <i class="fas fa-swords"></i> H2H Test
    </a>
    <a href="h2h" target="_blank" style="display: inline-flex; align-items: center; gap: 4px; background: #F0FDFA; color: #0891B2; padding: 4px 12px; border-radius: 6px; text-decoration: none; font-size: 0.8rem; font-weight: 600;">
        <i class="fas fa-exchange-alt"></i> H2H Page
    </a>
    <a href="admin/test-stats-collector" target="_blank" style="display: inline-flex; align-items: center; gap: 4px; background: #ECFDF5; color: #047857; padding: 4px 12px; border-radius: 6px; text-decoration: none; font-size: 0.8rem; font-weight: 600;">
        <i class="fas fa-chart-bar"></i> Stats Collector
    </a>
</div>
<main class="main-content">
    <?php if ($activeTab === 'approve'): ?>
    <?php if (!hasAdminPermission('payments')): ?>
    <div class="alert alert-danger text-center py-4"><i class="fas fa-lock me-2"></i>You do not have permission to manage payments.</div>
    <?php else: ?>
    <div class="card">
        <div class="card-header"><h2 class="card-title"><i class="fas fa-check-circle me-1"></i>Approve Payments</h2><span class="badge badge-pending"><?= count($payments) ?> Pending</span></div>
        <input type="text" id="searchRef" class="search-input" placeholder="&#xF002; Type reference number to instantly filter...">
        <div class="table-responsive">
            <table class="table" id="paymentsTable">
                <thead><tr><th>User</th><th>Reference</th><th>Amount</th><th>Tier</th><th>Duration</th><th>Submitted</th><th>Action</th></tr></thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-inbox me-2"></i>No pending payments</td></tr>
                    <?php else: foreach ($payments as $p):
                        $durLabel = $p['duration'] ?? 'monthly';
                        $durText = ['daily' => '1 Day', 'biweekly' => '14 Days', 'monthly' => '30 Days'][$durLabel] ?? '30 Days';
                    ?>
                    <tr data-ref="<?= strtolower(htmlspecialchars($p['reference_number'])) ?>">
                        <td><code style="color: var(--primary);"><?= htmlspecialchars($p['display_name'] ?: $p['phone']) ?></code></td>
                        <td><code><?= htmlspecialchars($p['reference_number']) ?></code></td>
                        <td><strong><?= number_format($p['amount']) ?> TZS</strong></td>
                        <td><span class="badge <?= $p['tier']==='rollover'?'badge-rollover':($p['tier']==='parlay'?'badge-parlay':'badge-both') ?>"><?= ucfirst($p['tier']) ?></span></td>
                        <td><span class="badge badge-pending"><?= $durText ?></span></td>
                        <td class="text-muted"><?= date('M d, H:i', strtotime($p['created_at'])) ?></td>
                        <td>
                            <form method="POST" class="d-inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="payment_id" value="<?= $p['id'] ?>"><input type="hidden" name="tier" value="<?= htmlspecialchars($p['tier']) ?>"><button type="submit" class="btn btn-approve me-1" onclick="return confirm('Approve <?= strtoupper($p['tier']) ?>?')">Approve</button></form>
                            <form method="POST" class="d-inline reject-form"><input type="hidden" name="action" value="reject"><input type="hidden" name="payment_id" value="<?= $p['id'] ?>"><input type="hidden" name="rejection_reason" value=""><button type="button" class="btn btn-reject" onclick="var r=prompt('Rejection reason:','Wrong reference number');if(r){this.form.rejection_reason.value=r;this.form.submit();}">Reject</button></form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Payment History -->
    <div class="card mt-3">
        <div class="card-header"><h2 class="card-title"><i class="fas fa-history me-1"></i>Payment History</h2></div>
        <?php
        $histDb = getDB();
        $histPayments = [];
        $histLimit = 500;
        if ($histDb) {
            $stmt = $histDb->prepare("SELECT pv.*, wu.phone, wu.display_name FROM payment_verifications pv JOIN web_users wu ON pv.user_id = wu.id WHERE pv.status IN ('approved','rejected') ORDER BY pv.verified_at DESC LIMIT " . (int)$histLimit);
            $stmt->execute();
            $histPayments = $stmt->fetchAll();
        }
        $histInitial = 10;
        $histTotal = count($histPayments);
        ?>
        <input type="text" id="searchHistRef" class="search-input" placeholder="&#xF002; Search history by reference number...">
        <div class="table-responsive">
            <table class="table" id="historyTable">
                <thead><tr><th>User</th><th>Reference</th><th>Amount</th><th>Tier</th><th>Status</th><th>Reason</th><th>Processed</th></tr></thead>
                <tbody>
                    <?php if (empty($histPayments)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-inbox me-2"></i>No processed payments</td></tr>
                    <?php else: foreach ($histPayments as $hi => $h): ?>
                    <tr class="hist-row" data-histidx="<?= $hi ?>" data-histref="<?= strtolower(htmlspecialchars($h['reference_number'])) ?>" style="<?= $hi >= $histInitial ? 'display:none;' : '' ?>">
                        <td><code style="color: var(--primary);"><?= htmlspecialchars($h['display_name'] ?: $h['phone']) ?></code></td>
                        <td><code><?= htmlspecialchars($h['reference_number']) ?></code></td>
                        <td><strong><?= number_format($h['amount']) ?> TZS</strong></td>
                        <td><span class="badge <?= $h['tier']==='rollover'?'badge-rollover':($h['tier']==='parlay'?'badge-parlay':'badge-both') ?>"><?= ucfirst($h['tier']) ?></span></td>
                        <td><span class="badge" style="background:<?= $h['status']==='approved'?'#10B981':'#EF4444' ?>;color:#fff;"><?= ucfirst($h['status']) ?></span></td>
                        <td style="font-size:0.8rem;"><?= htmlspecialchars($h['rejection_reason'] ?? ($h['status']==='approved' ? '—' : 'No reason provided')) ?></td>
                        <td class="text-muted"><?= date('M d, H:i', strtotime($h['verified_at'])) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($histTotal > $histInitial): ?>
        <div class="text-center py-2">
            <button class="btn btn-sm load-more-hist" data-current="<?= $histInitial ?>" data-step="<?= $histInitial ?>" data-total="<?= $histTotal ?>" style="background:var(--primary);color:white;border-radius:6px;padding:4px 16px;font-size:0.75rem;font-weight:600;">Show <?= $histTotal - $histInitial ?> more</button>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($activeTab === 'users'): ?>
    <?php if (!hasAdminPermission('users')): ?>
    <div class="alert alert-danger text-center py-4"><i class="fas fa-lock me-2"></i>You do not have permission to manage users.</div>
    <?php else: ?>
    <div class="card">
        <div class="card-header"><h2 class="card-title"><i class="fas fa-users me-1"></i>All Users</h2><span class="badge" style="background: var(--primary); color: white;"><?= $totalUsers ?> Total</span></div>
        <div class="mb-2 px-3"><input type="text" class="table-search form-control form-control-sm" data-table="usersTable" placeholder="Search by phone, name, or email..." style="max-width:360px;"></div>
        <div class="table-responsive">
            <table class="table" id="usersTable" data-page-size="10">
                <thead><tr><th>ID</th><th>Phone</th><th>Name</th><th>Status</th><th>Parlay Exp</th><th>Rollover Exp</th><th>Joined</th><th>Demo</th></tr></thead>
                <tbody>
                    <?php if (empty($allUsers)): ?><tr><td colspan="8" class="text-center text-muted py-4"><i class="fas fa-users me-2"></i>No users registered yet</td></tr>
                    <?php else: foreach ($allUsers as $u): ?>
                    <?php
                        $paExp = '-'; $roExp = '-';
                        if ($u['parlay_expiry']) { $rem = max(0,strtotime($u['parlay_expiry'])-time()); $h = floor($rem/3600); $pl = $h>=24 ? floor($h/24).'d' : $h.'h'; $paExp = (new DateTime($u['parlay_expiry']))->format('M d, H:i').' ('.$pl.')'; }
                        if ($u['rollover_expiry']) { $rem = max(0,strtotime($u['rollover_expiry'])-time()); $h = floor($rem/3600); $rl = $h>=24 ? floor($h/24).'d' : $h.'h'; $roExp = (new DateTime($u['rollover_expiry']))->format('M d, H:i').' ('.$rl.')'; }
                        $uName = htmlspecialchars($u['display_name'] ?? '');
                    ?>
                    <tr>
                        <td>#<?= $u['id'] ?></td>
                        <td><code style="color: var(--primary);"><?= htmlspecialchars($u['phone']) ?></code></td>
                        <td style="font-size:0.85rem;"><?= $uName ?: '<span class="text-muted">—</span>' ?></td>
                        <td><span class="badge <?= str_replace(['trial_parlay','premium_parlay','premium_rollover','premium_both'], ['badge-pending','badge-parlay','badge-rollover','badge-both'], $u['status']) ?>"><?= ucfirst(str_replace('_', ' ', $u['status'])) ?></span></td>
                        <td class="text-muted" style="white-space: nowrap;"><?= $paExp ?></td>
                        <td class="text-muted" style="white-space: nowrap;"><?= $roExp ?></td>
                        <td class="text-muted"><?= date('M d',strtotime($u['join_date'])) ?></td>
                        <td>
                            <?php if ($u['is_demo'] ?? false): ?>
                            <span style="display:inline-flex;align-items:center;gap:4px;background:#FEF3C7;color:#92400E;padding:2px 10px;border-radius:4px;font-size:0.75rem;font-weight:600;"><i class="fas fa-user-tag"></i>Demo</span>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Remove demo status from <?= htmlspecialchars($u['phone']) ?>?')">
                                <input type="hidden" name="action" value="remove_demo">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" style="background:none;border:none;color:#EF4444;cursor:pointer;font-size:0.7rem;text-decoration:underline;padding:0;">Remove</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Mark <?= htmlspecialchars($u['phone']) ?> as demo user?')">
                                <input type="hidden" name="action" value="set_demo">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" style="background:none;border:1px dashed #D1D5DB;color:#6B7280;padding:2px 10px;border-radius:4px;cursor:pointer;font-size:0.75rem;">Set Demo</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
             </table>
        </div>
        <div class="text-center py-2 load-more-wrap" data-table="usersTable" style="border-top:1px solid var(--border-color);">
            <button type="button" class="btn btn-sm load-more-btn" style="background:var(--bg-soft);color:var(--primary);border:1px solid var(--border-color);padding:4px 20px;border-radius:6px;">
                Show <span class="count">0</span> more
            </button>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

    <?php if ($activeTab === 'admins'): ?>
    <?php if (!hasAdminPermission('admins')): ?>
    <div class="alert alert-danger text-center py-4"><i class="fas fa-lock me-2"></i>You do not have permission to manage admins.</div>
    <?php else: ?>
    <div class="card">
        <div class="card-header"><h2 class="card-title"><i class="fas fa-cog me-1"></i>Manage Admin Access</h2></div>
        <div class="alert" style="background: #E0F2FE; color: #0369A1; border-left: 3px solid var(--primary); margin-bottom: 1rem; font-size: 0.85rem;">
            <strong><i class="fas fa-lightbulb me-1"></i>Management:</strong> Grant or revoke privileges. Check the modules each admin can access.
        </div>
        <div class="table-responsive mb-3">
            <input type="text" id="searchAdmin" class="search-input mb-2" placeholder="&#xF002; Search by phone, display name, or ID...">
            <table class="table" id="adminsTable">
                <thead><tr><th>ID</th><th>Phone</th><th>Display Name</th><th>Role</th><th>Permissions</th><th>Added At</th><th>Action</th></tr></thead>
                <tbody>
                    <?php if (empty($allAdmins)): ?><tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-cog me-2"></i>No admins configured</td></tr>
                    <?php else: foreach ($allAdmins as $admin):
                        $adminPerms = $admin['is_super'] ? '*' : ($admin['permissions'] ? json_decode($admin['permissions'], true) : []);
                        $permLabels = ['scraper'=>'Operations','payments'=>'Payments','users'=>'Users','admins'=>'Admins','code_purchases'=>'Credits','rewards'=>'Rewards','visitors'=>'Logs'];
                        $adminDisplayName = $admin['display_name'] ? htmlspecialchars($admin['display_name']) : '<span class="text-muted">—</span>';
                    ?>
                    <tr>
                        <td>#<?= $admin['user_id'] ?></td>
                        <td><code style="color: var(--primary);"><?= htmlspecialchars($admin['phone'] ?? 'N/A') ?></code></td>
                        <td><?= $adminDisplayName ?></td>
                        <td><span class="badge" style="background: <?= $admin['is_super'] ? '#FBBF24' : '#8B5CF6' ?>; color: #000;"><i class="fas fa-<?= $admin['is_super'] ? 'crown' : 'user-shield' ?> me-1"></i><?= $admin['is_super'] ? 'Super Admin' : 'Admin' ?></span></td>
                        <td style="font-size:0.75rem;">
                            <?php if ($adminPerms === '*'): ?><span class="text-muted">All modules</span>
                            <?php elseif (is_array($adminPerms)): ?>
                                <?php foreach ($adminPerms as $p): ?>
                                    <span class="badge" style="background:#E0F2FE;color:#0369A1;font-size:0.65rem;margin:1px;"><?= $permLabels[$p] ?? $p ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= $admin['added_at']?date('M d, H:i',strtotime($admin['added_at'])):'-' ?></td>
                        <td>
                            <?php if ($admin['user_id'] != $user['id']): ?>
                            <button type="button" class="btn btn-sm" style="background:var(--primary);color:white;padding:2px 8px;font-size:0.7rem;" data-userid="<?= (int)$admin['user_id'] ?>" data-phone="<?= htmlspecialchars($admin['display_name'] ?: ($admin['phone'] ?? ''), ENT_QUOTES) ?>" data-issuper="<?= $admin['is_super'] ? '1' : '0' ?>" data-perms="<?= $admin['permissions'] ? htmlspecialchars(implode(',', (array)json_decode($admin['permissions'])), ENT_QUOTES) : '' ?>" onclick="editAdminPerms(this)"><i class="fas fa-edit me-1"></i>Modules</button>
                            <form method="POST" class="d-inline"><input type="hidden" name="action" value="revoke_admin"><input type="hidden" name="target_user_id" value="<?= $admin['user_id'] ?>"><button type="submit" class="btn btn-revoke" style="padding:2px 8px;font-size:0.7rem;" onclick="return confirm('Revoke admin access?')"><i class="fas fa-ban me-1"></i>Revoke</button></form>
                            <?php else: ?><span class="text-muted small">You</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div style="background: var(--bg-soft); padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
            <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 0.75rem;"><i class="fas fa-plus-circle me-1"></i>Grant Admin Access</h3>
            <?php
            $allUserSearch = $db ? $db->query("SELECT id, phone, display_name FROM web_users ORDER BY id ASC")->fetchAll() : [];
            $userSearchJson = json_encode(array_map(function($u) {
                return ['id' => (int)$u['id'], 'phone' => $u['phone'] ?? '', 'name' => $u['display_name'] ?? ''];
            }, $allUserSearch));
            ?>
            <form method="POST" onsubmit="if(!document.getElementById('grantTargetUserId').value){alert('Please select a user from the search results.');return false;}">
                <input type="hidden" name="action" value="grant_admin">
                <input type="hidden" name="target_user_id" id="grantTargetUserId" value="">
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label text-muted" style="font-size: 0.8rem;">Search User</label>
                        <div class="position-relative">
                            <input type="text" id="grantUserSearch" class="form-control form-control-sm" placeholder="Type phone number, display name, or ID..." autocomplete="off" required>
                            <div id="grantUserDropdown" class="position-absolute w-100" style="display:none; background:#fff; border:1px solid #E5E7EB; border-radius:6px; max-height:200px; overflow-y:auto; z-index:999; box-shadow:0 4px 12px rgba(0,0,0,0.15);"></div>
                        </div>
                        <small class="text-muted" id="grantSelectedUser"></small>
                    </div>
                    <div class="col-md-6"><label class="form-label text-muted" style="font-size: 0.8rem;">Reason</label><input type="text" name="reason" class="form-control form-control-sm" placeholder="Optional"></div>
                    <div class="col-12 mt-2">
                        <label class="form-label text-muted" style="font-size: 0.8rem;">Module Access (leave all unchecked for full access)</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php $modules = ['scraper'=>'Operations','payments'=>'Payments','users'=>'Users','admins'=>'Admins','code_purchases'=>'Credits','rewards'=>'Rewards','visitors'=>'Logs']; ?>
                            <?php foreach ($modules as $mk => $ml): ?>
                            <label class="d-flex align-items-center gap-1" style="font-size:0.8rem;cursor:pointer;">
                                <input type="checkbox" name="permissions[]" value="<?= $mk ?>"> <?= $ml ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted">Unchecked = full access (Super Admin). Check specific modules to restrict access.</small>
                    </div>
                    <div class="col-12"><button type="submit" class="btn btn-grant mt-2"><i class="fas fa-check-circle me-1"></i>Grant Access</button></div>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Permissions Modal -->
    <div class="modal fade" id="editPermsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:#fff;border-radius:12px;">
    <div class="modal-header border-0 pb-0"><h5 class="fw-bold" style="color:#1F2937;"><i class="fas fa-shield-alt me-1" style="color:#8B5CF6;"></i>Edit Admin Modules</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST">
    <input type="hidden" name="action" value="update_admin_perms">
    <input type="hidden" name="target_user_id" id="editPermsUserId">
    <div class="modal-body px-4">
        <p class="text-muted small mb-3">Select modules for <strong id="editPermsPhone"></strong>:</p>
        <div class="d-flex flex-wrap gap-3">
            <?php $allMods = ['scraper'=>'Operations','payments'=>'Payments','users'=>'Users','admins'=>'Admins','code_purchases'=>'Credits','rewards'=>'Rewards','visitors'=>'Logs']; ?>
            <?php foreach ($allMods as $mk => $ml): ?>
            <label class="d-flex align-items-center gap-1" style="font-size:0.85rem;cursor:pointer;">
                <input type="checkbox" name="permissions[]" value="<?= $mk ?>" class="perm-checkbox"> <?= $ml ?>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="mt-2"><label class="d-flex align-items-center gap-1" style="font-size:0.85rem;cursor:pointer;"><input type="checkbox" id="permFullAccess" onchange="togglePermChecks(this)"> Full Access (Super Admin)</label></div>
    </div>
    <div class="modal-footer border-0 pt-0"><button type="submit" class="btn btn-premium w-100">Save Changes</button></div>
    </form>
    </div>
    </div>
    </div>
    <script>
    function editAdminPerms(btn) {
        var userId = btn.getAttribute('data-userid');
        var phone = btn.getAttribute('data-phone');
        var isSuper = btn.getAttribute('data-issuper') === '1';
        var perms = btn.getAttribute('data-perms');
        document.getElementById('editPermsUserId').value = userId;
        document.getElementById('editPermsPhone').textContent = phone;
        document.querySelectorAll('.perm-checkbox').forEach(function(cb) { cb.checked = false; });
        if (isSuper) {
            document.getElementById('permFullAccess').checked = true;
            document.querySelectorAll('.perm-checkbox').forEach(function(cb) { cb.checked = true; });
        } else if (perms) {
            document.getElementById('permFullAccess').checked = false;
            perms.split(',').forEach(function(v) { v = v.trim(); if (v) { var cb = document.querySelector('.perm-checkbox[value="'+v+'"]'); if (cb) cb.checked = true; } });
        }
        new bootstrap.Modal(document.getElementById('editPermsModal')).show();
    }
    function togglePermChecks(el) {
        document.querySelectorAll('.perm-checkbox').forEach(function(cb) { cb.checked = el.checked; });
    }
    document.addEventListener('DOMContentLoaded', function() {
        var inp = document.getElementById('searchAdmin');
        if (inp) inp.addEventListener('input', function(e) {
            var q = e.target.value.toLowerCase().trim();
            document.querySelectorAll('#adminsTable tbody tr').forEach(function(row) {
                row.style.display = !q || row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
        // Grant admin - user search autocomplete
        var userList = <?= $userSearchJson ?>;
        var searchInput = document.getElementById('grantUserSearch');
        var dropdown = document.getElementById('grantUserDropdown');
        var hiddenInput = document.getElementById('grantTargetUserId');
        var selectedLabel = document.getElementById('grantSelectedUser');
        if (searchInput && dropdown && hiddenInput) {
            function renderDropdown(query) {
                dropdown.innerHTML = '';
                if (!query) { dropdown.style.display = 'none'; return; }
                var q = query.toLowerCase();
                var matches = userList.filter(function(u) {
                    return u.id.toString() === q || u.phone.toLowerCase().includes(q) || (u.name && u.name.toLowerCase().includes(q));
                }).slice(0, 15);
                if (matches.length === 0) {
                    dropdown.innerHTML = '<div class="p-2 text-muted small">No users found</div>';
                    dropdown.style.display = 'block';
                    return;
                }
                matches.forEach(function(u) {
                    var div = document.createElement('div');
                    div.className = 'p-2';
                    div.style.cssText = 'cursor:pointer;border-bottom:1px solid #F3F4F6;font-size:0.8rem;';
                    var label = '#' + u.id + ' ' + u.phone;
                    if (u.name) label += ' (' + u.name + ')';
                    div.textContent = label;
                    div.addEventListener('click', function() {
                        hiddenInput.value = u.id;
                        searchInput.value = (u.name || u.phone) + ' (#' + u.id + ')';
                        selectedLabel.textContent = 'Selected: ' + u.phone + (u.name ? ' (' + u.name + ')' : '');
                        dropdown.style.display = 'none';
                    });
                    dropdown.appendChild(div);
                });
                dropdown.style.display = 'block';
            }
            searchInput.addEventListener('input', function() {
                hiddenInput.value = '';
                selectedLabel.textContent = '';
                renderDropdown(this.value);
            });
            searchInput.addEventListener('blur', function() { setTimeout(function() { dropdown.style.display = 'none'; }, 200); });
            searchInput.addEventListener('focus', function() { if (this.value) renderDropdown(this.value); });
        }
    });
    </script>
    <?php endif; ?>
    <?php endif; ?>

<?php if ($activeTab === 'visitors'): ?>
<?php if (!hasAdminPermission('visitors')): ?>
<div class="alert alert-danger text-center py-4"><i class="fas fa-lock me-2"></i>You do not have permission to view visitor logs.</div>
<?php else: ?>
<?php
// Lazy-load analytics only when visitors tab is active
$tabStats = $db->query("SELECT tab_name, COUNT(*) as visits FROM tab_views WHERE visited_at >= CURDATE() GROUP BY tab_name ORDER BY visits DESC")->fetchAll();
$countryStats = $db->query("SELECT country, COUNT(*) as visits FROM page_views WHERE country != 'Unknown' AND visited_at >= CURDATE() GROUP BY country ORDER BY visits DESC LIMIT 8")->fetchAll();
$todayVisits = $db->query("SELECT COUNT(*) FROM page_views WHERE visited_at >= CURDATE()")->fetchColumn() ?: 0;
$pageStats = $db->query("SELECT page, COUNT(*) as visits FROM page_views WHERE visited_at >= CURDATE() GROUP BY page ORDER BY visits DESC")->fetchAll();
$topIps = $db->query("SELECT ip_address, COUNT(*) as hits, MAX(visited_at) as last_hit FROM page_views WHERE visited_at >= CURDATE() AND ip_address != '' GROUP BY ip_address ORDER BY hits DESC LIMIT 5")->fetchAll();
$recentVisits = $db->query("SELECT pv.*, wu.phone, wu.email FROM page_views pv LEFT JOIN web_users wu ON pv.user_id = wu.id ORDER BY pv.visited_at DESC LIMIT 20")->fetchAll();
?>
<div class="card">
    <div class="card-header"><h2 class="card-title">Today's Visitor Activity</h2><span class="badge" style="background: var(--primary); color: white;"><?= $todayVisits ?> Total Visits</span></div>
    <div class="stats-grid" style="margin-bottom: 1rem;">
        <?php if (!empty($countryStats)): ?>
        <div class="stat-card" style="border: 2px solid #10B981; margin-bottom: 1rem;">
            <h5 style="margin: 0 0 0.5rem 0; font-size: 0.9rem;"><i class="fas fa-globe me-1"></i>Top Countries Today</h5>
            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
            <?php foreach ($countryStats as $c): ?>
            <span style="background: #ECFDF5; padding: 0.25rem 0.5rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600;"><?= htmlspecialchars($c['country']) ?>: <strong><?= $c['visits'] ?></strong></span>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($tabStats)): ?>
        <div class="stat-card" style="border: 2px solid #8B5CF6; margin-bottom: 1rem;">
            <h5 style="margin: 0 0 0.5rem 0; font-size: 0.9rem;"><i class="fas fa-bullseye me-1"></i>Popular Dashboard Tabs</h5>
            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
            <?php foreach ($tabStats as $t): ?>
            <span style="background: #F3E8FF; padding: 0.25rem 0.5rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600;"><i class="fas fa-bullseye me-1" style="color: var(--primary);"></i><?= htmlspecialchars(ucwords(str_replace('_', ' ', $t['tab_name']))) ?>: <strong><?= $t['visits'] ?></strong></span>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($pageStats)): ?>
        <div class="stat-card" style="border: 2px solid #3B82F6; margin-bottom: 1rem;">
            <h5 style="margin: 0 0 0.5rem 0; font-size: 0.9rem;"><i class="fas fa-file me-1"></i>Top Pages Today</h5>
            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
            <?php foreach ($pageStats as $ps): ?>
            <span style="background: #DBEAFE; padding: 0.25rem 0.5rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600;"><i class="fas fa-file me-1" style="color: var(--primary);"></i><?= htmlspecialchars(basename($ps['page'])) ?>: <strong><?= (int)$ps['visits'] ?></strong></span>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="mb-2" style="display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem;">
        <input type="text" class="table-search form-control form-control-sm" data-table="visitorTable" placeholder="Filter by IP, Page, or Email..." style="max-width:320px;">
        <?php if (!empty($topIps)): ?>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.4rem;">
            <span style="font-size:0.75rem;font-weight:600;color:var(--text-muted);"><i class="fas fa-shield-alt me-1" style="color:#EF4444;"></i>Top IPs:</span>
            <?php foreach ($topIps as $ip):
                $h = (int)$ip['hits'];
                if ($h > 100) { $bg = 'rgba(239,68,68,0.12)'; $c = '#EF4444'; $l = 'Likely scraper or bot'; }
                elseif ($h > 50) { $bg = 'rgba(249,115,22,0.12)'; $c = '#F97316'; $l = 'Heavy usage — monitor'; }
                elseif ($h > 10) { $bg = 'rgba(234,179,8,0.12)'; $c = '#EAB308'; $l = 'Active user'; }
                else { $bg = 'rgba(34,197,94,0.12)'; $c = '#22C55E'; $l = 'Normal visitor'; }
            ?>
            <span style="background:<?= $bg ?>;color:<?= $c ?>;padding:0.15rem 0.5rem;border-radius:4px;font-size:0.75rem;font-weight:700;cursor:help;" title="<?= $l ?>, last hit: <?= htmlspecialchars($ip['last_hit']) ?>"><?= htmlspecialchars($ip['ip_address']) ?> [<?= $h ?>]</span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table" id="visitorTable" data-page-size="10">
            <thead><tr><th>Time</th><th>User</th><th>Country</th><th>Page</th><th>IP Address</th><th>Browser</th></tr></thead>
            <tbody>
            <?php if (empty($recentVisits)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-inbox me-2"></i>No visitor data yet</td></tr>
            <?php else: foreach ($recentVisits as $v): ?>
                <tr>
                    <td class="text-muted"><?php $dt = new DateTime($v['visited_at'], new DateTimeZone('Africa/Dar_es_Salaam')); echo $dt->format('H:i:s'); ?></td>
                    <td><?= $v['user_id'] ? '<a href="?tab=users" class="text-decoration-none">#'.$v['user_id'].'</a>' : '<span class="badge badge-pending">Guest</span>' ?></td>
                    <td><?= $v['country'] !== 'Unknown' ? '<i class="fas fa-globe me-1"></i>'.htmlspecialchars($v['country']) : '<i class="fas fa-globe me-1"></i>Unknown' ?></td>
                    <td><code style="color: var(--primary);"><?= htmlspecialchars($v['page']) ?></code></td>
                    <td><?= htmlspecialchars($v['ip_address']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars(substr($v['user_agent'], 0, 40)) ?>...</td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="text-center py-2 load-more-wrap" data-table="visitorTable" style="border-top:1px solid var(--border-color);">
        <button type="button" class="btn btn-sm load-more-btn" style="background:var(--bg-soft);color:var(--primary);border:1px solid var(--border-color);padding:4px 20px;border-radius:6px;">
            Show <span class="count">0</span> more
        </button>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($activeTab === 'code_purchases'): ?>
<?php if (!hasAdminPermission('code_purchases')): ?>
<div class="alert alert-danger text-center py-4"><i class="fas fa-lock me-2"></i>You do not have permission to manage code purchases.</div>
<?php else:
$creditStats = getCreditSummaryStats();
$topSellers = getTopSellerStats(10);
?>
<div class="row g-3 mb-3">
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="stat-label">Credits Sold</div><div class="stat-number"><?= $creditStats['total_credits_sold'] ?></div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="stat-label">Credit Revenue</div><div class="stat-number"><?= number_format($creditStats['total_credit_revenue']) ?> TZS</div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="stat-label">Publisher Earnings</div><div class="stat-number"><?= number_format($creditStats['total_publisher_earnings']) ?> TZS</div></div>
    </div>
    <div class="col-md-3 col-6">
        <div class="stat-card"><div class="stat-label">Active Codes</div><div class="stat-number"><?= $creditStats['active_codes'] ?></div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h2 class="card-title"><i class="fas fa-trophy me-1" style="color:#FBBF24;"></i>Top Contributing Sellers</h2><span class="text-muted" style="font-size:0.75rem;">Reward top performers with bonus credits</span></div>
    <div class="mb-2"><input type="text" class="table-search form-control form-control-sm" data-table="sellerTable" placeholder="Search seller by name or phone..." style="max-width:320px;"></div>
    <div class="table-responsive">
        <table class="table" id="sellerTable" data-page-size="10">
            <thead><tr><th>#</th><th>Seller</th><th>Codes</th><th>Sales</th><th>Earned (TZS)</th><th>Credits</th><th>Reward</th></tr></thead>
            <tbody>
                <?php if (empty($topSellers)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-users me-2"></i>No sellers yet</td></tr>
                <?php else: $rank = 1; foreach ($topSellers as $s): ?>
                <tr>
                    <td><strong>#<?= $rank++ ?></strong></td>
                    <td><code style="color: var(--primary);"><?= htmlspecialchars($s['display_name'] ?: $s['phone']) ?></code></td>
                    <td><?= (int)$s['total_codes'] ?></td>
                    <td><strong><?= (int)$s['total_sales'] ?></strong></td>
                    <td><?= number_format((float)$s['total_earned']) ?></td>
                    <td><span class="badge" style="background:#DCFCE7;color:#166534;"><?= (int)$s['publisher_credits'] ?></span></td>
                    <td>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Award 6 bonus credits (2 days) to <?= htmlspecialchars($s['display_name'] ?: $s['phone']) ?>?')">
                            <input type="hidden" name="action" value="award_bonus_credits">
                            <input type="hidden" name="target_user_id" value="<?= (int)$s['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="background:linear-gradient(135deg,#FBBF24,#F59E0B);color:#000;padding:2px 10px;font-size:0.7rem;border:none;"><i class="fas fa-gift me-1"></i>6 Credits</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="text-center py-2 load-more-wrap" data-table="sellerTable" style="border-top:1px solid var(--border-color);">
        <button type="button" class="btn btn-sm load-more-btn" style="background:var(--bg-soft);color:var(--primary);border:1px solid var(--border-color);padding:4px 20px;border-radius:6px;">
            Show <span class="count">0</span> more
        </button>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="card-title"><i class="fas fa-coins me-1"></i>Pending Credit Purchases</h2><span class="badge" style="background: var(--primary); color: white;"><?= count($pendingCreditPurchases) ?> Pending</span></div>
    <div class="mb-2"><input type="text" class="table-search form-control form-control-sm" data-table="pendingCreditsTable" placeholder="Search by name or phone..." style="max-width:320px;"></div>
    <div class="table-responsive">
        <table class="table" id="pendingCreditsTable" data-page-size="10">
            <thead><tr><th>ID</th><th>User</th><th>Credits</th><th>Amount</th><th>Reference</th><th>Date</th><th>Action</th></tr></thead>
            <tbody>
                <?php if (empty($pendingCreditPurchases)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-coins me-2"></i>No pending credit purchases</td></tr>
                <?php else: foreach ($pendingCreditPurchases as $pc): ?>
                <tr>
                    <td>#<?= $pc['id'] ?></td>
                    <td><code style="color: var(--primary);"><?= htmlspecialchars($pc['display_name'] ?: $pc['phone']) ?></code><br><small class="text-muted"><?= htmlspecialchars($pc['phone']) ?></small></td>
                    <td><strong><?= (int)$pc['credits_requested'] ?></strong></td>
                    <td><?= number_format($pc['amount_paid']) ?> TZS</td>
                    <td><code><?= htmlspecialchars($pc['payment_reference']) ?></code></td>
                    <td class="text-muted"><?= date('M d, H:i', strtotime($pc['created_at'])) ?></td>
                    <td>
                        <form method="POST" class="d-inline"><input type="hidden" name="action" value="approve_credit"><input type="hidden" name="credit_id" value="<?= $pc['id'] ?>"><button type="submit" class="btn btn-approve me-1" onclick="return confirm('Award <?= (int)$pc['credits_requested'] ?> credits to <?= htmlspecialchars($pc['phone']) ?>?')">Approve</button></form>
                        <form method="POST" class="d-inline reject-form"><input type="hidden" name="action" value="reject_credit"><input type="hidden" name="credit_id" value="<?= $pc['id'] ?>"><input type="hidden" name="rejection_reason" value=""><button type="button" class="btn btn-sm" style="background: #EF4444; color: white; border: none; padding: 0.35rem 0.75rem; border-radius: 6px;" onclick="var r=prompt('Rejection reason:','Wrong reference number');if(r){this.form.rejection_reason.value=r;this.form.submit();}">Reject</button></form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="text-center py-2 load-more-wrap" data-table="pendingCreditsTable" style="border-top:1px solid var(--border-color);">
        <button type="button" class="btn btn-sm load-more-btn" style="background:var(--bg-soft);color:var(--primary);border:1px solid var(--border-color);padding:4px 20px;border-radius:6px;">
            Show <span class="count">0</span> more
        </button>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><h2 class="card-title"><i class="fas fa-users-gear me-1"></i>Publisher Credit Balances</h2></div>
    <div class="mb-2"><input type="text" class="table-search form-control form-control-sm" data-table="publisherBalancesTable" placeholder="Search by name or phone..." style="max-width:320px;"></div>
    <div class="table-responsive">
        <table class="table" id="publisherBalancesTable" data-page-size="10">
            <thead><tr><th>ID</th><th>Phone</th><th>Status</th><th>Credits</th><th>Free Awarded</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if (empty($allUsersWithCredits)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-users me-2"></i>No users found</td></tr>
                <?php else: foreach ($allUsersWithCredits as $u): ?>
                <tr>
                    <td>#<?= $u['id'] ?></td>
                    <td><code style="color: var(--primary);"><?= htmlspecialchars($u['display_name'] ?: $u['phone']) ?></code><br><small class="text-muted"><?= htmlspecialchars($u['phone']) ?></small></td>
                    <td><span class="badge <?= $u['status'] === 'premium_rollover' || $u['status'] === 'premium_both' ? 'badge-rollover' : 'badge-pending' ?>"><?= htmlspecialchars($u['status']) ?></span></td>
                    <td><strong><?= (int)$u['publisher_credits'] ?></strong></td>
                    <td><?= $u['free_credits_updated_at'] ? '<i class="fas fa-check-circle" style="color:#22C55E;"></i> ' . date('M d', strtotime($u['free_credits_updated_at'])) : '<span class="text-muted"><i class="fas fa-times me-1"></i>No</span>' ?></td>
                    <td><button type="button" class="btn btn-sm" style="background: var(--primary); color: white; border: none; padding: 0.25rem 0.5rem; border-radius: 6px;" onclick="showCreditHistory(<?= $u['id'] ?>, '<?= htmlspecialchars($u['display_name'] ?: $u['phone'], ENT_QUOTES) ?>')">History</button></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="text-center py-2 load-more-wrap" data-table="publisherBalancesTable" style="border-top:1px solid var(--border-color);">
        <button type="button" class="btn btn-sm load-more-btn" style="background:var(--bg-soft);color:var(--primary);border:1px solid var(--border-color);padding:4px 20px;border-radius:6px;">
            Show <span class="count">0</span> more
        </button>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><h2 class="card-title"><i class="fas fa-receipt me-1"></i>All Credit Purchase History</h2></div>
    <div class="mb-2"><input type="text" class="table-search form-control form-control-sm" data-table="creditHistoryTable" placeholder="Search by name or phone..." style="max-width:320px;"></div>
    <div class="table-responsive">
        <table class="table" id="creditHistoryTable" data-page-size="10">
            <thead><tr><th>ID</th><th>User</th><th>Credits</th><th>Amount</th><th>Reference</th><th>Status</th><th>Verified</th></tr></thead>
            <tbody>
                <?php if (empty($allCreditPurchases)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-receipt me-2"></i>No credit purchases yet</td></tr>
                <?php else: foreach ($allCreditPurchases as $ac): ?>
                <tr>
                    <td>#<?= $ac['id'] ?></td>
                    <td><code style="color: var(--primary);"><?= htmlspecialchars($ac['display_name'] ?: $ac['phone']) ?></code><br><small class="text-muted"><?= htmlspecialchars($ac['phone']) ?></small></td>
                    <td><?= (int)$ac['credits_requested'] ?></td>
                    <td><?= number_format($ac['amount_paid']) ?> TZS</td>
                    <td><code><?= htmlspecialchars($ac['payment_reference']) ?></code></td>
                    <td><span class="badge <?= $ac['status'] === 'approved' ? 'badge-rollover' : ($ac['status'] === 'pending' ? 'badge-pending' : 'badge-parlay') ?>"><?= $ac['status'] ?></span></td>
                    <td class="text-muted"><?= $ac['verified_at'] ? date('M d, H:i', strtotime($ac['verified_at'])) : '-' ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="text-center py-2 load-more-wrap" data-table="creditHistoryTable" style="border-top:1px solid var(--border-color);">
        <button type="button" class="btn btn-sm load-more-btn" style="background:var(--bg-soft);color:var(--primary);border:1px solid var(--border-color);padding:4px 20px;border-radius:6px;">
            Show <span class="count">0</span> more
        </button>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><h2 class="card-title"><i class="fas fa-plane me-1" style="color:#F59E0B;"></i>Pending Aviator Access Purchases</h2><span class="badge" style="background: var(--primary); color: white;"><?= count($pendingAviatorPurchases) ?> Pending</span></div>
    <div class="mb-2"><input type="text" class="table-search form-control form-control-sm" data-table="aviatorPurchasesTable" placeholder="Search by name or phone..." style="max-width:320px;"></div>
    <div class="table-responsive">
        <table class="table" id="aviatorPurchasesTable" data-page-size="10">
            <thead><tr><th>ID</th><th>User</th><th>Amount</th><th>Reference</th><th>Date</th><th>Action</th></tr></thead>
            <tbody>
                <?php if (empty($pendingAviatorPurchases)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-plane me-2"></i>No pending aviator purchases</td></tr>
                <?php else: foreach ($pendingAviatorPurchases as $ap): ?>
                <tr>
                    <td>#<?= $ap['id'] ?></td>
                    <td><code style="color: var(--primary);"><?= htmlspecialchars($ap['display_name'] ?: $ap['phone']) ?></code><br><small class="text-muted"><?= htmlspecialchars($ap['phone']) ?></small></td>
                    <td><?= number_format($ap['amount']) ?> TZS</td>
                    <td><code><?= htmlspecialchars($ap['payment_reference']) ?></code></td>
                    <td class="text-muted"><?= date('M d, H:i', strtotime($ap['created_at'])) ?></td>
                    <td>
                        <form method="POST" class="d-inline"><input type="hidden" name="action" value="approve_aviator"><input type="hidden" name="aviator_id" value="<?= $ap['id'] ?>"><button type="submit" class="btn btn-approve me-1" onclick="return confirm('Grant 1-day Aviator access to <?= htmlspecialchars($ap['phone']) ?>?')">Approve</button></form>
                        <form method="POST" class="d-inline reject-form"><input type="hidden" name="action" value="reject_aviator"><input type="hidden" name="aviator_id" value="<?= $ap['id'] ?>"><input type="hidden" name="rejection_reason" value=""><button type="button" class="btn btn-sm" style="background: #EF4444; color: white; border: none; padding: 0.35rem 0.75rem; border-radius: 6px;" onclick="var r=prompt('Rejection reason:','Wrong reference number');if(r){this.form.rejection_reason.value=r;this.form.submit();}">Reject</button></form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="text-center py-2 load-more-wrap" data-table="aviatorPurchasesTable" style="border-top:1px solid var(--border-color);">
        <button type="button" class="btn btn-sm load-more-btn" style="background:var(--bg-soft);color:var(--primary);border:1px solid var(--border-color);padding:4px 20px;border-radius:6px;">
            Show <span class="count">0</span> more
        </button>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><h2 class="card-title"><i class="fas fa-history me-1"></i>All Aviator Purchase History</h2></div>
    <div class="mb-2"><input type="text" class="table-search form-control form-control-sm" data-table="aviatorHistoryTable" placeholder="Search by name or phone..." style="max-width:320px;"></div>
    <div class="table-responsive">
        <table class="table" id="aviatorHistoryTable" data-page-size="10">
            <thead><tr><th>ID</th><th>User</th><th>Amount</th><th>Reference</th><th>Status</th><th>Verified</th></tr></thead>
            <tbody>
                <?php if (empty($allAviatorPurchases)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-plane me-2"></i>No aviator purchases yet</td></tr>
                <?php else: foreach ($allAviatorPurchases as $ap): ?>
                <tr>
                    <td>#<?= $ap['id'] ?></td>
                    <td><code style="color: var(--primary);"><?= htmlspecialchars($ap['display_name'] ?: $ap['phone']) ?></code><br><small class="text-muted"><?= htmlspecialchars($ap['phone']) ?></small></td>
                    <td><?= number_format($ap['amount']) ?> TZS</td>
                    <td><code><?= htmlspecialchars($ap['payment_reference']) ?></code></td>
                    <td><span class="badge <?= $ap['status'] === 'approved' ? 'badge-rollover' : ($ap['status'] === 'pending' ? 'badge-pending' : 'badge-parlay') ?>"><?= $ap['status'] ?></span></td>
                    <td class="text-muted"><?= $ap['verified_at'] ? date('M d, H:i', strtotime($ap['verified_at'])) : '-' ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="text-center py-2 load-more-wrap" data-table="aviatorHistoryTable" style="border-top:1px solid var(--border-color);">
        <button type="button" class="btn btn-sm load-more-btn" style="background:var(--bg-soft);color:var(--primary);border:1px solid var(--border-color);padding:4px 20px;border-radius:6px;">
            Show <span class="count">0</span> more
        </button>
    </div>
</div>

<!-- Winning Slips Approval -->
<div class="card mt-3">
    <div class="card-header"><h2 class="card-title"><i class="fas fa-trophy me-1" style="color:#F59E0B;"></i>Pending Winning Slips / Slip Zinazosubiri</h2><span class="badge" style="background: var(--primary); color: white;"><?= count($pendingSlips) ?> Pending</span></div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>#</th><th>User</th><th>Image</th><th>Description</th><th>Date</th><th>Action</th></tr></thead>
            <tbody>
                <?php if (empty($pendingSlips)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><i class="fas fa-trophy me-2"></i>No pending slips</td></tr>
                <?php else: foreach ($pendingSlips as $slip): ?>
                <tr>
                    <td>#<?= $slip['id'] ?></td>
                    <td><code style="color:var(--primary);"><?= htmlspecialchars($slip['uname'] ?: $slip['phone']) ?></code></td>
                    <td><a href="<?= htmlspecialchars($slip['image_path']) ?>" target="_blank"><img src="<?= htmlspecialchars($slip['image_path']) ?>" style="height:40px;border-radius:4px;" alt="slip"></a></td>
                    <td><small><?= htmlspecialchars($slip['description'] ?: '-') ?></small></td>
                    <td class="text-muted"><?= date('M d, H:i', strtotime($slip['created_at'])) ?></td>
                    <td>
                        <form method="POST" class="d-inline"><input type="hidden" name="action" value="approve_slip"><input type="hidden" name="slip_id" value="<?= $slip['id'] ?>"><button type="submit" class="btn btn-approve me-1" style="padding:0.2rem 0.6rem;font-size:0.75rem;">Approve</button></form>
                        <form method="POST" class="d-inline"><input type="hidden" name="action" value="reject_slip"><input type="hidden" name="slip_id" value="<?= $slip['id'] ?>"><button type="submit" class="btn btn-sm" style="background:#EF4444;color:white;border:none;padding:0.2rem 0.6rem;border-radius:6px;font-size:0.75rem;" onclick="return confirm('Reject this slip?')">Reject</button></form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($activeTab === 'top_sellers'): ?>
<?php if (!hasAdminPermission('rewards')): ?>
<div class="alert alert-danger text-center py-4"><i class="fas fa-lock me-2"></i>You do not have permission to access this section.</div>
<?php else: ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="fas fa-trophy me-1" style="color: #F59E0B;"></i>Top Sellers — Monthly Reward</h2>
        <span class="badge" style="background: #F59E0B; color: #000;"><?= number_format(FREE_CREDITS_PER_WEEK) ?> Credits Each</span>
    </div>
    <div class="alert" style="background: #F0F4FF; border-left: 4px solid var(--primary); font-size: 0.85rem;">
        <strong><i class="fas fa-info-circle me-1"></i>Reward Scheme:</strong>
        Every end of month, the top 3 sellers (by approved sales) each earn <strong><?= number_format(FREE_CREDITS_PER_WEEK) ?> free credits</strong>.
        This is tracked per calendar month. You can only award once per month.
    </div>
    <?php if ($topSellerRewardAwarded): ?>
    <div class="alert alert-success text-center py-3" style="font-size:1rem;">
        <i class="fas fa-check-circle me-1" style="color:#22C55E;"></i>
        <strong>Rewards already awarded for <?= date('F Y') ?>.</strong> Next cycle begins <?= date('F Y', strtotime('first day of next month')) ?>.
    </div>
    <?php elseif (empty($topSellersThisMonth)): ?>
    <div class="alert alert-warning text-center py-3">
        <i class="fas fa-exclamation-triangle me-1"></i>
        No approved sales this month yet. Top sellers will appear here once sales are recorded.
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Rank</th><th>Seller</th><th>Phone</th><th>Approved Sales</th><th>Reward</th></tr></thead>
            <tbody>
                <?php $rank = 0; foreach ($topSellersThisMonth as $ts): $rank++; ?>
                <tr>
                    <td><span style="font-size:1.3rem;"><?= $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : '🥉') ?></span></td>
                    <td><strong><?= htmlspecialchars($ts['display_name'] ?: 'Seller #'.$ts['id']) ?></strong></td>
                    <td><?= htmlspecialchars($ts['phone']) ?></td>
                    <td><span class="badge" style="background: #F59E0B; color: #000; font-size:0.9rem;"><?= (int)$ts['total_sales'] ?> sales</span></td>
                    <td><strong style="color: #059669;">+<?= number_format(FREE_CREDITS_PER_WEEK) ?> credits</strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="POST" onsubmit="return confirm('Award <?= number_format(FREE_CREDITS_PER_WEEK) ?> credits each to top <?= count($topSellersThisMonth) ?> sellers for <?= date('F Y') ?>?')">
        <input type="hidden" name="action" value="award_top_sellers">
        <button type="submit" class="btn btn-approve w-100" style="padding: 0.75rem;">
            <i class="fas fa-gift me-1"></i>Award <?= number_format(FREE_CREDITS_PER_WEEK) ?> Credits to Top <?= count($topSellersThisMonth) ?> Sellers
        </button>
    </form>
    <?php endif; ?>
</div>

<!-- Give Free Credits -->
<div class="card mt-3">
    <div class="card-header"><h2 class="card-title"><i class="fas fa-gift me-1" style="color:#F59E0B;"></i>Give Free Credits</h2></div>
    <div class="p-3">
        <form method="POST" class="row g-2 align-items-end" onsubmit="return document.getElementById('selectedUserId').value !== '' || (alert('Please select a user from the search results first.'), false);">
            <input type="hidden" name="action" value="give_free_credits">
            <div class="col-md-5" style="position:relative;">
                <label class="form-label text-muted small">Find User</label>
                <input type="text" id="userSearchInput" class="form-control form-control-sm" placeholder="Type phone or display name..." autocomplete="off">
                <input type="hidden" name="target_user_id" id="selectedUserId" value="">
                <div id="selectedUserLabel" class="small" style="color:var(--primary);font-weight:600;margin-top:2px;"></div>
                <div id="userSearchResults" class="list-group" style="position:absolute;z-index:100;display:none;max-height:200px;overflow-y:auto;width:100%;"></div>
            </div>
            <div class="col-md-2">
                <label class="form-label text-muted small">Credits</label>
                <input type="number" name="credits" class="form-control form-control-sm" min="1" value="1" required>
            </div>
            <div class="col-md-3">
                <label class="form-label text-muted small">Reason (optional)</label>
                <input type="text" name="reason" class="form-control form-control-sm" placeholder="e.g. Gift, promotion">
            </div>
            <div class="col-md-2">
                <label class="form-label text-muted small">&nbsp;</label>
                <button type="submit" class="btn btn-premium w-100" style="font-size:0.85rem;padding:0.5rem;"><i class="fas fa-gift me-1"></i>Give</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('userSearchInput');
    var results = document.getElementById('userSearchResults');
    var hidden = document.getElementById('selectedUserId');
    var label = document.getElementById('selectedUserLabel');
    var debounceTimer;
    function clearSelected() {
        hidden.value = '';
        if (label) label.textContent = '';
    }
    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        clearSelected();
        var q = this.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        debounceTimer = setTimeout(function() {
            var baseUrl = window.location.pathname.replace(/\/[^/]*$/, '') + '/';
            baseUrl = baseUrl.replace(/\/\/+/g, '/');
            fetch(baseUrl + 'ajax_search_users.php?q=' + encodeURIComponent(q))
                .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function(data) {
                    results.innerHTML = '';
                    if (data.length === 0) {
                        results.innerHTML = '<div class="list-group-item text-muted small">No users found</div>';
                    } else {
                        data.forEach(function(u) {
                            var a = document.createElement('a');
                            a.href = '#';
                            a.className = 'list-group-item list-group-item-action small';
                            a.textContent = (u.display_name ? u.display_name + ' — ' : '') + u.phone + ' (#' + u.id + ')';
                            a.dataset.id = u.id;
                            a.addEventListener('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                input.value = this.textContent;
                                hidden.value = this.dataset.id;
                                if (label) label.textContent = 'Selected: ' + u.phone + (u.display_name ? ' (' + u.display_name + ')' : '');
                                results.style.display = 'none';
                            });
                            results.appendChild(a);
                        });
                    }
                    results.style.display = 'block';
                })
                .catch(function(err) {
                    results.innerHTML = '<div class="list-group-item text-danger small">Search failed (' + err.message + ')</div>';
                    results.style.display = 'block';
                });
        }, 300);
    });
    document.addEventListener('click', function(e) {
        var wrap = document.getElementById('userSearchResults');
        if (wrap && !wrap.contains(e.target) && e.target !== input) results.style.display = 'none';
    });
});
</script>
<?php endif; ?>
<?php endif; ?>

<?php if ($activeTab === 'scrape_analyze'): ?>
<?php if (!hasAdminPermission('analysis') && !hasAdminPermission('scraper') && !hasAdminPermission('picks')): ?>
<div class="alert alert-danger text-center py-4"><i class="fas fa-lock me-2"></i>You do not have permission to access this section.</div>
<?php else:
$requestedSub = $_GET['sub'] ?? '';
$sub = in_array($requestedSub, ['analysis', 'verified']) ? $requestedSub : 'analysis';
?>
<div style="display:flex;gap:8px;margin-bottom:1rem;border-bottom:2px solid #E5E7EB;padding-bottom:0.5rem;padding-left:4px;">
    <a href="?tab=scrape_analyze" class="nav-link <?= $sub === 'analysis' ? 'active' : '' ?>" style="text-decoration:none;font-weight:600;font-size:0.8rem;padding:4px 14px;border-radius:8px;<?= $sub === 'analysis' ? 'background:var(--primary);color:#fff;' : 'color:var(--text-muted);' ?>"><i class="fas fa-microchip me-1"></i>Analysis</a>
    <?php if ($isSuperAdmin || hasAdminPermission('consensus')): ?>
    <a href="?tab=scrape_analyze&sub=verified" class="nav-link <?= $sub === 'verified' ? 'active' : '' ?>" style="text-decoration:none;font-weight:600;font-size:0.8rem;padding:4px 14px;border-radius:8px;<?= $sub === 'verified' ? 'background:var(--primary);color:#fff;' : 'color:var(--text-muted);' ?>"><i class="fas fa-check-circle me-1"></i>Verified Data</a>
    <?php endif; ?>
</div>

<?php if ($sub === 'analysis'): ?>
<div class="alert" style="background: #F0F4FF; border-left: 4px solid var(--primary); margin-bottom: 1rem; font-size: 0.85rem;">
    <strong><i class="fas fa-sitemap me-1"></i>Analysis</strong>
    — Run analysis to generate picks from your scraped odds data.
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card h-100 d-flex flex-column" style="border-left: 4px solid #8B5CF6;">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-brain me-1"></i>Run Analysis</h2>
                <span class="badge" style="background: var(--primary); color: white;">Generates picks from scraped odds</span>
            </div>
            <div class="alert" style="background: #F0F4FF; border-left: 4px solid var(--primary); margin-bottom: 1rem; font-size: 0.8rem;">
                <strong><i class="fas fa-lightbulb me-1"></i>How it works:</strong> Reads the latest scraped odds from Google Sheets (OAuth2),
                runs pattern detection, form analysis, H2H, risk tiers, and Over 1.5 detection,
                then adds new picks to the database (additive mode).
            </div>
            <form method="POST" class="mt-auto">
                <input type="hidden" name="action" value="run_analysis">
                <button type="submit" class="btn btn-approve w-100" style="padding: 0.6rem; font-size: 0.9rem;" onclick="return confirm('Run analysis now? New picks will be added without removing existing ones.')">
                    <i class="fas fa-play me-1"></i>Run Analysis Now
                </button>
            </form>
            <?php if (!empty($analysisLog)): ?>
            <div style="margin-top: 0.75rem; background: #F8FAFC; border: 1px solid var(--border-color); border-radius: 6px; padding: 0.75rem; font-family: 'Courier New', monospace; font-size: 0.75rem; max-height: 200px; overflow-y: auto;">
                <?php foreach ($analysisLog as $log): ?>
                <div style="margin: 2px 0; color: var(--text-muted);"><?= htmlspecialchars($log) ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100 d-flex flex-column" style="border-left: 4px solid #EF4444;">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-chart-line me-1" style="color:#EF4444;"></i>Odds Signals</h2>
                <span class="badge" style="background: #EF4444; color: white;">Decision tool</span>
            </div>
            <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:0.75rem;">Analyze today's odds movement across 5 markets (1X2, DC, Over 1.5, GG, Team to Score) with confidence scores.</p>
            <a href="odds-signals" class="btn btn-approve w-100 mt-auto" style="padding:0.6rem;font-size:0.9rem;background:#EF4444;border-color:#EF4444;">
                <i class="fas fa-chart-line me-1"></i>Open Odds Signals
            </a>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/classes/BayesianModel.php';
// Bayesian accuracy monitor card
$bayesianModel = new BayesianModel();
$accTrend = $bayesianModel->getAccuracyTrend(30);
$overallCorrect = 0; $overallTotal = 0;
foreach ($accTrend as $d) { $overallCorrect += (int)$d['correct']; $overallTotal += (int)$d['total']; }
$overallAcc = $overallTotal > 0 ? round($overallCorrect / $overallTotal * 100, 1) : 0;

// Auto-tune: check DB cache first (avoids 80k+ queries on every new session)
if ($overallTotal >= 20) {
    $tunedK = $_SESSION['bayesian_k'] ?? null;
    $tunedErr = $_SESSION['bayesian_err'] ?? null;
    if ($tunedK === null) {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS bayesian_config (`key` VARCHAR(50) PRIMARY KEY, `value` TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
            $cfgRow = $db->query("SELECT `value` FROM bayesian_config WHERE `key` = 'prior_k'")->fetch();
            if ($cfgRow) {
                $tunedK = (float)$cfgRow['value'];
                $cfgErr = $db->query("SELECT `value` FROM bayesian_config WHERE `key` = 'prior_k_err'")->fetch();
                $tunedErr = $cfgErr ? (float)$cfgErr['value'] : null;
            } else {
                $tuneResult = $bayesianModel->tunePriorStrength();
                if (is_array($tuneResult)) {
                    $tunedK = $tuneResult['best_k'];
                    $tunedErr = $tuneResult['best_err'];
                    $db->prepare("REPLACE INTO bayesian_config (`key`, `value`) VALUES ('prior_k', ?)")->execute([$tunedK]);
                    $db->prepare("REPLACE INTO bayesian_config (`key`, `value`) VALUES ('prior_k_err', ?)")->execute([$tunedErr]);
                }
            }
        } catch (Exception $e) {
            $tunedK = $_SESSION['bayesian_k'] ?? $bayesianModel->getPriorStrength();
        }
        $_SESSION['bayesian_k'] = $tunedK;
        $_SESSION['bayesian_k_err'] = $tunedErr;
    }
} else {
    $tunedK = $_SESSION['bayesian_k'] ?? $bayesianModel->getPriorStrength();
    $tunedErr = $_SESSION['bayesian_k_err'] ?? null;
}
?>
<div class="card mt-3" style="border-left: 4px solid #10B981;">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <h2 class="card-title" style="margin:0;"><i class="fas fa-robot me-1" style="color:#10B981;"></i>Bayesian Model Monitor</h2>
        <span class="badge" style="background:#10B981;color:#fff;">Rolling 30-day</span>
        <span style="margin-left:auto;font-size:0.78rem;color:var(--text-muted);">k = <?= $tunedK ?> <?php if ($tunedErr): ?><span title="Grid-search error rate">(err: <?= $tunedErr ?>%)</span><?php endif; ?></span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <div style="text-align:center;padding:1rem;background:var(--bg-soft);border-radius:8px;">
                    <div style="font-size:2rem;font-weight:700;color:#10B981;"><?= $overallAcc ?>%</div>
                    <div style="font-size:0.75rem;color:var(--text-muted);">30-Day Accuracy</div>
                </div>
            </div>
            <div class="col-md-3">
                <div style="text-align:center;padding:1rem;background:var(--bg-soft);border-radius:8px;">
                    <div style="font-size:2rem;font-weight:700;color:var(--primary);"><?= $overallCorrect ?>/<?= $overallTotal ?></div>
                    <div style="font-size:0.75rem;color:var(--text-muted);">Correct / Total</div>
                </div>
            </div>
            <div class="col-md-3">
                <div style="text-align:center;padding:1rem;background:var(--bg-soft);border-radius:8px;">
                    <div style="font-size:1.3rem;font-weight:700;color:<?= ($overallAcc >= 50 ? '#10B981' : '#EF4444') ?>;">
                        <?= $overallAcc >= 60 ? '✓ Good' : ($overallAcc >= 45 ? '⚠ Average' : '✗ Poor') ?>
                    </div>
                    <div style="font-size:0.75rem;color:var(--text-muted);">Status</div>
                </div>
            </div>
        </div>
        <?php if (!empty($accTrend)): ?>
        <div style="margin-top:1rem;">
            <h6 style="font-size:0.78rem;font-weight:600;margin-bottom:0.5rem;">Daily Accuracy (last 30 days)</h6>
            <div style="display:flex;gap:2px;align-items:flex-end;height:60px;flex-wrap:nowrap;overflow-x:auto;">
                <?php foreach ($accTrend as $d):
                    $barH = max(4, min(60, ((float)$d['accuracy'] / 100) * 60));
                    $color = (float)$d['accuracy'] >= 50 ? '#10B981' : '#EF4444';
                ?>
                <div style="display:flex;flex-direction:column;align-items:center;min-width:28px;">
                    <div style="width:20px;height:<?= $barH ?>px;background:<?= $color ?>;border-radius:3px 3px 0 0;opacity:0.8;" title="<?= $d['day'] ?>: <?= $d['accuracy'] ?>% (<?= $d['correct'] ?>/<?= $d['total'] ?>)"></div>
                    <span style="font-size:0.55rem;color:var(--text-muted);writing-mode:vertical-lr;transform:rotate(180deg);"><?= substr($d['day'], 5) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="row g-2 mt-3" style="border-top:1px solid var(--border-color);padding-top:1rem;">
            <div class="col-md-3">
                <form method="POST">
                    <input type="hidden" name="action" value="bayesian_batch_predict">
                    <button type="submit" class="btn btn-sm w-100" style="background:#059669;color:#fff;border:0;padding:6px 10px;border-radius:6px;font-size:0.75rem;" onclick="return confirm('Run batch predictions for today\\'s matches?')">
                        <i class="fas fa-rocket me-1"></i>Batch Predict
                    </button>
                </form>
            </div>
            <div class="col-md-3">
                <form method="POST">
                    <input type="hidden" name="action" value="bayesian_settle">
                    <button type="submit" class="btn btn-sm w-100" style="background:#D97706;color:#fff;border:0;padding:6px 10px;border-radius:6px;font-size:0.75rem;" onclick="return confirm('Settle today\\'s Bayesian predictions?')">
                        <i class="fas fa-check-double me-1"></i>Settle
                    </button>
                </form>
            </div>
            <div class="col-md-3">
                <form method="POST">
                    <input type="hidden" name="action" value="retune_bayesian">
                    <button type="submit" class="btn btn-sm w-100" style="background:#7C3AED;color:#fff;border:0;padding:6px 10px;border-radius:6px;font-size:0.75rem;" onclick="return confirm('Re-scan k from 5 to 100?')">
                        <i class="fas fa-sliders-h me-1"></i>Re-tune k
                    </button>
                </form>
            </div>
            <div class="col-md-3">
                <form method="POST">
                    <input type="hidden" name="action" value="bayesian_clear_session">
                    <button type="submit" class="btn btn-sm w-100" style="background:#6B7280;color:#fff;border:0;padding:6px 10px;border-radius:6px;font-size:0.75rem;">
                        <i class="fas fa-redo me-1"></i>Reset Cache
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php endif; // end if ($sub === 'analysis') ?>

<?php if ($sub === 'verified'): ?>
<?php
require_once __DIR__ . '/classes/MultiBookieSheetsAPI.php';
$mbApi = new MultiBookieSheetsAPI();
$movementData = $mbApi->getOddsMovements();
$movements = $movementData['movements'] ?? [];
$scrapes = $movementData['scrapes'] ?? [];
$bookieList = [];
foreach ($movements as $m) {
    $b = $m['bookie'];
    if ($b && !in_array($b, $bookieList)) $bookieList[] = $b;
}
$latestScrape = !empty($scrapes) ? end($scrapes) : '';
?>
<div class="card">
    <div class="card-header"><h2 class="card-title"><i class="fas fa-check-circle me-1" style="color:#10B981;"></i>Multi-Bookie Verified Data</h2>
        <span class="badge" style="background:var(--accent);color:#fff;"><?= $latestScrape ? 'Latest: '.htmlspecialchars($latestScrape) : 'No data' ?></span>
    </div>

    <?php
    $bookieCounts = [];
    foreach ($movements as $m) {
        $b = $m['bookie'];
        if (!isset($bookieCounts[$b])) $bookieCounts[$b] = 0;
        $bookieCounts[$b]++;
    }
    ?>
    <?php if (empty($bookieList)): ?>
        <div class="p-4 text-center">
            <p class="text-muted"><i class="fas fa-database me-1"></i>No multi-bookie data yet.</p>
            <p class="small text-muted">Run <code>python multi_bookie_scraper.py</code> to start collecting odds from Sportybet, SportPesa, and VunjabeiBet.</p>
        </div>
    <?php else: ?>
    <div style="padding:1rem;">
        <div class="stats-grid" style="margin-bottom:1rem;">
            <?php foreach ($bookieList as $b): ?>
            <div class="stat-card"><div class="stat-icon" style="background:#D1FAE5;color:#059669;"><i class="fas fa-check"></i></div>
                <div class="stat-number"><?= $bookieCounts[$b] ?? 0 ?></div><div class="stat-label"><?= htmlspecialchars($b) ?></div></div>
            <?php endforeach; ?>
        </div>

        <?php if ($latestScrape): ?>
    <?php
    $matchGroups = [];
    foreach ($movements as $m) {
        $key = strtolower(trim($m['home'])) . '|' . strtolower(trim($m['away']));
        if (!isset($matchGroups[$key])) $matchGroups[$key] = ['home' => $m['home'], 'away' => $m['away'], 'bookies' => []];
        if (!in_array($m['bookie'], $matchGroups[$key]['bookies'])) $matchGroups[$key]['bookies'][] = $m['bookie'];
    }
    $candidates = array_values(array_filter($matchGroups, fn($g) => count($g['bookies']) >= 2));
    usort($candidates, fn($a, $b) => count($b['bookies']) <=> count($a['bookies']));
    ?>
    <div style="display:flex;align-items:center;gap:10px;margin:0 0 0.5rem 0;flex-wrap:wrap;">
        <h6 style="font-weight:600;margin:0;">Matches seen by ≥2 bookies (verified candidates)</h6>
        <input type="search" id="verifiedSearch" placeholder="Search match..." style="padding:4px 10px;border:1px solid var(--border-color);border-radius:6px;background:var(--bg);color:var(--text);font-size:0.78rem;max-width:220px;">
        <span style="font-size:0.75rem;color:var(--text-muted);margin-left:auto;"><span id="verifiedTotal"><?= count($candidates) ?></span> matches</span>
    </div>
    <?php
    $calcOutcome = function($home, $away, $fields, $thresholds = []) use ($movements) {
        $rows = array_values(array_filter($movements, function($m) use ($home, $away) {
            return strcasecmp(trim($m['home']), trim($home)) === 0 && strcasecmp(trim($m['away']), trim($away)) === 0;
        }));
        $results = [];
        foreach ($fields as $key => $field) {
            $t = $thresholds[$key] ?? 0.5;
            $downCount = 0; $downSum = 0; $upCount = 0; $upSum = 0;
            foreach ($rows as $r) {
                $delta = (float)($r[$field] ?? 0);
                if ($delta < -$t) { $downCount++; $downSum += $delta; }
                elseif ($delta > $t) { $upCount++; $upSum += $delta; }
            }
            $total = $downCount + $upCount;
            if ($total === 0) { $results[$key] = null; continue; }
            $agree = $downCount > $upCount ? 'down' : 'up';
            $agreeCount = $agree === 'down' ? $downCount : $upCount;
            $agreeSum = $agree === 'down' ? $downSum : $upSum;
            $rawAvg = $agreeCount > 0 ? $agreeSum / $agreeCount : 0;
            $agreePct = $agreeCount / max($total, 1);
            $deltaStrength = min(abs($rawAvg) * 2, 5);
            $strength = round($agreePct * 50 + $deltaStrength * 10);
            $results[$key] = ['agreement' => $agree, 'count' => $agreeCount, 'total' => $total, 'avg_delta' => abs($rawAvg), 'strength' => $strength];
        }
        return $results;
    };
    $renderVerCell = function($con) {
        if ($con === null) return '<span class="text-muted">—</span>';
        $arrow = $con['agreement'] === 'down' ? '↑' : '↓';
        $color = $con['agreement'] === 'down' ? '#10B981' : '#EF4444';
        $pct = $con['strength'];
        return '<span style="color:' . $color . ';font-weight:700;font-size:0.95rem;">' . $arrow . ' ' . $pct . '%</span> <span class="text-muted small">(' . $con['count'] . '/' . $con['total'] . ')</span>';
    };
    ?>
    <?php if (!empty($candidates)): ?>
    <div class="load-more-wrap" data-table="verifiedTable">
    <div class="table-responsive">
        <table id="verifiedTable" class="table" style="font-size:0.88rem;" data-page-size="50" data-no-auto>
            <thead><tr>
                <th>Match</th><th style="width:60px;">Bks</th><th style="width:70px;">1</th><th style="width:70px;">X</th><th style="width:70px;">2</th>
                <th style="width:80px;">Ov. 2.5</th><th style="width:80px;">Und. 2.5</th><th style="width:80px;">BTTS-Yes</th><th style="width:80px;">BTTS-No</th>
            </tr></thead>
            <tbody>
            <?php foreach ($candidates as $cand):
                $con = $calcOutcome($cand['home'], $cand['away'], [
                    '1' => 'odds_1_delta', 'X' => 'odds_x_delta', '2' => 'odds_2_delta',
                    'Ov. 2.5' => 'ou25_over_delta', 'Und. 2.5' => 'ou25_under_delta',
                    'BTTS-Yes' => 'btts_yes_delta', 'BTTS-No' => 'btts_no_delta',
                ], [
                    '1' => 0.5, 'X' => 0.5, '2' => 0.5,
                    'Ov. 2.5' => 0.08, 'Und. 2.5' => 0.08,
                    'BTTS-Yes' => 0.05, 'BTTS-No' => 0.05,
                ]);
            ?>
            <tr>
                <td style="white-space:nowrap;"><?= htmlspecialchars($cand['home']) ?> <span class="text-muted">vs</span> <?= htmlspecialchars($cand['away']) ?></td>
                <td><span class="badge" style="background:var(--primary);"><?= count($cand['bookies']) ?></span></td>
                <td><?= $renderVerCell($con['1']) ?></td>
                <td><?= $renderVerCell($con['X']) ?></td>
                <td><?= $renderVerCell($con['2']) ?></td>
                <td><?= $renderVerCell($con['Ov. 2.5']) ?></td>
                <td><?= $renderVerCell($con['Und. 2.5']) ?></td>
                <td><?= $renderVerCell($con['BTTS-Yes']) ?></td>
                <td><?= $renderVerCell($con['BTTS-No']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="text-align:center;padding:8px 0;display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
        <button class="load-more-btn" style="background:var(--bg-soft);border:1px solid var(--border-color);color:var(--primary);padding:6px 20px;border-radius:6px;cursor:pointer;font-size:0.82rem;font-weight:600;">
            Load More (<span class="count">0</span> remaining)
        </button>
        <button id="loadAllVerified" style="background:transparent;border:1px solid var(--border-color);color:var(--text-muted);padding:6px 14px;border-radius:6px;cursor:pointer;font-size:0.78rem;display:none;">Show All <?= count($candidates) ?> Matches</button>
    </div>
    </div>
    <?php else: ?>
        <p class="text-muted small">No matches appear on multiple bookies yet. Bookies may use different match names.</p>
    <?php endif; ?>

    <details style="margin-top:1rem;">
        <summary style="cursor:pointer;font-weight:600;color:var(--primary);"><i class="fas fa-history me-1"></i>Total Movements in Sheet: <?= count($movements) ?></summary>
    </details>
    <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; // end if ($sub === 'verified') ?>


<?php if (!hasAdminPermission('picks')): ?>
<div class="alert alert-danger text-center py-4"><i class="fas fa-lock me-2"></i>You do not have permission to manage featured content.</div>
<?php else: ?>


<?php if ($isSuperAdmin): ?>
<?php
$articleMsg = '';
$articleError = '';
$editingArticle = null;
$editMode = isset($_GET['edit_article']) ? (int)$_GET['edit_article'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_article' || $action === 'update_article') {
        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $content = $_POST['content'] ?? '';
        $excerpt = trim($_POST['excerpt'] ?? '');
        $metaDesc = trim($_POST['meta_description'] ?? '');
        $author = trim($_POST['author'] ?? 'PREDIXA');
        $published = isset($_POST['published']) ? 1 : 0;
        if (!$title || !$slug || !$content) {
            $articleError = 'Title, slug, and content are required.';
        } else {
            if ($action === 'create_article') {
                $db->prepare("INSERT INTO betting_articles (title, slug, content, excerpt, meta_description, author, published) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$title, $slug, $content, $excerpt, $metaDesc, $author, $published]);
                $articleMsg = 'Article created.';
            } else {
                $aid = (int)$_POST['article_id'];
                $db->prepare("UPDATE betting_articles SET title=?, slug=?, content=?, excerpt=?, meta_description=?, author=?, published=? WHERE id=?")->execute([$title, $slug, $content, $excerpt, $metaDesc, $author, $published, $aid]);
                $articleMsg = 'Article updated.';
            }
        }
    } elseif ($action === 'toggle_publish') {
        $aid = (int)$_POST['article_id'];
        $published = (int)$_POST['published'];
        $db->prepare("UPDATE betting_articles SET published=? WHERE id=?")->execute([$published, $aid]);
        $articleMsg = $published ? 'Article published.' : 'Article unpublished.';
    } elseif ($action === 'delete_article') {
        $aid = (int)$_POST['article_id'];
        $db->prepare("DELETE FROM betting_articles WHERE id=?")->execute([$aid]);
        $articleMsg = 'Article deleted.';
    }
}
if ($editMode) {
    $stmt = $db->prepare("SELECT * FROM betting_articles WHERE id=?");
    $stmt->execute([$editMode]);
    $editingArticle = $stmt->fetch();
}
$articles = $db->query("SELECT * FROM betting_articles ORDER BY created_at DESC")->fetchAll();
?>
<div class="card mb-3">
    <div class="card-header" data-bs-toggle="collapse" data-bs-target="#bsAccordion" role="button" style="cursor:pointer;">
        <h2 class="card-title"><i class="fas fa-book-open me-1"></i>Betting School</h2>
        <span class="badge" style="background: #F59E0B; color: white;"><?= count($articles) ?> Articles</span>
    </div>
    <div class="collapse show" id="bsAccordion">
        <div class="p-0">
            <?php if ($articleMsg): ?><div class="alert" style="background: #ECFDF5; border-left: 4px solid #10B981; margin:1rem;font-size:0.82rem;"><?= htmlspecialchars($articleMsg) ?></div><?php endif;
            if ($articleError): ?><div class="alert" style="background: #FEF2F2; border-left: 4px solid #EF4444; margin:1rem;font-size:0.82rem;"><?= htmlspecialchars($articleError) ?></div><?php endif; ?>
            <div class="accordion" id="bsAccordionInner">
                <div class="accordion-item" style="border:none;">
                    <h2 class="accordion-header" style="border-bottom:1px solid var(--border-color);">
                        <button class="accordion-button <?= $editingArticle ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#bsNewArticle" style="font-size:0.85rem;font-weight:600;background:transparent;">
                            <?= $editingArticle ? '<i class="fas fa-edit me-2"></i>Edit Article' : '<i class="fas fa-plus me-2"></i>New Article' ?>
                        </button>
                    </h2>
                    <div id="bsNewArticle" class="accordion-collapse collapse <?= $editingArticle ? 'show' : '' ?>" data-bs-parent="#bsAccordionInner">
                        <div class="accordion-body p-3">
                            <?php if ($editingArticle): ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_article">
                                <input type="hidden" name="article_id" value="<?= $editingArticle['id'] ?>">
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6"><label style="font-size:0.75rem;font-weight:600;">Title</label><input type="text" name="title" class="form-control form-control-sm" value="<?= htmlspecialchars($editingArticle['title']) ?>" required></div>
                                    <div class="col-md-3"><label style="font-size:0.75rem;font-weight:600;">Slug</label><input type="text" name="slug" class="form-control form-control-sm" value="<?= htmlspecialchars($editingArticle['slug']) ?>" required></div>
                                    <div class="col-md-3"><label style="font-size:0.75rem;font-weight:600;">Author</label><input type="text" name="author" class="form-control form-control-sm" value="<?= htmlspecialchars($editingArticle['author'] ?? 'PREDIXA') ?>"></div>
                                </div>
                                <div class="mb-2"><label style="font-size:0.75rem;font-weight:600;">Excerpt</label><input type="text" name="excerpt" class="form-control form-control-sm" value="<?= htmlspecialchars($editingArticle['excerpt'] ?? '') ?>"></div>
                                <div class="mb-2"><label style="font-size:0.75rem;font-weight:600;">Meta Description</label><input type="text" name="meta_description" class="form-control form-control-sm" value="<?= htmlspecialchars($editingArticle['meta_description'] ?? '') ?>"></div>
                                <div class="mb-2">
                                    <label style="font-size:0.75rem;font-weight:600;">Content (HTML)</label>
                                    <div class="mb-2 p-2" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;font-size:0.75rem;">
                                        <strong style="color:#334155;">Internal Links — click to copy:</strong>
                                        <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;dropping-odds\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">dropping-odds</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;betting-school\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">betting-school</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;tipster\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">tipster</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;tipster/leaderboard\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">tipster/leaderboard</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;terms\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">terms</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;dashboard\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">dashboard</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;signup\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">signup</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;login\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">login</span>
                                        </div>
                                        <div style="margin-top:4px;color:#64748B;">Replace <code>text</code> with anchor, e.g. <code>&lt;a href="pikka"&gt;betting codes&lt;/a&gt;</code></div>
                                    </div>
                                    <textarea name="content" class="form-control" rows="8" style="font-family:monospace;font-size:0.82rem;"><?= htmlspecialchars($editingArticle['content']) ?></textarea>
                                </div>
                                <div class="d-flex gap-2 align-items-center">
                                    <label><input type="checkbox" name="published" value="1" <?= $editingArticle['published'] ? 'checked' : '' ?>> Published</label>
                                    <button type="submit" class="btn btn-approve btn-sm">Update Article</button>
                                    <a href="?tab=scrape_analyze&sub=featured" class="btn btn-sm" style="border:1px solid var(--border-color);">Cancel</a>
                                </div>
                            </form>
                            <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="create_article">
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6"><label style="font-size:0.75rem;font-weight:600;">Title</label><input type="text" name="title" class="form-control form-control-sm" placeholder="e.g. Understanding Double Chance Betting" required></div>
                                    <div class="col-md-3"><label style="font-size:0.75rem;font-weight:600;">Slug (URL)</label><input type="text" name="slug" class="form-control form-control-sm" placeholder="e.g. double-chance-betting-guide" required></div>
                                    <div class="col-md-3"><label style="font-size:0.75rem;font-weight:600;">Author</label><input type="text" name="author" class="form-control form-control-sm" value="PREDIXA"></div>
                                </div>
                                <div class="mb-2"><label style="font-size:0.75rem;font-weight:600;">Excerpt</label><input type="text" name="excerpt" class="form-control form-control-sm" placeholder="Brief summary shown on listing page"></div>
                                <div class="mb-2"><label style="font-size:0.75rem;font-weight:600;">Meta Description (SEO)</label><input type="text" name="meta_description" class="form-control form-control-sm" placeholder="Meta description for search engines"></div>
                                <div class="mb-2">
                                    <label style="font-size:0.75rem;font-weight:600;">Content (HTML)</label>
                                    <div class="mb-2 p-2" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;font-size:0.75rem;">
                                        <strong style="color:#334155;">Internal Links — click to copy:</strong>
                                        <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;dropping-odds\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">dropping-odds</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;betting-school\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">betting-school</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;tipster\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">tipster</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;tipster/leaderboard\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">tipster/leaderboard</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;terms\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">terms</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;dashboard\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">dashboard</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;signup\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">signup</span>
                                            <span onclick="navigator.clipboard.writeText('<a href=\&quot;login\&quot;>text</a>')" style="cursor:pointer;background:#EDE9FE;color:#6D28D9;padding:2px 8px;border-radius:4px;white-space:nowrap;">login</span>
                                        </div>
                                        <div style="margin-top:4px;color:#64748B;">Replace <code>text</code> with anchor, e.g. <code>&lt;a href="pikka"&gt;betting codes&lt;/a&gt;</code></div>
                                    </div>
                                    <textarea name="content" class="form-control" rows="8" style="font-family:monospace;font-size:0.82rem;" placeholder="Write the full article here with HTML tags..."></textarea>
                                </div>
                                <div class="d-flex gap-2 align-items-center">
                                    <label><input type="checkbox" name="published" value="1"> Publish immediately</label>
                                    <button type="submit" class="btn btn-approve btn-sm">Create Article</button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="accordion-item" style="border:none;">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#bsAllArticles" style="font-size:0.85rem;font-weight:600;background:transparent;">
                            <i class="fas fa-list me-2"></i>All Articles (<?= count($articles) ?>)
                        </button>
                    </h2>
                    <div id="bsAllArticles" class="accordion-collapse collapse" data-bs-parent="#bsAccordionInner">
                        <div class="accordion-body p-0">
                            <div class="table-responsive">
                                <table class="table" style="margin-bottom:0;">
                                    <thead><tr><th>ID</th><th>Title</th><th>Slug</th><th>Author</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                                    <tbody>
                                    <?php if (empty($articles)): ?>
                                        <tr><td colspan="7" class="text-center text-muted py-3">No articles yet.</td></tr>
                                    <?php else: foreach ($articles as $a): ?>
                                        <tr>
                                            <td><?= $a['id'] ?></td>
                                            <td><a href="betting-school/<?= htmlspecialchars($a['slug']) ?>" target="_blank" style="color:var(--primary);"><?= htmlspecialchars($a['title']) ?></a></td>
                                            <td><code>/betting-school/<?= htmlspecialchars($a['slug']) ?></code></td>
                                            <td><?= htmlspecialchars($a['author'] ?? 'PREDIXA') ?></td>
                                            <td><?= $a['published'] ? '<span class="badge" style="background:#10B981;">Published</span>' : '<span class="badge" style="background:#9CA3AF;">Draft</span>' ?></td>
                                            <td><?= date('j M Y', strtotime($a['created_at'])) ?></td>
                                            <td>
                                                <a href="?tab=scrape_analyze&sub=featured&edit_article=<?= $a['id'] ?>" class="btn btn-sm" style="background:var(--bg-soft);border:1px solid var(--border-color);padding:2px 10px;font-size:0.75rem;">Edit</a>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="toggle_publish">
                                                    <input type="hidden" name="article_id" value="<?= $a['id'] ?>">
                                                    <input type="hidden" name="published" value="<?= $a['published'] ? 0 : 1 ?>">
                                                    <button type="submit" class="btn btn-sm" style="background:var(--bg-soft);border:1px solid var(--border-color);padding:2px 10px;font-size:0.75rem;"><?= $a['published'] ? 'Unpublish' : 'Publish' ?></button>
                                                </form>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this article?')">
                                                    <input type="hidden" name="action" value="delete_article">
                                                    <input type="hidden" name="article_id" value="<?= $a['id'] ?>">
                                                    <button type="submit" class="btn btn-sm" style="background:#FEF2F2;color:#EF4444;border:1px solid #FCA5A5;padding:2px 10px;font-size:0.75rem;">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; // end super admin for Betting School ?>

<div class="card mt-3">
    <div class="card-header"><h2 class="card-title"><i class="fas fa-ticket me-1"></i>All Betting Codes <?php if (!empty($activeCodes)): ?><span class="badge bg-success ms-1" style="font-size: 0.7rem;"><?= count($activeCodes) ?> Active Today</span><?php endif; ?></h2></div>
    <?php
    $allCodesAdmin = getAllCodesAdmin();
    $activeCodes = array_filter($allCodesAdmin, fn($c) => $c['status'] === 'active' && date('Y-m-d', strtotime($c['created_at'])) === date('Y-m-d'));
    $soldCodes = array_filter($allCodesAdmin, fn($c) => $c['status'] === 'sold');
    ?>
    <div class="mb-2"><input type="text" class="table-search form-control form-control-sm" data-table="allCodesTable" placeholder="Search by name, phone or code..." style="max-width:320px;"></div>
    <div class="table-responsive">
        <table class="table" id="allCodesTable" data-page-size="10">
            <thead><tr><th>ID</th><th>Phone</th><th>Code</th><th>Description</th><th>Matches</th><th>Status</th><th>Sales</th><th>Created</th></tr></thead>
            <tbody>
                <?php if (empty($allCodesAdmin)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4"><i class="fas fa-ticket me-2"></i>No betting codes yet</td></tr>
                <?php else: foreach ($allCodesAdmin as $c): ?>
                <tr>
                    <td>#<?= $c['id'] ?></td>
                    <td><code style="color: var(--primary);"><?= htmlspecialchars($c['display_name'] ?: $c['phone']) ?></code><br><small class="text-muted"><?= htmlspecialchars($c['phone']) ?></small></td>
                    <td><code><?= htmlspecialchars($c['code']) ?></code></td>
                    <td><?= htmlspecialchars($c['description']) ?></td>
                    <td><?= htmlspecialchars($c['matches']) ?></td>
                    <td><span class="badge <?= $c['status'] === 'active' ? 'badge-parlay' : 'badge-pending' ?>"><?= $c['status'] ?></span></td>
                    <td><strong><?= (int)($c['sales_count'] ?? 0) ?></strong></td>
                    <td class="text-muted"><?= date('M d, H:i', strtotime($c['created_at'])) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="text-center py-2 load-more-wrap" data-table="allCodesTable" style="border-top:1px solid var(--border-color);">
        <button type="button" class="btn btn-sm load-more-btn" style="background:var(--bg-soft);color:var(--primary);border:1px solid var(--border-color);padding:4px 20px;border-radius:6px;">
            Show <span class="count">0</span> more
        </button>
    </div>
</div>

<?php endif; ?>
<?php endif; ?>

</main>

<!-- Presentation Mode Modal -->
<div id="presentationModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this){this.style.display='none';this.classList.remove('show');}">
    <div style="background:#fff;border-radius:16px;max-width:500px;width:90%;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #E5E7EB;">
            <h3 style="margin:0;font-size:1.1rem;font-weight:700;"><i class="fas fa-eye-slash" style="color:var(--accent);margin-right:8px;"></i>Presentation Mode</h3>
            <button type="button" onclick="document.getElementById('presentationModal').style.display='none';document.getElementById('presentationModal').classList.remove('show');" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#9CA3AF;line-height:1;">&times;</button>
        </div>
        <form method="post" style="padding:20px;">
            <input type="hidden" name="save_presentation_mode" value="1">
            <p style="color:#6B7280;font-size:0.9rem;margin:0 0 16px;">Select sections to hide for <strong>demo users</strong>. Demo users will not see these sections anywhere on the site. Normal users are unaffected.</p>
            <?php
            $hs = getHiddenSections();
            error_log('PresentationModal: $hs = ' . json_encode($hs));
            $allSections = [
                'aviator' => 'Aviator (prediction tool, game, nav links)',
                'betting_codes' => 'Betting Codes (marketplace, dashboard tab, nav links)',
            ];
            ?>
            <div style="display:grid;gap:10px;">
                <?php foreach ($allSections as $key => $label): ?>
                <label style="display:flex;align-items:center;gap:10px;padding:8px 12px;border:1px solid #E5E7EB;border-radius:8px;cursor:pointer;font-size:0.9rem;transition:background 0.15s;" onmouseover="this.style.background='#F9FAFB'" onmouseout="this.style.background='transparent'">
                    <input type="checkbox" name="hidden_sections[]" value="<?= htmlspecialchars($key) ?>" <?= in_array($key, $hs) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--accent);">
                    <span><?= htmlspecialchars($label) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($hs)): ?>
            <div style="margin-top:16px;padding:10px 14px;background:#FFF7ED;border:1px solid #FED7AA;border-radius:8px;font-size:0.85rem;color:#9A3412;">
                <strong>Currently hidden for demo users:</strong> <?= implode(', ', array_map('htmlspecialchars', $hs)) ?>
            </div>
            <?php else: ?>
            <div style="margin-top:16px;padding:10px 14px;background:#F0F4FF;border:1px solid #BFDBFE;border-radius:8px;font-size:0.85rem;color:#1E40AF;">
                <strong>Nothing hidden.</strong> No sections are currently hidden for demo users.
            </div>
            <?php endif; ?>
            <div style="margin-top:20px;display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('presentationModal').style.display='none';document.getElementById('presentationModal').classList.remove('show');" style="padding:8px 20px;border:1px solid #D1D5DB;border-radius:8px;background:#fff;cursor:pointer;font-weight:600;font-size:0.9rem;">Cancel</button>
                <button type="submit" style="padding:8px 20px;border:none;border-radius:8px;background:linear-gradient(135deg,#F59E0B,#D97706);color:#fff;cursor:pointer;font-weight:700;font-size:0.9rem;">Save</button>
            </div>
        </form>
    </div>
</div>

<footer class="footer">
    <div class="footer-content">
        <p class="footer-copy">© <?= date('Y') ?> Predixa Admin Panel. Secure Access Only.</p>
    </div>
</footer>

<script>
function filterTable(inputId, rowsSelector, dataAttr) {
    document.getElementById(inputId)?.addEventListener('input', function(e) {
        const term = e.target.value.toLowerCase().trim();
        document.querySelectorAll(rowsSelector).forEach(row => {
            row.style.display = (row.getAttribute(dataAttr) || '').includes(term) ? '' : 'none';
        });
    });
}
filterTable('searchRef', '#paymentsTable tbody tr', 'data-ref');
filterTable('searchHistRef', '#historyTable tbody tr', 'data-histref');
document.querySelector('.load-more-hist')?.addEventListener('click', function() {
    var cur = parseInt(this.dataset.current);
    var step = parseInt(this.dataset.step);
    var total = parseInt(this.dataset.total);
    var next = Math.min(cur + step, total);
    document.querySelectorAll('.hist-row').forEach(function(el) {
        var idx = parseInt(el.dataset.histidx);
        if (idx < next) el.style.display = '';
    });
    this.dataset.current = next;
    if (next >= total) this.style.display = 'none';
    else this.textContent = 'Show ' + (total - next) + ' more';
});


</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Pagination: limit rows per table to page-size, read tableId from parent wrap
function getTableId(btn) {
    var wrap = btn.closest('.load-more-wrap');
    return wrap ? wrap.dataset.table : null;
}

function applyPagination(tableId) {
    var table = document.getElementById(tableId);
    if (!table || !table.dataset.pageSize) return;
    var pageSize = parseInt(table.dataset.pageSize);
    var maxVisible = parseInt(table.dataset.maxVisible || pageSize);
    var rows = table.querySelectorAll('tbody tr');
    rows.forEach(function(row, i) {
        row.style.display = i < maxVisible ? '' : 'none';
    });
    var wrap = document.querySelector('.load-more-wrap[data-table="' + tableId + '"]');
    if (wrap) {
        var btn = wrap.querySelector('.load-more-btn');
        var remaining = rows.length - maxVisible;
        var countEl = btn ? btn.querySelector('.count') : null;
        if (countEl) countEl.textContent = remaining > 0 ? remaining : 0;
        wrap.style.display = remaining > 0 ? '' : 'none';
    }
}

// Initialize all paginated tables (skip verified — custom handler below)
document.querySelectorAll('table[data-page-size]:not([data-no-auto])').forEach(function(t) {
    t.dataset.maxVisible = t.dataset.pageSize;
    applyPagination(t.id);
});

// Load More buttons — read table ID from parent wrap (skip verified — custom handler)
document.querySelectorAll('.load-more-btn').forEach(function(btn) {
    var wrap = btn.closest('.load-more-wrap');
    if (wrap && wrap.dataset.table === 'verifiedTable') return;
    btn.addEventListener('click', function() {
        var tableId = getTableId(this);
        var table = tableId ? document.getElementById(tableId) : null;
        if (!table) return;
        var pageSize = parseInt(table.dataset.pageSize || 10);
        var currentMax = parseInt(table.dataset.maxVisible || pageSize);
        table.dataset.maxVisible = currentMax + pageSize;
        applyPagination(tableId);
    });
});

// Search: within paginated rows, or all matching rows if searching
document.querySelectorAll('.table-search').forEach(function(input) {
    var handler = function() {
        var filter = this.value.toLowerCase().trim();
        var table = document.getElementById(this.dataset.table);
        if (!table) return;
        var maxVisible = parseInt(table.dataset.maxVisible || 999);
        var rows = table.querySelectorAll('tbody tr');
        rows.forEach(function(row, i) {
            if (filter === '') {
                row.style.display = i < maxVisible ? '' : 'none';
            } else {
                row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
            }
        });
    };
    input.addEventListener('keyup', handler);
    input.addEventListener('search', handler);
});

// Verified: search by match name column only
var verifiedSearch = document.getElementById('verifiedSearch');
var verifiedTable = document.getElementById('verifiedTable');
if (verifiedSearch && verifiedTable) {
    var verifiedTotalSpan = document.getElementById('verifiedTotal');
    var loadAllBtn = document.getElementById('loadAllVerified');
    var totalRows = verifiedTable.querySelectorAll('tbody tr').length;
    // Initial pagination: show first 50 rows
    var pageSize = parseInt(verifiedTable.dataset.pageSize || 50);
    verifiedTable.dataset.maxVisible = pageSize;
    verifiedTable.querySelectorAll('tbody tr').forEach(function(row, i) {
        row.style.display = i < pageSize ? '' : 'none';
    });
    var remaining = totalRows - pageSize;
    var countEl = document.querySelector('.load-more-wrap[data-table="verifiedTable"] .count');
    if (countEl) countEl.textContent = remaining > 0 ? remaining : 0;
    var loadMoreSection = document.querySelector('.load-more-wrap[data-table="verifiedTable"]');
    if (loadMoreSection) {
        var loadMoreBtn = loadMoreSection.querySelector('.load-more-btn');
        if (loadMoreBtn) loadMoreBtn.style.display = remaining > 0 ? '' : 'none';
    }
    if (loadAllBtn) loadAllBtn.style.display = totalRows > pageSize ? '' : 'none';
    verifiedSearch.addEventListener('input', function() {
        var q = this.value.toLowerCase().trim();
        var rows = verifiedTable.querySelectorAll('tbody tr');
        var matchCount = 0;
        rows.forEach(function(row) {
            var cell = row.querySelector('td:first-child');
            var text = cell ? cell.textContent.toLowerCase() : '';
            var show = q === '' || text.includes(q);
            row.style.display = show ? '' : 'none';
            if (show) matchCount++;
        });
        if (verifiedTotalSpan) verifiedTotalSpan.textContent = matchCount + '/' + totalRows;
        // When searching, hide pagination controls; always show the table
        if (loadMoreSection) {
            var btn = loadMoreSection.querySelector('.load-more-btn');
            if (btn) btn.style.display = (q === '' && remaining > 0) ? '' : 'none';
        }
    });
    // Override load more for verified to preserve search state
    var verifiedLoadBtn = document.querySelector('.load-more-wrap[data-table="verifiedTable"] .load-more-btn');
    if (verifiedLoadBtn) {
        verifiedLoadBtn.addEventListener('click', function() {
            var maxVisible = parseInt(verifiedTable.dataset.maxVisible || 50);
            verifiedTable.dataset.maxVisible = maxVisible + 50;
            var rows = verifiedTable.querySelectorAll('tbody tr');
            var visible = 0;
            rows.forEach(function(row) {
                if (row.style.display !== 'none') visible++;
                if (row.style.display === '' && visible > maxVisible) row.style.display = 'none';
            });
            var remaining = rows.length - (maxVisible + 50);
            var countEl = this.querySelector('.count');
            if (countEl) countEl.textContent = remaining > 0 ? remaining : 0;
            if (remaining <= 0) this.closest('.load-more-wrap').querySelector('.load-more-btn').style.display = 'none';
        });
    }
    if (loadAllBtn) {
        loadAllBtn.addEventListener('click', function() {
            verifiedTable.querySelectorAll('tbody tr').forEach(function(row) {
                row.style.display = '';
            });
            this.style.display = 'none';
            var wrap = document.querySelector('.load-more-wrap[data-table="verifiedTable"]');
            if (wrap) {
                var btn = wrap.querySelector('.load-more-btn');
                if (btn) btn.style.display = 'none';
            }
        });
    }
}

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
document.querySelectorAll('#headerMenu a').forEach(function(link) {
    link.addEventListener('click', function() {
        var menu = document.getElementById('headerMenu');
        if (menu) menu.classList.remove('active');
    });
});
</script>

<!-- Credit History Modal -->
<div class="modal fade" id="creditHistoryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-history me-1"></i>Credit History</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="creditHistoryBody">
        <div class="text-center py-4"><i class="fas fa-spinner fa-spin me-2"></i>Loading...</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-sm" style="background:var(--bg-soft);color:var(--text-muted);border:1px solid var(--border-color);" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<script>
function showCreditHistory(userId, userName) {
    document.querySelector('#creditHistoryModal .modal-title').textContent = '\u{1F4CB} Credit History \u2014 ' + userName;
    var body = document.getElementById('creditHistoryBody');
    body.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin me-2"></i>Loading...</div>';
    var modal = new bootstrap.Modal(document.getElementById('creditHistoryModal'));
    modal.show();
    fetch('?action=get_credit_history&user_id=' + userId)
        .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(function(html) { body.innerHTML = html; })
        .catch(function(err) { body.innerHTML = '<div class="text-center text-danger py-4">Failed to load history: ' + err.message + '</div>'; });
}
</script>
</body>
</html>
