<?php
require_once __DIR__ . '/../config.php';

$API_KEY = STATS_API_KEY;
$API_BASE = 'https://v3.football.api-sports.io';
$RATE_LIMIT_SEC = 65;

$opts = getopt('', ['date:', 'test', 'limit:', 'help']);
// Allow web include to pass params via globals
if (!empty($GLOBALS['_collector_opts'])) {
    $opts = $GLOBALS['_collector_opts'];
}
if (isset($opts['help'])) {
    echo "Usage: php collect_match_stats.php [--date YYYY-MM-DD] [--test] [--limit N]\n";
    echo "  --date   Collect stats for a specific date (default: yesterday)\n";
    echo "  --test   Test mode: shorter sleep\n";
    echo "  --limit  Max fixtures to check (default: all)\n";
    exit(0);
}

$date = $opts['date'] ?? date('Y-m-d', strtotime('-1 day'));
$isTest = isset($opts['test']);
$sleepSec = 0;
$maxFixtures = isset($opts['limit']) ? (int)$opts['limit'] : 9999;

$logFile = __DIR__ . '/../logs/stats_collector_' . $date . '.log';
if (!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0755, true);

$TOP_LEAGUE_IDS = [
    39,   // Premier League (England)
    140,  // La Liga (Spain)
    135,  // Serie A (Italy)
    78,   // Bundesliga (Germany)
    61,   // Ligue 1 (France)
    88,   // Eredivisie (Netherlands)
    94,   // Primeira Liga (Portugal)
    40,   // Championship (England)
    71,   // Serie A (Brazil)
    253,  // MLS (USA)
    203,  // Turkish Super Lig
    179,  // Scottish Premiership
    144,  // Belgian Pro League
    262,  // Liga MX (Mexico)
    98,   // J1 League (Japan)
    292,  // K League 1 (South Korea)
    188,  // A-League (Australia)
    103,  // Eliteserien (Norway)
    113,  // Allsvenskan (Sweden)
    119,  // Danish Superliga
    106,  // Ekstraklasa (Poland)
    204,  // Swiss Super League
    283,  // Romanian Liga I
    163,  // Bulgarian First League
    196,  // Croatian HNL
    111,  // Czech First League
    207,  // Greek Super League
    307,  // Saudi Pro League
    244,  // Veikkausliiga (Finland)
    169,  // Argentine Primera Division
];

function apiLog($msg) {
    global $logFile;
    $line = date('[Y-m-d H:i:s] ') . $msg;
    echo $line . "\n";
    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
}

function apiGet($endpoint, $params = []) {
    global $API_KEY, $API_BASE;
    $url = $API_BASE . $endpoint . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['x-apisports-key: ' . $API_KEY],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    return json_decode($resp, true);
}

function extractStats($statistics) {
    $map = [];
    foreach ($statistics as $s) {
        $type = $s['type'] ?? '';
        $val = $s['value'];
        if ($val === '' || $val === null) continue;
        $map[$type] = $val;
    }
    return $map;
}

function parseStatInt($map, $key) {
    if (!isset($map[$key])) return null;
    $v = str_replace('%', '', trim($map[$key]));
    return is_numeric($v) ? (int)$v : null;
}

function parseStatStr($map, $key) {
    return isset($map[$key]) ? trim($map[$key]) : null;
}

function parseStatDec($map, $key) {
    if (!isset($map[$key])) return null;
    $v = trim($map[$key]);
    return is_numeric($v) ? (float)$v : null;
}

$db = getDB();
apiLog("=== Stats Collector started for $date (test=$isTest) ===");

apiLog("Fetching fixtures for $date...");
$fixturesResp = apiGet('/fixtures', ['date' => $date, 'status' => 'FT']);
if (!$fixturesResp || empty($fixturesResp['response'])) {
    apiLog("No fixtures found or API error. Response: " . json_encode($fixturesResp['errors'] ?? 'unknown'));
    exit(1);
}
$allFixtures = $fixturesResp['response'];
apiLog("Found " . count($allFixtures) . " finished fixtures");

usort($allFixtures, function($a, $b) use ($TOP_LEAGUE_IDS) {
    $aP = array_search($a['league']['id'] ?? 0, $TOP_LEAGUE_IDS);
    $bP = array_search($b['league']['id'] ?? 0, $TOP_LEAGUE_IDS);
    $aP = $aP === false ? 999 : $aP;
    $bP = $bP === false ? 999 : $bP;
    return $aP <=> $bP;
});

$existingStmt = $db->prepare("SELECT id FROM match_statistics WHERE api_fixture_id = ?");

try { $db->exec("ALTER TABLE match_statistics ADD COLUMN home_free_kicks INT DEFAULT NULL AFTER away_offsides, ADD COLUMN away_free_kicks INT DEFAULT NULL AFTER home_free_kicks"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE match_statistics ADD COLUMN home_team_id INT DEFAULT NULL AFTER away_team_api, ADD COLUMN away_team_id INT DEFAULT NULL AFTER home_team_id"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE match_statistics ADD INDEX idx_home_team_id (home_team_id), ADD INDEX idx_away_team_id (away_team_id)"); } catch (Exception $e) {}

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

