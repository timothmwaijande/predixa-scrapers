<?php
$isCLI = php_sapi_name() === 'cli';
$secretKey = 'pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580';
$providedKey = $isCLI ? ($argv[1] ?? '') : ($_GET['key'] ?? '');

if ($providedKey !== $secretKey) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid key']));
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/BayesianModel.php';
require_once __DIR__ . '/../classes/GoogleSheetsAPI.php';

$start = microtime(true);
$log = [];
$response = ['status' => 'ok', 'log' => []];

try {
    $bm = new BayesianModel();

    $predictResult = $bm->runBatchPredictions();
    $log[] = "Stored: {$predictResult['stored']}, Skipped: {$predictResult['skipped']}, Errors: {$predictResult['errors']}";

    $settleResult = $bm->settlePredictions();
    $log[] = "Settled: {$settleResult['settled']}";

    $stats = $bm->getAccuracyStats();
    $total = (int)($stats['total'] ?? 0);
    if ($total > 0) {
        $correct = (int)$stats['correct'];
        $rate = round($correct / $total * 100, 1);
        $log[] = "Accuracy: {$rate}% ({$correct}/{$total})";
    } else {
        $log[] = "No settled predictions yet.";
    }

    // Value edge: compare Bayesian probs vs market odds from Google Sheets
    try {
        $gapi = new GoogleSheetsAPI();
        $oddsDrops = $gapi->getOddsDrops();
        if (!empty($oddsDrops)) {
            $predictions = $bm->getTodayPredictions();
            $valueEdges = 0;
            foreach ($predictions as $pred) {
                $bestMatch = null;
                $bestScore = 0;
                $hNorm = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $pred['home_team'])));
                $aNorm = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $pred['away_team'])));
                $lNorm = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $pred['league'] ?? '')));

                foreach ($oddsDrops as $odds) {
                    $ohNorm = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $odds['Home_Team'])));
                    $oaNorm = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $odds['Away_Team'])));
                    $olNorm = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $odds['League'] ?? '')));
                    $score = 0;
                    if ($hNorm === $ohNorm && $aNorm === $oaNorm) $score += 2;
                    elseif ($hNorm === $oaNorm && $aNorm === $ohNorm) $score += 1;
                    if ($lNorm && $olNorm && (strpos($lNorm, $olNorm) !== false || strpos($olNorm, $lNorm) !== false)) $score += 1;
                    elseif (!$lNorm) $score += 1;
                    if ($score > $bestScore) { $bestScore = $score; $bestMatch = $odds; }
                }

                if ($bestMatch && $bestScore >= 2) {
                    $odds1 = (float)$bestMatch['Odds_1_Now'];
                    $oddsX = (float)$bestMatch['Odds_X_Now'];
                    $odds2 = (float)$bestMatch['Odds_2_Now'];
                    if ($odds1 > 0 && $oddsX > 0 && $odds2 > 0) {
                        $implied1 = 1 / $odds1;
                        $impliedX = 1 / $oddsX;
                        $implied2 = 1 / $odds2;
                        $sumImplied = $implied1 + $impliedX + $implied2;
                        $market1 = $implied1 / $sumImplied * 100;
                        $marketX = $impliedX / $sumImplied * 100;
                        $market2 = $implied2 / $sumImplied * 100;

                        $bayes1 = (float)$pred['prob_1'];
                        $bayesX = (float)$pred['prob_x'];
                        $bayes2 = (float)$pred['prob_2'];

                        $edge1 = round($bayes1 - $market1, 1);
                        $edgeX = round($bayesX - $marketX, 1);
                        $edge2 = round($bayes2 - $market2, 1);

                        $edges = [['1', $edge1], ['X', $edgeX], ['2', $edge2]];
                        usort($edges, function($a, $b) { return $b[1] <=> $a[1]; });
                        $bestEdge = $edges[0];
                        $valuePick = $bestEdge[1] > 0 ? $bestEdge[0] : null;

                        $bm->updateValueEdge($pred['id'], $edge1, $edgeX, $edge2, $valuePick, $odds1, $oddsX, $odds2);
                        $valueEdges++;
                    }
                }
            }
            $log[] = "Value edges computed: {$valueEdges}";
        } else {
            $log[] = "No odds data from Google Sheets for value edge comparison.";
        }
    } catch (Exception $e) {
        $log[] = "Value edge skipped: " . $e->getMessage();
        error_log("bayesian_predict value_edge: " . $e->getMessage());
    }

    $elapsed = round(microtime(true) - $start, 2);
    $log[] = "Completed in {$elapsed}s";

    $response['log'] = $log;
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    error_log("bayesian_predict cron: " . $e->getMessage());
}
