<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/GoogleSheetsAPI.php';
require_once __DIR__ . '/TeamForm.php';
require_once __DIR__ . '/RiskTier.php';

class OddsAnalyzer {
    private $sheets;
    private $form;
    private $lookbackHours = 4;
    public $guardLog = [];

    private $config = [
        'win_pick_threshold' => -3.0,
        'dc_pick_threshold' => -3.0,
        'opp_delta_min' => 4.0,
        'draw_delta_warn' => 5.0,
        'form_rating_min' => 5.5,
        'position_max' => 8,
        'gd_min' => -3,
        'sharp_money_threshold' => -8.0,
        'sharp_money_boost' => -2.0,
        'over_15_min_odds' => 1.30,
        'over_15_max_odds' => 1.90,
        'drift_guard_enabled' => true,
        'drift_guard_fav_min' => 0.5,
        'drift_guard_opp_min' => -3.5,
        'enable_pattern_detection' => true,
        'enable_stable_favorite_boost' => true,
        'enable_late_drop_urgency' => true,
        'enable_divergence_strength' => true,
        'enable_vertical_spike_avoid' => true,
        'enable_choppy_volatility_filter' => true,
        'enable_opening_trap_detection' => true,
        'enable_contradictory_signal_filter' => true,
        'avoid_vertical_spike_threshold' => 15.0,
        'avoid_choppy_volatility_max' => 8.0,
        'avoid_opening_trap_min' => 8.0,
        'avoid_contradictory_draw_fall' => -2.0,
        'divergence_home_min' => -10.0,
        'divergence_away_min' => 15.0,
        'ucl_overrides' => [
            'win_pick_threshold' => -4.0,
            'dc_pick_threshold' => -3.0,
        ],
    ];

    private $coreLeagues = [
        'England Premier League' => 'Premier League',
        'Spain LaLiga' => 'La Liga',
        'Germany Bundesliga' => 'Bundesliga',
        'Italy Serie A' => 'Serie A',
        'International Clubs UEFA Champions League' => 'Champions League',
        'International Clubs UEFA Europa League' => 'Europa League',
    ];

    private $secondaryLeagues = [
        'France Ligue 1' => 'Ligue 1',
        'Netherlands Eredivisie' => 'Eredivisie',
        'Portugal Liga Portugal' => 'Liga Portugal',
    ];

    public function __construct() {
        $this->sheets = new GoogleSheetsAPI();
        $this->form = new TeamForm();
    }

    public function getTips() {
        try {
            $rows = $this->sheets->getOddsDrops();
            if (empty($rows)) return [];

            $windowStart = date('Y-m-d H:i:s', strtotime("-{$this->lookbackHours} hours"));
            $validRows = [];
            foreach ($rows as $r) {
                $ts = $r['Detected_At'] ?? '';
                if ($ts && $ts >= $windowStart) $validRows[] = $r;
            }
            if (empty($validRows)) return [];

            $uniqueMatches = [];
            foreach ($validRows as $row) {
                $home = TeamForm::normalizeTeamName($row['Home_Team']);
                $away = TeamForm::normalizeTeamName($row['Away_Team']);
                $league = $row['League'];
                $matchTime = $row['Match_Time'];
                $sig = "{$home}|{$away}|{$league}|{$matchTime}";
                if (!isset($uniqueMatches[$sig]) || ($row['Detected_At'] ?? '') > ($uniqueMatches[$sig]['Detected_At'] ?? '')) {
                    $uniqueMatches[$sig] = $row;
                }
            }

            $activeLeagues = array_unique(array_column($uniqueMatches, 'League'));
            $standingsData = [];
            foreach ($activeLeagues as $l) {
                if ($l) $standingsData[$l] = $this->sheets->getStandings($l);
            }

            $results = [];
            foreach ($uniqueMatches as $row) {
                $league = $row['League'];
                $leagueStandings = $standingsData[$league] ?? [];
                $res = $this->analyzeMatch($row, $leagueStandings, $league);
                if ($res) $results[] = $res;
            }

            usort($results, function ($a, $b) {
                return strcmp($b['detected_at'] ?? '', $a['detected_at'] ?? '');
            });
            return $results;
        } catch (Exception $e) {
            error_log("OddsAnalyzer::getTips error: " . $e->getMessage());
            return [];
        }
    }

