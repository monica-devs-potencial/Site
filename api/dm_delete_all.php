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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}
requireCsrfToken();

$with = trim($_POST['with'] ?? '');
if ($with === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro "with" obrigatório'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = getDB();

$partnerStmt = $db->prepare("SELECT id FROM users WHERE username = ?");
$partnerStmt->execute([$with]);
$partnerId = $partnerStmt->fetchColumn();

if (!$partnerId) {
    http_response_code(404);
    echo json_encode(['error' => 'Usuário não encontrado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$myId    = (int) $_SESSION['user_id'];
$theirId = (int) $partnerId;

// Collect file paths so we can delete the uploaded files after removing rows
$filesStmt = $db->prepare(
    "SELECT image_path, audio_path FROM private_messages
     WHERE (from_user_id = ? AND to_user_id = ?)
        OR (from_user_id = ? AND to_user_id = ?)"
);
$filesStmt->execute([$myId, $theirId, $theirId, $myId]);
$files = $filesStmt->fetchAll();

// Hard-delete all messages in the conversation
$db->prepare(
    "DELETE FROM private_messages
     WHERE (from_user_id = ? AND to_user_id = ?)
        OR (from_user_id = ? AND to_user_id = ?)"
)->execute([$myId, $theirId, $theirId, $myId]);

// Remove associated uploaded files
foreach ($files as $row) {
    foreach (['image_path', 'audio_path'] as $col) {
        if (!empty($row[$col])) {
            $path = UPLOADS_DIR . '/' . $row[$col];
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
