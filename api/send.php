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

$content   = trim($_POST['content'] ?? '');
$imagePath = null;
$audioPath = null;
$filePath  = null;

// ── Handle optional image upload ─────────────────────────────────────────────
if (!empty($_FILES['image']['tmp_name'])) {
    $file = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Erro no upload da imagem'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($file['size'] > MAX_IMG_SIZE) {
        http_response_code(400);
        echo json_encode(['error' => 'Imagem muito grande (máx. 5 MB)'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Validate by inspecting actual file contents, not the client-supplied MIME type
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);

    if (!in_array($mime, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de arquivo não permitido (use JPEG, PNG, GIF ou WebP)'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $ext      = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;

    if (!is_dir(UPLOADS_DIR)) {
        mkdir(UPLOADS_DIR, 0755, true);
    }

    $dest = UPLOADS_DIR . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível salvar a imagem'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $imagePath = $filename;
}

// ── Handle optional audio upload ─────────────────────────────────────────────
if (!empty($_FILES['audio']['tmp_name'])) {
    $file = $_FILES['audio'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Erro no upload do áudio'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($file['size'] > MAX_AUDIO_SIZE) {
        http_response_code(400);
        echo json_encode(['error' => 'Áudio muito grande (máx. 10 MB)'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Validate by inspecting actual file contents, not the client-supplied MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    // Normalise common variants reported by finfo
    $mimeMap = [
        'audio/ogg'      => 'ogg',
        'audio/webm'     => 'webm',
        'audio/mp4'      => 'mp4',
        'audio/mpeg'     => 'mp3',
        'audio/x-m4a'    => 'm4a',
        // finfo sometimes returns these for ogg/webm blobs
        'application/ogg' => 'ogg',
        'video/webm'      => 'webm',
    ];

    if (!array_key_exists($mime, $mimeMap)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de áudio não suportado'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $ext      = $mimeMap[$mime];
    $filename = 'audio_' . bin2hex(random_bytes(16)) . '.' . $ext;

    if (!is_dir(UPLOADS_DIR)) {
        mkdir(UPLOADS_DIR, 0755, true);
    }

    $dest = UPLOADS_DIR . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível salvar o áudio'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $audioPath = $filename;
}

// ── Handle optional general file upload (video, PDF, doc, …) ─────────────────
if (!empty($_FILES['file']['tmp_name'])) {
    $file = $_FILES['file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Erro no upload do arquivo'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        http_response_code(400);
        echo json_encode(['error' => 'Arquivo muito grande (máx. 50 MB)'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    $allowedFiles = [
        // Video
        'video/mp4'       => 'mp4',  'video/webm'      => 'webm',
        'video/ogg'       => 'ogv',  'video/quicktime' => 'mov',
        'video/x-msvideo' => 'avi',  'video/mpeg'      => 'mpg',
        // Audio (fallback)
        'audio/mpeg'      => 'mp3',  'audio/ogg'       => 'ogg',
        'audio/webm'      => 'webm', 'audio/mp4'       => 'mp4',
        'audio/x-m4a'     => 'm4a',  'application/ogg' => 'ogg',
        // PDF
        'application/pdf' => 'pdf',
        // Office documents
        'application/msword'                                                          => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'    => 'docx',
        'application/vnd.ms-excel'                                                   => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'          => 'xlsx',
        'application/vnd.ms-powerpoint'                                              => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'  => 'pptx',
        // Archives
        'application/zip'              => 'zip',
        'application/x-zip-compressed' => 'zip',
        'application/x-rar-compressed' => 'rar',
        'application/x-7z-compressed'  => '7z',
        'application/gzip'             => 'gz',
        'application/x-tar'            => 'tar',
    ];

    if (!array_key_exists($mime, $allowedFiles)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de arquivo não suportado'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Use the client-supplied filename (sanitised) so the recipient sees a meaningful name
    $origName = basename($file['name'] ?? '');
    $safeName = preg_replace('/[^A-Za-z0-9.\-_]/', '_', $origName);
    $ext      = $allowedFiles[$mime];
    $filename = 'file_' . bin2hex(random_bytes(12)) . '_' . $safeName;

    if (!is_dir(UPLOADS_DIR)) {
        mkdir(UPLOADS_DIR, 0755, true);
    }

    $dest = UPLOADS_DIR . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível salvar o arquivo'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $filePath = $filename;
    unset($ext);
}

// ── Validate text content ─────────────────────────────────────────────────────
// A message must have text, an image, an audio clip, or a file
if ($content === '' && $imagePath === null && $audioPath === null && $filePath === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensagem vazia'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (mb_strlen($content, 'UTF-8') > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensagem muito longa (máx. 500 caracteres)'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$db = getDB();

try {
    $db->prepare("UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([$_SESSION['user_id']]);

    $stmt = $db->prepare("INSERT INTO messages (user_id, username, content, image_path, audio_path, file_path) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['username'], $content, $imagePath, $audioPath, $filePath]);

    $id = $db->lastInsertId();
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao salvar mensagem no banco de dados'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok' => true, 'id' => (int)$id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Probabilistic auto-prune: run cleanup on roughly 1 in 50 requests to keep tables lean
if (random_int(1, 50) === 1) {
    pruneMessages($db);
}

