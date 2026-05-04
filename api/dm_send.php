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

$to      = trim($_POST['to'] ?? '');
$content = trim($_POST['content'] ?? '');

if ($to === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Destinatário obrigatório'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = getDB();

// Resolve recipient to user ID
$partnerStmt = $db->prepare("SELECT id FROM users WHERE username = ?");
$partnerStmt->execute([$to]);
$toId = $partnerStmt->fetchColumn();

if (!$toId) {
    http_response_code(404);
    echo json_encode(['error' => 'Usuário não encontrado'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int) $toId === (int) $_SESSION['user_id']) {
    http_response_code(400);
    echo json_encode(['error' => 'Não é possível enviar mensagem para si mesmo'], JSON_UNESCAPED_UNICODE);
    exit;
}

$imagePath = null;
$audioPath = null;
$filePath  = null;

// ── Handle optional image upload ─────────────────────────────────────────────
if (!empty($_FILES['image']['tmp_name'])) {
    $file = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Erro no upload da imagem'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($file['size'] > MAX_IMG_SIZE) {
        http_response_code(400);
        echo json_encode(['error' => 'Imagem muito grande (máx. 5 MB)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    if (!isset($allowed[$mime])) {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de imagem não permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!is_dir(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0755, true);
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOADS_DIR . '/' . $filename)) {
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível salvar a imagem'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $imagePath = $filename;
}

// ── Handle optional audio upload ─────────────────────────────────────────────
if (!empty($_FILES['audio']['tmp_name'])) {
    $file = $_FILES['audio'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Erro no upload do áudio'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($file['size'] > MAX_AUDIO_SIZE) {
        http_response_code(400);
        echo json_encode(['error' => 'Áudio muito grande (máx. 10 MB)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    $mimeMap = [
        'audio/ogg' => 'ogg', 'audio/webm' => 'webm', 'audio/mp4' => 'mp4',
        'audio/mpeg' => 'mp3', 'audio/x-m4a' => 'm4a',
        'application/ogg' => 'ogg', 'video/webm' => 'webm',
    ];

    if (!array_key_exists($mime, $mimeMap)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de áudio não suportado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!is_dir(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0755, true);
    $filename = 'audio_' . bin2hex(random_bytes(16)) . '.' . $mimeMap[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOADS_DIR . '/' . $filename)) {
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível salvar o áudio'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $audioPath = $filename;
}

// ── Handle optional general file upload ──────────────────────────────────────
if (!empty($_FILES['file']['tmp_name'])) {
    $file = $_FILES['file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Erro no upload do arquivo'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        http_response_code(400);
        echo json_encode(['error' => 'Arquivo muito grande (máx. 50 MB)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    $allowedFiles = [
        'video/mp4'       => 'mp4',  'video/webm'      => 'webm',
        'video/ogg'       => 'ogv',  'video/quicktime' => 'mov',
        'video/x-msvideo' => 'avi',  'video/mpeg'      => 'mpg',
        'audio/mpeg'      => 'mp3',  'audio/ogg'       => 'ogg',
        'audio/webm'      => 'webm', 'audio/mp4'       => 'mp4',
        'audio/x-m4a'     => 'm4a',  'application/ogg' => 'ogg',
        'application/pdf' => 'pdf',
        'application/msword'                                                         => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
        'application/vnd.ms-excel'                                                  => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
        'application/vnd.ms-powerpoint'                                             => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/zip'              => 'zip',
        'application/x-zip-compressed' => 'zip',
        'application/x-rar-compressed' => 'rar',
        'application/x-7z-compressed'  => '7z',
        'application/gzip'             => 'gz',
        'application/x-tar'            => 'tar',
    ];

    if (!array_key_exists($mime, $allowedFiles)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de arquivo não suportado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $origName = basename($file['name'] ?? '');
    $safeName = preg_replace('/[^A-Za-z0-9.\-_]/', '_', $origName);
    $filename = 'file_' . bin2hex(random_bytes(12)) . '_' . $safeName;

    if (!is_dir(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], UPLOADS_DIR . '/' . $filename)) {
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível salvar o arquivo'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $filePath = $filename;
}

if ($content === '' && $imagePath === null && $audioPath === null && $filePath === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensagem vazia'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($content, 'UTF-8') > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensagem muito longa (máx. 500 caracteres)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db->prepare("UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE id = ?")
   ->execute([$_SESSION['user_id']]);

$stmt = $db->prepare(
    "INSERT INTO private_messages (from_user_id, to_user_id, content, image_path, audio_path, file_path)
     VALUES (?, ?, ?, ?, ?, ?)"
);
$stmt->execute([$_SESSION['user_id'], $toId, $content, $imagePath, $audioPath, $filePath]);
$id = $db->lastInsertId();

echo json_encode(
    ['ok' => true, 'id' => (int) $id],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

// Probabilistic auto-prune: run cleanup on roughly 1 in 50 requests to keep tables lean
if (random_int(1, 50) === 1) {
    pruneMessages($db);
}
