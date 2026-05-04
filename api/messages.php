<?php
mb_internal_encoding('UTF-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$db = getDB();

$db->prepare("UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE id = ?")
   ->execute([$_SESSION['user_id']]);

$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;

if ($since > 0) {
    $stmt = $db->prepare(
        "SELECT id, username, content, image_path, audio_path, file_path, deleted_at, edited_at,
                UNIX_TIMESTAMP(created_at) AS ts,
                DATE_FORMAT(created_at, '%d/%m/%Y %H:%i') AS created_at
         FROM messages WHERE id > ? ORDER BY id ASC LIMIT 100"
    );
    $stmt->execute([$since]);
} else {
    $stmt = $db->prepare(
        "SELECT id, username, content, image_path, audio_path, file_path, deleted_at, edited_at,
                UNIX_TIMESTAMP(created_at) AS ts,
                DATE_FORMAT(created_at, '%d/%m/%Y %H:%i') AS created_at
         FROM messages ORDER BY id DESC LIMIT 50"
    );
    $stmt->execute();
}

$messages = $stmt->fetchAll();

if ($since === 0) {
    $messages = array_reverse($messages);
}

// Update the current user's last_read_id to the highest message id just loaded
if (!empty($messages)) {
    $maxId = max(array_column($messages, 'id'));
    $db->prepare("UPDATE users SET last_read_id = GREATEST(last_read_id, ?) WHERE id = ?")
       ->execute([$maxId, $_SESSION['user_id']]);
}

// Highest last_read_id among all other users (used by the sender to show double ticks)
$peerStmt = $db->prepare("SELECT COALESCE(MAX(last_read_id), 0) FROM users WHERE id != ?");
$peerStmt->execute([$_SESSION['user_id']]);
$peerLastRead = (int) $peerStmt->fetchColumn();

echo json_encode([
    'messages'       => $messages,
    'is_admin'       => isAdmin() ? 1 : 0,
    'peer_last_read' => $peerLastRead,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

