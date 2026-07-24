<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/BayesianModel.php';

$db = getDB();
if (!$db) { echo json_encode(['error' => 'DB connection failed']); exit; }

$teamA = trim($_GET['team_a'] ?? '');
$teamB = trim($_GET['team_b'] ?? '');

if (!$teamA || !$teamB) {
    echo json_encode(['error' => 'team_a and team_b are required']);
    exit;
}

$model = new BayesianModel();

function resolveId($db, $model, $name) {
    $id = $model->resolveTeamId($name);
    if ($id) return $id;
    $stmt = $db->prepare("SELECT id FROM teams WHERE name = ? OR normalized_name = ? LIMIT 1");
    $stmt->execute([$name, strtolower(trim($name))]);
    $r = $stmt->fetch();
    return $r ? (int)$r['id'] : null;
}

function resolveName($db, $model, $name) {
    $resolved = $model->resolveTeamName($name);
    return $resolved ?: $name;
}

$idA = resolveId($db, $model, $teamA);
$idB = resolveId($db, $model, $teamB);
$nameA = resolveName($db, $model, $teamA);
$nameB = resolveName($db, $model, $teamB);

$meetings = [];
$summary = ['total' => 0, 'teamA_wins' => 0, 'draws' => 0, 'teamB_wins' => 0, 'goals' => 0, 'btts' => 0];
$homeSplit = ['total' => 0, 'teamA_wins' => 0, 'draws' => 0, 'teamB_wins' => 0];
$awaySplit = ['total' => 0, 'teamA_wins' => 0, 'draws' => 0, 'teamB_wins' => 0];
$formA = [];
$formB = [];
$homeStatsSum = [];
$awayStatsSum = [];

