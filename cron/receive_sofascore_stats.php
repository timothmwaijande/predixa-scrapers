<?php
/**
 * Sofascore Stats Receiver
 * Accepts JSON from scrape_sofascore_stats.py and stores in match_statistics.
 *
 * POST /cron/receive_sofascore_stats.php
 * Body: JSON from scraper
 * Header: X-Stats-Key or ?key=
 */
require_once __DIR__ . '/../config.php';

$key = $_SERVER['HTTP_X_STATS_KEY'] ?? $_GET['key'] ?? $_POST['key'] ?? '';
if ($key !== STATS_SECRET_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid key']);
    exit(1);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data || !isset($data['matches'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON or missing matches']);
    exit(1);
}

$date = $data['date'] ?? date('Y-m-d');
$matches = $data['matches'];
$source = $data['source'] ?? 'sofascore';

$db = getDB();

$logFile = __DIR__ . '/../logs/sofascore_receiver_' . $date . '.log';
if (!is_dir(dirname($logFile))) @mkdir(dirname($logFile), 0755, true);

function rLog($msg) {
    global $logFile;
    $line = date('[Y-m-d H:i:s] ') . $msg;
    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
}

rLog("=== Sofascore Receiver: $date, " . count($matches) . " matches from $source ===");

// Ensure columns exist
try { $db->exec("ALTER TABLE match_statistics ADD COLUMN sofascore_event_id BIGINT DEFAULT NULL AFTER api_fixture_id"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE match_statistics ADD UNIQUE INDEX idx_sofascore_event (sofascore_event_id)"); } catch (Exception $e) {}

// Upsert by sofascore_event_id first, then by api_fixture_id
$insertStmt = $db->prepare("INSERT INTO match_statistics
    (sofascore_event_id, api_fixture_id, match_date, league_name, league_id_api,
     home_team_api, away_team_api, home_team_id, away_team_id,
     home_score, away_score, referee, venue,
     home_shots_on_goal, away_shots_on_goal, home_shots_off_goal, away_shots_off_goal,
     home_total_shots, away_total_shots, home_blocked_shots, away_blocked_shots,
     home_shots_inside_box, away_shots_inside_box, home_shots_outside_box, away_shots_outside_box,
     home_ball_possession, away_ball_possession,
     home_corner_kicks, away_corner_kicks, home_offsides, away_offsides,
     home_free_kicks, away_free_kicks,
     home_fouls, away_fouls, home_yellow_cards, away_yellow_cards,
     home_red_cards, away_red_cards,
     home_goalkeeper_saves, away_goalkeeper_saves,
     home_total_passes, away_total_passes, home_passes_accurate, away_passes_accurate,
     home_pass_accuracy, away_pass_accuracy,
     home_expected_goals, away_expected_goals,
     home_goals_prevented, away_goals_prevented,
     raw_statistics, raw_fixture)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
      sofascore_event_id=VALUES(sofascore_event_id),
      home_team_id=VALUES(home_team_id), away_team_id=VALUES(away_team_id),
      home_shots_on_goal=VALUES(home_shots_on_goal), away_shots_on_goal=VALUES(away_shots_on_goal),
      home_shots_off_goal=VALUES(home_shots_off_goal), away_shots_off_goal=VALUES(away_shots_off_goal),
      home_total_shots=VALUES(home_total_shots), away_total_shots=VALUES(away_total_shots),
      home_blocked_shots=VALUES(home_blocked_shots), away_blocked_shots=VALUES(away_blocked_shots),
      home_shots_inside_box=VALUES(home_shots_inside_box), away_shots_inside_box=VALUES(away_shots_inside_box),
      home_shots_outside_box=VALUES(home_shots_outside_box), away_shots_outside_box=VALUES(away_shots_outside_box),
      home_ball_possession=VALUES(home_ball_possession), away_ball_possession=VALUES(away_ball_possession),
      home_corner_kicks=VALUES(home_corner_kicks), away_corner_kicks=VALUES(away_corner_kicks),
      home_offsides=VALUES(home_offsides), away_offsides=VALUES(away_offsides),
      home_free_kicks=VALUES(home_free_kicks), away_free_kicks=VALUES(away_free_kicks),
      home_fouls=VALUES(home_fouls), away_fouls=VALUES(away_fouls),
      home_yellow_cards=VALUES(home_yellow_cards), away_yellow_cards=VALUES(away_yellow_cards),
      home_red_cards=VALUES(home_red_cards), away_red_cards=VALUES(away_red_cards),
      home_goalkeeper_saves=VALUES(home_goalkeeper_saves), away_goalkeeper_saves=VALUES(away_goalkeeper_saves),
      home_total_passes=VALUES(home_total_passes), away_total_passes=VALUES(away_total_passes),
      home_passes_accurate=VALUES(home_passes_accurate), away_passes_accurate=VALUES(away_passes_accurate),
      home_pass_accuracy=VALUES(home_pass_accuracy), away_pass_accuracy=VALUES(away_pass_accuracy),
      home_expected_goals=VALUES(home_expected_goals), away_expected_goals=VALUES(away_expected_goals),
      home_goals_prevented=VALUES(home_goals_prevented), away_goals_prevented=VALUES(away_goals_prevented),
      league_name=VALUES(league_name), league_id_api=VALUES(league_id_api),
      home_team_api=VALUES(home_team_api), away_team_api=VALUES(away_team_api),
      home_score=VALUES(home_score), away_score=VALUES(away_score),
      referee=VALUES(referee), venue=VALUES(venue),
      raw_statistics=VALUES(raw_statistics), raw_fixture=VALUES(raw_fixture)
");

function resolveTeamIdForStats($db, $teamName) {
    $norm = strtolower(trim($teamName));
    $stmt = $db->prepare("SELECT id FROM teams WHERE normalized_name = ? LIMIT 1");
    $stmt->execute([$norm]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        $db->prepare("INSERT IGNORE INTO teams (name, normalized_name) VALUES (?, ?)")->execute([$teamName, $norm]);
        $id = $db->lastInsertId();
    }
    return $id;
}

function cleanPassAccuracy($val) {
    if ($val === null) return null;
    $s = str_replace('%', '', trim($val));
    if (preg_match('#(\d+)/(\d+)\s*\((\d+)%\)#', $val, $m)) {
        return (int)$m[3];
    }
    return is_numeric($s) ? (int)$s : null;
}

function cleanPassTotal($val) {
    if ($val === null) return null;
    if (preg_match('#^(\d+)/(\d+)\s*\(#', $val, $m)) {
        return (int)$m[2];
    }
    return is_numeric($val) ? (int)$val : null;
}

function cleanPassAccurate($val) {
    if ($val === null) return null;
    if (preg_match('#^(\d+)/(\d+)\s*\(#', $val, $m)) {
        return (int)$m[1];
    }
    return is_numeric($val) ? (int)$val : null;
}

$collected = 0;
$skipped = 0;
$errors = 0;

foreach ($matches as $m) {
    $sofaId = $m['sofascore_event_id'] ?? null;
    $homeName = $m['home_team'] ?? '';
    $awayName = $m['away_team'] ?? '';
    $leagueName = $m['league_name'] ?? '';
    $leagueIdApi = $m['league_id_sofa'] ?? null;

    if (!$homeName || !$awayName) {
        $skipped++;
        continue;
    }

    // Check if already exists
    if ($sofaId) {
        $chk = $db->prepare("SELECT id FROM match_statistics WHERE sofascore_event_id = ?");
        $chk->execute([$sofaId]);
        if ($chk->fetch()) {
            $skipped++;
            continue;
        }
    }

    $homeTeamId = resolveTeamIdForStats($db, $homeName);
    $awayTeamId = resolveTeamIdForStats($db, $awayName);

    // Calculate pass accuracy from Sofascore's "Accurate passes: 299 vs 319" and "Passes: 367 vs 376"
    $homePassAccurate = cleanPassAccurate($m['home_passes_accurate'] ?? null);
    $homePassTotal = cleanPassTotal($m['home_total_passes'] ?? null);
    $awayPassAccurate = cleanPassAccurate($m['away_passes_accurate'] ?? null);
    $awayPassTotal = cleanPassTotal($m['away_total_passes'] ?? null);

    $homePassAccuracy = null;
    if ($homePassAccurate && $homePassTotal && $homePassTotal > 0) {
        $homePassAccuracy = round($homePassAccurate / $homePassTotal * 100) . '%';
    }
    $awayPassAccuracy = null;
    if ($awayPassAccurate && $awayPassTotal && $awayPassTotal > 0) {
        $awayPassAccuracy = round($awayPassAccurate / $awayPassTotal * 100) . '%';
    }

    // Offsides not provided by Sofascore - leave null

    $rawFixture = json_encode([
        "sofascore_event_id" => $sofaId,
        "home_team" => $homeName,
        "away_team" => $awayName,
        "source" => "sofascore",
    ]);

    try {
        $insertStmt->execute([
            $sofaId,
            null,
            $date,
            $leagueName,
            $leagueIdApi,
            $homeName,
            $awayName,
            $homeTeamId,
            $awayTeamId,
            $m['home_score'] ?? null,
            $m['away_score'] ?? null,
            $m['referee'] ?? null,
            $m['venue'] ?? null,
            $m['home_shots_on_goal'] ?? null,
            $m['away_shots_on_goal'] ?? null,
            $m['home_shots_off_goal'] ?? null,
            $m['away_shots_off_goal'] ?? null,
            $m['home_total_shots'] ?? null,
            $m['away_total_shots'] ?? null,
            $m['home_blocked_shots'] ?? null,
            $m['away_blocked_shots'] ?? null,
            $m['home_shots_inside_box'] ?? null,
            $m['away_shots_inside_box'] ?? null,
            $m['home_shots_outside_box'] ?? null,
            $m['away_shots_outside_box'] ?? null,
            $m['home_ball_possession'] ?? null,
            $m['away_ball_possession'] ?? null,
            $m['home_corner_kicks'] ?? null,
            $m['away_corner_kicks'] ?? null,
            null, // offsides not in sofascore
            null,
            $m['home_free_kicks'] ?? null,
            $m['away_free_kicks'] ?? null,
            $m['home_fouls'] ?? null,
            $m['away_fouls'] ?? null,
            $m['home_yellow_cards'] ?? null,
            $m['away_yellow_cards'] ?? null,
            null, // red cards not in sofascore match overview
            null,
            $m['home_goalkeeper_saves'] ?? null,
            $m['away_goalkeeper_saves'] ?? null,
            $homePassTotal,
            $awayPassTotal,
            $homePassAccurate,
            $awayPassAccurate,
            $homePassAccuracy,
            $awayPassAccuracy,
            $m['home_expected_goals'] ?? null,
            $m['away_expected_goals'] ?? null,
            $m['home_goals_prevented'] ?? null,
            $m['away_goals_prevented'] ?? null,
            $m['raw_statistics'] ?? null,
            $rawFixture,
        ]);
        $collected++;
        rLog("  OK: $homeName $m[home_score]-$m[away_score] $awayName ($leagueName)");
    } catch (Exception $e) {
        $errors++;
        rLog("  ERROR: $homeName vs $awayName: " . $e->getMessage());
    }
}

rLog("=== Done: $collected stored, $skipped skipped, $errors errors ===");

header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'date' => $date,
    'source' => $source,
    'received' => count($matches),
    'stored' => $collected,
    'skipped' => $skipped,
    'errors' => $errors,
]);
