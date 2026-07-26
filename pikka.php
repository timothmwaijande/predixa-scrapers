<?php
session_start();
require_once 'config.php';
$db = getDB();
if (!$db) { header("HTTP/1.0 500"); exit; }
logPageVisit('pikka.php');

// Ensure status and match_date columns exist
try { $db->exec("ALTER TABLE tipster_picks ADD COLUMN status ENUM('pending','won','lost') DEFAULT 'pending' AFTER odds"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE tipster_picks ADD COLUMN match_date DATE DEFAULT NULL AFTER league"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE tipster_picks ADD COLUMN is_pinned TINYINT(1) DEFAULT 0 AFTER status"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE tipster_picks ADD COLUMN body TEXT DEFAULT NULL AFTER odds"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE tipster_picks MODIFY COLUMN match_name VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE tipster_picks MODIFY COLUMN pick VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE web_users ADD COLUMN bio TEXT DEFAULT NULL AFTER display_name"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE web_users ADD COLUMN avatar_color VARCHAR(7) DEFAULT NULL AFTER bio"); } catch (Exception $e) {}

try { $db->exec("CREATE TABLE IF NOT EXISTS tipster_follows (follower_id INT NOT NULL, following_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (follower_id, following_id))"); } catch (Exception $e) {}
try { $db->exec("CREATE TABLE IF NOT EXISTS tipster_saves (pick_id INT NOT NULL, user_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY (pick_id, user_id))"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE web_users ADD COLUMN is_premium TINYINT(1) DEFAULT 0 AFTER avatar_color"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE tipster_picks ADD COLUMN is_boosted TINYINT(1) DEFAULT 0 AFTER is_pinned"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE tipster_picks ADD COLUMN boosted_until DATETIME DEFAULT NULL AFTER is_boosted"); } catch (Exception $e) {}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$isLoggedIn = $userId > 0;
$isAdmin = $userId == 1;
$currentUser = $isLoggedIn ? $db->query("SELECT display_name, avatar_color FROM web_users WHERE id=$userId")->fetch() : null;

// AJAX heart like handler
if ($action === 'like' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    header('Content-Type: application/json');
    $pickId = (int)($_POST['pick_id'] ?? 0);
    if (!$pickId) { echo json_encode(['ok' => false]); exit; }
    $existing = $db->prepare("SELECT id FROM tipster_votes WHERE pick_id=? AND user_id=?");
    $existing->execute([$pickId, $userId]);
    if ($existing->fetch()) {
        $db->prepare("DELETE FROM tipster_votes WHERE pick_id=? AND user_id=?")->execute([$pickId, $userId]);
        $liked = false;
    } else {
        $db->prepare("INSERT INTO tipster_votes (pick_id, user_id, vote) VALUES (?,?,1)")->execute([$pickId, $userId]);
        $liked = true;
    }
    $db->prepare("UPDATE tipster_picks SET upvotes = (SELECT COUNT(*) FROM tipster_votes WHERE pick_id=? AND vote=1) WHERE id=?")->execute([$pickId, $pickId]);
    $p = $db->prepare("SELECT upvotes FROM tipster_picks WHERE id=?");
    $p->execute([$pickId]);
    $res = $p->fetch();
    echo json_encode(['ok' => true, 'liked' => $liked, 'count' => (int)$res['upvotes']]);
    exit;
}

// AJAX follow handler
if ($action === 'follow' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    header('Content-Type: application/json');
    $targetId = (int)($_POST['user_id'] ?? 0);
    if (!$targetId || $targetId === $userId) { echo json_encode(['ok' => false]); exit; }
    $check = $db->prepare("SELECT 1 FROM tipster_follows WHERE follower_id=? AND following_id=?");
    $check->execute([$userId, $targetId]);
    if ($check->fetch()) {
        $db->prepare("DELETE FROM tipster_follows WHERE follower_id=? AND following_id=?")->execute([$userId, $targetId]);
        $following = false;
    } else {
        $db->prepare("INSERT INTO tipster_follows (follower_id, following_id) VALUES (?,?)")->execute([$userId, $targetId]);
        $following = true;
    }
    $cnt = $db->prepare("SELECT COUNT(*) FROM tipster_follows WHERE following_id=?");
    $cnt->execute([$targetId]);
    echo json_encode(['ok' => true, 'following' => $following, 'followers' => (int)$cnt->fetchColumn()]);
    exit;
}

// AJAX toggle pin handler
if ($action === 'toggle_pin' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    header('Content-Type: application/json');
    $pickId = (int)($_POST['pick_id'] ?? 0);
    if (!$pickId) { echo json_encode(['ok' => false]); exit; }
    $own = $db->prepare("SELECT user_id, is_pinned FROM tipster_picks WHERE id=?");
    $own->execute([$pickId]);
    $row = $own->fetch();
    if (!$row || ($row['user_id'] != $userId && !$isAdmin)) { echo json_encode(['ok' => false, 'error' => 'not yours']); exit; }
    $currentlyPinned = (int)$row['is_pinned'];
    if ($currentlyPinned) {
        $db->prepare("UPDATE tipster_picks SET is_pinned=0 WHERE id=?")->execute([$pickId]);
        echo json_encode(['ok' => true, 'pinned' => false]);
    } else {
        // Unpin any existing pinned pick for this user first
        $db->prepare("UPDATE tipster_picks SET is_pinned=0 WHERE user_id=? AND is_pinned=1")->execute([$row['user_id']]);
        $db->prepare("UPDATE tipster_picks SET is_pinned=1 WHERE id=?")->execute([$pickId]);
        echo json_encode(['ok' => true, 'pinned' => true]);
    }
    exit;
}

// AJAX toggle save handler
if ($action === 'toggle_save' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    header('Content-Type: application/json');
    $pickId = (int)($_POST['pick_id'] ?? 0);
    if (!$pickId) { echo json_encode(['ok' => false]); exit; }
    $check = $db->prepare("SELECT 1 FROM tipster_saves WHERE pick_id=? AND user_id=?");
    $check->execute([$pickId, $userId]);
    if ($check->fetch()) {
        $db->prepare("DELETE FROM tipster_saves WHERE pick_id=? AND user_id=?")->execute([$pickId, $userId]);
        $saved = false;
    } else {
        $db->prepare("INSERT INTO tipster_saves (pick_id, user_id) VALUES (?,?)")->execute([$pickId, $userId]);
        $saved = true;
    }
    echo json_encode(['ok' => true, 'saved' => $saved]);
    exit;
}

// AJAX update bio + avatar color handler
if ($action === 'update_bio' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    header('Content-Type: application/json');
    $bio = trim($_POST['bio'] ?? '');
    $color = trim($_POST['avatar_color'] ?? '');
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        $db->prepare("UPDATE web_users SET bio=?, avatar_color=? WHERE id=?")->execute([$bio, $color, $userId]);
    } else {
        $db->prepare("UPDATE web_users SET bio=? WHERE id=?")->execute([$bio, $userId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// AJAX comment handler
if ($action === 'comment' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    header('Content-Type: application/json');
    $pickId = (int)($_POST['pick_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    if (!$pickId || !$body) { echo json_encode(['ok' => false, 'error' => 'Missing fields']); exit; }
    $db->prepare("INSERT INTO tipster_comments (pick_id, user_id, body) VALUES (?,?,?)")->execute([$pickId, $userId, $body]);
    $cid = $db->lastInsertId();
    $name = htmlspecialchars($_SESSION['display_name'] ?? substr($_SESSION['phone'] ?? '', 0, 4).'***');
    echo json_encode(['ok' => true, 'id' => $cid, 'name' => $name, 'body' => htmlspecialchars($body), 'time' => date('j M H:i')]);
    exit;
}

// AJAX get comments for modal
if ($action === 'get_comments') {
    header('Content-Type: application/json');
    $pickId = (int)($_GET['pick_id'] ?? 0);
    if (!$pickId) { echo json_encode(['ok' => false, 'error' => 'Missing pick_id']); exit; }
    $db = getDB();
    $stmt = $db->prepare("SELECT tc.*, u.display_name, u.phone, u.avatar_color FROM tipster_comments tc JOIN web_users u ON tc.user_id = u.id WHERE tc.pick_id = ? ORDER BY tc.created_at ASC");
    $stmt->execute([$pickId]);
    $comments = $stmt->fetchAll();
    echo json_encode(['ok' => true, 'comments' => $comments]);
    exit;
}

// AJAX load more (infinite scroll)
if ($action === 'load_more') {
    header('Content-Type: application/json');
    $page = max(1, (int)($_GET['page'] ?? 1));
    if (!$isLoggedIn) {
        echo json_encode(['html' => [], 'hasMore' => false, 'locked' => true]);
        exit;
    }
    $perPage = 15;
    $offset = ($page - 1) * $perPage;
    $statusFilter = $_GET['status'] ?? '';
    $search = trim($_GET['search'] ?? '');
    $sort = $_GET['sort'] ?? 'newest';
    $view = $_GET['view'] ?? 'feed';
    $matchFilter = trim($_GET['match'] ?? '');
    if (!$isLoggedIn && in_array($view, ['following', 'saved'])) { $view = 'feed'; }
    $where = []; $params = [];
    if ($view === 'following' && $isLoggedIn) {
        $where[] = "tp.user_id IN (SELECT following_id FROM tipster_follows WHERE follower_id=?)";
        $params[] = $userId;
    }
    if ($view === 'saved' && $isLoggedIn) {
        $where[] = "tp.id IN (SELECT pick_id FROM tipster_saves WHERE user_id=?)";
        $params[] = $userId;
    }
    if ($statusFilter && in_array($statusFilter, ['pending', 'won', 'lost'])) {
        $where[] = "tp.status = ?"; $params[] = $statusFilter;
    }
    if ($search) {
        $where[] = "(tp.match_name LIKE ? OR tp.pick LIKE ?)";
        $st = "%{$search}%";
        $params[] = $st; $params[] = $st;
    }
    if ($matchFilter) {
        $where[] = "tp.match_name = ?";
        $params[] = $matchFilter;
    }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    switch ($sort) {
        case 'upvotes': $orderBy = 'ORDER BY tp.is_boosted DESC, tp.upvotes DESC, tp.created_at DESC'; break;
        case 'odds': $orderBy = 'ORDER BY tp.is_boosted DESC, tp.odds DESC, tp.created_at DESC'; break;
        default: $orderBy = 'ORDER BY tp.is_boosted DESC, tp.created_at DESC';
    }
    $stmt = $db->prepare("SELECT tp.*, u.display_name, u.phone, u.avatar_color, u.is_premium FROM tipster_picks tp JOIN web_users u ON tp.user_id = u.id $whereClause $orderBy LIMIT ? OFFSET ?");
    $i = 1;
    foreach ($params as $p) { $stmt->bindValue($i++, $p); }
    $stmt->bindValue($i++, (int)$perPage, PDO::PARAM_INT);
    $stmt->bindValue($i, (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    $picks = $stmt->fetchAll();
    $userVotes = []; $userFollows = []; $userSaves = [];
    if ($isLoggedIn && !empty($picks)) {
        $pickIds = array_column($picks, 'id');
        $v = $db->prepare("SELECT pick_id, vote FROM tipster_votes WHERE user_id=? AND pick_id IN (" . implode(',', array_fill(0, count($pickIds), '?')) . ")");
        $v->execute(array_merge([$userId], $pickIds));
        foreach ($v->fetchAll() as $r) { $userVotes[$r['pick_id']] = (int)$r['vote']; }
        $fl = $db->prepare("SELECT following_id FROM tipster_follows WHERE follower_id=?");
        $fl->execute([$userId]);
        foreach ($fl->fetchAll() as $r) { $userFollows[$r['following_id']] = true; }
        $sv = $db->prepare("SELECT pick_id FROM tipster_saves WHERE user_id=?");
        $sv->execute([$userId]);
        foreach ($sv->fetchAll() as $r) { $userSaves[$r['pick_id']] = true; }
    }
    $commentsByPick = [];
    if (!empty($picks)) {
        $pickIds = array_column($picks, 'id');
        $placeholders = implode(',', array_fill(0, count($pickIds), '?'));
        $cStmt = $db->prepare("SELECT tc.*, u.display_name, u.phone FROM tipster_comments tc JOIN web_users u ON tc.user_id = u.id WHERE tc.pick_id IN ($placeholders) ORDER BY tc.created_at ASC");
        $cStmt->execute($pickIds);
        foreach ($cStmt->fetchAll() as $c) { $commentsByPick[$c['pick_id']][] = $c; }
    }
    $html = [];
    foreach ($picks as $p) {
        $html[] = renderPickCard($p, $isLoggedIn, $userId, $isAdmin, $userVotes, $userFollows, $commentsByPick, $userSaves);
    }
    echo json_encode(['html' => $html, 'hasMore' => count($picks) === $perPage]);
    exit;
}

// Tip submission handler
if ($action === 'create_pick' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    $body = trim($_POST['body'] ?? '');
    $matchName = trim($_POST['match_name'] ?? '');
    $league = trim($_POST['league'] ?? '');
    $matchDate = trim($_POST['match_date'] ?? '');
    $pick = trim($_POST['pick'] ?? '');
    $odds = (float)($_POST['odds'] ?? 0);
    $reasoning = trim($_POST['reasoning'] ?? '');
    if (!$body && !$pick) {
        $error = 'Write something to post.';
    } else {
        $db->prepare("INSERT INTO tipster_picks (user_id, body, match_name, league, match_date, pick, odds, reasoning) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$userId, $body ?: null, $matchName ?: null, $league ?: null, $matchDate ?: null, $pick ?: null, $odds > 0 ? $odds : null, $reasoning ?: null]);
        $success = true;
    }
}

// Mark result handler (admin or pick author)
if ($action === 'mark_result' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    $pickId = (int)($_POST['pick_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($pickId && in_array($status, ['won', 'lost'])) {
        // Verify permission: admin can mark any, author can mark own
        $own = $db->prepare("SELECT user_id FROM tipster_picks WHERE id=?");
        $own->execute([$pickId]);
        $row = $own->fetch();
        if ($row && ($isAdmin || (int)$row['user_id'] === $userId)) {
            $db->prepare("UPDATE tipster_picks SET status = ?, match_date = COALESCE(match_date, CURDATE()) WHERE id = ?")->execute([$status, $pickId]);
        }
    }
    header('Location: pikka');
    exit;
}

// Admin: toggle premium
if ($action === 'toggle_premium' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $targetId = (int)($_POST['user_id'] ?? 0);
    if ($targetId) {
        $db->prepare("UPDATE web_users SET is_premium = CASE WHEN is_premium=1 THEN 0 ELSE 1 END WHERE id=?")->execute([$targetId]);
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'pikka?action=admin'));
    exit;
}

// Admin: toggle boost
if ($action === 'toggle_boost' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $pickId = (int)($_POST['pick_id'] ?? 0);
    if ($pickId) {
        $existing = $db->prepare("SELECT is_boosted, boosted_until FROM tipster_picks WHERE id=?");
        $existing->execute([$pickId]);
        $row = $existing->fetch();
        if ($row) {
            if (!empty($row['is_boosted']) && strtotime($row['boosted_until'] ?? '') > time()) {
                $db->prepare("UPDATE tipster_picks SET is_boosted=0, boosted_until=NULL WHERE id=?")->execute([$pickId]);
            } else {
                $db->prepare("UPDATE tipster_picks SET is_boosted=1, boosted_until=DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id=?")->execute([$pickId]);
            }
        }
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'pikka?action=admin'));
    exit;
}

// Admin panel
if ($action === 'admin' && $isAdmin) {
    $title = 'Admin — Pikka';
    $metaDesc = 'Pikka admin panel for managing premium users and boosted picks.';
    $userSearch = trim($_GET['user_search'] ?? '');
    if ($userSearch) {
        $us = "%{$userSearch}%";
        $userStmt = $db->prepare("SELECT id, display_name, phone, is_premium, join_date FROM web_users WHERE display_name LIKE ? OR phone LIKE ? ORDER BY id ASC");
        $userStmt->execute([$us, $us]);
        $allUsers = $userStmt->fetchAll();
    } else {
        $allUsers = $db->query("SELECT id, display_name, phone, is_premium, join_date FROM web_users ORDER BY id ASC LIMIT 20")->fetchAll();
    }
    $adminSearch = trim($_GET['search'] ?? '');
    if ($adminSearch) {
        $st = "%{$adminSearch}%";
        $adminCountStmt = $db->prepare("SELECT COUNT(*) FROM tipster_picks tp JOIN web_users u ON tp.user_id = u.id WHERE tp.match_name LIKE ? OR tp.pick LIKE ? OR u.display_name LIKE ?");
        $adminCountStmt->execute([$st, $st, $st]);
        $adminTotal = (int)$adminCountStmt->fetchColumn();
        $adminPicks = $db->prepare("SELECT tp.id, tp.match_name, tp.pick, tp.odds, tp.is_boosted, tp.boosted_until, tp.created_at, tp.views, u.display_name FROM tipster_picks tp JOIN web_users u ON tp.user_id = u.id WHERE tp.match_name LIKE ? OR tp.pick LIKE ? OR u.display_name LIKE ? ORDER BY tp.created_at DESC LIMIT 20");
        $adminPicks->bindValue(1, $st, PDO::PARAM_STR);
        $adminPicks->bindValue(2, $st, PDO::PARAM_STR);
        $adminPicks->bindValue(3, $st, PDO::PARAM_STR);
        $adminPicks->execute();
    } else {
        $adminTotal = (int)$db->query("SELECT COUNT(*) FROM tipster_picks")->fetchColumn();
        $adminPicks = $db->query("SELECT tp.id, tp.match_name, tp.pick, tp.odds, tp.is_boosted, tp.boosted_until, tp.created_at, tp.views, u.display_name FROM tipster_picks tp JOIN web_users u ON tp.user_id = u.id ORDER BY tp.created_at DESC LIMIT 20");
    }
    $allPicks = $adminPicks->fetchAll();
}

// Delete own pick
if ($action === 'delete_pick' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    $pickId = (int)($_POST['pick_id'] ?? 0);
    if ($pickId) {
        $check = $db->prepare("SELECT user_id FROM tipster_picks WHERE id = ?");
        $check->execute([$pickId]);
        $pick = $check->fetch();
        if ($pick && (int)$pick['user_id'] === $userId) {
            $db->prepare("DELETE FROM tipster_votes WHERE pick_id = ?")->execute([$pickId]);
            $db->prepare("DELETE FROM tipster_comments WHERE pick_id = ?")->execute([$pickId]);
            $db->prepare("DELETE FROM tipster_picks WHERE id = ?")->execute([$pickId]);
        }
    }
    header('Location: pikka');
    exit;
}

// Edit own pick (supports both AJAX and form POST)
if ($action === 'edit_pick' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    $pickId = (int)($_POST['pick_id'] ?? 0);
    $match = trim($_POST['match'] ?? '');
    $league = trim($_POST['league'] ?? '');
    $pickText = trim($_POST['pick'] ?? '');
    $odds = (float)($_POST['odds'] ?? 0);
    $reasoning = trim($_POST['reasoning'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $success = false;
    if ($pickId) {
        $check = $db->prepare("SELECT user_id FROM tipster_picks WHERE id = ?");
        $check->execute([$pickId]);
        $pick = $check->fetch();
        if ($pick && (int)$pick['user_id'] === $userId) {
            if ($match && $pickText) {
                $upd = $db->prepare("UPDATE tipster_picks SET match_name=?, league=?, pick=?, odds=?, reasoning=?, body=? WHERE id=?");
                $ok = $upd->execute([$match, $league, $pickText, $odds, $reasoning, $body, $pickId]);
                $success = $ok;
                if (!$ok) error_log("edit_pick structured failed for id=$pickId");
            } elseif ($body) {
                $upd = $db->prepare("UPDATE tipster_picks SET body=? WHERE id=?");
                $ok = $upd->execute([$body, $pickId]);
                $success = $ok;
                if (!$ok) error_log("edit_pick body failed for id=$pickId");
            } else {
                $success = true;
            }
        }
    }
    if (!empty($_GET['ajax'])) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        $resp = json_encode(['ok' => $success, 'match' => $match, 'pick' => $pickText, 'body' => $body]);
        echo $resp;
        exit;
    }
    header('Location: pikka');
    exit;
}

// Track view (AJAX)
if ($action === 'track_view' && !empty($_GET['pick_id'])) {
    $pid = (int)$_GET['pick_id'];
    if ($pid) {
        $author = $db->query("SELECT user_id FROM tipster_picks WHERE id=$pid")->fetch();
        if (!$author || ($isLoggedIn && ($author['user_id'] == $userId || $isAdmin))) {
            // Skip view count for author or admin
        } else {
            $db->prepare("UPDATE tipster_picks SET views = views + 1 WHERE id = ?")->execute([$pid]);
        }
    }
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;
}

// Edit comment (AJAX)
if ($action === 'edit_comment' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    $commentId = (int)($_POST['comment_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    $success = false;
    if ($commentId && $body) {
        $check = $db->prepare("SELECT user_id FROM tipster_comments WHERE id = ?");
        $check->execute([$commentId]);
        $c = $check->fetch();
        if ($c && (int)$c['user_id'] === $userId) {
            $db->prepare("UPDATE tipster_comments SET body = ? WHERE id = ?")->execute([$body, $commentId]);
            $success = true;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => $success]);
    exit;
}

// Profile page
if ($action === 'profile') {
    $profileId = (int)($_GET['id'] ?? 0);
    $uStmt = $db->prepare("SELECT id, display_name, phone, join_date, bio, avatar_color, profile_pic, is_premium FROM web_users WHERE id = ?");
    $uStmt->execute([$profileId]);
    $profileUser = $uStmt->fetch();
    if (!$profileUser) { $title = 'User Not Found — Pikka'; $metaDesc = ''; }
    else {
        $profilePremium = !empty($profileUser['is_premium']);
        $pStmt = $db->prepare("SELECT tp.*, (SELECT COUNT(*) FROM tipster_votes WHERE pick_id=tp.id AND vote=1) as likes FROM tipster_picks tp WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $pStmt->execute([$profileId]);
        $profilePicks = $pStmt->fetchAll();
        $pName = htmlspecialchars($profileUser['display_name'] ?: substr($profileUser['phone'], 0, 4).'***');
        $title = $pName . ' — Pikka';
        $metaDesc = 'Tipster profile for ' . $pName . '. View their picks and performance on Pikka.';
        // Get pinned pick
        $pinStmt = $db->prepare("SELECT tp.*, (SELECT COUNT(*) FROM tipster_votes WHERE pick_id=tp.id AND vote=1) as likes FROM tipster_picks tp WHERE user_id = ? AND is_pinned = 1 LIMIT 1");
        $pinStmt->execute([$profileId]);
        $pinnedPick = $pinStmt->fetch();
        // Follow data
        $followerCnt = $db->prepare("SELECT COUNT(*) FROM tipster_follows WHERE following_id=?");
        $followerCnt->execute([$profileId]);
        $profileFollowers = (int)$followerCnt->fetchColumn();
        $followingCnt = $db->prepare("SELECT COUNT(*) FROM tipster_follows WHERE follower_id=?");
        $followingCnt->execute([$profileId]);
        $profileFollowing = (int)$followingCnt->fetchColumn();
        $isFollowing = false;
        if ($isLoggedIn && $profileId !== $userId) {
            $chk = $db->prepare("SELECT 1 FROM tipster_follows WHERE follower_id=? AND following_id=?");
            $chk->execute([$userId, $profileId]);
            $isFollowing = (bool)$chk->fetch();
        }
        $popMatches = $db->query("SELECT match_name, COUNT(*) as cnt FROM tipster_picks GROUP BY match_name HAVING SUM(CASE WHEN status IN ('won','lost') THEN 1 ELSE 0 END) = 0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
$wonPicks = $db->query("SELECT tp.id, tp.pick, tp.odds, tp.match_name, tp.reasoning, tp.body, tp.status, tp.created_at, tp.upvotes, tp.views, u.display_name, u.phone, u.avatar_color FROM tipster_picks tp JOIN web_users u ON tp.user_id = u.id WHERE tp.status='won' ORDER BY tp.created_at DESC LIMIT 5")->fetchAll();
    }
// Leaderboard
} elseif ($action === 'leaderboard') {
    $stmt = $db->query("SELECT tp.user_id, u.display_name, u.phone, COUNT(tp.id) as total_picks, SUM(CASE WHEN tp.status='won' THEN 1 ELSE 0 END) as won, SUM(CASE WHEN tp.status='lost' THEN 1 ELSE 0 END) as lost, SUM(tp.upvotes) as total_likes FROM tipster_picks tp JOIN web_users u ON tp.user_id = u.id GROUP BY tp.user_id ORDER BY total_likes DESC, total_picks DESC LIMIT 50");
    $leaderboard = $stmt->fetchAll();
    $title = 'Leaderboard — Pikka';
    $metaDesc = 'See top tipsters ranked by winning picks and net score on Pikka.';
} else {
    // List picks with search, filter, sort
    $statusFilter = $_GET['status'] ?? '';
    $search = trim($_GET['search'] ?? '');
    $sort = $_GET['sort'] ?? 'newest';
    $view = $_GET['view'] ?? 'feed';
    $matchFilter = trim($_GET['match'] ?? '');
    if (!$isLoggedIn && in_array($view, ['following', 'saved'])) { $view = 'feed'; }
    $where = [];
    $params = [];
    $joinFollows = false;
    $joinVotes = false;
    if ($view === 'following' && $isLoggedIn) {
        $joinFollows = true;
        $where[] = "tp.user_id IN (SELECT following_id FROM tipster_follows WHERE follower_id=?)";
        $params[] = $userId;
    }
    if ($view === 'saved' && $isLoggedIn) {
        $joinVotes = true;
        $where[] = "tp.id IN (SELECT pick_id FROM tipster_saves WHERE user_id=?)";
        $params[] = $userId;
    }
    if ($statusFilter && in_array($statusFilter, ['pending', 'won', 'lost'])) {
        $where[] = "tp.status = ?";
        $params[] = $statusFilter;
    }
    if ($search) {
        $where[] = "(tp.match_name LIKE ? OR tp.pick LIKE ?)";
        $st = "%{$search}%";
        $params[] = $st;
        $params[] = $st;
    }
    if ($matchFilter) {
        $where[] = "tp.match_name = ?";
        $params[] = $matchFilter;
    }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    switch ($sort) {
        case 'upvotes': $orderBy = 'ORDER BY tp.is_boosted DESC, tp.upvotes DESC, tp.created_at DESC'; break;
        case 'odds': $orderBy = 'ORDER BY tp.is_boosted DESC, tp.odds DESC, tp.created_at DESC'; break;
        default: $orderBy = 'ORDER BY tp.is_boosted DESC, tp.created_at DESC';
    }
    $perPage = $isLoggedIn ? 15 : 4;
    $stmt = $db->prepare("SELECT tp.*, u.display_name, u.phone, u.avatar_color, u.is_premium FROM tipster_picks tp JOIN web_users u ON tp.user_id = u.id $whereClause $orderBy LIMIT ?");
    $i = 1;
    foreach ($params as $p) { $stmt->bindValue($i++, $p); }
    $stmt->bindValue($i, (int)$perPage, PDO::PARAM_INT);
    $stmt->execute();
    $allRows = $stmt->fetchAll();
    // Split into free picks and locked teasers
    if ($isLoggedIn) {
        $picks = $allRows;
        $lockedPicks = [];
    } else {
        $picks = array_slice($allRows, 0, 1);
        $lockedPicks = array_slice($allRows, 1, 3);
    }
    // Community stats
    $ss = $db->query("SELECT COUNT(*) as total, SUM(CASE WHEN status='won' THEN 1 ELSE 0 END) as won, SUM(CASE WHEN status='lost' THEN 1 ELSE 0 END) as lost, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending FROM tipster_picks")->fetch();
    $sTotal = (int)$ss['total'];
    $sWon = (int)$ss['won'];
    $sLost = (int)$ss['lost'];
    $sPend = (int)$ss['pending'];
    $sWr = ($sWon + $sLost) > 0 ? round($sWon / ($sWon + $sLost) * 100) : 0;
    // Most popular matches (most picks) — only matches with zero resolved picks
    $popMatches = $db->query("SELECT match_name, COUNT(*) as cnt FROM tipster_picks GROUP BY match_name HAVING SUM(CASE WHEN status IN ('won','lost') THEN 1 ELSE 0 END) = 0 ORDER BY cnt DESC LIMIT 10")->fetchAll();
    $wonPicks = $db->query("SELECT tp.id, tp.pick, tp.odds, tp.match_name, tp.reasoning, tp.body, tp.status, tp.created_at, tp.upvotes, tp.views, u.display_name, u.phone, u.avatar_color FROM tipster_picks tp JOIN web_users u ON tp.user_id = u.id WHERE tp.status='won' ORDER BY tp.created_at DESC LIMIT 5")->fetchAll();
    // Get user's votes, follows, saves
    $userVotes = [];
    $userFollows = [];
    $userSaves = [];
    if ($isLoggedIn) {
        $v = $db->prepare("SELECT pick_id, vote FROM tipster_votes WHERE user_id=?");
        $v->execute([$userId]);
        foreach ($v->fetchAll() as $r) { $userVotes[$r['pick_id']] = (int)$r['vote']; }
        $fl = $db->prepare("SELECT following_id FROM tipster_follows WHERE follower_id=?");
        $fl->execute([$userId]);
        foreach ($fl->fetchAll() as $r) { $userFollows[$r['following_id']] = true; }
        $sv = $db->prepare("SELECT pick_id FROM tipster_saves WHERE user_id=?");
        $sv->execute([$userId]);
        foreach ($sv->fetchAll() as $r) { $userSaves[$r['pick_id']] = true; }
    }
    // Fetch comments for all displayed picks
    $pickIds = array_column($picks, 'id');
    $commentsByPick = [];
    if (!empty($pickIds)) {
        $placeholders = implode(',', array_fill(0, count($pickIds), '?'));
        $cStmt = $db->prepare("SELECT tc.*, u.display_name, u.phone FROM tipster_comments tc JOIN web_users u ON tc.user_id = u.id WHERE tc.pick_id IN ($placeholders) ORDER BY tc.created_at ASC");
        $cStmt->execute($pickIds);
        foreach ($cStmt->fetchAll() as $c) {
            $commentsByPick[$c['pick_id']][] = $c;
        }
    }
    $title = 'Pikka';
    $metaDesc = 'Pikka — share tipping picks, upvote, comment, and track performance on the PREDIXA community.';
}

/**
 * Renders a locked teaser card for non-logged-in users past the free limit.
 */
function renderLockedCard($p) {
    $match = htmlspecialchars($p['match_name'] ?? '');
    $pick = htmlspecialchars($p['pick'] ?? '');
    $odds = (float)($p['odds'] ?? 0);
    $pName = htmlspecialchars($p['display_name'] ?: substr($p['phone'] ?? '', 0, 4).'***');
    ob_start(); ?>
    <div class="pick-card" style="position:relative;opacity:0.25;filter:blur(6px);pointer-events:none;user-select:none;border-color:rgba(99,102,241,0.15);">
        <div class="d-flex align-items-start gap-3">
            <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--pikka),var(--accent));display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:700;color:#fff;flex-shrink:0;"><?= strtoupper(substr($pName, 0, 2)) ?></div>
            <div class="flex-grow-1" style="min-width:0;">
                <div style="font-weight:700;font-size:0.85rem;color:var(--text);"><?= $pName ?></div>
                <div class="mt-1"><strong style="color:var(--text);"><?= $match ?></strong></div>
                <div class="mt-1"><span class="badge-market" style="font-size:0.75rem;padding:2px 8px;border-radius:4px;font-weight:600;background:rgba(99,102,241,0.1);color:var(--primary);"><?= $pick ?></span><?php if ($odds > 0): ?><span style="font-size:0.82rem;font-weight:600;margin-left:6px;">@ <?= number_format($odds, 2) ?></span><?php endif; ?></div>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}

/**
 * Renders a single pick card HTML snippet.
 */
function renderPickCard($p, $isLoggedIn, $userId, $isAdmin, $userVotes, $userFollows, $comments = [], $userSaves = []) {
    $pName = htmlspecialchars($p['display_name'] ?: substr($p['phone'], 0, 4).'***');
    $pAvatar = $p['avatar_color'] ?? null;
    $pUserId = (int)$p['user_id'];
    $isPremium = !empty($p['is_premium']);
    $rawMatch = $p['match_name'] ?? '';
    $match = htmlspecialchars($rawMatch);
    $rawLeague = $p['league'] ?? '';
    $league = htmlspecialchars($rawLeague);
    $rawPick = $p['pick'] ?? '';
    $pick = htmlspecialchars($rawPick);
    $odds = (float)$p['odds'];
    $rawReason = $p['reasoning'] ?? '';
    $reason = htmlspecialchars($rawReason);
    $rawBody = $p['body'] ?? '';
    $body = htmlspecialchars($rawBody);
    $structured = $rawMatch && $rawPick;
    $time = date('j M H:i', strtotime($p['created_at']));
    $up = (int)$p['upvotes'];
    $pid = (int)$p['id'];
    $myVote = $userVotes[$pid] ?? 0;
    $status = $p['status'] ?? 'pending';
    $isBoosted = !empty($p['is_boosted']) && (!empty($p['boosted_until']) && strtotime($p['boosted_until']) > time());
    $boostedBadge = $isBoosted ? '<span style="font-size:0.65rem;font-weight:700;color:#FFD700;border:1px solid rgba(255,215,0,0.5);border-radius:4px;padding:1px 7px;letter-spacing:0.5px;background:rgba(255,215,0,0.1);">🌟 Boosted</span> ' : '';
    $statusBadge = $status === 'won' ? '<span class="badge" style="background:#22C55E;color:#fff;font-size:0.6rem;padding:2px 8px;border-radius:4px;">WON</span>' : ($status === 'lost' ? '<span class="badge" style="background:#EF4444;color:#fff;font-size:0.6rem;padding:2px 8px;border-radius:4px;">LOST</span>' : '<span class="badge" style="background:#6B7280;color:#fff;font-size:0.6rem;padding:2px 8px;border-radius:4px;">PENDING</span>');
    $commentCount = count($comments[$pid] ?? []);
    $displayText = $structured ? '' : $rawBody;
    ob_start(); ?>
    <div class="pick-card <?= $structured ? '' : 'text-post' ?>" id="pick-<?= $pid ?>" style="position:relative;cursor:pointer;<?= $isBoosted ? 'border-color:rgba(255,215,0,0.5);background:linear-gradient(135deg,rgba(255,215,0,0.08) 0%,rgba(17,24,39,0.95) 100%);' : '' ?>"
        data-pid="<?= $pid ?>"
        data-match="<?= htmlspecialchars($rawMatch, ENT_QUOTES) ?>"
        data-league="<?= htmlspecialchars($rawLeague, ENT_QUOTES) ?>"
        data-pick="<?= htmlspecialchars($rawPick, ENT_QUOTES) ?>"
        data-odds="<?= $odds ?>"
        data-reasoning="<?= htmlspecialchars($rawReason, ENT_QUOTES) ?>"
        data-body="<?= htmlspecialchars($rawBody, ENT_QUOTES) ?>"
        data-user="<?= $pName ?>"
        data-time="<?= $time ?>"
        data-upvotes="<?= $up ?>"
        data-status="<?= $status ?>"
        data-avatar="<?= $pAvatar ?>"
        data-views="<?= (int)($p['views'] ?? 0) ?>">
        <?php if ($isLoggedIn): ?>
        <button class="save-btn" style="position:absolute;top:10px;right:10px;z-index:2;background:none;border:none;font-size:0.85rem;cursor:pointer;color:<?= !empty($userSaves[$pid]) ? '#6366F1' : 'var(--text)' ?>;padding:0;line-height:1;" onclick="toggleSave(<?= $pid ?>)" title="Save pick"><i class="fa<?= !empty($userSaves[$pid]) ? 's' : 'r' ?> fa-bookmark"></i></button>
        <?php endif; ?>
        <div class="d-flex align-items-start gap-3">
            <div style="width:40px;height:40px;border-radius:50%;background:<?= $pAvatar ?: 'linear-gradient(135deg,var(--pikka),var(--accent))' ?>;display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:700;color:#fff;flex-shrink:0;border:2px solid rgba(99,102,241,0.2);"><?= strtoupper(substr($pName, 0, 2)) ?></div>
            <div class="flex-grow-1" style="min-width:0;">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
                    <div>
                        <a href="pikka?action=profile&id=<?= $pUserId ?>" style="font-weight:700;font-size:0.85rem;color:var(--text);text-decoration:none;"><?= $pName ?></a><?php if ($isPremium): ?><span style="font-size:0.85rem;margin-left:3px;cursor:help;" title="Premium Tipster">🔱</span><?php endif; ?>
                        <?php if ($isLoggedIn && $pUserId !== $userId): ?> <button class="follow-mini <?= !empty($userFollows[$pUserId]) ? 'following' : '' ?>" onclick="follow(<?= $pUserId ?>)" id="follow-<?= $pUserId ?>"><?= !empty($userFollows[$pUserId]) ? 'Following' : 'Follow' ?></button><?php endif; ?>
                        <span class="meta ms-1"><?= $time ?></span><?= $boostedBadge ?>
                        <?php $views = (int)($p['views'] ?? 0); ?>
                        <?php if ($isAdmin || ($isLoggedIn && $pUserId === $userId)): ?><?= $statusBadge ?><span class="meta ms-2"><i class="far fa-eye" style="font-size:0.65rem;"></i> <?= $views ?> view<?= $views !== 1 ? 's' : '' ?></span><?php endif; ?>
                    </div>
                </div>
                <?php if ($structured): ?>
                <div class="ie-group" data-pid="<?= $pid ?>">
                    <div class="ie-field ie-match" style="margin-top:2px;">
                        <strong class="ie-val"><?= $match ?></strong>
                        <?php if ($league): ?><span class="meta ms-2 ie-field ie-league"><span class="ie-val"><?= $league ?></span></span><?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                        <span class="badge-market ie-field ie-pick" style="font-size:0.8rem;padding:2px 10px;border-radius:4px;font-weight:600;background:rgba(99,102,241,0.15);color:var(--primary);"><span class="ie-val"><?= $pick ?></span></span>
                        <?php if ($odds > 0): ?><span class="ie-field ie-odds" style="font-size:0.85rem;font-weight:600;"><span class="ie-val">@ <?= number_format($odds, 2) ?></span></span><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($rawBody): ?>
                <div class="ie-field ie-body" data-pid="<?= $pid ?>" style="font-size:0.9rem;color:var(--text);margin-top:4px;white-space:pre-wrap;word-wrap:break-word;line-height:1.5;"><span class="ie-val"><?= $body ?></span></div>
                <?php endif; ?>
                <?php if ($structured && $reason): ?>
                <div class="ie-field ie-reasoning" data-pid="<?= $pid ?>" style="font-size:0.8rem;color:var(--text);margin-top:4px;"><span class="ie-val"><?php
                    $maxLen = 150;
                    if (mb_strlen($rawReason) > $maxLen) {
                        echo '<span class="reason-preview">' . htmlspecialchars(mb_substr($rawReason, 0, $maxLen)) . '...</span> ';
                        echo '<span class="read-more-trigger" style="color:var(--primary);cursor:pointer;font-weight:600;" onclick="event.stopPropagation();openViewPostSimple('.$pid.')">Read more</span>';
                    } else {
                        echo $reason;
                    }
                ?></span></div>
                <?php endif; ?>
                <div class="d-flex gap-2 mt-2 flex-wrap align-items-center">
                    <?php if ($isLoggedIn): ?>
                    <button class="heart-btn <?= $myVote ? 'liked' : '' ?>" onclick="like(<?= $pid ?>)" title="Like"><i class="fa<?= $myVote ? 's' : 'r' ?> fa-heart"></i> <span id="like-<?= $pid ?>"><?= $up ?></span></button>
                    <?php else: ?>
                    <span style="font-size:0.8rem;color:var(--muted);font-weight:600;"><i class="far fa-heart"></i> <?= $up ?></span>
                    <?php endif; ?>
                    <button class="vote-btn comment-toggle" style="font-size:0.7rem;padding:2px 8px;" data-pick="<?= $pid ?>" title="Comments"><i class="fas fa-comment"></i> <?= $commentCount ?></button>
                    <a href="https://wa.me/?text=<?= rawurlencode("{$pick} @ {$odds} — {$match} ({$league}) shared on Pikka") ?>" target="_blank" class="vote-btn" style="font-size:0.7rem;padding:2px 8px;" title="Share on WhatsApp"><i class="fab fa-whatsapp" style="color:#25D366;"></i></a>
                    <a href="https://twitter.com/intent/tweet?text=<?= rawurlencode("{$pick} @ {$odds} — {$match}\n\nPikka") ?>" target="_blank" class="vote-btn" style="font-size:0.7rem;padding:2px 8px;" title="Share on X"><i class="fab fa-x-twitter"></i></a>
                    <a href="https://t.me/share/url?url=<?= rawurlencode("{$pick} @ {$odds} — {$match}") ?>&text=<?= rawurlencode("{$pick} @ {$odds} — {$match}") ?>" target="_blank" class="vote-btn" style="font-size:0.7rem;padding:2px 8px;" title="Share on Telegram"><i class="fab fa-telegram-plane" style="color:#229ED9;"></i></a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode("{$pick} @ {$odds} — {$match}") ?>" target="_blank" class="vote-btn" style="font-size:0.7rem;padding:2px 8px;" title="Share on Facebook"><i class="fab fa-facebook-f" style="color:#1877F2;"></i></a>
                    <button class="vote-btn" style="font-size:0.7rem;padding:2px 8px;" onclick="var t=decodeURIComponent('<?= rawurlencode($pick) ?>')+' — Pikka';navigator.clipboard.writeText(t);window.open('https://www.instagram.com/','_blank')" title="Share on Instagram"><i class="fab fa-instagram" style="color:#E4405F;"></i></button>
                    <button class="vote-btn" style="font-size:0.7rem;padding:2px 8px;" onclick="copyPickLink(this,<?= $pid ?>,'<?= rawurlencode($pick) ?>')" title="Copy pick text"><i class="fas fa-copy"></i></button>
                    <?php if ($isAdmin || ($isLoggedIn && $pUserId === $userId)): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Mark as <?= $status === 'won' ? 'LOST' : 'WON' ?>?')">
                        <input type="hidden" name="action" value="mark_result">
                        <input type="hidden" name="pick_id" value="<?= $pid ?>">
                        <input type="hidden" name="status" value="<?= $status === 'won' ? 'lost' : 'won' ?>">
                        <button type="submit" class="vote-btn" style="font-size:0.7rem;padding:2px 8px;border-color:<?= $status === 'won' ? '#EF4444' : '#22C55E' ?>;color:<?= $status === 'won' ? '#EF4444' : '#22C55E' ?>;"><?= $status === 'won' ? 'Mark LOST' : 'Mark WON' ?></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($isLoggedIn && $pUserId === $userId): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this pick? This cannot be undone.')">
                        <input type="hidden" name="action" value="delete_pick">
                        <input type="hidden" name="pick_id" value="<?= $pid ?>">
                        <button type="submit" class="vote-btn" style="font-size:0.7rem;padding:2px 8px;border-color:#EF4444;color:#EF4444;"><i class="fas fa-trash-can"></i></button>
                    </form>
                    <button class="vote-btn ie-edit-btn" style="font-size:0.7rem;padding:2px 8px;border-color:var(--pikka);color:var(--pikka);" onclick="inlineEditPick(<?= $pid ?>)" title="Edit"><i class="fas fa-pen"></i></button>
                    <?php endif; ?>
                    <?php if ($isAdmin || ($isLoggedIn && $pUserId === $userId)): ?>
                    <button class="vote-btn" style="font-size:0.7rem;padding:2px 8px;border-color:var(--pikka);color:var(--pikka);" onclick="togglePin(<?= $pid ?>)" title="Pin to profile"><i class="fas fa-thumbtack"></i></button>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="toggle_boost">
                        <input type="hidden" name="pick_id" value="<?= $pid ?>">
                        <button type="submit" class="vote-btn" style="font-size:0.7rem;padding:2px 8px;border-color:<?= $isBoosted ? '#EF4444' : '#FFD700' ?>;color:<?= $isBoosted ? '#EF4444' : '#FFD700' ?>;" title="Boost this pick"><i class="fas fa-rocket"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
                <div id="comments-<?= $pid ?>" class="d-none mt-2" style="border-top:1px solid var(--border);padding-top:8px;">
                    <?php if ($isLoggedIn): ?>
                    <form class="comment-form mb-2" data-pick="<?= $pid ?>">
                        <div class="d-flex gap-2">
                            <input type="text" class="form-control form-control-sm comment-input" placeholder="Write a comment..." required style="background:var(--bg);border:1px solid var(--border);color:var(--text);font-size:0.8rem;">
                            <button type="submit" class="btn btn-sm" style="background:var(--primary);color:white;border:none;font-size:0.75rem;white-space:nowrap;">Post</button>
                        </div>
                    </form>
                    <?php endif; ?>
                    <div class="comment-list">
                        <?php if (!empty($comments[$pid])): foreach ($comments[$pid] as $c):
                            $cName = htmlspecialchars($c['display_name'] ?: substr($c['phone'], 0, 4).'***');
                            $cUserId = (int)($c['user_id'] ?? 0);
                            $cId = (int)$c['id'];
                        ?>
                        <div style="font-size:0.78rem;padding:4px 0;border-bottom:1px solid rgba(45,49,66,0.5);">
                            <strong style="color:var(--primary);"><?= $cName ?></strong>
                            <span style="color:var(--muted);font-size:0.7rem;margin-left:4px;"><?= date('j M H:i', strtotime($c['created_at'])) ?></span>
                            <?php if ($isLoggedIn && $cUserId === $userId): ?>
                            <button class="vote-btn" style="font-size:0.55rem;padding:1px 5px;margin-left:4px;border-color:var(--pikka);color:var(--pikka);" onclick="inlineEditComment(<?= $cId ?>, this)" title="Edit"><i class="fas fa-pen"></i></button>
                            <?php endif; ?>
                            <div class="comment-body-<?= $cId ?>" style="color:var(--text);"><?= htmlspecialchars($c['body']) ?></div>
                        </div>
                        <?php endforeach; endif; ?>
                        <div class="text-center py-2" style="font-size:0.75rem;color:var(--muted);display:<?= empty($comments[$pid]) ? 'block' : 'none' ?>">No comments yet.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}

$pageTitle = $title;
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<base href="<?= $baseUrl ?>">
<title><?= $title ?></title>
<meta property="og:title" content="<?= $title ?>">
<meta property="og:description" content="<?= $metaDesc ?>">
<meta name="description" content="<?= $metaDesc ?>">
<link rel="canonical" href="https://predixa.co.tz/pikka<?= $action === 'leaderboard' ? '/leaderboard' : '' ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root { --pikka: #6366F1; --pikka-dark: #4F46E5; --pikka-bg: #0E121B; --pikka-card: #131822; --primary: #6366F1; --primary-dark: #4F46E5; --accent: #818CF8; --text: #F1F5F9; --muted: #94A3B8; --border: #2D3748; --bg: #111827; --secondary: #1A1F2E; --border-color: #2D3748; --text-muted: #94A3B8; }
body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #0B0F1A 0%, #162040 50%, #1A1F2E 100%); color: var(--text); min-height: 100vh; padding-top: 60px; }
.navbar { background: rgba(14,18,27,0.97); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border); }
.pikka-brand { text-decoration:none; display:flex; align-items:center; gap:6px; }
.pikka-brand i { font-size:1.5rem; color:var(--pikka); }
.pikka-brand .p-name { font-weight:800; font-size:1.5rem; background:linear-gradient(135deg,var(--pikka),#818CF8); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
.pikka-brand .p-by { font-weight:600; font-size:0.7rem; background:linear-gradient(135deg,var(--pikka),#818CF8); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; align-self:flex-end; padding-bottom:2px; }
.pick-card { background: linear-gradient(135deg, rgba(99,102,241,0.12) 0%, rgba(17,24,39,0.95) 100%); border: 1px solid rgba(99,102,241,0.2); border-radius: 12px; padding: 16px; margin-bottom: 12px; transition: all .25s; }
.pick-card:hover { border-color: rgba(99,102,241,0.5); transform: translateY(-1px); }
.pick-card .meta { font-size: 0.78rem; color: var(--muted); }
.vote-btn { cursor:pointer; padding:4px 10px; border-radius:6px; border:1px solid rgba(99,102,241,0.25); background:transparent; color:var(--muted); font-size:0.85rem; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
.vote-btn:hover { border-color:var(--pikka); color:var(--pikka); }
.vote-btn.voted-up { border-color:#22C55E; color:#22C55E; background:rgba(34,197,94,0.1); }
.vote-btn.voted-down { border-color:#EF4444; color:#EF4444; background:rgba(239,68,68,0.1); }
.leader-row { background: linear-gradient(135deg, rgba(99,102,241,0.08) 0%, rgba(17,24,39,0.95) 100%); border: 1px solid rgba(99,102,241,0.16); border-radius: 10px; padding: 12px 16px; margin-bottom: 8px; display:flex; align-items:center; gap:12px; transition: all .2s; }
.leader-row:hover { border-color: rgba(99,102,241,0.35); }
.leader-row .rank { font-weight:800; font-size:1.1rem; min-width:32px; color:var(--muted); }
.leader-row .rank.gold { color:var(--pikka); }
.leader-row .rank.silver { color:#94A3B8; }
.leader-row .rank.bronze { color:#CD7F32; }
.dropdown-menu { background:#1A1D24; border:1px solid var(--border); }
.nav-link { color: var(--muted) !important; font-weight: 600; padding: 8px 16px; border-radius: 6px; transition: all 0.3s; }
.nav-link:hover { color: #FFFFFF !important; }
.dropdown-item { color:#F1F5F9; font-size:0.85rem; padding:0.5rem 1rem; }
.dropdown-item:hover { background:#2D3142; color:#F1F5F9; }
.dropdown-item.active { background:rgba(99,102,241,0.12); color:var(--pikka); }
.modal-content { background:#1A1D24; border:1px solid var(--border); border-radius:12px; }
.modal-header { border-bottom:1px solid var(--border); padding:16px 20px; }
.modal-footer { border-top:1px solid var(--border); padding:12px 20px; }
.modal-body { padding:20px; display:flex; flex-direction:column; gap:12px; }
#postTipModal input::placeholder, #postTipModal textarea::placeholder { color:rgba(241,245,249,0.3); }
#editProfileModal textarea::placeholder { color:rgba(241,245,249,0.3); }
#postTipModal input, #postTipModal textarea { color:var(--text); }
#postTipModal input, #postTipModal textarea, #postTipModal select { background:#2D3142; border:1px solid #3D4254; color:var(--text); font-size:0.85rem; }
.pikka-header { border-bottom: 1px solid rgba(99,102,241,0.15); padding-bottom: 16px; margin-bottom: 16px; }
.pikka-badge { background:rgba(99,102,241,0.1); color:var(--pikka); border:1px solid rgba(99,102,241,0.25); padding:2px 10px; border-radius:12px; font-size:0.7rem; font-weight:600; }
.btn-pikka { background:linear-gradient(135deg,var(--pikka),var(--accent)); color:#fff; border:none; font-weight:700; padding:6px 16px; border-radius:6px; font-size:0.8rem; cursor:pointer; transition:all .2s; text-decoration:none; display:inline-block; }
.btn-pikka:hover { transform:translateY(-1px); box-shadow:0 4px 15px rgba(99,102,241,0.3); color:#fff; }
.heart-btn { cursor:pointer; padding:4px 8px; border-radius:6px; border:1px solid rgba(99,102,241,0.2); background:transparent; color:var(--muted); font-size:0.85rem; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:3px; line-height:1; }
.heart-btn:hover { border-color:#EF4444; color:#EF4444; }
.heart-btn.liked { border-color:#EF4444; color:#EF4444; background:rgba(239,68,68,0.08); }
.follow-btn { cursor:pointer; padding:4px 14px; border-radius:6px; font-weight:600; font-size:0.8rem; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:4px; border:1px solid var(--pikka); color:var(--pikka); background:transparent; }
.follow-btn:hover { background:var(--pikka); color:#fff; }
.follow-btn.following { border-color:var(--border); color:var(--muted); background:transparent; }
.follow-btn.following:hover { border-color:#EF4444; color:#EF4444; background:rgba(239,68,68,0.08); }
.follow-mini { cursor:pointer; padding:0 6px; border-radius:4px; font-weight:600; font-size:0.65rem; transition:all .15s; text-decoration:none; display:inline; border:1px solid var(--pikka); color:var(--pikka); background:transparent; line-height:1.5; vertical-align:middle; margin-left:3px; }
.follow-mini:hover { background:var(--pikka); color:#fff; }
.follow-mini.following { border-color:var(--border); color:var(--muted); background:transparent; }
.follow-mini.following:hover { border-color:#EF4444; color:#EF4444; background:rgba(239,68,68,0.08); }
.search-input::placeholder { color:var(--muted) !important; opacity:0.7 !important; }
.search-input:-ms-input-placeholder { color:var(--muted) !important; }
.search-input::-webkit-input-placeholder { color:var(--muted) !important; }
.search-input { color:#F1F5F9 !important; background:linear-gradient(135deg,rgba(99,102,241,0.06) 0%,rgba(17,24,39,0.9) 100%) !important; border-color:rgba(99,102,241,0.15) !important; }
.avatar-circle { width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--accent));display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:700;color:#fff;flex-shrink:0; }
.pikka-sidebar { background:linear-gradient(135deg,rgba(99,102,241,0.06) 0%,rgba(17,24,39,0.9) 100%);border:1px solid rgba(99,102,241,0.15);border-radius:12px;padding:12px;position:sticky;top:76px; }
.pikka-sidebar .sidebar-item { display:block;padding:8px 12px;border-radius:8px;font-size:0.85rem;font-weight:600;color:var(--text);text-decoration:none;transition:all .15s;margin-bottom:2px; }
.pikka-sidebar .sidebar-item:hover { background:rgba(99,102,241,0.1);color:var(--text); }
.pikka-sidebar .sidebar-item.active { background:rgba(99,102,241,0.15);color:var(--pikka); }
@media (max-width:991px) {
    .pikka-sidebar { display:flex;flex-wrap:wrap;gap:4px;padding:8px 12px;position:static; }
    .pikka-sidebar .sidebar-item { font-size:0.78rem;padding:6px 10px; }
    .pikka-sidebar hr { display:none; }
    .pikka-sidebar form { display:none; }
}
.fab-post { position:fixed;bottom:24px;right:24px;width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--pikka),#818CF8);color:#fff;border:none;font-size:1.5rem;font-weight:300;cursor:pointer;box-shadow:0 4px 20px rgba(99,102,241,0.4);display:flex;align-items:center;justify-content:center;transition:all .25s;z-index:1050; }
.fab-post:hover { transform:scale(1.1);box-shadow:0 6px 28px rgba(99,102,241,0.6); }
.comment-input::placeholder { color:#94A3B8 !important; opacity:0.7 !important; }
.text-post { border-color:rgba(99,102,241,0.1) !important; }
.text-post:hover { border-color:rgba(99,102,241,0.3) !important; }
#postBody::placeholder { color:#94A3B8 !important; opacity:0.7 !important; }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark fixed-top">
    <div class="container">
        <a class="pikka-brand" href="./"><i class="fas fa-futbol me-1"></i><span class="p-name">Pikka</span> <span class="p-by">by Predixa</span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="./">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="signals">Smart Picks</a></li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="javascript:void(0)" role="button" data-bs-toggle="dropdown">Free Tools</a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="dropping-odds"><i class="fas fa-arrow-down me-1" style="color:#EF4444;"></i> Dropping Odds</a></li>
                        <li><a class="dropdown-item" href="signals"><i class="fas fa-microchip me-1" style="color:#22C55E;"></i> Smart Picks</a></li>
                        <li><a class="dropdown-item" href="track-record"><i class="fas fa-chart-line me-1" style="color:#FBBF24;"></i> Performance</a></li>
                        <li><a class="dropdown-item" href="betting-school"><i class="fas fa-book-open me-1" style="color:#fff;"></i> Betting School</a></li>
                        <li><a class="dropdown-item active" href="pikka"><i class="fas fa-futbol me-1" style="color:var(--pikka);"></i> Pikka</a></li>
                    </ul>
                </li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item d-flex align-items-center gap-2">
                    <?php if ($isLoggedIn): ?>
                    <a href="pikka?action=profile&id=<?= $userId ?>" class="btn btn-sm" style="border:1px solid var(--pikka);color:var(--pikka);padding:4px 10px;border-radius:6px;text-decoration:none;font-size:0.8rem;" title="My Profile"><i class="fas fa-user me-1"></i>Profile</a>
                    <a href="dashboard" class="btn btn-sm" style="border:1px solid var(--primary);color:var(--primary);padding:4px 14px;border-radius:6px;text-decoration:none;font-size:0.8rem;"><i class="fas fa-gauge-high me-1"></i>Dashboard</a>
                    <a href="logout" class="btn btn-sm" style="border:1px solid var(--border);color:var(--muted);padding:4px 14px;border-radius:6px;text-decoration:none;font-size:0.8rem;"><i class="fas fa-right-from-bracket me-1"></i>Logout</a>
                    <?php else: ?>
                    <a href="login?redirect=pikka" class="btn btn-sm" style="border:1px solid var(--primary);color:var(--primary);padding:4px 14px;border-radius:6px;text-decoration:none;font-size:0.8rem;">Sign In</a>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container py-4">

<?php if ($action === 'profile'): ?>
    <!-- Profile Page -->
    <?php if (!$profileUser): ?>
    <div class="text-center py-5"><i class="fas fa-user-slash" style="font-size:3rem;color:var(--muted);margin-bottom:1rem;"></i><p style="color:var(--muted);">User not found.</p><a href="pikka" class="btn btn-sm" style="border:1px solid var(--pikka);color:var(--pikka);padding:6px 20px;border-radius:6px;text-decoration:none;">Back to Picks</a></div>
    <?php else: ?>
    <div class="row g-4">
        <!-- Left Sidebar -->
        <div class="col-lg-3">
            <div class="pikka-sidebar">
                <form method="GET" action="pikka" style="margin-bottom:12px;position:relative;">
                    <i class="fas fa-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:0.9rem;pointer-events:none;"></i>
                    <input type="text" name="search" class="form-control search-input" placeholder="Search Pikka" style="font-size:0.9rem;padding:10px 14px 10px 38px;border-radius:12px;width:100%;">
                </form>
                <a href="pikka" class="sidebar-item"><i class="fas fa-rss me-2"></i>Feed</a>
                <?php if ($isLoggedIn): ?>
                <a href="pikka?view=following" class="sidebar-item"><i class="fas fa-users me-2"></i>Following</a>
                <a href="pikka?view=saved" class="sidebar-item"><i class="fas fa-bookmark me-2"></i>Saved</a>
                <?php endif; ?>
                <a href="pikka/leaderboard" class="sidebar-item"><i class="fas fa-trophy me-2"></i>Leaderboard</a>
                <?php if ($isLoggedIn): ?>
                <a href="pikka?action=profile&id=<?= $userId ?>" class="sidebar-item active"><i class="fas fa-user me-2"></i>My Profile</a>
                <?php endif; ?>
                <hr style="border-color:var(--border);margin:12px 0;">
            </div>
        </div>
        <!-- Profile Content -->
        <div class="col-lg-6">
    <div class="profile-cover" style="background:linear-gradient(135deg,var(--pikka) 0%,#4F46E5 40%,#1E1B4B 100%);border-radius:16px;padding:32px 24px 20px;margin-bottom:20px;position:relative;min-height:160px;">
        <div class="d-flex align-items-end gap-4 flex-wrap">
            <div id="profile-avatar" style="width:76px;height:76px;border-radius:50%;background:<?= $profileUser['avatar_color'] ?? 'linear-gradient(135deg,var(--pikka),#DC2626)' ?>;display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:800;color:#fff;flex-shrink:0;border:3px solid rgba(255,255,255,0.2);"><?= strtoupper(substr($pName, 0, 2)) ?></div>
            <div class="flex-grow-1" style="text-shadow:0 1px 3px rgba(0,0,0,0.3);">
                <h3 style="font-weight:800;margin:0;color:#fff;"><?= $pName ?><?php if ($profilePremium): ?> <span style="font-size:1.2rem;" title="Premium Tipster">🔱</span><?php endif; ?></h3>
                <?php if ($profileUser['bio']): ?>
                <p class="profile-bio-text" style="margin:2px 0 0;font-size:0.85rem;color:rgba(255,255,255,0.8);"><?= htmlspecialchars($profileUser['bio']) ?></p>
                <?php else: ?>
                <p class="profile-bio-text" style="margin:2px 0 0;font-size:0.85rem;color:rgba(255,255,255,0.8);display:none;"></p>
                <?php endif; ?>
                <small style="color:rgba(255,255,255,0.6);">Member since <?= date('M Y', strtotime($profileUser['join_date'])) ?></small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if ($isLoggedIn && $profileId !== $userId): ?>
                <button class="follow-btn <?= $isFollowing ? 'following' : '' ?>" onclick="follow(<?= $profileId ?>)" id="follow-<?= $profileId ?>" style="border-color:rgba(255,255,255,0.5);color:#fff;"><?php if(!$isFollowing): ?><i class="far fa-user-plus"></i><?php endif; ?> <span><?= $isFollowing ? 'Following' : 'Follow' ?></span></button>
                <?php endif; ?>
                <?php if ($isLoggedIn && $profileId === $userId): ?>
                <button class="btn btn-sm" onclick="editBio()" style="border:1px solid rgba(255,255,255,0.3);color:#fff;padding:4px 14px;border-radius:6px;text-decoration:none;font-size:0.8rem;background:rgba(255,255,255,0.1);"><i class="fas fa-pen me-1"></i>Edit Profile</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-4 mt-3 flex-wrap" style="border-top:1px solid rgba(255,255,255,0.15);padding-top:14px;">
            <div style="text-align:center;"><div style="font-weight:700;font-size:1.2rem;color:#fff;text-shadow:0 1px 4px rgba(0,0,0,0.4);"><?= $profileFollowers ?></div><small style="color:rgba(255,255,255,0.65);font-weight:600;">Followers</small></div>
            <div style="text-align:center;"><div style="font-weight:700;font-size:1.2rem;color:#fff;text-shadow:0 1px 4px rgba(0,0,0,0.4);"><?= $profileFollowing ?></div><small style="color:rgba(255,255,255,0.65);font-weight:600;">Following</small></div>
        </div>
    </div>

    <?php if ($pinnedPick): 
        $pMatch = htmlspecialchars($pinnedPick['match_name']);
        $pLeague = htmlspecialchars($pinnedPick['league'] ?? '');
        $pPick = htmlspecialchars($pinnedPick['pick']);
        $pOdds = (float)$pinnedPick['odds'];
        $pReason = htmlspecialchars($pinnedPick['reasoning'] ?? '');
        $pTime = date('j M H:i', strtotime($pinnedPick['created_at']));
        $pUp = (int)$pinnedPick['likes'];
        $pPid = (int)$pinnedPick['id'];
        $pPuserId = (int)$pinnedPick['user_id'];
    ?>
    <div class="pick-card" style="border-color:var(--pikka);border-width:2px;background:linear-gradient(135deg,rgba(99,102,241,0.18) 0%,rgba(17,24,39,0.95) 100%);">
        <div class="d-flex align-items-start gap-3">
            <div class="d-flex flex-column align-items-center" style="min-width:36px;">
                <span style="font-size:0.8rem;color:var(--muted);font-weight:600;"><i class="far fa-heart"></i> <?= $pUp ?></span>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span style="font-size:0.65rem;font-weight:700;color:var(--pikka);border:1px solid var(--pikka);border-radius:4px;padding:1px 7px;letter-spacing:0.5px;">📌 PINNED</span>
                    <?php if ($isAdmin || ($isLoggedIn && $pPuserId === $userId)): ?>
                    <button class="vote-btn" style="font-size:0.65rem;padding:1px 7px;" onclick="togglePin(<?= $pPid ?>);this.closest('.pick-card').remove()" title="Unpin"><i class="fas fa-thumbtack"></i> Unpin</button>
                    <?php endif; ?>
                </div>
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
                    <div><strong><?= $pMatch ?></strong><?php if ($pLeague): ?><span class="meta ms-2"><?= $pLeague ?></span><?php endif; ?></div>
                    <span class="meta"><?= $pTime ?></span>
                </div>
                <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                    <span class="badge-market" style="font-size:0.75rem;padding:2px 8px;border-radius:4px;font-weight:600;background:rgba(99,102,241,0.2);color:var(--primary);"><?= $pPick ?></span>
                    <?php if ($pOdds > 0): ?><span style="font-size:0.82rem;font-weight:600;">@ <?= number_format($pOdds, 2) ?></span><?php endif; ?>
                </div>
                <?php if ($pReason): ?>
                <div style="font-size:0.8rem;color:var(--text);margin-top:4px;border-left:2px solid var(--pikka);padding-left:8px;"><?= $pReason ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div style="font-size:0.85rem;color:var(--muted);margin-bottom:12px;"><?= count($profilePicks) ?> recent tips</div>
    <?php if (empty($profilePicks)): ?>
    <div class="text-center py-5" style="color:var(--muted);"><i class="fas fa-inbox" style="font-size:3rem;color:var(--pikka);margin-bottom:1rem;"></i><p>No tips posted yet.</p></div>
    <?php else: foreach ($profilePicks as $p):
        $match = htmlspecialchars($p['match_name']);
        $league = htmlspecialchars($p['league'] ?? '');
        $pick = htmlspecialchars($p['pick']);
        $odds = (float)$p['odds'];
        $reason = htmlspecialchars($p['reasoning'] ?? '');
        $time = date('j M H:i', strtotime($p['created_at']));
        $up = (int)$p['upvotes'];
        $pid = (int)$p['id'];
        $pUserId = (int)$p['user_id'];
        $status = $p['status'] ?? 'pending';
        $statusBadge = $status === 'won' ? '<span class="badge" style="background:#22C55E;color:#fff;font-size:0.6rem;padding:2px 8px;border-radius:4px;">WON</span>' : ($status === 'lost' ? '<span class="badge" style="background:#EF4444;color:#fff;font-size:0.6rem;padding:2px 8px;border-radius:4px;">LOST</span>' : '<span class="badge" style="background:#6B7280;color:#fff;font-size:0.6rem;padding:2px 8px;border-radius:4px;">PENDING</span>');
    ?>
    <div class="pick-card">
        <div class="d-flex align-items-start gap-3">
            <div class="d-flex flex-column align-items-center" style="min-width:36px;">
                <span style="font-size:0.8rem;color:var(--muted);font-weight:600;"><i class="far fa-heart"></i> <?= $up ?></span>
            </div>
            <div class="flex-grow-1">
                <div class="ie-group" data-pid="<?= $pid ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
                    <div class="ie-field ie-match"><strong class="ie-val"><?= $match ?></strong><?php if ($league): ?><span class="meta ms-2 ie-field ie-league"><span class="ie-val"><?= $league ?></span></span><?php endif; ?></div>
                    <div class="d-flex align-items-center gap-1"><span class="meta"><?= $time ?></span><?php if ($isAdmin || ($isLoggedIn && $pUserId === $userId)): ?><?= $statusBadge ?><span class="meta ms-1"><i class="far fa-eye" style="font-size:0.6rem;"></i> <?= (int)($p['views'] ?? 0) ?></span><?php endif; ?></div>
                </div>
                <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                    <span class="badge-market ie-field ie-pick" style="font-size:0.75rem;padding:2px 8px;border-radius:4px;font-weight:600;background:rgba(99,102,241,0.15);color:var(--primary);"><span class="ie-val"><?= $pick ?></span></span>
                    <?php if ($odds > 0): ?><span class="ie-field ie-odds" style="font-size:0.82rem;font-weight:600;"><span class="ie-val">@ <?= number_format($odds, 2) ?></span></span><?php endif; ?>
                </div>
                <?php if ($reason): ?>
                <div class="ie-field ie-reasoning" data-pid="<?= $pid ?>" style="font-size:0.8rem;color:var(--text);margin-top:4px;border-left:2px solid var(--border);padding-left:8px;"><span class="ie-val"><?= $reason ?></span></div>
                <?php endif; ?>
                </div>
                <?php if ($isAdmin || ($isLoggedIn && $pUserId === $userId)): ?>
                <div style="margin-top:6px;" class="d-flex gap-1 flex-wrap">
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Mark as <?= $status === 'won' ? 'LOST' : 'WON' ?>?')">
                        <input type="hidden" name="action" value="mark_result">
                        <input type="hidden" name="pick_id" value="<?= $pid ?>">
                        <input type="hidden" name="status" value="<?= $status === 'won' ? 'lost' : 'won' ?>">
                        <button type="submit" class="vote-btn" style="font-size:0.7rem;padding:2px 8px;border-color:<?= $status === 'won' ? '#EF4444' : '#22C55E' ?>;color:<?= $status === 'won' ? '#EF4444' : '#22C55E' ?>;"><?= $status === 'won' ? 'Mark LOST' : 'Mark WON' ?></button>
                    </form>
                    <?php if ($isLoggedIn && $pUserId === $userId): ?>
                    <button class="vote-btn ie-edit-btn" style="font-size:0.7rem;padding:2px 8px;border-color:var(--pikka);color:var(--pikka);" onclick="inlineEditPick(<?= $pid ?>)" title="Edit"><i class="fas fa-pen"></i></button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>
    </div>
    <!-- Right Sidebar -->
    <div class="col-lg-3">
        <div class="pikka-sidebar" style="position:sticky;top:76px;">
        <div style="position:relative;isolation:isolate;">
        <?php if (!empty($popMatches)): ?>
            <h6 style="font-weight:700;font-size:0.85rem;margin:0 0 10px;color:var(--text);"><i class="fas fa-fire me-1" style="color:var(--pikka);"></i>Popular Matches</h6>
            <?php foreach ($popMatches as $popMatch):
                $popDetails = $db->prepare("SELECT tp.id, tp.pick, tp.odds, tp.upvotes, tp.reasoning, tp.body, tp.created_at, u.display_name, u.phone, u.avatar_color FROM tipster_picks tp JOIN web_users u ON tp.user_id = u.id WHERE tp.match_name=? AND tp.status='pending' ORDER BY tp.upvotes DESC LIMIT 3");
                $popDetails->execute([$popMatch['match_name']]);
                $popPicks = $popDetails->fetchAll();
                $seenPicks = [];
                $popPicks = array_values(array_filter($popPicks, function($pp) use (&$seenPicks) {
                    $key = $pp['pick'] ?? '__null__';
                    if (isset($seenPicks[$key])) return false;
                    $seenPicks[$key] = true;
                    return true;
                }));
            ?>
            <div style="background:linear-gradient(135deg,rgba(99,102,241,0.1) 0%,rgba(17,24,39,0.9) 100%);border:1px solid rgba(99,102,241,0.2);border-radius:10px;padding:14px;margin-bottom:10px;">
                <a href="pikka?match=<?= urlencode($popMatch['match_name']) ?>" style="font-weight:700;font-size:0.85rem;color:var(--text);text-decoration:none;display:block;"><?= htmlspecialchars($popMatch['match_name']) ?> <i class="fas fa-arrow-right" style="font-size:0.6rem;color:var(--pikka);"></i></a>
                <div style="font-size:0.7rem;color:var(--muted);margin-top:2px;"><?= $popMatch['cnt'] ?> active pick<?= $popMatch['cnt'] > 1 ? 's' : '' ?></div>
                <?php if ($popPicks): foreach ($popPicks as $pp):
                    $ppUserRaw = $pp['display_name'] ?: substr($pp['phone'] ?? '', 0, 4).'***';
                    $ppUser = htmlspecialchars($ppUserRaw);
                    $ppAvatar = $pp['avatar_color'] ?? null;
                    $ppData = htmlspecialchars(json_encode([
                        'match' => $popMatch['match_name'],
                        'pick' => $pp['pick'],
                        'odds' => (float)$pp['odds'],
                        'reasoning' => $pp['reasoning'] ?? '',
                        'body' => $pp['body'] ?? '',
                        'user' => $ppUserRaw,
                        'avatar' => $ppAvatar,
                        'upvotes' => (int)$pp['upvotes'],
                        'time' => date('j M H:i', strtotime($pp['created_at'] ?? ''))
                    ]), ENT_QUOTES);
                ?>
                <div style="font-size:0.7rem;padding:3px 0;border-bottom:1px solid rgba(99,102,241,0.1);display:flex;justify-content:space-between;align-items:center;cursor:pointer;" onclick="event.stopPropagation();openViewPost(<?= (int)$pp['id'] ?>, <?= $ppData ?>)">
                    <span style="color:var(--primary);font-weight:600;"><?= htmlspecialchars($pp['pick']) ?></span>
                    <span style="color:var(--muted);"><?= (int)$pp['upvotes'] ?> ❤️</span>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($popMatches) && !$isLoggedIn): ?>
        <div style="position:absolute;inset:0;backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);background:rgba(17,24,39,0.5);display:flex;align-items:center;justify-content:center;border-radius:10px;z-index:2;">
            <div style="text-align:center;padding:20px;">
                <i class="fas fa-lock" style="color:var(--pikka);font-size:1.5rem;margin-bottom:8px;"></i>
                <div style="font-size:0.75rem;color:var(--muted);"><a href="login?redirect=pikka" style="color:var(--pikka);">Sign in</a> to see popular matches</div>
            </div>
        </div>
        <?php elseif (empty($popMatches) && !$isLoggedIn): ?>
        <div style="padding:16px;text-align:center;">
            <i class="fas fa-lock" style="color:var(--pikka);font-size:1.1rem;margin-bottom:6px;"></i>
            <div style="font-size:0.7rem;color:var(--muted);"><a href="login?redirect=pikka" style="color:var(--pikka);">Sign in</a> to see popular matches</div>
        </div>
        <?php endif; ?>
        </div>
        <?php if (!empty($wonPicks)): ?>
            <h6 style="font-weight:700;font-size:0.85rem;margin:0 0 10px;color:var(--text);"><i class="fas fa-trophy me-1" style="color:#22C55E;"></i>Recent Winners</h6>
            <?php foreach ($wonPicks as $wp):
                if (($wp['status'] ?? 'won') !== 'won') continue;
                $wpUserRaw = $wp['display_name'] ?: substr($wp['phone'] ?? '', 0, 4).'***';
                $wpMatch = htmlspecialchars($wp['match_name'] ?? '');
                $wpData = htmlspecialchars(json_encode([
                    'match' => $wp['match_name'] ?? '',
                    'pick' => $wp['pick'],
                    'odds' => (float)$wp['odds'],
                    'reasoning' => $wp['reasoning'] ?? '',
                    'body' => $wp['body'] ?? '',
                    'user' => $wpUserRaw,
                    'avatar' => $wp['avatar_color'] ?? null,
                    'upvotes' => (int)$wp['upvotes'],
                    'time' => date('j M H:i', strtotime($wp['created_at'] ?? ''))
                ]), ENT_QUOTES);
            ?>
            <div style="background:linear-gradient(135deg,rgba(34,197,94,0.08) 0%,rgba(17,24,39,0.9) 100%);border:1px solid rgba(34,197,94,0.2);border-radius:10px;padding:10px 14px;margin-bottom:6px;cursor:pointer;" onclick="event.stopPropagation();openViewPost(<?= (int)$wp['id'] ?>, <?= $wpData ?>)">
                <div style="font-size:0.75rem;font-weight:600;color:var(--text);"><?= htmlspecialchars($wp['pick']) ?><?= $wp['odds'] ? ' @ '.number_format((float)$wp['odds'], 2) : '' ?></div>
                <div style="font-size:0.85rem;color:var(--muted);"><?= $wpMatch ?> <span class="badge" style="background:#22C55E;color:#fff;font-size:0.55rem;padding:1px 5px;border-radius:3px;">WON</span></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>
    </div>
    <?php endif; ?>

<?php elseif ($action === 'leaderboard'): ?>
    <!-- Leaderboard -->
    <div class="pikka-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 style="font-weight:800;margin:0;"><i class="fas fa-trophy me-2" style="color:var(--primary);"></i>Leaderboard</h4>
            <small style="color:var(--muted);">Top tipsters ranked by performance 🌶️</small>
        </div>
        <a href="pikka" class="btn btn-sm" style="background:var(--pikka);color:#090B0E;padding:6px 16px;border-radius:6px;text-decoration:none;font-size:0.8rem;font-weight:700;border:none;"><i class="fas fa-list me-1"></i>Recent Picks</a>
    </div>
    <div class="mb-3" style="font-size:0.85rem;color:var(--muted);"><?= count($leaderboard) ?> active tipsters</div>
    <?php if (empty($leaderboard)): ?>
    <div class="text-center py-5" style="color:var(--muted);"><i class="fas fa-trophy" style="font-size:3rem;color:var(--pikka);margin-bottom:1rem;"></i><p>No tipster data yet — be the first!</p></div>
    <?php else: $rank = 1; foreach ($leaderboard as $lb):
        $lbName = htmlspecialchars($lb['display_name'] ?: substr($lb['phone'], 0, 4).'***');
        $lbTotal = (int)$lb['total_picks'];
        $lbWon = (int)$lb['won'];
        $lbLost = (int)$lb['lost'];
        $lbLikes = (int)$lb['total_likes'];
        $lbWr = $lbTotal > 0 ? round($lbWon / max($lbWon + $lbLost, 1) * 100) : 0;
        $rClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : ''));
    ?>
    <a href="pikka?action=profile&id=<?= (int)$lb['user_id'] ?>" style="text-decoration:none;color:inherit;">
    <div class="leader-row">
        <div class="rank <?= $rClass ?>">#<?= $rank ?></div>
        <div><div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--accent));display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:700;color:#fff;"><?= strtoupper(substr($lbName, 0, 2)) ?></div></div>
        <div class="flex-grow-1"><strong><?= $lbName ?></strong></div>
        <div class="d-flex gap-3" style="font-size:0.8rem;color:var(--muted);text-align:right;">
            <span><span style="color:#22C55E;font-weight:600;"><?= $lbWon ?></span>W / <span style="color:#EF4444;font-weight:600;"><?= $lbLost ?></span>L</span>
            <span><span style="font-weight:700;"><?= $lbWr ?>%</span></span>
            <span><span style="font-weight:700;"><i class="far fa-heart" style="color:#EF4444;"></i> <?= $lbLikes ?></span> likes</span>
        </div>
    </div>
    </a>
    <?php $rank++; endforeach; endif; ?>

<?php elseif ($action === 'admin' && $isAdmin): ?>
<div class="row g-4">
    <div class="col-lg-3">
        <div class="pikka-sidebar">
            <a href="pikka" class="sidebar-item"><i class="fas fa-rss me-2"></i>Feed</a>
            <a href="pikka?action=admin" class="sidebar-item active"><i class="fas fa-shield me-2"></i>Admin</a>
        </div>
    </div>
    <div class="col-lg-9">
        <div class="pikka-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h4 style="font-weight:800;margin:0;"><i class="fas fa-shield me-2" style="color:var(--primary);"></i>Admin Panel</h4>
                <small style="color:var(--muted);">Manage premium tipsters and boosted picks</small>
            </div>
        </div>
        <h5 style="font-weight:700;font-size:1rem;margin-bottom:12px;"><i class="fas fa-crown me-1" style="color:#FFD700;"></i> Premium Tipsters</h5>
        <form method="GET" action="pikka" class="admin-search-form" style="margin-bottom:12px;display:flex;gap:8px;">
            <input type="hidden" name="action" value="admin">
            <input type="text" name="user_search" class="form-control form-control-sm search-input" placeholder="Search users by name or phone..." value="<?= htmlspecialchars($userSearch) ?>" style="max-width:300px;font-size:0.8rem;">
            <button type="submit" class="btn btn-sm btn-pikka" style="padding:4px 14px;font-size:0.75rem;">Search</button>
            <?php if ($userSearch): ?>
            <a href="pikka?action=admin" class="btn btn-sm" style="border:1px solid var(--border);color:var(--muted);padding:4px 14px;border-radius:6px;font-size:0.75rem;text-decoration:none;">Clear</a>
            <?php endif; ?>
        </form>
        <div style="font-size:0.8rem;color:var(--muted);margin-bottom:8px;"><?= count($allUsers) ?> user<?= count($allUsers) !== 1 ? 's' : '' ?></div>
        <div style="overflow-x:auto;margin-bottom:32px;">
            <table class="table table-dark table-sm" style="font-size:0.8rem;">
                <thead><tr><th>ID</th><th>Name</th><th>Phone</th><th>Premium</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($allUsers as $u): ?>
                <tr>
                    <td><?= (int)$u['id'] ?></td>
                    <td><?= htmlspecialchars($u['display_name'] ?: '—') ?></td>
                    <td><?= htmlspecialchars(substr($u['phone'], 0, 4).'***') ?></td>
                    <td><?= !empty($u['is_premium']) ? '<span style="color:#FFD700;font-weight:700;">🔱 Premium</span>' : '<span style="color:var(--muted);">No</span>' ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_premium">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="vote-btn" style="font-size:0.7rem;padding:2px 8px;border-color:<?= !empty($u['is_premium']) ? '#EF4444' : '#FFD700' ?>;color:<?= !empty($u['is_premium']) ? '#EF4444' : '#FFD700' ?>;"><?= !empty($u['is_premium']) ? 'Remove' : 'Grant' ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <h5 style="font-weight:700;font-size:1rem;margin-bottom:12px;"><i class="fas fa-rocket me-1" style="color:#FFD700;"></i> Boosted Picks</h5>
        <form method="GET" action="pikka" class="admin-search-form" style="margin-bottom:12px;display:flex;gap:8px;">
            <input type="hidden" name="action" value="admin">
            <input type="text" name="search" class="form-control form-control-sm search-input" placeholder="Search match, pick or user..." value="<?= htmlspecialchars($adminSearch) ?>" style="max-width:300px;font-size:0.8rem;">
            <button type="submit" class="btn btn-sm btn-pikka" style="padding:4px 14px;font-size:0.75rem;">Search</button>
            <?php if ($adminSearch): ?>
            <a href="pikka?action=admin" class="btn btn-sm" style="border:1px solid var(--border);color:var(--muted);padding:4px 14px;border-radius:6px;font-size:0.75rem;text-decoration:none;">Clear</a>
            <?php endif; ?>
        </form>
        <div style="font-size:0.8rem;color:var(--muted);margin-bottom:8px;"><?= $adminTotal ?> total pick<?= $adminTotal !== 1 ? 's' : '' ?></div>
        <div style="overflow-x:auto;">
            <table class="table table-dark table-sm" style="font-size:0.8rem;">
                <thead><tr><th>ID</th><th>User</th><th>Match</th><th>Pick</th><th>Odds</th><th>Views</th><th>Boosted</th><th>Expires</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($allPicks as $p): $pBoosted = !empty($p['is_boosted']) && (!empty($p['boosted_until']) && strtotime($p['boosted_until']) > time()); ?>
                <tr>
                    <td><?= (int)$p['id'] ?></td>
                    <td><?= htmlspecialchars($p['display_name'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($p['match_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($p['pick'] ?? '—') ?></td>
                    <td><?= (float)$p['odds'] > 0 ? number_format((float)$p['odds'], 2) : '—' ?></td>
                    <td><?= (int)($p['views'] ?? 0) ?></td>
                    <td><?= $pBoosted ? '<span style="color:#FFD700;font-weight:700;">🌟 Yes</span>' : '<span style="color:var(--muted);">No</span>' ?></td>
                    <td><?= $pBoosted ? date('j M H:i', strtotime($p['boosted_until'])) : '—' ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="toggle_boost">
                            <input type="hidden" name="pick_id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="vote-btn" style="font-size:0.7rem;padding:2px 8px;border-color:<?= $pBoosted ? '#EF4444' : '#FFD700' ?>;color:<?= $pBoosted ? '#EF4444' : '#FFD700' ?>;"><?= $pBoosted ? 'Unboost' : 'Boost 24h' ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php else: ?>
<div class="row g-4">
    <!-- Left Sidebar -->
    <div class="col-lg-3">
        <div class="pikka-sidebar">
            <form method="GET" action="pikka" style="margin-bottom:12px;position:relative;">
                <i class="fas fa-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:0.9rem;pointer-events:none;"></i>
                <input type="text" name="search" class="form-control search-input" placeholder="Search Pikka" value="<?= htmlspecialchars($search) ?>" style="font-size:0.9rem;padding:10px 14px 10px 38px;border-radius:12px;width:100%;">
                <input type="hidden" name="view" value="<?= $view ?>">
                <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?= $statusFilter ?>"><?php endif; ?>
                <?php if ($sort !== 'newest'): ?><input type="hidden" name="sort" value="<?= $sort ?>"><?php endif; ?>
            </form>
            <a href="pikka" class="sidebar-item <?= $view === 'feed' ? 'active' : '' ?>"><i class="fas fa-rss me-2"></i>Feed</a>
            <?php if ($isLoggedIn): ?>
            <a href="pikka?view=following" class="sidebar-item <?= $view === 'following' ? 'active' : '' ?>"><i class="fas fa-users me-2"></i>Following</a>
            <a href="pikka?view=saved" class="sidebar-item <?= $view === 'saved' ? 'active' : '' ?>"><i class="fas fa-bookmark me-2"></i>Saved</a>
            <?php endif; ?>
            <a href="pikka/leaderboard" class="sidebar-item"><i class="fas fa-trophy me-2"></i>Leaderboard</a>
            <?php if ($isLoggedIn): ?>
            <a href="pikka?action=profile&id=<?= $userId ?>" class="sidebar-item"><i class="fas fa-user me-2"></i>My Profile</a>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <a href="pikka?action=admin" class="sidebar-item"><i class="fas fa-shield me-2"></i>Admin</a>
            <?php endif; ?>
            <hr style="border-color:var(--border);margin:12px 0;">
        </div>
    </div>
    <!-- Main Content -->
    <div class="col-lg-6">
    <?php if (!empty($success)): ?>
    <div class="alert alert-success" style="background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.3);color:#22C55E;font-size:0.85rem;padding:10px 14px;border-radius:8px;">Pick posted! It's now visible to the community.</div>
    <?php elseif (!empty($error)): ?>
    <div class="alert alert-danger" style="background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);color:#EF4444;font-size:0.85rem;padding:10px 14px;border-radius:8px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($isLoggedIn): ?>
    <div class="d-flex align-items-center gap-3 mb-3 p-3" style="background:linear-gradient(135deg,rgba(99,102,241,0.06) 0%,rgba(17,24,39,0.9) 100%);border:1px solid rgba(99,102,241,0.15);border-radius:12px;cursor:pointer;" data-bs-toggle="modal" data-bs-target="#postTipModal">
        <div style="width:40px;height:40px;border-radius:50%;background:<?= $currentUser['avatar_color'] ?? 'linear-gradient(135deg,var(--pikka),var(--accent))' ?>;display:flex;align-items:center;justify-content:center;font-size:0.9rem;font-weight:700;color:#fff;flex-shrink:0;"><?= strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 2)) ?></div>
        <div style="flex-grow:1;font-size:0.85rem;color:var(--text);">Where are we cashing out today, <?= htmlspecialchars(explode(' ', $currentUser['display_name'] ?? 'Tipster')[0]) ?>?</div>
        <i class="fas fa-pen" style="color:var(--pikka);font-size:0.9rem;"></i>
    </div>
    <?php endif; ?>
    <!-- Filter + Sort -->
    <div class="d-flex flex-wrap gap-1 mb-2 align-items-center" style="font-size:0.78rem;">
        <?php
            $qsBase = '?view=' . urlencode($view) . '&search=' . urlencode($search) . '&sort=' . urlencode($sort) . '&match=' . urlencode($matchFilter);
            $statuses = ['' => 'All', 'pending' => 'Pending', 'won' => 'Won', 'lost' => 'Lost'];
            foreach ($statuses as $k => $l):
                $active = $statusFilter === $k;
        ?>
        <a href="pikka<?= $qsBase . ($k ? '&status='.$k : '') ?>" style="padding:3px 10px;border-radius:12px;border:1px solid <?= $active ? 'var(--pikka)' : 'var(--border)' ?>;background:<?= $active ? 'rgba(99,102,241,0.15)' : 'transparent' ?>;color:<?= $active ? 'var(--pikka)' : 'var(--muted)' ?>;text-decoration:none;font-weight:<?= $active ? '700' : '400' ?>;"><?= $l ?></a>
        <?php endforeach; ?>
        <?php $sorts = ['newest' => 'Newest', 'upvotes' => 'Upvoted', 'odds' => 'Highest Odds'];
            foreach ($sorts as $sk => $sl):
                $sActive = $sort === $sk;
        ?>
        <a href="pikka?view=<?= urlencode($view) ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>&sort=<?= $sk ?>&match=<?= urlencode($matchFilter) ?>" style="padding:3px 10px;border-radius:12px;border:1px solid <?= $sActive ? 'var(--pikka)' : 'var(--border)' ?>;background:<?= $sActive ? 'rgba(99,102,241,0.15)' : 'transparent' ?>;color:<?= $sActive ? 'var(--pikka)' : 'var(--muted)' ?>;text-decoration:none;font-weight:<?= $sActive ? '700' : '400' ?>;font-size:0.78rem;"><?= $sl ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($statusFilter || $search || $matchFilter): ?><div class="mb-3" style="font-size:0.85rem;color:var(--muted);"><a href="pikka?view=<?= urlencode($view) ?>" style="color:var(--muted);"><i class="fas fa-times me-1"></i>Clear filters</a><?php if ($matchFilter): ?> &middot; <span style="color:var(--pikka);font-weight:600;">Match: <?= htmlspecialchars($matchFilter) ?></span><?php endif; ?></div><?php endif; ?>
    <?php if (empty($picks)): ?>
    <div class="text-center py-5" style="color:var(--muted);"><i class="fas fa-inbox" style="font-size:3rem;color:var(--pikka);margin-bottom:1rem;"></i><p><?= $view === 'following' ? 'No picks from people you follow yet. Start following tipsters!' : ($view === 'saved' ? 'No saved picks yet. Like picks you love!' : ($statusFilter || $search || $matchFilter ? 'No picks match your filters.' : 'No picks shared yet. Be the first to share!')) ?></p></div>
    <?php else: ?>
    <?php foreach ($picks as $p): ?>
    <?= renderPickCard($p, $isLoggedIn, $userId, $isAdmin, $userVotes, $userFollows, $commentsByPick, $userSaves) ?>
    <?php endforeach; ?>
    <?php if (!empty($lockedPicks)): ?>
    <div style="border-top:1px dashed rgba(99,102,241,0.25);margin:12px 0;padding-top:12px;">
        <div style="font-size:0.7rem;color:var(--muted);text-align:center;margin-bottom:8px;text-transform:uppercase;letter-spacing:1px;"><i class="fas fa-lock me-1" style="color:var(--pikka);"></i>Locked — Sign up to see these picks</div>
        <?php foreach ($lockedPicks as $lp): ?>
        <?= renderLockedCard($lp) ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div id="load-more-sentinel" style="height:1px;"></div>
    <?php endif; ?>
    <?php if (!$isLoggedIn): ?>
    <div id="pikka-limit-banner" style="text-align:center;padding:40px 20px;background:linear-gradient(135deg,rgba(99,102,241,0.08) 0%,rgba(17,24,39,0.95) 100%);border:1px solid rgba(99,102,241,0.25);border-radius:16px;margin-bottom:12px;">
        <i class="fas fa-futbol" style="font-size:2.5rem;color:var(--pikka);margin-bottom:12px;"></i>
        <h5 style="font-weight:800;color:var(--text);">You've hit the free limit</h5>
        <p style="font-size:0.85rem;color:var(--muted);max-width:360px;margin:0 auto 16px;">Create a free account to unlock all picks, follow tipsters, save favourites, and join the Pikka community.</p>
        <a href="signup" class="btn btn-pikka" style="padding:8px 28px;font-size:0.9rem;">Create Free Account</a>
        <div style="margin-top:10px;font-size:0.75rem;color:var(--muted);">Already have an account? <a href="login?redirect=pikka" style="color:var(--pikka);">Sign in</a></div>
    </div>
    <?php endif; ?>
    </div>
    <!-- Right Sidebar -->
    <div class="col-lg-3">
        <div class="pikka-sidebar" style="position:sticky;top:76px;">
        <div style="position:relative;isolation:isolate;">
        <?php if (!empty($popMatches)): ?>
            <h6 style="font-weight:700;font-size:0.85rem;margin:0 0 10px;color:var(--text);"><i class="fas fa-fire me-1" style="color:var(--pikka);"></i>Popular Matches</h6>
            <?php foreach ($popMatches as $popMatch):
                $popDetails = $db->prepare("SELECT tp.id, tp.pick, tp.odds, tp.upvotes, tp.reasoning, tp.body, tp.created_at, u.display_name, u.phone, u.avatar_color FROM tipster_picks tp JOIN web_users u ON tp.user_id = u.id WHERE tp.match_name=? AND tp.status='pending' ORDER BY tp.upvotes DESC LIMIT 3");
                $popDetails->execute([$popMatch['match_name']]);
                $popPicks = $popDetails->fetchAll();
                $seenPicks = [];
                $popPicks = array_values(array_filter($popPicks, function($pp) use (&$seenPicks) {
                    $key = $pp['pick'] ?? '__null__';
                    if (isset($seenPicks[$key])) return false;
                    $seenPicks[$key] = true;
                    return true;
                }));
            ?>
            <div style="background:linear-gradient(135deg,rgba(99,102,241,0.1) 0%,rgba(17,24,39,0.9) 100%);border:1px solid rgba(99,102,241,0.2);border-radius:10px;padding:14px;margin-bottom:10px;">
                <a href="pikka?match=<?= urlencode($popMatch['match_name']) ?>" style="font-weight:700;font-size:0.85rem;color:var(--text);text-decoration:none;display:block;"><?= htmlspecialchars($popMatch['match_name']) ?> <i class="fas fa-arrow-right" style="font-size:0.6rem;color:var(--pikka);"></i></a>
                <div style="font-size:0.7rem;color:var(--muted);margin-top:2px;"><?= $popMatch['cnt'] ?> active pick<?= $popMatch['cnt'] > 1 ? 's' : '' ?></div>
                <?php if ($popPicks): foreach ($popPicks as $pp):
                    $ppUserRaw = $pp['display_name'] ?: substr($pp['phone'] ?? '', 0, 4).'***';
                    $ppUser = htmlspecialchars($ppUserRaw);
                    $ppAvatar = $pp['avatar_color'] ?? null;
                    $ppData = htmlspecialchars(json_encode([
                        'match' => $popMatch['match_name'],
                        'pick' => $pp['pick'],
                        'odds' => (float)$pp['odds'],
                        'reasoning' => $pp['reasoning'] ?? '',
                        'body' => $pp['body'] ?? '',
                        'user' => $ppUserRaw,
                        'avatar' => $ppAvatar,
                        'upvotes' => (int)$pp['upvotes'],
                        'time' => date('j M H:i', strtotime($pp['created_at'] ?? ''))
                    ]), ENT_QUOTES);
                ?>
                <div style="font-size:0.7rem;padding:3px 0;border-bottom:1px solid rgba(99,102,241,0.1);display:flex;justify-content:space-between;align-items:center;cursor:pointer;" onclick="event.stopPropagation();openViewPost(<?= (int)$pp['id'] ?>, <?= $ppData ?>)">
                    <span style="color:var(--primary);font-weight:600;"><?= htmlspecialchars($pp['pick']) ?></span>
                    <span style="color:var(--muted);"><?= (int)$pp['upvotes'] ?> ❤️</span>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($popMatches) && !$isLoggedIn): ?>
        <div style="position:absolute;inset:0;backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);background:rgba(17,24,39,0.5);display:flex;align-items:center;justify-content:center;border-radius:10px;z-index:2;">
            <div style="text-align:center;padding:20px;">
                <i class="fas fa-lock" style="color:var(--pikka);font-size:1.5rem;margin-bottom:8px;"></i>
                <div style="font-size:0.75rem;color:var(--muted);"><a href="login?redirect=pikka" style="color:var(--pikka);">Sign in</a> to see popular matches</div>
            </div>
        </div>
        <?php elseif (empty($popMatches) && !$isLoggedIn): ?>
        <div style="padding:16px;text-align:center;">
            <i class="fas fa-lock" style="color:var(--pikka);font-size:1.1rem;margin-bottom:6px;"></i>
            <div style="font-size:0.7rem;color:var(--muted);"><a href="login?redirect=pikka" style="color:var(--pikka);">Sign in</a> to see popular matches</div>
        </div>
        <?php endif; ?>
        </div>
        <?php if (!empty($wonPicks)): ?>
            <h6 style="font-weight:700;font-size:0.85rem;margin:0 0 10px;color:var(--text);"><i class="fas fa-trophy me-1" style="color:#22C55E;"></i>Recent Winners</h6>
            <?php foreach ($wonPicks as $wp):
                if (($wp['status'] ?? 'won') !== 'won') continue;
                $wpUserRaw = $wp['display_name'] ?: substr($wp['phone'] ?? '', 0, 4).'***';
                $wpMatch = htmlspecialchars($wp['match_name'] ?? '');
                $wpData = htmlspecialchars(json_encode([
                    'match' => $wp['match_name'] ?? '',
                    'pick' => $wp['pick'],
                    'odds' => (float)$wp['odds'],
                    'reasoning' => $wp['reasoning'] ?? '',
                    'body' => $wp['body'] ?? '',
                    'user' => $wpUserRaw,
                    'avatar' => $wp['avatar_color'] ?? null,
                    'upvotes' => (int)$wp['upvotes'],
                    'time' => date('j M H:i', strtotime($wp['created_at'] ?? ''))
                ]), ENT_QUOTES);
            ?>
            <div style="background:linear-gradient(135deg,rgba(34,197,94,0.08) 0%,rgba(17,24,39,0.9) 100%);border:1px solid rgba(34,197,94,0.2);border-radius:10px;padding:10px 14px;margin-bottom:6px;cursor:pointer;" onclick="event.stopPropagation();openViewPost(<?= (int)$wp['id'] ?>, <?= $wpData ?>)">
                <div style="font-size:0.75rem;font-weight:600;color:var(--text);"><?= htmlspecialchars($wp['pick']) ?><?= $wp['odds'] ? ' @ '.number_format((float)$wp['odds'], 2) : '' ?></div>
                <div style="font-size:0.85rem;color:var(--muted);"><?= $wpMatch ?> <span class="badge" style="background:#22C55E;color:#fff;font-size:0.55rem;padding:1px 5px;border-radius:3px;">WON</span></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; /* end profile/leaderboard/list/admin */ ?>
</div>

<?php if ($isLoggedIn): ?>
<button class="fab-post" data-bs-toggle="modal" data-bs-target="#postTipModal" title="Post a Pick">+</button>
<?php endif; ?>
<footer style="background:var(--secondary);border-top:1px solid var(--border);padding:40px 0;margin-top:60px;">
    <div class="container">
        <div class="row">
            <div class="col-md-4">
                <h5 class="mb-3" style="font-weight:700;color:var(--text);"><i class="fas fa-futbol me-1" style="color:var(--accent);"></i>PREDIXA</h5>
                <p style="font-size:0.85rem;color:var(--muted);">AI-powered football analytics, daily picks, and a tipster marketplace. Subscribe, bet, publish codes, and earn.</p>
            </div>
            <div class="col-md-2">
                <h6 class="mb-3" style="font-weight:700;color:var(--text);">Quick Links</h6>
                <ul class="list-unstyled" style="font-size:0.85rem;">
                    <li class="mb-2"><a href="./#pricing" style="color:var(--muted);text-decoration:none;"><i class="fas fa-tag me-1" style="color:var(--accent);"></i> Plans</a></li>
                    <li class="mb-2"><?php if (isset($_SESSION['user_id'])): ?><a href="dashboard" style="color:var(--muted);text-decoration:none;"><i class="fas fa-gauge-high me-1" style="color:var(--accent);"></i> Dashboard</a><?php else: ?><a href="login?redirect=pikka" style="color:var(--muted);text-decoration:none;"><i class="fas fa-right-to-bracket me-1" style="color:var(--primary);"></i> Login</a><?php endif; ?></li>
                    <?php if (!isset($_SESSION['user_id'])): ?><li class="mb-2"><a href="signup" style="color:var(--muted);text-decoration:none;"><i class="fas fa-user-plus me-1" style="color:#22C55E;"></i> Sign Up</a></li><?php endif; ?>
                    <li class="mb-2"><a href="./#codes-faq" style="color:var(--muted);text-decoration:none;"><i class="fas fa-circle-question me-1" style="color:var(--primary);"></i> FAQ</a></li>
                    <li class="mb-2"><a href="https://www.begambleaware.org/" target="_blank" rel="noopener noreferrer" style="color:var(--muted);text-decoration:none;"><i class="fas fa-shield-halved me-1" style="color:#10B981;"></i> Responsible Gambling</a></li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6 class="mb-3" style="font-weight:700;color:var(--text);">Free Tools</h6>
                <ul class="list-unstyled" style="font-size:0.85rem;">
                    <li class="mb-2"><a href="dropping-odds" style="color:var(--muted);text-decoration:none;"><i class="fas fa-arrow-trend-down me-1" style="color:#EF4444;"></i> Dropping Odds</a></li>
                    <li class="mb-2"><a href="signals" style="color:var(--muted);text-decoration:none;"><i class="fas fa-microchip me-1" style="color:#22C55E;"></i> Smart Picks</a></li>
                    <li class="mb-2"><a href="track-record" style="color:var(--muted);text-decoration:none;"><i class="fas fa-chart-line me-1" style="color:#FBBF24;"></i> Performance</a></li>
                    <li class="mb-2"><a href="betting-school" style="color:var(--muted);text-decoration:none;"><i class="fas fa-book me-1" style="color:#8B5CF6;"></i> Betting School</a></li>
                    <li class="mb-2"><a href="pikka" style="color:var(--muted);text-decoration:none;"><i class="fas fa-futbol me-1" style="color:var(--pikka);"></i> Pikka</a></li>
                </ul>
            </div>
            <div class="col-md-3">
                <h6 class="mb-3" style="font-weight:700;color:var(--text);">Support</h6>
                <ul class="list-unstyled" style="font-size:0.85rem;">
                    <li class="mb-2"><a href="https://wa.me/255713348298" target="_blank" style="color:#25D366;text-decoration:none;"><i class="fab fa-whatsapp me-1"></i> WhatsApp</a></li>
                    <li class="mb-2"><a href="mailto:support@predixa.co.tz" style="color:var(--muted);text-decoration:none;"><i class="fas fa-envelope me-1" style="color:var(--primary);"></i> Email Us</a></li>
                    <li class="mb-2" style="color:var(--muted);"><i class="fas fa-clock me-1" style="color:var(--accent);"></i> 24/7 Support</li>
                    <li class="mb-2"><a href="terms" style="color:var(--muted);text-decoration:none;"><i class="fas fa-file-lines me-1" style="color:var(--muted);"></i> Terms of Service</a></li>
                </ul>
            </div>
        </div>
        <div class="border-top border-secondary mt-4 pt-4 text-center" style="color:var(--muted);font-size:0.8rem;">
            <small>&copy; <?= date('Y') ?> Predixa. All rights reserved. | 18+ | Bet Responsibly</small>
        </div>
    </div>
</footer>

<script>
function like(pickId) {
    fetch('pikka', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=like&pick_id='+pickId })
    .then(r=>r.json()).then(d=>{
        if(d.ok) {
            var btn = document.querySelector('#pick-'+pickId+' .heart-btn');
            if(btn) {
                btn.classList.toggle('liked', d.liked);
                btn.innerHTML = (d.liked ? '<i class="fas fa-heart"></i>' : '<i class="far fa-heart"></i>') + ' <span id="like-'+pickId+'">'+d.count+'</span>';
            }
        }
    });
}
function follow(targetId) {
    fetch('pikka', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=follow&user_id='+targetId })
    .then(r=>r.json()).then(d=>{
        if(d.ok) {
            var btn = document.getElementById('follow-'+targetId);
            if(btn) {
                btn.classList.toggle('following', d.following);
                btn.innerHTML = (d.following ? '<i class="fas fa-user-plus"></i>' : '<i class="far fa-user-plus"></i>') + ' <span>'+(d.following ? 'Following' : 'Follow')+'</span>';
            }
            // Update follower count if on profile page
            var fc = document.querySelector('.followers-count');
            if(fc) fc.textContent = d.followers;
        }
    });
}
function togglePin(pickId) {
    fetch('pikka', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=toggle_pin&pick_id='+pickId })
    .then(r=>r.json()).then(d=>{
        if(d.ok) {
            var btn = document.querySelector('#pick-'+pickId+' .vote-btn[onclick*="togglePin"]');
            if(btn) btn.style.borderColor = d.pinned ? '#22C55E' : 'var(--pikka)';
        }
    });
}
function toggleSave(pickId) {
    fetch('pikka', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=toggle_save&pick_id='+pickId })
    .then(r=>r.json()).then(d=>{
        if(d.ok) {
            var btn = document.querySelector('#pick-'+pickId+' .save-btn');
            if(btn) {
                btn.innerHTML = d.saved ? '<i class="fas fa-bookmark"></i>' : '<i class="far fa-bookmark"></i>';
                btn.style.color = d.saved ? '#6366F1' : 'var(--muted)';
                btn.style.borderColor = d.saved ? 'var(--pikka)' : 'var(--border)';
            }
        }
    });
}
function editBio() {
    var modal = new bootstrap.Modal(document.getElementById('editProfileModal'));
    modal.show();
}
var selectedColor = '<?= ($isLoggedIn && $currentUser) ? ($currentUser['avatar_color'] ?? '#6366F1') : '#6366F1' ?>';
function selectAvatarColor(el, color) {
    document.querySelectorAll('.av-color-swatch').forEach(function(s) { s.style.borderColor = 'transparent'; });
    el.style.borderColor = '#fff';
    selectedColor = color;
}
function saveBio() {
    var bio = document.getElementById('bioInput').value.trim();
    fetch('pikka', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=update_bio&bio='+encodeURIComponent(bio)+'&avatar_color='+encodeURIComponent(selectedColor) })
    .then(r=>r.json()).then(d=>{
        if(d.ok) {
            var el = document.querySelector('.profile-bio-text');
            if(el) {
                if(bio) { el.textContent = bio; el.style.display = ''; }
                else el.style.display = 'none';
            }
            // Update avatar preview
            var av = document.getElementById('profile-avatar');
            if(av) av.style.background = selectedColor;
            bootstrap.Modal.getInstance(document.getElementById('editProfileModal')).hide();
        }
    });
}
function copyPickLink(btn, pid, pick) {
    var text = decodeURIComponent(pick) + ' — shared on Pikka';
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            btn.innerHTML = '<i class="fas fa-check" style="color:#22C55E;"></i>';
            setTimeout(function() { btn.innerHTML = '<i class="fas fa-copy"></i>'; }, 2000);
        }).catch(function() {
            btn.innerHTML = 'Failed';
            setTimeout(function() { btn.innerHTML = '<i class="fas fa-copy"></i>'; }, 2000);
        });
    } else {
        btn.innerHTML = 'Not supported';
        setTimeout(function() { btn.innerHTML = '<i class="fas fa-copy"></i>'; }, 2000);
    }
}
document.querySelectorAll('.comment-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var input = this.querySelector('input');
        var body = input.value.trim();
        var pickId = this.dataset.pick;
        if (!body) return;
        var btn = this.querySelector('button');
        btn.disabled = true;
        fetch('pikka', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=comment&pick_id='+pickId+'&body='+encodeURIComponent(body) })
        .then(r=>r.json()).then(d=>{
            if(d.ok) {
                input.value = '';
                var list = document.getElementById('comments-'+pickId).querySelector('.comment-list');
                list.querySelector('.text-center').style.display = 'none';
                var div = document.createElement('div');
                div.style.cssText = 'font-size:0.78rem;padding:4px 0;border-bottom:1px solid rgba(45,49,66,0.5);';
                div.innerHTML = '<strong style="color:var(--primary);">'+d.name+'</strong><span style="color:var(--muted);font-size:0.7rem;margin-left:4px;">'+d.time+'</span><div style="color:var(--text);">'+d.body+'</div>';
                list.insertBefore(div, list.querySelector('.text-center'));
                // Update comment count toggle
                var toggle = document.querySelector('.comment-toggle[data-pick="'+pickId+'"]');
                if(toggle) toggle.innerHTML = '<i class="fas fa-comment"></i> '+(list.querySelectorAll('div[style*="padding:4px 0"]').length);
            }
        }).finally(function() { btn.disabled = false; });
    });
});
document.querySelectorAll('.comment-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var pickId = this.dataset.pick;
        document.getElementById('comments-'+pickId).classList.toggle('d-none');
    });
});
<?php if ($isLoggedIn): ?>
// Auto-open post modal if ?post=1
if (window.location.search.indexOf('post=1') !== -1) {
    var postModal = new bootstrap.Modal(document.getElementById('postTipModal'));
    postModal.show();
}
function selectLeague(el, name) {
    document.querySelectorAll('.league-badge').forEach(function(b) { b.style.borderColor = 'var(--border)'; b.style.color = 'var(--muted)'; b.style.background = 'transparent'; });
    el.style.borderColor = 'var(--pikka)'; el.style.color = 'var(--pikka)'; el.style.background = 'rgba(99,102,241,0.12)';
    document.getElementById('leagueInput').value = name;
}
<?php endif; ?>

<?php if (isset($perPage)): ?>
// Infinite scroll
(function() {
    let page = 1;
    let hasMore = true;
    let loading = false;
    const sentinel = document.getElementById('load-more-sentinel');
    <?php if (!$isLoggedIn): ?>
    if (sentinel) sentinel.style.display = 'none';
    <?php endif; ?>
    if (sentinel) {
        const observer = new IntersectionObserver(async (entries) => {
            if (entries[0].isIntersecting && hasMore && !loading) {
                loading = true;
                page++;
                const params = new URLSearchParams({
                    action: 'load_more',
                    page: page,
                    view: '<?= $view ?>',
                    search: '<?= htmlspecialchars($search) ?>',
                    sort: '<?= $sort ?>',
                    status: '<?= $statusFilter ?>'
                });
                try {
                    const res = await fetch('pikka?' + params.toString());
                    const data = await res.json();
                    if (data.locked) {
                        hasMore = false;
                    } else if (data.html && data.html.length) {
                        for (const h of data.html) {
                            sentinel.parentNode.insertBefore(
                                document.createRange().createContextualFragment(h),
                                sentinel
                            );
                        }
                        hasMore = data.hasMore;
                    }
                } catch(e) {}
                loading = false;
            }
        }, { rootMargin: '400px' });
        observer.observe(sentinel);
    }
})();
<?php endif; ?>
// View tracking
(function() {
    const tracked = new Set();
    const obs = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                const card = entry.target;
                const pid = card.dataset.pid;
                if (pid && !tracked.has(pid)) {
                    tracked.add(pid);
                    fetch('pikka?action=track_view&pick_id=' + pid, { method: 'GET' }).catch(function(){});
                }
            }
        }
    }, { rootMargin: '200px' });
    function observeCards() {
        document.querySelectorAll('.pick-card:not([data-view-observed])').forEach(function(c) {
            c.setAttribute('data-view-observed', '1');
            obs.observe(c);
        });
    }
    document.addEventListener('DOMContentLoaded', observeCards);
    // Re-observe after infinite scroll loads new cards
    const origInsert = Node.prototype.insertBefore;
    Node.prototype.insertBefore = function() {
        const r = origInsert.apply(this, arguments);
        setTimeout(observeCards, 100);
        return r;
    };
})();

// Inline edit — picks (structured and text-only)
function inlineEditPick(pid) {
    var card = document.getElementById('pick-' + pid);
    if (!card) return;
    var group = card.querySelector('.ie-group');
    // If already editing, toggle back
    var target = group || card.querySelector('.ie-body');
    if (!target) return;
    if (target.dataset.editing === '1') { cancelEditPick(pid); return; }
    // Hide edit button, show save/cancel
    var editBtn = card.querySelector('.ie-edit-btn');
    if (editBtn) editBtn.style.display = 'none';
    // Create action bar
    var actionBar = document.createElement('div');
    actionBar.className = 'ie-actions';
    actionBar.style.cssText = 'margin-top:6px;display:flex;gap:6px;';
    actionBar.innerHTML = '<button class="vote-btn" style="font-size:0.7rem;padding:2px 10px;border-color:#22C55E;color:#22C55E;" onclick="saveEditPick(' + pid + ')">Save</button>' +
        '<button class="vote-btn" style="font-size:0.7rem;padding:2px 10px;border-color:var(--muted);color:var(--muted);" onclick="cancelEditPick(' + pid + ')">Cancel</button>';
    target.parentNode.insertBefore(actionBar, target.nextSibling);
    // Collect ALL editable fields in card (inside or outside .ie-group)
    var fields = card.querySelectorAll('.ie-group .ie-field, .ie-body, .ie-reasoning');
    fields.forEach(function(field) {
        var valEl = field.querySelector('.ie-val');
        if (!valEl) return;
        var fname = '';
        if (field.classList.contains('ie-match')) fname = 'match';
        else if (field.classList.contains('ie-league')) fname = 'league';
        else if (field.classList.contains('ie-pick')) fname = 'pick';
        else if (field.classList.contains('ie-odds')) fname = 'odds';
        else if (field.classList.contains('ie-body')) fname = 'body';
        else if (field.classList.contains('ie-reasoning')) fname = 'reasoning';
        var val = valEl.textContent.trim();
        if (fname === 'body' || fname === 'reasoning') {
            var ta = document.createElement('textarea');
            ta.className = 'form-control form-control-sm ie-input';
            ta.style.cssText = 'background:var(--bg);border:1px solid var(--border);color:var(--text);font-size:0.8rem;width:100%;margin-top:2px;';
            ta.rows = 3;
            ta.value = val;
            valEl.style.display = 'none';
            valEl.parentNode.insertBefore(ta, valEl.nextSibling);
        } else if (fname === 'odds') {
            var inp = document.createElement('input');
            inp.type = 'number'; inp.step = '0.01'; inp.min = '1.01';
            inp.className = 'form-control form-control-sm ie-input';
            inp.style.cssText = 'background:var(--bg);border:1px solid var(--border);color:var(--text);font-size:0.8rem;width:90px;display:inline-block;';
            inp.value = val.replace(/[^0-9.]/g, '');
            valEl.style.display = 'none';
            valEl.parentNode.insertBefore(inp, valEl.nextSibling);
        } else {
            var inp = document.createElement('input');
            inp.type = 'text';
            inp.className = 'form-control form-control-sm ie-input';
            inp.style.cssText = 'background:var(--bg);border:1px solid var(--border);color:var(--text);font-size:0.8rem;display:inline-block;';
            if (fname === 'match') inp.style.width = '280px';
            else if (fname === 'league') inp.style.width = '140px';
            else if (fname === 'pick') inp.style.width = '160px';
            inp.value = val;
            valEl.style.display = 'none';
            valEl.parentNode.insertBefore(inp, valEl.nextSibling);
        }
    });
    target.dataset.editing = '1';
}

function saveEditPick(pid) {
    var card = document.getElementById('pick-' + pid);
    if (!card) return;
    var group = card.querySelector('.ie-group');
    var target = group || card.querySelector('.ie-body');
    if (!target) return;
    // Collect values from ALL inputs in card
    var data = { pick_id: pid, action: 'edit_pick' };
    card.querySelectorAll('.ie-input').forEach(function(input) {
        var field = input.closest('.ie-field, .ie-body');
        if (!field) return;
        var fname = '';
        if (field.classList.contains('ie-match')) fname = 'match';
        else if (field.classList.contains('ie-league')) fname = 'league';
        else if (field.classList.contains('ie-pick')) fname = 'pick';
        else if (field.classList.contains('ie-odds')) fname = 'odds';
        else if (field.classList.contains('ie-body')) fname = 'body';
        else if (field.classList.contains('ie-reasoning')) fname = 'reasoning';
        if (fname) data[fname] = input.value.trim();
    });
    var hasStructured = group && group.querySelector('.ie-match') !== null;
    if (hasStructured && (!data.match || !data.pick)) { alert('Match and Pick are required.'); return; }
    var params = new URLSearchParams(data);
    console.log('[saveEditPick] sending:', Object.fromEntries(params));
    fetch('pikka?ajax=1', { method: 'POST', body: params, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
    .then(function(r) { console.log('[saveEditPick] status:', r.status); return r.json(); })
    .then(function(resp) {
        console.log('[saveEditPick] response:', resp);
        if (resp.ok) {
            // Update ALL displayed values in card
            card.querySelectorAll('.ie-input').forEach(function(input) {
                var field = input.closest('.ie-field, .ie-body');
                if (!field) return;
                var valEl = field.querySelector('.ie-val');
                if (!valEl) { console.log('[saveEditPick] no valEl for', field.className); return; }
                var newVal = input.value.trim();
                var fname = '';
                if (field.classList.contains('ie-match')) fname = 'match';
                else if (field.classList.contains('ie-league')) fname = 'league';
                else if (field.classList.contains('ie-pick')) fname = 'pick';
                else if (field.classList.contains('ie-odds')) fname = 'odds';
                else if (field.classList.contains('ie-body')) fname = 'body';
                else if (field.classList.contains('ie-reasoning')) fname = 'reasoning';
                if (fname === 'odds') newVal = newVal ? '@ ' + parseFloat(newVal).toFixed(2) : '';
                console.log('[saveEditPick] updating', fname, '->', newVal);
                valEl.innerHTML = newVal || valEl.innerHTML;
                input.remove();
                valEl.style.display = '';
            });
            cancelEditPick(pid);
        } else {
            alert('Failed to save. Try again.');
        }
    })
    .catch(function(e) { console.error('[saveEditPick] error:', e); alert('Network error.'); });
}

function cancelEditPick(pid) {
    var card = document.getElementById('pick-' + pid);
    if (!card) return;
    var group = card.querySelector('.ie-group');
    var target = group || card.querySelector('.ie-body');
    if (!target) return;
    // Remove inputs, show vals
    card.querySelectorAll('.ie-input').forEach(function(el) { el.remove(); });
    card.querySelectorAll('.ie-val').forEach(function(el) { el.style.display = ''; });
    // Remove action bar
    var actions = target.parentNode.querySelector('.ie-actions');
    if (actions) actions.remove();
    // Show edit button
    var editBtn = card.querySelector('.ie-edit-btn');
    if (editBtn) editBtn.style.display = '';
    delete target.dataset.editing;
}

// Inline edit — comments
function inlineEditComment(cid, btn) {
    var container = btn.closest('[style*="border-bottom"]') || btn.parentNode;
    if (!container) return;
    var bodyDiv = container.querySelector('.comment-body-' + cid);
    if (!bodyDiv) return;
    if (container.dataset.editing === '1') { return; }
    var currentText = bodyDiv.textContent.trim();
    bodyDiv.style.display = 'none';
    var ta = document.createElement('textarea');
    ta.className = 'form-control form-control-sm';
    ta.style.cssText = 'background:var(--bg);border:1px solid var(--border);color:var(--text);font-size:0.8rem;width:100%;margin-top:2px;';
    ta.rows = 2;
    ta.value = currentText;
    bodyDiv.parentNode.insertBefore(ta, bodyDiv.nextSibling);
    var saveBtn = document.createElement('button');
    saveBtn.textContent = 'Save';
    saveBtn.className = 'vote-btn';
    saveBtn.style.cssText = 'font-size:0.6rem;padding:1px 6px;margin-left:4px;border-color:#22C55E;color:#22C55E;';
    saveBtn.onclick = function() { saveEditComment(cid, ta, bodyDiv, container); };
    var cancelBtn = document.createElement('button');
    cancelBtn.textContent = 'Cancel';
    cancelBtn.className = 'vote-btn';
    cancelBtn.style.cssText = 'font-size:0.6rem;padding:1px 6px;margin-left:4px;border-color:var(--muted);color:var(--muted);';
    cancelBtn.onclick = function() { cancelEditComment(cid, ta, bodyDiv, container); };
    btn.style.display = 'none';
    var actions = document.createElement('span');
    actions.className = 'ie-comment-actions';
    actions.appendChild(saveBtn);
    actions.appendChild(cancelBtn);
    container.insertBefore(actions, bodyDiv.nextSibling);
    container.dataset.editing = '1';
}

function saveEditComment(cid, ta, bodyDiv, container) {
    var newBody = ta.value.trim();
    if (!newBody) { alert('Comment cannot be empty.'); return; }
    var params = new URLSearchParams({ action: 'edit_comment', comment_id: cid, body: newBody });
    fetch('pikka', { method: 'POST', body: params, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
    .then(function(r) { return r.json(); })
    .then(function(resp) {
        if (resp.ok) {
            bodyDiv.textContent = newBody;
            cancelEditComment(cid, ta, bodyDiv, container);
        } else {
            alert('Failed to save comment.');
        }
    })
    .catch(function() { alert('Network error.'); });
}

function cancelEditComment(cid, ta, bodyDiv, container) {
    if (ta && ta.parentNode) ta.remove();
    bodyDiv.style.display = '';
    container.querySelector('.ie-comment-actions')?.remove();
    container.querySelectorAll('.vote-btn').forEach(function(b) {
        if (b.textContent === 'Edit' || b.innerHTML.indexOf('fa-pen') > -1) b.style.display = '';
    });
    delete container.dataset.editing;
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($isLoggedIn): ?>
<!-- Post a Pick Modal (casual) -->
<div class="modal fade" id="postTipModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="background:var(--secondary);border:1px solid var(--border);border-radius:12px;">
<form method="POST" action="pikka" id="postPickForm">
<input type="hidden" name="action" value="create_pick">
<div class="modal-header" style="border-bottom:1px solid var(--border);padding:16px 20px;">
    <h5 style="font-weight:700;margin:0;font-size:1rem;"><i class="fas fa-pen me-2" style="color:var(--pikka);"></i>Post</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" style="padding:20px;display:flex;flex-direction:column;gap:10px;">
    <div style="display:flex;align-items:flex-start;gap:12px;">
        <div style="width:40px;height:40px;border-radius:50%;background:<?= $currentUser['avatar_color'] ?? 'linear-gradient(135deg,var(--pikka),var(--accent))' ?>;display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:700;color:#fff;flex-shrink:0;"><?= strtoupper(substr($currentUser['display_name'] ?? 'U', 0, 2)) ?></div>
        <textarea name="body" id="postBody" class="form-control" rows="3" placeholder="What are we cashing out today, <?= htmlspecialchars(explode(' ', $currentUser['display_name'] ?? 'Tipster')[0]) ?>?" style="flex:1;background:var(--bg);border:1px solid var(--border);color:var(--text);font-size:0.95rem;resize:none;border-radius:8px;padding:10px;"></textarea>
    </div>
    <div style="text-align:right;font-size:0.75rem;color:var(--muted);" id="charCount">0</div>

    <!-- Collapsible structured fields -->
    <div id="pickDetailsToggle" style="cursor:pointer;user-select:none;" onclick="togglePickDetails()">
        <span style="color:var(--pikka);font-size:0.8rem;font-weight:600;"><i class="fas fa-plus-circle me-1"></i> Add match details</span>
    </div>
    <div id="pickDetailsFields" style="display:none;flex-direction:column;gap:10px;">
        <div class="row g-2">
            <div class="col-8">
                <input type="text" name="match_name" id="postMatch" class="form-control form-control-sm" placeholder="Match (e.g. Arsenal vs Chelsea)" style="background:var(--bg);border:1px solid var(--border);color:var(--text);font-size:0.85rem;">
            </div>
            <div class="col-4">
                <input type="date" name="match_date" class="form-control form-control-sm" style="background:var(--bg);border:1px solid var(--border);color:var(--text);font-size:0.85rem;">
            </div>
        </div>
        <div>
            <label style="font-size:0.7rem;color:var(--muted);font-weight:600;margin-bottom:2px;">League</label>
            <div class="league-badges d-flex flex-wrap gap-1 mb-1">
                <?php $leagues = ['EPL', 'La Liga', 'Serie A', 'Bundesliga', 'Ligue 1', 'UCL', 'UEL', 'TPLPremier', 'KPL', 'CAF CL', 'FA Cup']; ?>
                <?php foreach ($leagues as $lg): ?>
                <span class="league-badge" onclick="selectLeague(this,'<?= $lg ?>')" style="cursor:pointer;padding:2px 8px;border-radius:4px;font-size:0.65rem;font-weight:600;border:1px solid var(--border);color:var(--muted);background:transparent;transition:all .15s;"><?= $lg ?></span>
                <?php endforeach; ?>
                <span class="league-badge custom-league" onclick="document.getElementById('postLeague').focus()" style="cursor:pointer;padding:2px 8px;border-radius:4px;font-size:0.65rem;font-weight:600;border:1px dashed var(--border);color:var(--muted);background:transparent;transition:all .15s;">+ Custom</span>
            </div>
            <input type="text" name="league" id="postLeague" class="form-control form-control-sm" placeholder="Type or tap above" style="background:var(--bg);border:1px solid var(--border);color:var(--text);font-size:0.85rem;">
        </div>
        <div>
            <textarea name="pick" id="postPick" class="form-control" rows="2" placeholder="Pick (e.g. Home Win, or long bet builder market)" style="background:var(--bg);border:1px solid var(--border);color:var(--text);font-size:0.85rem;resize:none;"><?= htmlspecialchars($_GET['pick'] ?? '') ?></textarea>
        </div>
        <div class="row g-2">
            <div class="col-6">
                <input type="number" step="0.01" min="1.01" name="odds" class="form-control form-control-sm" placeholder="Odds" style="background:var(--bg);border:1px solid var(--border);color:var(--text);font-size:0.85rem;">
            </div>
            <div class="col-6">
                <button type="button" class="btn btn-sm w-100" id="parseBtn" onclick="autoFillPost()" style="background:rgba(99,102,241,0.12);color:var(--pikka);border:1px solid rgba(99,102,241,0.3);font-size:0.7rem;font-weight:600;padding:4px;"><i class="fas fa-wand-magic-sparkles me-1"></i>Auto-fill</button>
            </div>
        </div>
    </div>
</div>
<div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 20px;gap:6px;">
    <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--border);color:var(--muted);padding:6px 16px;border-radius:6px;font-size:0.8rem;">Cancel</button>
    <button type="submit" class="btn btn-sm" style="background:var(--pikka);color:#090B0E;padding:6px 20px;border-radius:6px;font-size:0.8rem;font-weight:700;border:none;">Post</button>
</div>
</form>
</div>
</div>
</div>
<script>
document.getElementById('postBody')?.addEventListener('input', function() {
    document.getElementById('charCount').textContent = this.value.length;
});
function togglePickDetails() {
    var el = document.getElementById('pickDetailsFields');
    var toggle = document.getElementById('pickDetailsToggle');
    if (el.style.display === 'none' || el.style.display === '') {
        el.style.display = 'flex';
        toggle.innerHTML = '<span style="color:var(--pikka);font-size:0.8rem;font-weight:600;"><i class="fas fa-minus-circle me-1"></i> Hide match details</span>';
    } else {
        el.style.display = 'none';
        toggle.innerHTML = '<span style="color:var(--pikka);font-size:0.8rem;font-weight:600;"><i class="fas fa-plus-circle me-1"></i> Add match details</span>';
    }
}
function autoFillPost() {
    var body = document.getElementById('postBody').value.trim();
    if (!body) return;
    // Auto-parse patterns: "Team vs Team - Pick @ Odds" or "Team vs Team - Pick"
    var match = '', pick = '', odds = '';
    // Try "Team vs Team - Pick @ Odds" or "Team v Team - Pick @ Odds"
    var m = body.match(/^([A-Za-z0-9\s\.\-\']+)\s+(?:vs|v\.|V\.|VS|Vs)\s+([A-Za-z0-9\s\.\-\']+)\s*[-–]\s*(.+?)(?:\s*@\s*([\d\.]+))?$/i);
    if (m) {
        match = m[1].trim() + ' vs ' + m[2].trim();
        pick = m[3].trim();
        odds = m[4] || '';
    }
    // Try "Team vs Team @ Odds" (no pick dashed)
    if (!match) {
        var m2 = body.match(/^([A-Za-z0-9\s\.\-\']+)\s+(?:vs|v\.|V\.|VS|Vs)\s+([A-Za-z0-9\s\.\-\']+)(?:\s*[-–]\s*(.+?))?\s*@\s*([\d\.]+)$/i);
        if (m2) {
            match = m2[1].trim() + ' vs ' + m2[2].trim();
            pick = m2[3] ? m2[3].trim() : '';
            odds = m2[4];
        }
    }
    if (match) document.getElementById('postMatch').value = match;
    if (pick) document.getElementById('postPick').value = pick;
    if (odds) document.querySelector('#postPickForm input[name="odds"]').value = odds;

    if (match || pick || odds) {
        document.getElementById('pickDetailsFields').style.display = 'flex';
        document.getElementById('pickDetailsToggle').innerHTML = '<span style="color:var(--pikka);font-size:0.8rem;font-weight:600;"><i class="fas fa-minus-circle me-1"></i> Hide match details</span>';
    }
}
</script>
<?php endif; ?>

<?php if ($isLoggedIn): ?>
<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content" style="background:var(--secondary);border:1px solid var(--border);border-radius:12px;">
<div class="modal-header" style="border-bottom:1px solid var(--border);padding:16px 20px;">
    <h5 style="font-weight:700;margin:0;font-size:1rem;"><i class="fas fa-user-pen me-2" style="color:var(--pikka);"></i>Edit Profile</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" style="padding:20px;display:flex;flex-direction:column;gap:12px;">
    <label style="font-size:0.8rem;color:var(--muted);font-weight:600;">Avatar Color</label>
    <div style="display:flex;gap:8px;flex-wrap:wrap;" id="avatarColors">
        <?php $colors = ['#6366F1','#DC2626','#22C55E','#F59E0B','#06B6D4','#EC4899','#8B5CF6','#F97316','#14B8A6','#64748B'];
        $curColor = $currentUser['avatar_color'] ?? '#6366F1';
        foreach ($colors as $c): ?>
        <div class="av-color-swatch" data-color="<?= $c ?>" style="width:36px;height:36px;border-radius:50%;background:<?= $c ?>;cursor:pointer;border:3px solid <?= $c === $curColor ? '#fff' : 'transparent' ?>;transition:0.2s;" onclick="selectAvatarColor(this,'<?= $c ?>')"></div>
        <?php endforeach; ?>
    </div>
    <label style="font-size:0.8rem;color:var(--muted);font-weight:600;">Bio</label>
    <textarea id="bioInput" class="form-control form-control-sm" rows="3" placeholder="Tell tipsters about yourself..." style="background:var(--bg);border:1px solid var(--border);color:var(--text);font-size:0.85rem;resize:vertical;"><?= isset($profileUser) ? htmlspecialchars($profileUser['bio'] ?? '') : '' ?></textarea>
</div>
<div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 20px;">
    <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--border);color:var(--muted);padding:6px 16px;border-radius:6px;font-size:0.8rem;">Cancel</button>
    <button type="button" class="btn btn-sm" onclick="saveBio()" style="background:var(--pikka);color:#090B0E;padding:6px 16px;border-radius:6px;font-size:0.8rem;font-weight:700;border:none;">Save</button>
</div>
</div>
</div>
</div>
<?php endif; ?>

<!-- Edit Pick Modal removed — replaced by inline editing -->

<!-- View Post Modal -->
<div class="modal fade" id="viewPostModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-lg">
<div class="modal-content" style="background:var(--secondary);border:1px solid var(--border);border-radius:12px;">
    <div class="modal-header" style="border-bottom:1px solid var(--border);padding:16px 20px;">
        <h5 style="font-weight:700;margin:0;font-size:1rem;"><i class="fas fa-eye me-2" style="color:var(--pikka);"></i>Pick Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body" style="padding:20px;" id="viewPostContent"></div>
    <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 20px;">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--border);color:var(--muted);padding:6px 16px;border-radius:6px;font-size:0.8rem;">Close</button>
    </div>
</div>
</div>
</div>

<script>
document.addEventListener('click', function(e) {
    var card = e.target.closest('.pick-card');
    if (!card) return;
    if (e.target.closest('button, a, input, textarea, form, select, .vote-btn, .heart-btn, .save-btn, .comment-toggle, .read-more-trigger, .follow-mini')) return;
    openViewPostSimple(card.dataset.pid);
});

function openViewPostSimple(pid) {
    var card = document.getElementById('pick-' + pid);
    if (!card) return;
    openViewPostModal({
        pid: pid,
        match: card.dataset.match || '',
        league: card.dataset.league || '',
        pick: card.dataset.pick || '',
        odds: card.dataset.odds || 0,
        reasoning: card.dataset.reasoning || '',
        body: card.dataset.body || '',
        user: card.dataset.user || 'Unknown',
        time: card.dataset.time || '',
        upvotes: card.dataset.upvotes || 0,
        avatar: card.dataset.avatar || '',
        status: card.dataset.status || ''
    });
}

function openViewPost(pid, jsonData) {
    var data = typeof jsonData === 'string' ? JSON.parse(jsonData) : jsonData;
    data.pid = pid;
    openViewPostModal(data);
}

function openViewPostModal(data) {
    var hasStructured = data.match && data.pick;
    var avatarBg = data.avatar || 'linear-gradient(135deg,var(--pikka),var(--accent))';
    var initial = data.user ? data.user.charAt(0).toUpperCase() : '?';
    var html = '<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">';
    html += '<div style="width:44px;height:44px;border-radius:50%;background:' + avatarBg + ';display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:700;color:#fff;flex-shrink:0;">' + initial + '</div>';
    html += '<div><div style="font-weight:700;font-size:0.95rem;color:var(--text);">' + escapeHtml(data.user) + '</div>';
    html += '<div style="font-size:0.75rem;color:var(--muted);">' + escapeHtml(data.time || '');
    if (data.upvotes > 0) html += ' &middot; ' + data.upvotes + ' ❤️';
    html += '</div></div></div>';
    if (hasStructured) {
        html += '<div style="font-size:0.85rem;color:var(--text-muted);margin-bottom:8px;"><strong>' + escapeHtml(data.match || '') + '</strong>';
        if (data.league) html += ' <span style="color:var(--muted);font-size:0.75rem;">' + escapeHtml(data.league) + '</span>';
        html += '</div>';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">';
        html += '<span style="font-size:1rem;font-weight:700;background:rgba(99,102,241,0.15);color:var(--primary);padding:4px 14px;border-radius:6px;">' + escapeHtml(data.pick || '') + '</span>';
        if (parseFloat(data.odds) > 0) html += '<span style="font-size:0.95rem;font-weight:600;">@ ' + parseFloat(data.odds).toFixed(2) + '</span>';
        html += '</div>';
    }
    if (data.body) {
        html += '<div style="font-size:0.9rem;color:var(--text);margin-top:4px;white-space:pre-wrap;word-wrap:break-word;line-height:1.6;background:rgba(0,0,0,0.1);padding:14px;border-radius:10px;">' + escapeHtml(data.body) + '</div>';
    }
    if (hasStructured && data.reasoning) {
        html += '<div style="font-size:0.85rem;color:var(--text);background:rgba(0,0,0,0.15);padding:12px;border-radius:8px;border-left:3px solid var(--pikka);">' + escapeHtml(data.reasoning) + '</div>';
    }
    if (data.status) {
        var sColor = data.status === 'won' ? '#22C55E' : data.status === 'lost' ? '#EF4444' : '#6B7280';
        html += '<div style="margin-top:10px;"><span style="background:' + sColor + ';color:#fff;font-size:0.7rem;padding:3px 10px;border-radius:4px;font-weight:700;">' + data.status.toUpperCase() + '</span></div>';
    }
    // Comment section
    html += '<div style="margin-top:16px;border-top:1px solid var(--border);padding-top:12px;">';
    html += '<h6 style="font-size:0.85rem;font-weight:700;margin-bottom:10px;color:var(--text);"><i class="fas fa-comments me-1" style="color:var(--pikka);"></i> Comments</h6>';
    html += '<div id="modal-comments-list" style="max-height:250px;overflow-y:auto;font-size:0.8rem;">';
    html += '<div class="text-center py-3" style="color:var(--muted);"><i class="fas fa-spinner fa-spin me-1"></i> Loading...</div>';
    html += '</div>';
    <?php if ($isLoggedIn): ?>
    html += '<form id="modal-comment-form" style="display:flex;gap:6px;margin-top:10px;">';
    html += '<input type="text" class="form-control form-control-sm comment-input" placeholder="Write a comment..." required style="flex:1;background:var(--bg);border:1px solid var(--border);color:var(--text);font-size:0.8rem;border-radius:6px;">';
    html += '<button type="submit" class="btn btn-sm" style="background:var(--pikka);color:#fff;border:none;padding:4px 14px;border-radius:6px;font-size:0.8rem;font-weight:600;">Post</button>';
    html += '</form>';
    <?php endif; ?>
    html += '</div>';

    document.getElementById('viewPostContent').innerHTML = html;
    new bootstrap.Modal(document.getElementById('viewPostModal')).show();

    // Load comments via AJAX
    if (data.pid) {
        fetch('pikka?action=get_comments&pick_id=' + data.pid)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var list = document.getElementById('modal-comments-list');
            if (!list) return;
            if (d.ok && d.comments && d.comments.length > 0) {
                list.innerHTML = '';
                d.comments.forEach(function(c) {
                    var avatarColor = c.avatar_color || 'var(--pikka)';
                    var name = escapeHtml(c.display_name || c.phone || 'Unknown');
                    var av = name.charAt(0).toUpperCase();
                    var time = c.created_at ? new Date(c.created_at + ' UTC').toLocaleDateString('en-GB', {day:'numeric',month:'short'}) : '';
                    var body = escapeHtml(c.body);
                    list.innerHTML += '<div style="display:flex;gap:8px;padding:6px 0;border-bottom:1px solid rgba(45,49,66,0.5);">';
                    list.innerHTML += '<div style="width:28px;height:28px;border-radius:50%;background:' + avatarColor + ';display:flex;align-items:center;justify-content:center;font-size:0.65rem;font-weight:700;color:#fff;flex-shrink:0;margin-top:2px;">' + av + '</div>';
                    list.innerHTML += '<div style="flex:1;min-width:0;"><strong style="font-size:0.78rem;color:var(--text);">' + name + '</strong><span style="font-size:0.65rem;color:var(--muted);margin-left:4px;">' + time + '</span><div style="font-size:0.78rem;color:var(--text-muted);margin-top:1px;word-wrap:break-word;">' + body + '</div></div></div>';
                });
            } else {
                list.innerHTML = '<div class="text-center py-3" style="color:var(--muted);">No comments yet.</div>';
            }
        })
        .catch(function() {
            var list = document.getElementById('modal-comments-list');
            if (list) list.innerHTML = '<div class="text-center py-3" style="color:var(--muted);">Failed to load comments.</div>';
        });
    }

    // Wire up comment form submission
    <?php if ($isLoggedIn): ?>
    var form = document.getElementById('modal-comment-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var input = this.querySelector('input');
            var body = input.value.trim();
            if (!body || !data.pid) return;
            var btn = this.querySelector('button');
            btn.disabled = true;
            fetch('pikka', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=comment&pick_id=' + data.pid + '&body=' + encodeURIComponent(body) })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.ok) {
                    input.value = '';
                    var list = document.getElementById('modal-comments-list');
                    if (list) {
                        var emptyMsg = list.querySelector('.text-center');
                        if (emptyMsg && emptyMsg.textContent.indexOf('No comments') !== -1) list.innerHTML = '';
                        var div = document.createElement('div');
                        div.style.cssText = 'display:flex;gap:8px;padding:6px 0;border-bottom:1px solid rgba(45,49,66,0.5);';
                        div.innerHTML = '<div style="width:28px;height:28px;border-radius:50%;background:var(--pikka);display:flex;align-items:center;justify-content:center;font-size:0.65rem;font-weight:700;color:#fff;flex-shrink:0;margin-top:2px;">' + d.name.charAt(0).toUpperCase() + '</div><div style="flex:1;min-width:0;"><strong style="font-size:0.78rem;color:var(--text);">' + d.name + '</strong><span style="font-size:0.65rem;color:var(--muted);margin-left:4px;">' + d.time + '</span><div style="font-size:0.78rem;color:var(--text-muted);margin-top:1px;word-wrap:break-word;">' + d.body + '</div></div>';
                        list.insertBefore(div, list.firstChild);
                    }
                }
            })
            .finally(function() { btn.disabled = false; });
        });
    }
    <?php endif; ?>
}

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// Auto-submit admin search on input
document.addEventListener('DOMContentLoaded', function() {
    var adminForms = document.querySelectorAll('.admin-search-form input[type="text"]');
    adminForms.forEach(function(input) {
        var timer = null;
        input.addEventListener('input', function() {
            clearTimeout(timer);
            timer = setTimeout(function() {
                input.closest('form').submit();
            }, 400);
        });
    });
});
</script>

</body>
</html>
