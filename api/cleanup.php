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

// Allow access for the super-admin username ("Flamengo") or any user with is_admin flag
if (!isAdmin() && ($_SESSION['username'] ?? '') !== 'Flamengo') {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

requireCsrfToken();

$db      = getDB();
$deleted = pruneMessages($db);
$total   = $deleted['public'] + $deleted['dm'] + $deleted['group'];

echo json_encode([
    'ok'      => true,
    'deleted' => $deleted,
    'total'   => $total,
    'message' => $total > 0
        ? "Limpeza concluída: $total mensagem(ns) removida(s)."
        : 'Nenhuma mensagem precisava ser removida.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
