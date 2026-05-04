<?php
mb_internal_encoding('UTF-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db    = getDB();
$myId  = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: list groups the user belongs to ────────────────────────────────────
if ($method === 'GET') {
    $action = trim($_GET['action'] ?? '');

    // GET ?action=members&group_id=X  →  member list for a group
    if ($action === 'members') {
        $groupId = (int)($_GET['group_id'] ?? 0);
        if ($groupId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'group_id inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Check membership
        $memStmt = $db->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ?");
        $memStmt->execute([$groupId, $myId]);
        if (!$memStmt->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'Você não pertence a este grupo'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $db->prepare(
            "SELECT u.id, u.username, u.avatar_path, gm.role
             FROM group_members gm
             JOIN users u ON u.id = gm.user_id
             WHERE gm.group_id = ?
             ORDER BY gm.role ASC, u.username ASC"
        );
        $stmt->execute([$groupId]);
        $members = [];
        foreach ($stmt->fetchAll() as $row) {
            $members[] = [
                'id'       => (int)$row['id'],
                'username' => $row['username'],
                'role'     => $row['role'],
                'avatar_url' => $row['avatar_path'] ? AVATARS_URL . $row['avatar_path'] : null,
            ];
        }
        // group name
        $gStmt = $db->prepare("SELECT name FROM chat_groups WHERE id = ?");
        $gStmt->execute([$groupId]);
        $gName = $gStmt->fetchColumn() ?: '';

        echo json_encode(['ok' => true, 'group_name' => $gName, 'members' => $members], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // GET (default): list groups
    $stmt = $db->prepare(
        "SELECT g.id, g.name, gm.role,
                (SELECT COUNT(*) FROM group_members gm2 WHERE gm2.group_id = g.id) AS member_count
         FROM chat_groups g
         JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = ?
         ORDER BY g.created_at DESC"
    );
    $stmt->execute([$myId]);
    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
        $groups[] = [
            'id'           => (int)$row['id'],
            'name'         => $row['name'],
            'role'         => $row['role'],
            'member_count' => (int)$row['member_count'],
        ];
    }
    echo json_encode(['ok' => true, 'groups' => $groups], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── POST: create group ───────────────────────────────────────────────────────
if ($method === 'POST') {
    requireCsrfToken();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            http_response_code(400);
            echo json_encode(['error' => 'Nome do grupo inválido (máx. 100 caracteres)'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO chat_groups (name, created_by) VALUES (?, ?)")
               ->execute([$name, $myId]);
            $groupId = (int)$db->lastInsertId();

            // Creator is admin
            $db->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'admin')")
               ->execute([$groupId, $myId]);

            // Optional initial members (comma-separated usernames)
            $memberUsernames = array_filter(array_map('trim', explode(',', $_POST['members'] ?? '')));
            foreach ($memberUsernames as $uname) {
                if ($uname === $_SESSION['username']) continue;
                $uStmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                $uStmt->execute([$uname]);
                $uid = $uStmt->fetchColumn();
                if ($uid) {
                    $db->prepare("INSERT IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")
                       ->execute([$groupId, $uid]);
                }
            }

            $db->commit();
        } catch (\PDOException $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao criar grupo'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Get actual member count from DB
        $countStmt = $db->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ?");
        $countStmt->execute([$groupId]);
        $memberCount = (int)$countStmt->fetchColumn();

        echo json_encode([
            'ok'    => true,
            'group' => ['id' => $groupId, 'name' => $name, 'role' => 'admin', 'member_count' => $memberCount],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

http_response_code(405);
echo json_encode(['error' => 'Método ou ação não suportados'], JSON_UNESCAPED_UNICODE);