if ($idA && $idB) {
    $stmt = $db->prepare("
        SELECT mr.id, mr.match_date, mr.home_team, mr.away_team, mr.home_team_id, mr.away_team_id,
               mr.home_score, mr.away_score, mr.league,
               ms.home_shots_on_goal, ms.away_shots_on_goal,
               ms.home_total_shots, ms.away_total_shots,
               ms.home_ball_possession, ms.away_ball_possession,
               ms.home_corner_kicks, ms.away_corner_kicks,
               ms.home_fouls, ms.away_fouls,
               ms.home_yellow_cards, ms.away_yellow_cards,
               ms.home_red_cards, ms.away_red_cards,
               ms.home_goalkeeper_saves, ms.away_goalkeeper_saves,
               ms.home_total_passes, ms.away_total_passes,
               ms.home_passes_accurate, ms.away_passes_accurate,
               ms.home_expected_goals, ms.away_expected_goals,
               ms.home_goals_prevented, ms.away_goals_prevented,
               ms.referee, ms.venue
        FROM match_results mr
        LEFT JOIN match_statistics ms ON (
            (ms.home_team_id IS NOT NULL AND mr.home_team_id IS NOT NULL
             AND ms.home_team_id = mr.home_team_id AND ms.away_team_id = mr.away_team_id)
            OR (ms.home_team_id IS NOT NULL AND mr.home_team_id IS NOT NULL
             AND ms.home_team_id = mr.away_team_id AND ms.away_team_id = mr.home_team_id)
            OR (ms.match_date = mr.match_date
             AND ((ms.home_team_api = mr.home_team AND ms.away_team_api = mr.away_team)
                  OR (ms.home_team_api = mr.away_team AND ms.away_team_api = mr.home_team)))
        )
        WHERE ((mr.home_team_id = ? AND mr.away_team_id = ?) OR (mr.home_team_id = ? AND mr.away_team_id = ?))
          AND mr.home_score IS NOT NULL AND mr.away_score IS NOT NULL
        ORDER BY mr.match_date DESC
        LIMIT 100
    ");
    $stmt->execute([$idA, $idB, $idB, $idA]);
    $rows = $stmt->fetchAll();

    $seen = [];
    foreach ($rows as $r) {
        $key = $r['match_date'] . '|' . $r['home_score'] . '-' . $r['away_score'] . '|' . $r['league'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $hs = (int)$r['home_score'];
        $as = (int)$r['away_score'];
        $isHomeA = ((int)$r['home_team_id'] === $idA);

        $summary['total']++;
        $summary['goals'] += $hs + $as;
        if ($hs > 0 && $as > 0) $summary['btts']++;

        if ($hs > $as) {
            if ($isHomeA) $summary['teamA_wins']++; else $summary['teamB_wins']++;
        } elseif ($hs === $as) {
            $summary['draws']++;
        } else {
            if ($isHomeA) $summary['teamB_wins']++; else $summary['teamA_wins']++;
        }

        $split = $isHomeA ? $homeSplit : $awaySplit;
        $split['total']++;
        if ($hs > $as) {
            if ($isHomeA) $split['teamA_wins']++; else $split['teamB_wins']++;
        } elseif ($hs === $as) {
            $split['draws']++;
        } else {
            if ($isHomeA) $split['teamB_wins']++; else $split['teamA_wins']++;
        }
        if ($isHomeA) $homeSplit = $split; else $awaySplit = $split;

        $meeting = [
            'date' => $r['match_date'],
            'home_team' => $r['home_team'],
            'away_team' => $r['away_team'],
            'home_score' => $hs,
            'away_score' => $as,
            'league' => $r['league'],
            'is_home_a' => $isHomeA,
            'result' => ($hs > $as) ? ($isHomeA ? 'A' : 'B') : (($hs === $as) ? 'D' : ($isHomeA ? 'B' : 'A')),
            'has_stats' => ($r['home_shots_on_goal'] !== null),
        ];

        if ($meeting['has_stats']) {
            $meeting['stats'] = [
                'xg' => [$r['home_expected_goals'], $r['away_expected_goals']],
                'shots_on' => [$r['home_shots_on_goal'], $r['away_shots_on_goal']],
                'shots_total' => [$r['home_total_shots'], $r['away_total_shots']],
                'possession' => [$r['home_ball_possession'], $r['away_ball_possession']],
                'corners' => [$r['home_corner_kicks'], $r['away_corner_kicks']],
                'fouls' => [$r['home_fouls'], $r['away_fouls']],
                'cards' => [$r['home_yellow_cards'], $r['away_yellow_cards']],
                'saves' => [$r['home_goalkeeper_saves'], $r['away_goalkeeper_saves']],
                'passes_accurate' => [$r['home_passes_accurate'], $r['away_passes_accurate']],
                'passes_total' => [$r['home_total_passes'], $r['away_total_passes']],
                'goals_prevented' => [$r['home_goals_prevented'], $r['away_goals_prevented']],
                'referee' => $r['referee'],
                'venue' => $r['venue'],
            ];
        }

        $meetings[] = $meeting;
    }
}

$recentLimit = 50;
if ($idA) {
    $stmt = $db->prepare("
        SELECT match_date, home_team, away_team, home_team_id, home_score, away_score, league
        FROM match_results
        WHERE (home_team_id = ? OR away_team_id = ?)
          AND home_score IS NOT NULL AND away_score IS NOT NULL
        ORDER BY match_date DESC LIMIT $recentLimit
    ");
    $stmt->execute([$idA, $idA]);
    $seenForm = [];
    foreach ($stmt->fetchAll() as $r) {
        $fkey = $r['match_date'] . '|' . $r['home_score'] . '-' . $r['away_score'];
        if (isset($seenForm[$fkey])) continue;
        $seenForm[$fkey] = true;
        $isH = ((int)$r['home_team_id'] === $idA);
        $gf = $isH ? (int)$r['home_score'] : (int)$r['away_score'];
        $ga = $isH ? (int)$r['away_score'] : (int)$r['home_score'];
        $res = $gf > $ga ? 'W' : ($gf === $ga ? 'D' : 'L');
        $formA[] = ['date' => $r['match_date'], 'result' => $res, 'score' => $gf . '-' . $ga, 'opponent' => $isH ? $r['away_team'] : $r['home_team'], 'league' => $r['league'], 'is_home' => $isH];
        if (count($formA) >= 10) break;
    }
}

if ($idB) {
    $stmt = $db->prepare("
        SELECT match_date, home_team, away_team, home_team_id, home_score, away_score, league
        FROM match_results
        WHERE (home_team_id = ? OR away_team_id = ?)
          AND home_score IS NOT NULL AND away_score IS NOT NULL
        ORDER BY match_date DESC LIMIT $recentLimit
    ");
    $stmt->execute([$idB, $idB]);
    $seenForm = [];
    foreach ($stmt->fetchAll() as $r) {
        $fkey = $r['match_date'] . '|' . $r['home_score'] . '-' . $r['away_score'];
        if (isset($seenForm[$fkey])) continue;
        $seenForm[$fkey] = true;
        $isH = ((int)$r['home_team_id'] === $idB);
        $gf = $isH ? (int)$r['home_score'] : (int)$r['away_score'];
        $ga = $isH ? (int)$r['away_score'] : (int)$r['home_score'];
        $res = $gf > $ga ? 'W' : ($gf === $ga ? 'D' : 'L');
        $formB[] = ['date' => $r['match_date'], 'result' => $res, 'score' => $gf . '-' . $ga, 'opponent' => $isH ? $r['away_team'] : $r['home_team'], 'league' => $r['league'], 'is_home' => $isH];
        if (count($formB) >= 10) break;
    }
}

$statsWithXg = array_filter($meetings, fn($m) => $m['has_stats'] && !empty($m['stats']['xg'][0]));
$statsCount = count($statsWithXg);
$avgXgA = 0;
$avgXgB = 0;
if ($statsCount > 0) {
    foreach ($statsWithXg as $m) {
        $isH = $m['is_home_a'];
        $avgXgA += $isH ? (float)$m['stats']['xg'][0] : (float)$m['stats']['xg'][1];
        $avgXgB += $isH ? (float)$m['stats']['xg'][1] : (float)$m['stats']['xg'][0];
    }
    $avgXgA /= $statsCount;
    $avgXgB /= $statsCount;
}

$over25 = 0;
$over15 = 0;
$under35 = 0;
foreach ($meetings as $m) {
    $total = $m['home_score'] + $m['away_score'];
    if ($total > 2.5) $over25++;
    if ($total > 1.5) $over15++;
    if ($total <= 3.5) $under35++;
}
$mt = max(1, $summary['total']);

echo json_encode([
    'team_a' => ['id' => $idA, 'name' => $nameA],
    'team_b' => ['id' => $idB, 'name' => $nameB],
    'summary' => [
        'total' => $summary['total'],
        'teamA_wins' => $summary['teamA_wins'],
        'draws' => $summary['draws'],
        'teamB_wins' => $summary['teamB_wins'],
        'avg_goals' => round($summary['goals'] / $mt, 2),
        'btts_rate' => round($summary['btts'] / $mt * 100, 1),
        'over_25' => round($over25 / $mt * 100, 1),
        'over_15' => round($over15 / $mt * 100, 1),
        'under_35' => round($under35 / $mt * 100, 1),
    ],
    'home_split' => $homeSplit,
    'away_split' => $awaySplit,
    'avg_xg' => $statsCount > 0 ? ['teamA' => round($avgXgA, 2), 'teamB' => round($avgXgB, 2), 'matches_with_stats' => $statsCount] : null,
    'meetings' => $meetings,
    'form_a' => $formA,
    'form_b' => $formB,
], JSON_PRETTY_PRINT);
