<?php
$secretKey = 'pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580';
$providedKey = $_GET['key'] ?? '';
if ($providedKey !== $secretKey) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid key']));
}

require_once __DIR__ . '/../config.php';

$db = getDB();
if (!$db) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'DB failed']));
}

$lookbackDays = (int)($_GET['lookback'] ?? 730);
$minDate = date('Y-m-d', strtotime("-{$lookbackDays} days"));

try {
    // 1. Training data: match results with scores
    $trainStmt = $db->prepare("
        SELECT mr.id, mr.home_team, mr.away_team, mr.league, mr.match_date,
               mr.home_score, mr.away_score,
               bp.prob_1 as bayes_prob_1, bp.prob_x as bayes_prob_x, bp.prob_2 as bayes_prob_2,
               bp.confidence as bayes_confidence
        FROM match_results mr
        LEFT JOIN bayesian_predictions bp ON bp.match_name = CONCAT(mr.home_team, ' vs ', mr.away_team)
            AND bp.match_date = mr.match_date
        WHERE mr.match_date >= ?
          AND mr.home_score IS NOT NULL AND mr.away_score IS NOT NULL
        ORDER BY mr.match_date DESC
        LIMIT 5000
    ");
    $trainStmt->execute([$minDate]);
    $training = $trainStmt->fetchAll();

    // 2. Upcoming matches (from scraper results + admin picks)
    $upcomingStmt = $db->prepare("
        SELECT DISTINCT sr.match_name, sr.league
        FROM scraper_results sr
        WHERE DATE(sr.detected_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        UNION
        SELECT DISTINCT afp.match_name, afp.league
        FROM admin_featured_picks afp
        WHERE DATE(afp.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        UNION
        SELECT DISTINCT wp.match_name, wp.league
        FROM web_picks wp
        WHERE DATE(wp.detected_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        LIMIT 200
    ");
    $upcomingStmt->execute();
    $upcoming = $upcomingStmt->fetchAll();

    // Filter out already-played matches
    $filtered = [];
    foreach ($upcoming as $m) {
        $parts = preg_split('/\s+vs\.?\s+/i', trim($m['match_name']), 2);
        if (count($parts) !== 2) continue;
        $h = trim($parts[0]); $a = trim($parts[1]);
        $check = $db->prepare("SELECT 1 FROM match_results WHERE home_team = ? AND away_team = ? AND match_date < CURDATE() LIMIT 1");
        $check->execute([$h, $a]);
        if ($check->fetchColumn()) continue;
        $check->execute([$a, $h]);
        if ($check->fetchColumn()) continue;
        $filtered[] = $m;
    }

    // 2b. Bayesian predictions for upcoming matches (for train/serve consistency)
    $bayesUpcoming = $db->query("
        SELECT bp.match_name, bp.home_team, bp.away_team,
               bp.prob_1 as bayes_prob_1, bp.prob_x as bayes_prob_x, bp.prob_2 as bayes_prob_2,
               bp.confidence as bayes_confidence
        FROM bayesian_predictions bp
        WHERE bp.match_date IN (CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY))
          AND bp.prob_1 IS NOT NULL
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 3. League standings
    $standings = $db->query("
        SELECT team, league, position, points, played, goal_diff
        FROM league_standings
        WHERE updated_at = CURDATE()
    ")->fetchAll();

    // 4. League priors (historic averages from match_results)
    $priors = $db->query("
        SELECT league,
               COUNT(*) as total,
               ROUND(SUM(CASE WHEN home_score > away_score THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as home_win_pct,
               ROUND(SUM(CASE WHEN home_score = away_score THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as draw_pct,
               ROUND(AVG(home_score + away_score), 2) as avg_goals,
               ROUND(SUM(CASE WHEN home_score > 0 AND away_score > 0 THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as btts_pct
        FROM match_results
        WHERE match_date >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
          AND home_score IS NOT NULL AND away_score IS NOT NULL
        GROUP BY league
        HAVING total >= 10
        ORDER BY total DESC
    ")->fetchAll();

    echo json_encode([
        'status' => 'ok',
        'generated_at' => date('Y-m-d H:i:s'),
        'training' => $training,
        'upcoming' => $filtered,
        'bayesian_upcoming' => $bayesUpcoming,
        'standings' => $standings,
        'priors' => $priors,
        'stats' => [
            'training_count' => count($training),
            'upcoming_count' => count($filtered),
            'bayesian_upcoming_count' => count($bayesUpcoming),
            'standings_count' => count($standings),
            'league_count' => count($priors),
        ],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
