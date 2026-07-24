<?php
require_once __DIR__ . '/../config.php';

$key = $_GET['key'] ?? $_POST['key'] ?? '';
if ($key !== STATS_SECRET_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid key']);
    exit(1);
}

$date = $_GET['date'] ?? date('Y-m-d', strtotime('-1 day'));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 9999;

$db = getDB();
$outputs = [];
$allResults = [];

// Collect for target date
$GLOBALS['_collector_opts'] = [];
$GLOBALS['_collector_opts']['date'] = $date;
$GLOBALS['_collector_opts']['limit'] = (string)$limit;
if (isset($_GET['test'])) $GLOBALS['_collector_opts']['test'] = true;

ob_start();
include __DIR__ . '/collect_match_stats.php';
$outputs[] = ['date' => $date, 'output' => trim(ob_get_clean())];

// Backfill: find recent dates (last 7 days) missing from match_statistics
if ($db && !isset($_GET['nobackfill'])) {
    try {
        $backfillDates = $db->query("
            SELECT DISTINCT mr.match_date
            FROM match_results mr
            LEFT JOIN (
                SELECT DISTINCT match_date FROM match_statistics
            ) ms ON ms.match_date = mr.match_date
            WHERE ms.match_date IS NULL
              AND mr.match_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
              AND mr.match_date <= CURDATE()
              AND mr.home_score IS NOT NULL
            ORDER BY mr.match_date ASC
        ")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($backfillDates as $bfDate) {
            if ($bfDate === $date) continue;
            $GLOBALS['_collector_opts'] = [];
            $GLOBALS['_collector_opts']['date'] = $bfDate;
            $GLOBALS['_collector_opts']['limit'] = (string)$limit;
            if (isset($_GET['test'])) $GLOBALS['_collector_opts']['test'] = true;

            ob_start();
            include __DIR__ . '/collect_match_stats.php';
            $bfOutput = trim(ob_get_clean());
            $outputs[] = ['date' => $bfDate, 'output' => $bfOutput];

            if (isset($_GET['test'])) break;
        }
    } catch (Exception $e) {
        $outputs[] = ['date' => 'backfill-error', 'output' => $e->getMessage()];
    }
}

header('Content-Type: application/json');
echo json_encode([
    'status' => 'completed',
    'date' => $date,
    'limit' => $limit,
    'outputs' => $outputs,
]);