$insertStmt = $db->prepare("INSERT INTO match_statistics
    (api_fixture_id, match_date, league_name, league_id_api, home_team_api, away_team_api, home_team_id, away_team_id,
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
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
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
     referee=VALUES(referee), venue=VALUES(venue),
     raw_statistics=VALUES(raw_statistics), raw_fixture=VALUES(raw_fixture)
");

$maxDailyRequests = 99;
$collected = 0;
$skipped = 0;
$errors = 0;
$noStats = 0;
$requestCount = 0;
$checked = 0;

foreach ($allFixtures as $f) {
    if ($requestCount >= $maxDailyRequests) { apiLog("Daily API quota limit reached ($maxDailyRequests)"); break; }
    $fid = $f['fixture']['id'];
    $homeName = $f['teams']['home']['name'] ?? '';
    $awayName = $f['teams']['away']['name'] ?? '';
    $leagueName = $f['league']['name'] ?? '';
    $leagueCountry = $f['league']['country'] ?? '';
    if ($leagueCountry && !str_contains($leagueName, $leagueCountry)) {
        $leagueName .= ' (' . $leagueCountry . ')';
    }
    $leagueIdApi = $f['league']['id'] ?? null;
    $homeScore = $f['goals']['home'] ?? null;
    $awayScore = $f['goals']['away'] ?? null;

    $checked++;
    if ($checked > $maxFixtures) { apiLog("Reached limit of $maxFixtures fixtures checked"); break; }

    $existingStmt->execute([$fid]);
    if ($existingStmt->fetch()) {
        $skipped++;
        continue;
    }

    if ($isTest && $collected >= 5) break;

    apiLog("[$fid] $homeName $homeScore-$awayScore $awayName ($leagueName)");

    $statsResp = apiGet('/fixtures/statistics', ['fixture' => $fid]);
    $requestCount++;
    if (!$statsResp || empty($statsResp['response'])) {
        apiLog("  No statistics available");
        $noStats++;
        continue;
    }

    $homeStats = [];
    $awayStats = [];
    foreach ($statsResp['response'] as $teamBlock) {
        $extracted = extractStats($teamBlock['statistics'] ?? []);
        if (($teamBlock['team']['id'] ?? 0) == ($f['teams']['home']['id'] ?? -1)) {
            $homeStats = $extracted;
        } else {
            $awayStats = $extracted;
        }
    }

    $referee = $f['fixture']['referee'] ?? null;
    $venue = $f['fixture']['venue']['name'] ?? null;

    $homeTeamId = resolveTeamIdForStats($db, $homeName);
    $awayTeamId = resolveTeamIdForStats($db, $awayName);

    try {
        $insertStmt->execute([
            $fid, $date, $leagueName, $leagueIdApi, $homeName, $awayName, $homeTeamId, $awayTeamId,
            $homeScore, $awayScore, $referee, $venue,
            parseStatInt($homeStats, 'Shots on Goal'), parseStatInt($awayStats, 'Shots on Goal'),
            parseStatInt($homeStats, 'Shots off Goal'), parseStatInt($awayStats, 'Shots off Goal'),
            parseStatInt($homeStats, 'Total Shots'), parseStatInt($awayStats, 'Total Shots'),
            parseStatInt($homeStats, 'Blocked Shots'), parseStatInt($awayStats, 'Blocked Shots'),
            parseStatInt($homeStats, 'Shots insidebox'), parseStatInt($awayStats, 'Shots insidebox'),
            parseStatInt($homeStats, 'Shots outsidebox'), parseStatInt($awayStats, 'Shots outsidebox'),
            parseStatStr($homeStats, 'Ball Possession'), parseStatStr($awayStats, 'Ball Possession'),
            parseStatInt($homeStats, 'Corner Kicks'), parseStatInt($awayStats, 'Corner Kicks'),
            parseStatInt($homeStats, 'Offsides'), parseStatInt($awayStats, 'Offsides'),
            parseStatInt($homeStats, 'Free Kicks'), parseStatInt($awayStats, 'Free Kicks'),
            parseStatInt($homeStats, 'Fouls'), parseStatInt($awayStats, 'Fouls'),
            parseStatInt($homeStats, 'Yellow Cards'), parseStatInt($awayStats, 'Yellow Cards'),
            parseStatInt($homeStats, 'Red Cards'), parseStatInt($awayStats, 'Red Cards'),
            parseStatInt($homeStats, 'Goalkeeper Saves'), parseStatInt($awayStats, 'Goalkeeper Saves'),
            parseStatInt($homeStats, 'Total passes'), parseStatInt($awayStats, 'Total passes'),
            parseStatInt($homeStats, 'Passes accurate'), parseStatInt($awayStats, 'Passes accurate'),
            parseStatStr($homeStats, 'Passes %'), parseStatStr($awayStats, 'Passes %'),
            parseStatDec($homeStats, 'expected_goals'), parseStatDec($awayStats, 'expected_goals'),
            parseStatDec($homeStats, 'goals_prevented'), parseStatDec($awayStats, 'goals_prevented'),
            json_encode($statsResp['response']),
            json_encode($f),
        ]);
        $collected++;
        apiLog("  Stored OK");
    } catch (Exception $e) {
        $errors++;
        apiLog("  ERROR: " . $e->getMessage());
    }
}

apiLog("=== Done: checked=$checked, collected=$collected, skipped=$skipped, no_stats=$noStats, errors=$errors, requests=$requestCount ===");
echo json_encode(['checked' => $checked, 'collected' => $collected, 'skipped' => $skipped, 'no_stats' => $noStats, 'errors' => $errors, 'requests' => $requestCount]) . "\n";
