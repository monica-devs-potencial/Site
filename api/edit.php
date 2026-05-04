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

$id      = isset($_POST['id'])      ? (int)$_POST['id']           : 0;
$content = isset($_POST['content']) ? trim($_POST['content'])      : '';

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de mensagem inválido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($content === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Conteúdo não pode ser vazio'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$db = getDB();

// Only the owner of the message can edit it; deleted messages cannot be edited.
$stmt = $db->prepare(
    "UPDATE messages SET content = ?, edited_at = CURRENT_TIMESTAMP
     WHERE id = ? AND user_id = ? AND deleted_at IS NULL"
);
$stmt->execute([$content, $id, $_SESSION['user_id']]);

if ($stmt->rowCount() === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Mensagem não encontrada ou sem permissão'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
