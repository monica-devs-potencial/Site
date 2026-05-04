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

$groupId = (int)($_GET['group_id'] ?? 0);
if ($groupId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'group_id inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db   = getDB();
$myId = (int)$_SESSION['user_id'];

// Verify membership
$memStmt = $db->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
$memStmt->execute([$groupId, $myId]);
if (!$memStmt->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Você não pertence a este grupo'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db->prepare("UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE id = ?")->execute([$myId]);

$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;

if ($since > 0) {
    $stmt = $db->prepare(
        "SELECT gm.id, u.username, gm.content, gm.image_path, gm.audio_path, gm.file_path,
                gm.deleted_at, gm.edited_at,
                UNIX_TIMESTAMP(gm.created_at) AS ts,
                DATE_FORMAT(gm.created_at, '%d/%m/%Y %H:%i') AS created_at
         FROM group_messages gm
         JOIN users u ON u.id = gm.from_user_id
         WHERE gm.group_id = ? AND gm.id > ?
         ORDER BY gm.id ASC LIMIT 100"
    );
    $stmt->execute([$groupId, $since]);
} else {
    $stmt = $db->prepare(
        "SELECT gm.id, u.username, gm.content, gm.image_path, gm.audio_path, gm.file_path,
                gm.deleted_at, gm.edited_at,
                UNIX_TIMESTAMP(gm.created_at) AS ts,
                DATE_FORMAT(gm.created_at, '%d/%m/%Y %H:%i') AS created_at
         FROM group_messages gm
         JOIN users u ON u.id = gm.from_user_id
         WHERE gm.group_id = ?
         ORDER BY gm.id DESC LIMIT 50"
    );
    $stmt->execute([$groupId]);
}

$messages = $stmt->fetchAll();
if ($since === 0) {
    $messages = array_reverse($messages);
}

echo json_encode(
    ['ok' => true, 'messages' => $messages],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