    private function analyzeMatch($row, $standings, $league) {
        try {
            $config = $this->config;

            $home = $row['Home_Team'];
            $away = $row['Away_Team'];
            if (empty($row['Odds_1_Before']) || empty($row['Odds_2_Before']) || (float)$row['Odds_1_Before'] <= 0.1 || (float)$row['Odds_2_Before'] <= 0.1) return null;

            $o1Old = (float)$row['Odds_1_Before'];
            $o2Old = (float)$row['Odds_2_Before'];
            $o1Now = (float)$row['Odds_1_Now'];
            $oxNow = (float)$row['Odds_X_Now'];
            $o2Now = (float)$row['Odds_2_Now'];
            $oxOld = (float)$row['Odds_X_Before'];

            $pctChange = function ($new, $old) { return $old ? (($new - $old) / $old * 100) : 0; };
            $d1 = $pctChange($o1Now, $o1Old);
            $dx = $pctChange($oxNow, $oxOld);
            $d2 = $pctChange($o2Now, $o2Old);

            // Look up true opening odds for sharp money / opening trap detection
            $openingOdds = $this->sheets->getOpeningOdds($home, $away, $league);
            $o1Open = $openingOdds ? (float)$openingOdds['odds_1'] : null;
            $oxOpen = $openingOdds ? (float)$openingOdds['odds_x'] : null;
            $o2Open = $openingOdds ? (float)$openingOdds['odds_2'] : null;
            $d1Open = $o1Open ? $pctChange($o1Now, $o1Open) : null;
            $dxOpen = $oxOpen ? $pctChange($oxNow, $oxOpen) : null;
            $d2Open = $o2Open ? $pctChange($o2Now, $o2Open) : null;

            $isHomeFav = $o1Now < $o2Now;
            $favDelta = $isHomeFav ? $d1 : $d2;
            $oppDelta = $isHomeFav ? $d2 : $d1;
            $favTeam = $isHomeFav ? $home : $away;
            $oppTeam = $isHomeFav ? $away : $home;

            $isCup = TeamForm::isCupMatch($league);
            $isUcl = stripos($league, 'Champions League') !== false;
            $isEuropean = TeamForm::isEuropeanComp($league);
            $useStandings = !($isCup && !$isEuropean);
            $isDomesticCup = $isCup && !$isEuropean;

            $hoursToKickoff = null;
            $matchTimeStr = $row['Match_Time'] ?? '';
            if ($matchTimeStr && strpos($matchTimeStr, ':') !== false) {
                $parts = explode(':', $matchTimeStr);
                $matchHour = (int)$parts[0];
                $currentHour = (int)date('H');
                $hoursToKickoff = abs($matchHour - $currentHour);
            }

            if ($matchTimeStr && strpos($matchTimeStr, ':') !== false) {
                $parts = explode(':', $matchTimeStr);
                $matchHour = (int)$parts[0];
                $matchMin = (int)($parts[1] ?? 0);
                $matchTs = strtotime(date('Y-m-d') . ' ' . $matchTimeStr);
                $now = time();
                $hoursPast = ($now - $matchTs) / 3600;
                if ($hoursPast > 2) {
                    // Match time is >2h ago today — likely a stale pick from yesterday's source
                    $yesterdayTs = strtotime('-1 day ' . date('Y-m-d') . ' ' . $matchTimeStr);
                    $hoursPastYesterday = ($now - $yesterdayTs) / 3600;
                    if ($hoursPastYesterday > 2) return null;
                }
            }

            // Skip if match already has a result (already played)
            try {
                $db = getDB();
                if ($db) {
                    $playedCheck = $db->prepare("SELECT 1 FROM match_results WHERE home_team = ? AND away_team = ? AND match_date < CURDATE() LIMIT 1");
                    $playedCheck->execute([$home, $away]);
                    if ($playedCheck->fetchColumn()) return null;
                    $playedCheck->execute([$away, $home]);
                    if ($playedCheck->fetchColumn()) return null;
                }
            } catch (Exception $e) {}

            // Build history with true opening odds when available (3-point) or fallback to 2-point
            $o1History = $o1Open ? [$o1Open, $o1Old, $o1Now] : [$o1Old, $o1Now];
            $o2History = $o2Open ? [$o2Open, $o2Old, $o2Now] : [$o2Old, $o2Now];
            $xHistory = $oxOpen ? [$oxOpen, $oxOld, $oxNow] : [$oxOld, $oxNow];
            $patternResult = $this->detectOddsPattern($o1History, $o2History, $xHistory, $hoursToKickoff, $config);
            $patternMultiplier = $this->getDynamicThreshold(1.0, $patternResult, $hoursToKickoff, $config);

            if ($patternResult['action'] === 'AVOID') {
                return null;
            }

            $venueNotes = [];
            if ($patternResult['pattern_type'] === 'normal') {
                $venueNotes[] = '⚠️ Pattern: Normal movement - low confidence signal';
            }

            $homeForm = $this->form->getTeamRecentForm($home, null, true);
            $awayForm = $this->form->getTeamRecentForm($away, null, false);
            $favForm = $isHomeFav ? $homeForm : $awayForm;
            $oppForm = $isHomeFav ? $awayForm : $homeForm;

            $favStats = $useStandings ? ($standings[TeamForm::normalizeTeamName($favTeam)] ?? null) : null;
            $oppStats = $useStandings ? ($standings[TeamForm::normalizeTeamName($oppTeam)] ?? null) : null;

            if ($isEuropean && isset($config['ucl_overrides'])) {
                $winPickThreshold = $config['ucl_overrides']['win_pick_threshold'];
                $dcPickThreshold = $config['ucl_overrides']['dc_pick_threshold'];
            } else {
                $baseWin = $config['win_pick_threshold'];
                $baseDc = $config['dc_pick_threshold'];
                $winPickThreshold = $baseWin * $patternMultiplier;
                $dcPickThreshold = $baseDc * $patternMultiplier;
            }

            $oppDeltaMin = $config['opp_delta_min'];
            $drawDeltaWarn = $config['draw_delta_warn'];

            $h2hData = $this->form->getH2HData($home, $away);
            if ($h2hData['total_matches'] > 0) {
                $recent = array_slice($h2hData['recent_form'], -3);
                $venueNotes[] = '📊 H2H: [' . implode(', ', $recent) . '] avg ' . $h2hData['avg_total_goals'] . ' goals/match';
            }

            $effectiveWinThreshold = $winPickThreshold;

            $pickText = null;
            $pickType = null;
            $pickReason = '';

            // WIN 1UP — short-priced favorite with stable odds movement
            if ($pickType === null) {
                $favOdds = $isHomeFav ? $o1Now : $o2Now;
                if ($favOdds > 0 && $favOdds < 1.20 && abs($favDelta) <= 1 && abs($dx) <= 2 && !$isDomesticCup && ($favForm['form_rating'] ?? 0) >= 5.0) {
                    $pickText = $favTeam . ' Win';
                    $pickType = 'win';
                    $pickReason = 'Win 1UP — short-priced favorite with stable odds backing';
                    $venueNotes[] = '🏆 Win 1UP: short odds with stable market confidence';
                }
            }

            if ($useStandings && $favStats && $oppStats) {
                $combinedGf = ($favStats['f'] ?? 0) + ($oppStats['f'] ?? 0);
                $combinedGp = max(1, ($favStats['gp'] ?? 1) + ($oppStats['gp'] ?? 1));
                $combinedScoringRate = $combinedGf / $combinedGp;
                $isDrawRisk = $this->detectHighDrawRisk($homeForm, $awayForm, $dx, $combinedScoringRate);
                if ($isDrawRisk[0]) {
                    $dcPick = $isHomeFav ? '1X' : 'X2';
                    $pickText = $dcPick;
                    $pickType = 'dc';
                    $pickReason = 'High draw risk mitigation | ' . $isDrawRisk[1];
                    $venueNotes[] = '⚠️ High draw risk: ' . $isDrawRisk[1];
                }
            }

            if ($pickType === null && $favDelta <= $effectiveWinThreshold && $oppDelta >= $oppDeltaMin) {
                if (!($favForm['form_rating'] < 3.5 && abs($favDelta) < 15.0)) {
                    $favOdds = $isHomeFav ? $o1Now : $o2Now;
                    $reasons = ['Heavy market backing with opponent drift'];
                    if (abs($favDelta) < 5.0 || $favOdds < 1.18 || $favOdds > 1.60) {
                        $reasons[] = '⚠️ Odds movement/price too mild or extreme for straight win';
                        $pickText = $isHomeFav ? '1X' : 'X2';
                        $pickType = 'dc';
                        $pickReason = implode(' | ', $reasons) . ' | Converted to safer DC due to value filter';
                    } else {
                        if ($favForm['form_rating'] >= 7.0) $reasons[] = '✅ Strong recent form (' . $favForm['form_string'] . ')';
                        elseif ($favForm['form_rating'] < 4.0) $reasons[] = '⚠️ Weak recent form (' . $favForm['form_string'] . ') - higher risk';
                        $pickText = $favTeam . ' Win';
                        $pickType = 'win';
                        $pickReason = implode(' | ', $reasons);
                    }
                    if ($pickType === 'win' && $oxNow < 2.0) {
                        $pickText = $isHomeFav ? '1X' : 'X2';
                        $pickType = 'dc';
                        $pickReason = '⚠️ Draw odds below 2.0, converted to safer DC';
                    }

                }
            }

            if ($pickType === null && $dcPickThreshold <= $favDelta && $favDelta <= -1.0) {
                if ($dx >= 0 && $oppDelta >= 3.0) {
                    $dcPick = $isHomeFav ? '1X' : 'X2';
                    $reasons = ['Safety net activated'];
                    if ($isHomeFav) {
                        if ($homeForm['form_rating'] >= 6.5 && substr_count($homeForm['form_string'], 'W') >= 2) $reasons[] = '✅ Strong home form (' . $homeForm['form_string'] . ')';
                        elseif ($homeForm['form_rating'] < 4.5) $reasons[] = '🟡 Weak home form (' . $homeForm['form_string'] . ') - draw likely (1X covers this)';
                    } else {
                        if ($awayForm['form_rating'] >= 6.0) $reasons[] = '✅ Solid away form (' . $awayForm['form_string'] . ')';
                        elseif ($awayForm['form_rating'] < 4.0) { $pickText = null; $pickType = null; }
                    }
                    if ($pickType !== null) {
                        $reasons[] = $dx == 0 ? 'draw odds stable' : 'draw odds rising (+' . round($dx, 1) . '%)';
                        $pickText = $dcPick;
                        $pickType = 'dc';
                        $pickReason = implode(' | ', $reasons);
                    }
                }
            }

            if ($pickType === null) {
                $overResult = $this->evaluateOver15($favDelta, $dx, $oppDelta, $homeForm, $awayForm, $h2hData, $isDomesticCup, $isEuropean, $config, $isHomeFav ? $o1Now : $o2Now, $patternResult);
                if ($overResult !== null) {
                    $pickText = $overResult['pick'];
                    $pickType = $overResult['pick_type'];
                    $pickReason = $overResult['reason'];
                    $venueNotes = array_merge($venueNotes, $overResult['notes']);
                }
            }

            if ($pickType === null && $useStandings && $favStats) {
                $formOk = $favForm['form_rating'] >= $config['form_rating_min'] || ($favStats['pos'] ?? 99) <= 3;
                if ($formOk && ($favStats['pos'] ?? 99) <= $config['position_max'] && $favStats['gd'] >= $config['gd_min'] && $favDelta < -1.5) {
                    $pickText = $favTeam . ' Win';
                    $pickType = 'win';
                    $pickReason = 'Strong table position with market confirmation';
                    if ($favForm['form_rating'] >= 7.0) $pickReason .= ' | ✅ Excellent recent form (' . $favForm['form_string'] . ')';
                }
            }

            if ($pickType === null && $d1 < -1.5 && $d2 < -1.5 && $dx > 2.0) {
                $pickText = 'DC 12 (Home or Away Win)';
                $pickType = 'dc_12';
                $pickReason = 'Market expects decisive result (no draw) | Draw odds surging (+' . round($dx, 1) . '%) → draw unlikely';
                $venueNotes[] = '💡 DC 12: Bet covers BOTH teams to win (avoids draw)';
            }

            if ($pickType === null) {
                if ($patternResult['action'] === 'BET') {
                    $venueNotes[] = '✅ Pattern fallback: ' . $patternResult['badge'];
                    if ($favDelta <= 0 && $oppDelta >= $oppDeltaMin) {
                        $pickText = $favTeam . ' Win';
                        $pickType = 'win';
                        $pickReason = 'Pattern fallback: ' . $patternResult['pattern_type'] . ' (' . $patternResult['strength'] . ')';
                    } else {
                        $pickText = $isHomeFav ? '1X' : 'X2';
                        $pickType = 'dc';
                        $pickReason = 'Pattern fallback safe DC: ' . $patternResult['pattern_type'] . ' (' . $patternResult['strength'] . ')';
                    }
                } else {
                    return null;
                }
            }

            if ($pickType === 'win' && $isDomesticCup) return null;
            if ($pickType === 'win' && $isDomesticCup) return null;
            if ($pickType === 'win' && ($favForm['form_rating'] ?? 0) < 4.0) return null;

            // DC picks are always kept — the drift guard only adds a user-facing
            // warning note without dropping anything from the feed.
            if ($config['drift_guard_enabled'] && $pickType === 'dc'
                && $favDelta > $config['drift_guard_fav_min']) {
                $oppNote = ($oppDelta <= -3.5) ? ", underdog heavily backed " . round($oppDelta, 1) . "%"
                    : (($oppDelta <= 0) ? ", underdog mildly backed " . round($oppDelta, 1) . "%" : "");
                $msg = "⚠️ DriftGuard: favorite drifting +" . round($favDelta, 1) . "%"
                    . $oppNote . " — DC kept but confidence reduced";
                $this->guardLog[] = $msg;
                error_log($msg);
                $venueNotes[] = $msg;
            }

            $riskData = RiskTier::calculate(
                $favDelta, $oppDelta, $league,
                $favStats ?: [], $oppStats ?: [],
                $pickType, $isHomeFav ? $o1Now : $o2Now, $dx,
                $isDomesticCup, $favForm, $oppForm, $patternResult
            );

            if ($patternResult['pattern_type'] === 'normal') {
                if ($pickType === 'win') {
                    $pickText = $isHomeFav ? '1X' : 'X2';
                    $pickType = 'dc';
                    $venueNotes[] = '⚠️ Converted Win->DC due to Normal movement';
                }
                if (in_array($riskData['tier'], ['SAFE', 'MODERATE'])) {
                    $riskData['tier'] = 'SPECULATIVE';
                    $riskData['win_rate_low'] = max(45, $riskData['win_rate_low'] - 10);
                    $riskData['win_rate_high'] = max(50, $riskData['win_rate_high'] - 10);
                    $riskData['safety_notes'][] = '⚠️ Downgraded: Normal movement pattern detected';
                }
            }

            if ($venueNotes) {
                $riskData['safety_notes'] = array_merge($riskData['safety_notes'], $venueNotes);
            }

            // Sharp money detection: opening → current movement (not just last scrape)
            $sharpMoney = false;
            $sharpDetails = '';
            $openingFavDelta = null;
            $openingOppDelta = null;
            if ($o1Open && $o2Open) {
                $openingFavDelta = $isHomeFav ? $d1Open : $d2Open;
                $openingOppDelta = $isHomeFav ? $d2Open : $d1Open;
                $sharpThreshold = $config['sharp_money_threshold'] ?? -8.0;
                $oppSharpThreshold = 12.0;
                if ($openingFavDelta <= $sharpThreshold && $openingOppDelta >= $oppSharpThreshold) {
                    $sharpMoney = true;
                    $sharpDetails = "⚡ Sharp money on {$favTeam} (opened " . round($o1Open, 2) . " → now " . round($o1Now, 2) . ")";
                    if (($favForm['form_rating'] ?? 0) >= 7.0) {
                        $sharpDetails .= ' ✅ aligns with strong form';
                    }
                    if (($favForm['form_rating'] ?? 0) < 4.5 && abs($favDelta) > 5.0) {
                        $sharpDetails .= ' 🔄 reverse line movement (sharp vs public)';
                    }
                    $riskData['safety_notes'][] = $sharpDetails;
                }
            }

            $leagueLabel = '🔸 OTHER';
            if (isset($this->coreLeagues[$league])) $leagueLabel = '⭐ CORE';
            elseif (isset($this->secondaryLeagues[$league])) $leagueLabel = '🔹 SECONDARY';
            elseif ($isDomesticCup) $leagueLabel = '🏆 CUP';

            $moveStr = sprintf("📈 1: %+.1f%% | X: %+.1f%% | 2: %+.1f%%", $d1, $dx, $d2);

            return [
                'match' => "{$home} vs {$away}",
                'pick' => $pickText,
                'pick_type' => $pickType,
                'risk_tier' => $riskData['tier'],
                'win_rate_low' => $riskData['win_rate_low'],
                'win_rate_high' => $riskData['win_rate_high'],
                'safety_notes' => $riskData['safety_notes'],
                'tier_emoji' => $riskData['emoji'],
                'actual_odds' => $isHomeFav ? $o1Now : $o2Now,
                'is_home_fav' => $isHomeFav,
                'fav_team' => $favTeam,
                'opp_team' => $oppTeam,
                'fav_stats' => $favStats,
                'opp_stats' => $oppStats,
                'fav_form' => $favForm,
                'opp_form' => $oppForm,
                'move' => $moveStr,
                'fav_delta' => $favDelta,
                'opp_delta' => $oppDelta,
                'draw_delta' => $dx,
                'home_odds' => $o1Now,
                'draw_odds' => $oxNow,
                'away_odds' => $o2Now,
                'reason' => $pickReason,
                'details' => "🕒 {$matchTimeStr} | {$league}",
                'detected_at' => $row['Detected_At'] ?? date('Y-m-d H:i:s'),
                'is_cup' => $isDomesticCup,
                'league' => $league,
                'pattern_badge' => $patternResult['badge'],
                'pattern_type' => $patternResult['pattern_type'],
                'pattern_confidence' => $patternResult['confidence'],
                'pattern_action' => $patternResult['action'],
                'matches_pattern' => $patternResult['action'] === 'BET' && $patternResult['confidence'] >= 0.7,
                'h2h_data' => $h2hData,
                'has_opening_odds' => $o1Open !== null,
                'sharp_money' => $sharpMoney,
                'sharp_money_details' => $sharpDetails,
                'opening_fav_delta' => $openingFavDelta,
                'opening_odds_1' => $o1Open,
                'opening_odds_x' => $oxOpen,
                'opening_odds_2' => $o2Open,
            ];
        } catch (Exception $e) {
            error_log("Analyze error for {$row['Home_Team']} vs {$row['Away_Team']}: " . $e->getMessage());
            return null;
        }
    }

