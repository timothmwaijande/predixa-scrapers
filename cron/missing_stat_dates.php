<?php
/**
 * Missing Stats Dates
 * Returns recent dates (site timezone) that have fewer than `min_rows` rows in
 * match_statistics, so the GitHub Action can self-heal gaps by re-scraping them.
 *
 * GET /cron/missing_stat_dates.php?key=...&days=7&max=5&min_rows=1
 */
require_once __DIR__ . '/../config.php';

$key = $_GET['key'] ?? '';
if ($key !== STATS_SECRET_KEY) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid key']);
    exit(1);
}

$days = min(30, max(1, (int)($_GET['days'] ?? 7)));
$max = min(30, max(1, (int)($_GET['max'] ?? 5)));
$minRows = max(1, (int)($_GET['min_rows'] ?? 1));

$db = getDB();

$rows = $db->query("
    SELECT match_date, COUNT(*) c
    FROM match_statistics
    WHERE match_date BETWEEN DATE_SUB(CURDATE(), INTERVAL $days DAY) AND DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    GROUP BY match_date
")->fetchAll(PDO::FETCH_KEY_PAIR);

$dates = [];
for ($i = $days; $i >= 1; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $c = (int)($rows[$d] ?? 0);
    if ($c < $minRows) {
        $dates[] = $d;
        if (count($dates) >= $max) {
            break;
        }
    }
}

header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'today' => date('Y-m-d'),
    'days' => $days,
    'min_rows' => $minRows,
    'counts' => $rows,
    'dates' => $dates,
]);
