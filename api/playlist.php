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

$db   = getDB();
$myId = (int) $_SESSION['user_id'];

// ── GET: list user's playlist ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare(
        "SELECT id, title, artist, file_path
         FROM user_playlist WHERE user_id = ?
         ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute([$myId]);
    $tracks = $stmt->fetchAll();
    foreach ($tracks as &$t) {
        $t['url'] = 'data/uploads/' . $t['file_path'];
        $t['id']  = (int) $t['id'];
    }
    unset($t);
    echo json_encode(
        ['ok' => true, 'tracks' => $tracks],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim($_POST['action'] ?? '');

// ── POST action=add ──────────────────────────────────────────────────────────
if ($action === 'add') {
    $title  = mb_substr(trim($_POST['title']  ?? ''), 0, 200, 'UTF-8');
    $artist = mb_substr(trim($_POST['artist'] ?? ''), 0, 200, 'UTF-8');

    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Título obrigatório'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($_FILES['audio']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Arquivo de áudio obrigatório'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $file = $_FILES['audio'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Erro no upload'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($file['size'] > 20 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'Arquivo muito grande (máx. 20 MB)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    $mimeMap = [
        'audio/mpeg'      => 'mp3',
        'audio/mp4'       => 'm4a',
        'audio/x-m4a'     => 'm4a',
        'audio/ogg'       => 'ogg',
        'audio/webm'      => 'webm',
        'audio/wav'       => 'wav',
        'audio/x-wav'     => 'wav',
        'audio/flac'      => 'flac',
        'video/webm'      => 'webm',
        'application/ogg' => 'ogg',
    ];

    if (!array_key_exists($mime, $mimeMap)) {
        http_response_code(400);
        echo json_encode(
            ['error' => 'Tipo de arquivo não suportado. Use MP3, M4A, OGG, WAV ou WEBM.'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    if (!is_dir(UPLOADS_DIR)) mkdir(UPLOADS_DIR, 0755, true);
    $filename = 'pl_' . bin2hex(random_bytes(16)) . '.' . $mimeMap[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOADS_DIR . '/' . $filename)) {
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível salvar o arquivo'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $db->prepare(
        "INSERT INTO user_playlist (user_id, title, artist, file_path, sort_order)
         SELECT ?, ?, ?, ?, COALESCE(MAX(sort_order) + 1, 0)
         FROM user_playlist WHERE user_id = ?"
    );
    $stmt->execute([$myId, $title, $artist, $filename, $myId]);
    $id = (int) $db->lastInsertId();

    echo json_encode([
        'ok'    => true,
        'track' => [
            'id'        => $id,
            'title'     => $title,
            'artist'    => $artist,
            'file_path' => $filename,
            'url'       => 'data/uploads/' . $filename,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── POST action=remove ───────────────────────────────────────────────────────
if ($action === 'remove') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $db->prepare(
        "SELECT file_path FROM user_playlist WHERE id = ? AND user_id = ?"
    );
    $stmt->execute([$id, $myId]);
    $track = $stmt->fetch();

    if (!$track) {
        http_response_code(404);
        echo json_encode(['error' => 'Faixa não encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db->prepare("DELETE FROM user_playlist WHERE id = ? AND user_id = ?")
       ->execute([$id, $myId]);

    $path = UPLOADS_DIR . '/' . $track['file_path'];
    if (file_exists($path)) {
        @unlink($path);
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Ação inválida'], JSON_UNESCAPED_UNICODE);
