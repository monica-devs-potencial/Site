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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

requireCsrfToken();

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de mensagem inválido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$db = getDB();

// Admins can delete any DM message; regular users can only delete their own.
if (isAdmin()) {
    $stmt = $db->prepare("UPDATE private_messages SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
} else {
    $stmt = $db->prepare("UPDATE private_messages SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND from_user_id = ? AND deleted_at IS NULL");
    $stmt->execute([$id, $_SESSION['user_id']]);
}

if ($stmt->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Mensagem não encontrada ou já apagada'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
