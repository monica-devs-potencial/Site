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

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de mensagem inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db   = getDB();
$myId = (int)$_SESSION['user_id'];

// Fetch the message with group info
$msgStmt = $db->prepare(
    "SELECT gm.from_user_id, gm.group_id, gm.deleted_at FROM group_messages gm WHERE gm.id = ?"
);
$msgStmt->execute([$id]);
$msg = $msgStmt->fetch();

if (!$msg || $msg['deleted_at'] !== null) {
    http_response_code(404);
    echo json_encode(['error' => 'Mensagem não encontrada ou já apagada'], JSON_UNESCAPED_UNICODE);
    exit;
}

$groupId = (int)$msg['group_id'];

// Check that requester is a member
$roleStmt = $db->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ?");
$roleStmt->execute([$groupId, $myId]);
$myRole = $roleStmt->fetchColumn();
if ($myRole === false) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isOwner      = ((int)$msg['from_user_id'] === $myId);
$isGroupAdmin = ($myRole === 'admin');

if (!$isOwner && !$isGroupAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissão para apagar esta mensagem'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db->prepare("UPDATE group_messages SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL")
   ->execute([$id]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
