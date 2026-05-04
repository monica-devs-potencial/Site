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
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

requireCsrfToken();

$db = getDB();
$db->prepare("UPDATE users SET typing_at = NOW() WHERE username = ?")
   ->execute([$_SESSION['username']]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
