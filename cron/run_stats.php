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
$mode = $_GET['mode'] ?? 'full'; // full = target + backfill 1 day, backfill = oldest missing only, target = target only

$db = getDB();
$outputs = [];
$allResults = [];

if ($mode === 'backfill') {
    // Backfill mode: find the OLDEST missing date and collect only that
    try {
        $backfillDate = $db->query("
            SELECT mr.match_date
            FROM match_results mr
            LEFT JOIN match_statistics ms ON ms.match_date = mr.match_date AND ms.api_fixture_id IS NOT NULL
            WHERE ms.match_date IS NULL
              AND mr.match_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
              AND mr.match_date <= CURDATE()
              AND mr.home_score IS NOT NULL
            GROUP BY mr.match_date
            ORDER BY mr.match_date ASC
            LIMIT 1
        ")->fetchColumn();

        if ($backfillDate) {
            $GLOBALS['_collector_opts'] = ['date' => $backfillDate, 'limit' => (string)$limit];
            if (isset($_GET['test'])) $GLOBALS['_collector_opts']['test'] = true;

            ob_start();
            include __DIR__ . '/collect_match_stats.php';
            $outputs[] = ['date' => $backfillDate, 'mode' => 'backfill', 'output' => trim(ob_get_clean())];
        } else {
            $outputs[] = ['date' => null, 'mode' => 'backfill', 'output' => 'All dates up to date - nothing to backfill'];
        }
    } catch (Exception $e) {
        $outputs[] = ['date' => 'backfill-error', 'output' => $e->getMessage()];
    }
} else {
    // Full mode or target mode: collect target date
    $GLOBALS['_collector_opts'] = ['date' => $date, 'limit' => (string)$limit];
    if (isset($_GET['test'])) $GLOBALS['_collector_opts']['test'] = true;

    ob_start();
    include __DIR__ . '/collect_match_stats.php';
    $outputs[] = ['date' => $date, 'mode' => 'target', 'output' => trim(ob_get_clean())];

    // In full mode, also backfill 1 oldest missing date (if quota allows)
    if ($mode === 'full' && $db && !isset($_GET['nobackfill'])) {
        try {
            $backfillDate = $db->query("
                SELECT mr.match_date
                FROM match_results mr
                LEFT JOIN match_statistics ms ON ms.match_date = mr.match_date AND ms.api_fixture_id IS NOT NULL
                WHERE ms.match_date IS NULL
                  AND mr.match_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                  AND mr.match_date <= CURDATE()
                  AND mr.home_score IS NOT NULL
                GROUP BY mr.match_date
                ORDER BY mr.match_date ASC
                LIMIT 1
            ")->fetchColumn();

            if ($backfillDate && $backfillDate !== $date) {
                $GLOBALS['_collector_opts'] = ['date' => $backfillDate, 'limit' => (string)$limit];
                if (isset($_GET['test'])) $GLOBALS['_collector_opts']['test'] = true;

                ob_start();
                include __DIR__ . '/collect_match_stats.php';
                $outputs[] = ['date' => $backfillDate, 'mode' => 'backfill', 'output' => trim(ob_get_clean())];
            } else {
                $outputs[] = ['date' => null, 'mode' => 'backfill', 'output' => 'No backfill needed or same as target'];
            }
        } catch (Exception $e) {
            $outputs[] = ['date' => 'backfill-error', 'output' => $e->getMessage()];
        }
    }
}

header('Content-Type: application/json');
echo json_encode([
    'status' => 'completed',
    'date' => $date,
    'mode' => $mode,
    'limit' => $limit,
    'outputs' => $outputs,
]);