    private function detectOddsPattern($o1History, $o2History, $xHistory, $hoursToKickoff, $config) {
        $calcChange = function ($hist) {
            if (count($hist) < 2 || $hist[0] == 0) return 0.0;
            return (($hist[count($hist)-1] - $hist[0]) / $hist[0] * 100);
        };
        $calcVolatility = function ($hist) {
            if (count($hist) < 3) return 0.0;
            $mean = array_sum($hist) / count($hist);
            if ($mean == 0) return 0.0;
            $variance = array_sum(array_map(function($x) use ($mean) { return ($x - $mean) ** 2; }, $hist)) / count($hist);
            return (sqrt($variance) / $mean) * 100;
        };

        $o1Change = $calcChange($o1History);
        $o2Change = $calcChange($o2History);
        $xChange = $calcChange($xHistory);
        $o1Vol = $calcVolatility($o1History);

        if ($config['enable_vertical_spike_avoid'] && count($o1History) >= 3) {
            $recentChange = $o1History[count($o1History)-1] - $o1History[count($o1History)-2];
            if ($o1History[count($o1History)-2] != 0) $recentChange = $recentChange / $o1History[count($o1History)-2] * 100;
            if (abs($recentChange) > $config['avoid_vertical_spike_threshold']) {
                return ['pattern_type' => 'vertical_spike', 'confidence' => 0.95, 'action' => 'AVOID', 'strength' => 'STRONG', 'badge' => '🚨 VERTICAL SPIKE (' . sprintf('%+.1f', $recentChange) . '%)'];
            }
        }

        if ($config['enable_choppy_volatility_filter'] && $o1Vol > $config['avoid_choppy_volatility_max']) {
            return ['pattern_type' => 'choppy', 'confidence' => 0.7, 'action' => 'AVOID', 'strength' => 'MODERATE', 'badge' => '⚠️ CHOPPY (volatility ' . round($o1Vol, 1) . '%)'];
        }

        if ($config['enable_opening_trap_detection'] && $o1History[0] > $config['avoid_opening_trap_min'] && $o1Change < -10) {
            return ['pattern_type' => 'opening_trap', 'confidence' => 0.8, 'action' => 'AVOID', 'strength' => 'MODERATE', 'badge' => '🪤 OPENING TRAP (opened ' . round($o1History[0], 2) . ')'];
        }

        if ($config['enable_contradictory_signal_filter'] && $o1Change < -5 && $xChange < $config['avoid_contradictory_draw_fall']) {
            return ['pattern_type' => 'contradictory', 'confidence' => 0.75, 'action' => 'CAUTION', 'strength' => 'MODERATE', 'badge' => '⚠️ CONTRADICTORY (W1↓ + X↓)'];
        }

        if ($o1Change > 10) {
            return ['pattern_type' => 'rising_odds', 'confidence' => 0.8, 'action' => 'AVOID', 'strength' => 'STRONG', 'badge' => '📈 RISING ODDS (' . sprintf('%+.1f', $o1Change) . '%)'];
        }

        if ($config['enable_divergence_strength']) {
            $divHomeMin = $config['divergence_home_min'];
            $divAwayMin = $config['divergence_away_min'];
            if ($o1Change < $divHomeMin && $o2Change > $divAwayMin) {
                return ['pattern_type' => 'extreme_divergence', 'confidence' => 0.9, 'action' => 'BET', 'strength' => 'STRONG', 'badge' => '✂️ EXTREME DIVERGENCE (' . round($o1Change, 1) . '% vs ' . round($o2Change, 1) . '%)'];
            }
        }

        if ($config['enable_stable_favorite_boost'] && abs($o1Change) < 3 && $o1Vol < 3) {
            return ['pattern_type' => 'stable_favorite', 'confidence' => 0.85, 'action' => 'BET', 'strength' => 'STRONG', 'badge' => '➡️ STABLE FAVORITE (±' . round(abs($o1Change), 1) . '%)'];
        }

        if ($config['enable_late_drop_urgency'] && $hoursToKickoff !== null && $hoursToKickoff < 48 && count($o1History) >= 3) {
            $recentDrop = $o1History[count($o1History)-1] - $o1History[count($o1History)-2];
            if ($o1History[count($o1History)-2] != 0) $recentDrop = $recentDrop / $o1History[count($o1History)-2] * 100;
            if ($recentDrop < -5) {
                return ['pattern_type' => 'late_drop', 'confidence' => 0.8, 'action' => 'BET', 'strength' => 'STRONG', 'badge' => '📉 LATE DROP (' . round($recentDrop, 1) . '% in 48hrs)'];
            }
        }

        if ($o1Change < -5) {
            return ['pattern_type' => 'falling_odds', 'confidence' => 0.75, 'action' => 'BET', 'strength' => 'MODERATE', 'badge' => '📉 FALLING ODDS (' . round($o1Change, 1) . '%)'];
        }

        return ['pattern_type' => 'normal', 'confidence' => 0.5, 'action' => 'CAUTION', 'strength' => 'WEAK', 'badge' => '📊 NORMAL MOVEMENT'];
    }

