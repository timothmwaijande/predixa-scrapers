<?php
$secretKey = 'pred_f3cc603ea4f0e1a6038171dba59f41b601c0f815d1c1d580';
$providedKey = (PHP_SAPI === 'cli' ? ($argv[1] ?? '') : ($_GET['key'] ?? ''));
if ($providedKey !== $secretKey) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Invalid key']));
}
require_once __DIR__ . '/../config.php';
$db = getDB();
if (!$db) { die(json_encode(['status' => 'error', 'message' => 'DB failed'])); }

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['matches'])) {
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'Invalid JSON body — expected { matches: [...] }']));
}

$db->exec("CREATE TABLE IF NOT EXISTS `match_results` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `home_team` VARCHAR(255) NOT NULL,
    `away_team` VARCHAR(255) NOT NULL,
    `home_score` INT DEFAULT NULL,
    `away_score` INT DEFAULT NULL,
    `match_date` DATE NOT NULL,
    `league` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `first_seen_at` DATETIME DEFAULT NULL,
    INDEX `idx_match_date` (`match_date`),
    INDEX `idx_home_team` (`home_team`),
    INDEX `idx_away_team` (`away_team`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add column if missing (existing DB)
try { $db->exec("ALTER TABLE match_results ADD COLUMN first_seen_at DATETIME DEFAULT NULL AFTER created_at"); } catch (PDOException $e) {}

$inserted = 0;
$skipped = 0;

function normalizeTeam($name) {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    // Strip generic club prefixes/suffixes
    $name = preg_replace('/^(FC|CF|AC|SC|RC|SS|CD|AS|SK|FK|NK|UD|CD|CA|CR|EC|AA|AE|SSC|IF|IFK|BK|FF|PFC|HNK|FK|US|SV|VfL|1\. FC|CS|GD|SC|NK|HNK)\s+/i', '', $name);
    $name = preg_replace('/\s+(FC|CF|AC|SC|RC|SS|CD|AS|SK|FK|NK|UD|CD|CA|CR|EC|AA|AE|SSC|IF|IFK|BK|FF|II|W|Res\.|U\d+)$/i', '', $name);
    return trim(mb_strtolower($name));
}

// Ensure team_id columns exist
try { $db->exec("ALTER TABLE match_results ADD COLUMN home_team_id INT DEFAULT NULL AFTER home_team"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE match_results ADD COLUMN away_team_id INT DEFAULT NULL AFTER away_team"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE match_results ADD INDEX idx_home_team_id (home_team_id)"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE match_results ADD INDEX idx_away_team_id (away_team_id)"); } catch (Exception $e) {}
try { $db->exec("CREATE TABLE IF NOT EXISTS `teams` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `normalized_name` VARCHAR(255) NOT NULL,
    `aliases` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_teams_normalized` (`normalized_name`(100)),
    INDEX `idx_teams_name` (`name`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

function resolveTeamId($db, $teamName, $normalizedName) {
    $stmt = $db->prepare("SELECT id FROM teams WHERE normalized_name = ? LIMIT 1");
    $stmt->execute([$normalizedName]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        $db->prepare("INSERT IGNORE INTO teams (name, normalized_name) VALUES (?, ?)")->execute([$teamName, $normalizedName]);
        $id = $db->lastInsertId();
    }
    return $id;
}

$checkStmt = $db->prepare("SELECT id, home_score, away_score, first_seen_at, home_team_id, away_team_id, league FROM match_results WHERE home_team = ? AND away_team = ? AND match_date = ?");
$insertStmt = $db->prepare("INSERT INTO match_results (home_team, away_team, home_score, away_score, match_date, league, first_seen_at, home_team_id, away_team_id) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)");
$updateStmt = $db->prepare("UPDATE match_results SET home_score = ?, away_score = ?, league = COALESCE(NULLIF(?, ''), league) WHERE id = ?");
$updateTeamIds = $db->prepare("UPDATE match_results SET home_team_id = ?, away_team_id = ? WHERE id = ?");

foreach ($input['matches'] as $m) {
    $home = normalizeTeam($m['home_team'] ?? '');
    $away = normalizeTeam($m['away_team'] ?? '');
    $hs = (int)($m['home_score'] ?? 0);
    $as = (int)($m['away_score'] ?? 0);
    $league = trim($m['league'] ?? '');
    if (empty($home) || empty($away)) { $skipped++; continue; }

    $matchDate = trim($m['match_date'] ?? '');
    if (empty($matchDate)) { $matchDate = date('Y-m-d'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $matchDate)) { $matchDate = date('Y-m-d'); }

    $checkStmt->execute([$home, $away, $matchDate]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        $needsUpdate = false;
        if ((int)$existing['home_score'] !== $hs || (int)$existing['away_score'] !== $as) { $needsUpdate = true; }
        $updatingLeague = $league && (!$existing['league'] || $existing['league'] !== $league);
        if ($needsUpdate || $updatingLeague) {
            $updateStmt->execute([$hs, $as, $updatingLeague ? $league : '', $existing['id']]);
            $inserted++;
        } else {
            $skipped++;
        }
        if (!$existing['home_team_id'] || !$existing['away_team_id']) {
            $hid = resolveTeamId($db, trim($m['home_team']), $home);
            $aid = resolveTeamId($db, trim($m['away_team']), $away);
            $updateTeamIds->execute([$hid, $aid, $existing['id']]);
        }
    } else {
        $hid = resolveTeamId($db, trim($m['home_team']), $home);
        $aid = resolveTeamId($db, trim($m['away_team']), $away);
        $insertStmt->execute([$home, $away, $hs, $as, $matchDate, $league ?: null, $hid, $aid]);
        $inserted++;
    }
}

echo json_encode(['status' => 'ok', 'inserted' => $inserted, 'skipped' => $skipped]);
