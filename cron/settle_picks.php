<?php
require_once __DIR__ . '/../config.php';
$secretKey = SETTLE_KEY;
$providedKey = (PHP_SAPI === 'cli' ? ($argv[1] ?? '') : ($_GET['key'] ?? ''));
if ($providedKey !== $secretKey) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid key']));
}
$db = getDB();
if (!$db) { die(json_encode(['status' => 'error', 'message' => 'DB failed'])); }

$today = date('Y-m-d');
$lookback = date('Y-m-d', strtotime($today . ' -7 days'));  // was -3 days
$AFP_OFFSET = 1000000000;
$settled = 0;
$failed = 0;
$skipped = 0;

// Remove stale placeholder settlements (0-0) from today so picks get re-processed with real scores
$db->exec("DELETE FROM pick_settlements WHERE settlement_date = '$today' AND home_score = 0 AND away_score = 0");

// === 1. Settle web_picks (odds-signals + manual picks) ===
$stmt = $db->prepare("
    SELECT wp.* FROM web_picks wp
    LEFT JOIN pick_settlements ps ON wp.id = ps.web_pick_id AND ps.settlement_date = ? AND ps.result != 'pending'
    WHERE ps.id IS NULL
    AND DATE(wp.detected_at) >= ?
    AND wp.pick_type IN ('rollover','parlay','over_15')
    ORDER BY wp.id DESC
");
$stmt->execute([$today, $lookback]);
$picks = $stmt->fetchAll();

foreach ($picks as $pick) {
    $result = settleOnePick($db, $pick, $today);
    if ($result === false) $failed++;
    elseif ($result === null) $skipped++;
    else $settled++;
}

// === 2. Settle admin_featured_picks (scraper intersections) ===
$stmt2 = $db->prepare("
    SELECT afp.*, (? + afp.id) AS ps_web_pick_id FROM admin_featured_picks afp
    LEFT JOIN pick_settlements ps ON (? + afp.id) = ps.web_pick_id AND ps.settlement_date = ? AND ps.result != 'pending'
    WHERE ps.id IS NULL AND DATE(afp.created_at) >= ?
    ORDER BY afp.id DESC
");
$stmt2->execute([$AFP_OFFSET, $AFP_OFFSET, $today, $lookback]);
$afpPicks = $stmt2->fetchAll();

foreach ($afpPicks as $pick) {
    $pick['id'] = (int)$pick['ps_web_pick_id'];
    $result = settleOnePick($db, $pick, $today);
    if ($result === false) $failed++;
    elseif ($result === null) $skipped++;
    else $settled++;
}

echo json_encode(['status' => 'ok', 'settled' => $settled, 'failed' => $failed, 'skipped' => $skipped, 'date' => $today]);

// ========== Helper Functions ==========

function settleOnePick($db, $pick, $today) {
    $matchName = $pick['match_name'] ?? '';
    $pickValue = trim($pick['pick_value'] ?? '');
    $pickType = $pick['pick_type'] ?? '';
    $odds = $pick['odds'] ?? null;
    $pickId = (int)$pick['id'];

    if (empty($pickValue) || empty($matchName)) return null;
    if (stripos($pickValue, 'Most Corners') !== false) return null;

    // Defensive: skip if this pick already has a non-pending settlement (protects manual fixes)
    $already = $db->prepare("SELECT id FROM pick_settlements WHERE web_pick_id = ? AND settlement_date = ? AND result != 'pending' LIMIT 1");
    $already->execute([$pickId, $today]);
    if ($already->fetch()) { echo "  Skipping $matchName: already settled (manual override preserved)\n"; return null; }

    $parts = explode(' vs ', $matchName);
    if (count($parts) !== 2) $parts = explode(' VS ', $matchName);
    if (count($parts) !== 2) {
        echo "  Cannot parse match_name: $matchName\n";
        return false;
    }

    $homeTeam = normalizePickTeam($parts[0]);
    $awayTeam = normalizePickTeam($parts[1]);

    // Use pick's detected_at date as anchor (not today) so older picks can find their match
    $pickDate = date('Y-m-d', strtotime($pick['detected_at'] ?? $today));
    $result = findMatchResult($db, $homeTeam, $awayTeam, $pickDate);
    if (!$result) {
        // Fallback: also try today in case the match date differs from detection date
        $result = findMatchResult($db, $homeTeam, $awayTeam, $today);
    }
    if (!$result) {
        echo "  No result found for: $matchName\n";
        return false;
    }

    $hs = (int)$result['home_score'];
    $as = (int)$result['away_score'];
    $total = $hs + $as;
    $won = determinePickResult($pickValue, $hs, $as, $total, $pick);
    $outcome = $won === true ? 'won' : ($won === false ? 'lost' : 'void');

    // Upsert into pick_settlements
    $db->prepare("INSERT INTO pick_settlements (web_pick_id, match_name, pick_value, odds, home_score, away_score, result, settlement_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE home_score = VALUES(home_score), away_score = VALUES(away_score), result = VALUES(result)")
        ->execute([$pickId, $matchName, $pickValue, $odds, $hs, $as, $outcome, $today]);

    echo "  $matchName: $pickValue = $hs-$as -> $outcome\n";
    return true;
}

function resolveSettleTeamId($db, $name) {
    if (!$name) return null;
    $stmt = $db->prepare("SELECT id FROM teams WHERE name = ? LIMIT 1");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $stmt = $db->prepare("SELECT COALESCE(home_team_id, away_team_id) FROM match_results WHERE home_team = ? OR away_team = ? LIMIT 1");
    $stmt->execute([$name, $name]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;
    $stmt = $db->query("SELECT id, aliases FROM teams WHERE aliases IS NOT NULL AND aliases != '[]' AND aliases != 'null'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $aliases = json_decode($r['aliases'], true);
        if (is_array($aliases)) {
            foreach ($aliases as $a) {
                if (mb_strtolower(trim($a)) === mb_strtolower(trim($name))) return (int)$r['id'];
            }
        }
    }
    $stmt = $db->prepare("SELECT id FROM teams WHERE name LIKE ? ORDER BY LENGTH(name) ASC LIMIT 1");
    $stmt->execute(['%' . $name . '%']);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function findMatchResult($db, $homeTeam, $awayTeam, $anchorDate) {
    $yesterday = date('Y-m-d', strtotime($anchorDate . ' -1 day'));
    $twoDaysAgo = date('Y-m-d', strtotime($anchorDate . ' -2 days'));
    $tomorrow = date('Y-m-d', strtotime($anchorDate . ' +1 day'));
    $dates = array_unique([$anchorDate, $yesterday, $tomorrow, $twoDaysAgo]);

    $fallback = null;

    // Phase 1: Try by team_id (most reliable)
    $homeId = resolveSettleTeamId($db, $homeTeam);
    $awayId = resolveSettleTeamId($db, $awayTeam);
    if ($homeId && $awayId) {
        foreach ($dates as $d) {
            $stmt = $db->prepare("SELECT home_score, away_score, home_team, away_team FROM match_results WHERE ((home_team_id = ? AND away_team_id = ?) OR (home_team_id = ? AND away_team_id = ?)) AND match_date = ? LIMIT 1");
            $stmt->execute([$homeId, $awayId, $awayId, $homeId, $d]);
            $result = $stmt->fetch();
            if ($result) {
                if ((int)$result['home_score'] > 0 || (int)$result['away_score'] > 0) return $result;
                if (!$fallback) $fallback = $result;
            }
        }
    }

    // Phase 2: Try by exact name match
    foreach ($dates as $d) {
        $stmt = $db->prepare("SELECT home_score, away_score, home_team, away_team FROM match_results WHERE home_team = ? AND away_team = ? AND match_date = ? LIMIT 1");
        $stmt->execute([$homeTeam, $awayTeam, $d]);
        $result = $stmt->fetch();
        if ($result) {
            if ((int)$result['home_score'] > 0 || (int)$result['away_score'] > 0) return $result;
            if (!$fallback) $fallback = $result;
        }

        $stmt->execute([$awayTeam, $homeTeam, $d]);
        $result = $stmt->fetch();
        if ($result) {
            $swapped = ['home_score' => $result['away_score'], 'away_score' => $result['home_score'], 'home_team' => $result['home_team'], 'away_team' => $result['away_team']];
            if ((int)$swapped['home_score'] > 0 || (int)$swapped['away_score'] > 0) return $swapped;
            if (!$fallback) $fallback = $swapped;
        }
    }

    // Phase 3: Fuzzy match — prefer non-zero scores
    foreach ($dates as $d) {
        $stmt = $db->prepare("SELECT home_score, away_score, home_team, away_team FROM match_results WHERE match_date = ? ORDER BY id DESC");
        $stmt->execute([$d]);
        foreach ($stmt->fetchAll() as $m) {
            if (teamFuzzyMatch($homeTeam, $m['home_team']) && teamFuzzyMatch($awayTeam, $m['away_team'])) {
                if ((int)$m['home_score'] > 0 || (int)$m['away_score'] > 0) return $m;
                if (!$fallback) $fallback = $m;
            }
            if (teamFuzzyMatch($homeTeam, $m['away_team']) && teamFuzzyMatch($awayTeam, $m['home_team'])) {
                $swapped = ['home_score' => $m['away_score'], 'away_score' => $m['home_score'], 'home_team' => $m['home_team'], 'away_team' => $m['away_team']];
                if ((int)$swapped['home_score'] > 0 || (int)$swapped['away_score'] > 0) return $swapped;
                if (!$fallback) $fallback = $swapped;
            }
        }
    }
    return $fallback;
}

function normalizePickTeam($name) {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    // Strip common club prefixes (but NOT identity names like Real, Atletico, Club)
    $name = preg_replace('/^(FC|CF|AC|SC|RC|SS|CD|AS|SK|FK|NK|UD|CA|CR|EC|AA|AE|SSC|IF|IFK|BK|FF|AIK|BSC|VfL|1\. FC|US|SV|TS|TV|CS|PK|KK|NK|HNK|FK|PFC|NS|SC|GD)\s+/i', '', $name);
    $name = preg_replace('/\s+(FC|CF|AC|SC|RC|SS|CD|AS|SK|FK|NK|UD|CA|CR|EC|AA|AE|SSC|IF|IFK|BK|FF|II|W|B|Res\.|U\d+)$/i', '', $name);
    return trim(mb_strtolower($name));
}

function teamFuzzyMatch($a, $b) {
    if ($a === $b) return true;
    if (strpos($a, $b) !== false || strpos($b, $a) !== false) return true;

    // Strip common suffixes like "fk", "fc", "cf" etc
    $strip = ['fc', 'cf', 'ac', 'sc', 'rc', 'ss', 'cd', 'as', 'sk', 'fk', 'nk', 'ud', 'ca', 'cr', 'ec', 'aa', 'ae', 'ssc', 'if', 'ifk', 'bk', 'ff', 'ii', 'u21', 'u23', 'res', 'b'];
    $aClean = trim(preg_replace('/\s+(' . implode('|', $strip) . ')$/i', '', $a));
    $bClean = trim(preg_replace('/\s+(' . implode('|', $strip) . ')$/i', '', $b));
    if ($aClean === $bClean) return true;
    if (strpos($aClean, $bClean) !== false || strpos($bClean, $aClean) !== false) return true;

    // Also strip prefixes
    $aClean2 = trim(preg_replace('/^(' . implode('|', $strip) . ')\s+/i', '', $aClean));
    $bClean2 = trim(preg_replace('/^(' . implode('|', $strip) . ')\s+/i', '', $bClean));
    if ($aClean2 === $bClean2) return true;
    if ($aClean2 !== '' && $bClean2 !== '' && (strpos($aClean2, $bClean2) !== false || strpos($bClean2, $aClean2) !== false)) return true;

    // Word overlap
    $aWords = preg_split('/\s+/', $aClean2);
    $bWords = preg_split('/\s+/', $bClean2);
    $common = array_intersect($aWords, $bWords);
    $min = min(count($aWords), count($bWords));
    if ($min > 0 && count($common) >= $min * 0.5) return true;

    // Single-word teams: check if one is a substring of the other
    if (count($aWords) === 1 || count($bWords) === 1) {
        $longer = count($aWords) >= count($bWords) ? $aClean2 : $bClean2;
        $shorter = count($aWords) < count($bWords) ? $aClean2 : $bClean2;
        if (strlen($shorter) >= 3 && strpos($longer, $shorter) !== false) return true;
    }

    return false;
}

function determinePickResult($pickValue, $homeScore, $awayScore, $totalGoals, $pick = null) {
    $pv = strtoupper(trim($pickValue));

    // Normalize: extract settlement code from human-readable formats
    // e.g. "Home(1)" → "1", "Home or Draw (1X)" → "1X"
    if (preg_match('/\(([^)]+)\)/', $pv, $m)) {
        $pv = strtoupper(trim($m[1]));
    }
    // "Team Win" → normalize to "... Win" pattern handled below
    // Strip leading text before known patterns
    $pv = preg_replace('/^(HOME|AWAY|DRAW)\s*/i', '', $pv);
    $pv = preg_replace('/^(HOME|AWAY)\s+(OR\s+)?/i', '', $pv);
    $pv = trim($pv);
    if ($pv === '1X' || $pv === 'DC 1X' || $pv === 'DC1X') return $homeScore >= $awayScore;
    if ($pv === 'X2' || $pv === 'DC X2' || $pv === 'DCX2') return $awayScore >= $homeScore;
    if ($pv === '12' || $pv === 'DC 12' || $pv === 'DC12') return $homeScore !== $awayScore;
    if ($pv === '1') return $homeScore > $awayScore;
    if ($pv === '2') return $awayScore > $homeScore;
    if ($pv === 'X' || $pv === 'DRAW') return $homeScore === $awayScore;
    // Normalized market names from track-record.php
    if ($pv === 'HOME WIN' || $pv === 'HOMEWIN') return $homeScore > $awayScore;
    if ($pv === 'AWAY WIN' || $pv === 'AWAYWIN') return $awayScore > $homeScore;

    if (preg_match('/^OVER\s+(\d+\.?\d*)\s*GOALS?$/i', $pv, $m)) return $totalGoals > (float)$m[1];
    if (preg_match('/^UNDER\s+(\d+\.?\d*)\s*GOALS?$/i', $pv, $m)) return $totalGoals < (float)$m[1];

    if (stripos($pv, 'OVER 1.5') !== false) return $totalGoals > 1.5;
    if (stripos($pv, 'OVER 2.5') !== false) return $totalGoals > 2.5;
    if (stripos($pv, 'OVER 3.5') !== false) return $totalGoals > 3.5;
    if (stripos($pv, 'UNDER 3.5') !== false) return $totalGoals < 3.5;
    if (stripos($pv, 'UNDER 2.5') !== false) return $totalGoals < 2.5;
    if (stripos($pv, 'UNDER 1.5') !== false) return $totalGoals < 1.5;

    if ($pv === 'BTS' || $pv === 'GG' || $pv === 'BOTH TEAMS TO SCORE') return $homeScore > 0 && $awayScore > 0;
    if (stripos($pv, 'BTS') !== false || stripos($pv, 'GG') !== false) return $homeScore > 0 && $awayScore > 0;

    // Handle "Team Name Win" (Win 1UP picks)
    if (preg_match('/^(.+?)\s+Win$/i', $pv, $m)) {
        $winTeam = trim($m[1]);
        if ($pick && ($pick['is_home_fav'] ?? false)) {
            return $homeScore > $awayScore;
        }
        // Parse match_name to determine if winTeam is home or away
        $matchName = $pick['match_name'] ?? '';
        $parts = explode(' vs ', $matchName);
        if (count($parts) !== 2) $parts = explode(' VS ', $matchName);
        if (count($parts) === 2) {
            $homeName = strtolower(trim($parts[0]));
            $awayName = strtolower(trim($parts[1]));
            $wt = strtolower($winTeam);
            $favTeam = strtolower(trim($pick['fav_team'] ?? ''));
            $oppTeam = strtolower(trim($pick['opp_team'] ?? ''));
            if ($favTeam && $oppTeam) {
                if ($favTeam === $wt) return $homeScore > $awayScore;
                if ($oppTeam === $wt) return $awayScore > $homeScore;
            }
            if (strpos($homeName, $wt) !== false || strpos($wt, $homeName) !== false) return $homeScore > $awayScore;
            if (strpos($awayName, $wt) !== false || strpos($wt, $awayName) !== false) return $awayScore > $homeScore;
        }
        return null;
    }

    return null;
}