    private function getDynamicThreshold($baseThreshold, $pattern, $hoursToKickoff, $config) {
        $multiplier = 1.0;
        $type = $pattern['pattern_type'] ?? 'normal';
        $action = $pattern['action'] ?? 'CAUTION';
        if ($type === 'stable_favorite' && $action === 'BET') $multiplier = 0.7;
        elseif ($type === 'late_drop' && $action === 'BET') $multiplier = 0.6;
        elseif ($type === 'extreme_divergence' && $action === 'BET') $multiplier = 0.75;
        elseif ($type === 'vertical_spike') $multiplier = 2.0;
        elseif ($type === 'choppy') $multiplier = 1.5;
        elseif ($type === 'contradictory') $multiplier = 1.3;
        elseif ($type === 'opening_trap') $multiplier = 1.5;

        if ($hoursToKickoff !== null) {
            if ($hoursToKickoff * 60 < ($config['late_window_mins'] ?? 120)) $multiplier *= ($config['late_drift_multiplier'] ?? 0.8);
            elseif ($hoursToKickoff > ($config['early_window_hours'] ?? 48)) $multiplier *= ($config['early_drift_multiplier'] ?? 1.2);
        }
        return $baseThreshold * $multiplier;
    }

    private function evaluateGGMovement($d1Pct, $dxPct, $d2Pct) {
        if ($d1Pct >= -5.0 && $d1Pct <= 5.0 && $d2Pct >= 3.0 && abs($dxPct) <= 3.0) {
            return ['confidence' => 'HIGH', 'score' => 0.85, 'signal' => '✅ High GG Probability (Normal Movement)', 'action' => 'BOOST', 'tier_override' => 'MODERATE'];
        }
        if ($d1Pct >= -10.0 && $d1Pct < -5.0 && $d2Pct >= 5.0 && $dxPct >= -2.0) {
            return ['confidence' => 'MEDIUM', 'score' => 0.65, 'signal' => '⚽ Moderate GG Probability (Fall Movement)', 'action' => 'ACCEPT', 'tier_override' => 'VALUE'];
        }
        if ($d1Pct < -10.0 && $dxPct >= -3.0 && $dxPct <= 3.0) {
            return ['confidence' => 'LOW', 'score' => 0.35, 'signal' => '⚠️ Low GG Probability (Blowout Risk)', 'action' => 'AVOID', 'tier_override' => 'SPECULATIVE'];
        }
        return ['confidence' => 'NEUTRAL', 'score' => 0.50, 'signal' => '📊 Standard Movement', 'action' => 'NEUTRAL', 'tier_override' => null];
    }

