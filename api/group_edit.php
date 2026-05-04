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

$id      = (int)($_POST['id'] ?? 0);
$content = trim($_POST['content'] ?? '');

if ($id <= 0 || $content === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetros inválidos'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($content, 'UTF-8') > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensagem muito longa (máx. 500 caracteres)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db   = getDB();
$myId = (int)$_SESSION['user_id'];

// Only owner can edit
$msgStmt = $db->prepare("SELECT from_user_id, deleted_at FROM group_messages WHERE id = ?");
$msgStmt->execute([$id]);
$msg = $msgStmt->fetch();

if (!$msg || $msg['deleted_at'] !== null) {
    http_response_code(404);
    echo json_encode(['error' => 'Mensagem não encontrada'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int)$msg['from_user_id'] !== $myId) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissão para editar esta mensagem'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db->prepare("UPDATE group_messages SET content = ?, edited_at = CURRENT_TIMESTAMP WHERE id = ?")
   ->execute([$content, $id]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
