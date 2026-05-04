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

$with = trim($_GET['with'] ?? '');
if ($with === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro "with" obrigatório'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = getDB();

// Resolve partner to user ID
$partnerStmt = $db->prepare("SELECT id FROM users WHERE username = ?");
$partnerStmt->execute([$with]);
$partnerId = $partnerStmt->fetchColumn();

if (!$partnerId) {
    http_response_code(404);
    echo json_encode(['error' => 'Usuário não encontrado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db->prepare("UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE id = ?")
   ->execute([$_SESSION['user_id']]);

$since = isset($_GET['since']) ? (int) $_GET['since'] : 0;
$myId  = (int) $_SESSION['user_id'];
$theirId = (int) $partnerId;

if ($since > 0) {
    $stmt = $db->prepare(
        "SELECT pm.id, u.username, pm.content, pm.image_path, pm.audio_path, pm.file_path,
                pm.deleted_at, pm.edited_at,
                UNIX_TIMESTAMP(pm.created_at) AS ts,
                DATE_FORMAT(pm.created_at, '%d/%m/%Y %H:%i') AS created_at
         FROM private_messages pm
         JOIN users u ON u.id = pm.from_user_id
         WHERE pm.id > ?
           AND ((pm.from_user_id = ? AND pm.to_user_id = ?)
             OR (pm.from_user_id = ? AND pm.to_user_id = ?))
         ORDER BY pm.id ASC LIMIT 100"
    );
    $stmt->execute([$since, $myId, $theirId, $theirId, $myId]);
} else {
    $stmt = $db->prepare(
        "SELECT pm.id, u.username, pm.content, pm.image_path, pm.audio_path, pm.file_path,
                pm.deleted_at, pm.edited_at,
                UNIX_TIMESTAMP(pm.created_at) AS ts,
                DATE_FORMAT(pm.created_at, '%d/%m/%Y %H:%i') AS created_at
         FROM private_messages pm
         JOIN users u ON u.id = pm.from_user_id
         WHERE (pm.from_user_id = ? AND pm.to_user_id = ?)
            OR (pm.from_user_id = ? AND pm.to_user_id = ?)
         ORDER BY pm.id DESC LIMIT 50"
    );
    $stmt->execute([$myId, $theirId, $theirId, $myId]);
}

$messages = $stmt->fetchAll();

if ($since === 0) {
    $messages = array_reverse($messages);
}

// Mark messages as read by the current user (upsert with max ID seen)
if (!empty($messages)) {
    $maxId = 0;
    foreach ($messages as $m) {
        $mid = (int)$m['id'];
        if ($mid > $maxId) $maxId = $mid;
    }
    $db->prepare(
        "INSERT INTO dm_read_receipts (user_id, partner_id, last_read_id)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))"
    )->execute([$myId, $theirId, $maxId]);
}

// Return the partner's last read position so the sender can show ✓✓ (read receipt)
$peerStmt = $db->prepare(
    "SELECT last_read_id FROM dm_read_receipts WHERE user_id = ? AND partner_id = ?"
);
$peerStmt->execute([$theirId, $myId]);
$peerLastRead = (int)($peerStmt->fetchColumn() ?: 0);

echo json_encode(
    ['messages' => $messages, 'peer_last_read' => $peerLastRead],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