    private function detectHighDrawRisk($homeForm, $awayForm, $drawDelta, $combinedScoringRate) {
        $homeStr = $homeForm['form_string'] ?? '-----';
        $awayStr = $awayForm['form_string'] ?? '-----';
        if (substr_count($homeStr, 'L') + substr_count($homeStr, 'D') >= 4 && substr_count($awayStr, 'L') + substr_count($awayStr, 'D') >= 4) {
            return [true, 'Both teams in poor form (L/D streaks) → 78% draw probability'];
        }
        if (substr_count($homeStr, 'D') >= 3 || substr_count($awayStr, 'D') >= 3) {
            return [true, 'Draw-heavy recent history → expect tight match'];
        }
        if ($drawDelta > 3.0 && $combinedScoringRate < 2.3) {
            return [true, 'Rising draw odds (+' . round($drawDelta, 1) . '%) + low scoring (' . round($combinedScoringRate, 1) . ' GPG) → defensive match'];
        }
        return [false, ''];
    }

    private function evaluateOver15($favDelta, $dx, $oppDelta, $homeForm, $awayForm, $h2hData, $isCup, $isEuropean, $config, $favOdds, $patternResult) {
        if ($isCup) return null;

        $isBalanced = abs($favDelta - $dx) <= 1.0 && abs($dx - $oppDelta) <= 1.0 && abs($favDelta - $oppDelta) <= 1.0;
        if ($isBalanced) return null;

        $isPrimary = ($favDelta >= 0 && $favDelta <= 3.2 && $dx >= -2.5 && $dx <= 1.0 && $oppDelta < 0 && $dx >= $oppDelta);
        $isAlternative = ($favDelta < -3.0 && $oppDelta >= 0 && $oppDelta <= 2.5 && $dx >= 0 && $dx <= 2.5);
        $isGoalFest = ((abs($favDelta) > 6.0 || abs($oppDelta) > 6.0) && $dx >= 0 && $dx <= 1.5);

        if (!($dx >= -2.5 && $dx <= 1.0) && !$isAlternative && !$isGoalFest) return null;

        if (!($isPrimary || $isAlternative || $isGoalFest)) return null;

        $minOdds = $config['over_15_min_odds'];
        $maxOdds = $config['over_15_max_odds'];
        if ($favOdds < $minOdds - 0.15 || $favOdds > $maxOdds + 0.25) return null;

        $reasons = [];
        $notes = [];

        if ($isGoalFest) $reasons[] = '🔥 Massive goal-fest pattern detected (4-6+ goals likely)';
        elseif ($isAlternative) $reasons[] = '✅ Fav weakening (<-3%) while Opp & Draw stable → goal pressure';
        else $reasons[] = '✅ Market drift+drop pattern confirmed (Fav stable/rise, Opp drop, Draw stable)';

        $homeMatches = strlen(str_replace('-', '', $homeForm['form_string'] ?? ''));
        $awayMatches = strlen(str_replace('-', '', $awayForm['form_string'] ?? ''));
        $hasForm = $homeMatches >= 3 && $awayMatches >= 3 && ($homeForm['gf'] + $awayForm['gf'] > 0);

        $reasons[] = '📊 Pattern: ' . ($patternResult['badge'] ?? 'NORMAL');

        if ($hasForm) {
            $homeGpg = $homeForm['gf'] / max($homeMatches, 1);
            $awayGpg = $awayForm['gf'] / max($awayMatches, 1);
            $combinedGpg = $homeGpg + $awayGpg;
            if ($combinedGpg >= 2.0) $reasons[] = '✅ Strong combined scoring form (' . round($combinedGpg, 1) . ' GPG)';
            else $reasons[] = '⚠️ Form slightly low (' . round($combinedGpg, 1) . ' GPG) but market overrides';
        } else {
            $notes[] = 'ℹ️ No form data → Validated by strict market movement only';
            $reasons[] = '✅ Market signal only (form data unavailable)';
        }

        if ($h2hData['total_matches'] >= 3) {
            if ($h2hData['avg_total_goals'] >= 2.8) $reasons[] = '📊 H2H high-scoring fixture (' . $h2hData['avg_total_goals'] . ' avg)';
            elseif ($h2hData['btts_rate'] >= 60) $reasons[] = '📊 H2H both teams often score (' . $h2hData['btts_rate'] . '% BTTS)';
        }

        return [
            'pick' => 'Over 1.5 Goals',
            'pick_type' => 'over',
            'reason' => implode(' | ', $reasons),
            'notes' => $notes,
        ];
    }
}
