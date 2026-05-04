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

// Heartbeat: update last_seen so the current user appears online while the page is open
$db->prepare("UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE id = ?")
   ->execute([$_SESSION['user_id']]);

// Return ALL registered users; mark each as online if last_seen within 30 seconds
$stmt = $db->query(
    "SELECT username, avatar_path,
            (last_seen >= DATE_SUB(NOW(), INTERVAL 30 SECOND)) AS is_online
     FROM users
     ORDER BY username ASC"
);
$rows = $stmt->fetchAll();

// $users keeps the plain list (username strings) for backwards compatibility
$users   = array_column($rows, 'username');
$online  = [];   // username => bool

// Build avatar map for all users
$avatars = [];
foreach ($rows as $row) {
    $avatars[$row['username']] = $row['avatar_path']
        ? AVATARS_URL . $row['avatar_path']
        : null;
    $online[$row['username']] = (bool)(int)$row['is_online'];
}

// Users who sent a typing signal in the last 5 seconds (excluding self)
$typingStmt = $db->prepare(
    "SELECT username FROM users
     WHERE typing_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)
       AND username != ?
     ORDER BY username ASC"
);
$typingStmt->execute([$_SESSION['username']]);
$typingUsers = $typingStmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode(
    ['users' => $users, 'typing' => $typingUsers, 'avatars' => $avatars, 'online' => $online],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
