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
$newUsername  = trim($_POST['new_username'] ?? '');
$displayName  = trim($_POST['display_name'] ?? '');
$email        = trim($_POST['email'] ?? '');
$curPassword = $_POST['current_password'] ?? '';
$newPassword = $_POST['new_password']     ?? '';
$confPassword= $_POST['confirm_password'] ?? '';
$db          = getDB();

// ── Username change (optional – only when new_username differs from current) ──
$currentUsername = $_SESSION['username'];
if ($newUsername !== '' && $newUsername !== $currentUsername) {
    if (mb_strlen($newUsername) < 3 || mb_strlen($newUsername) > 20) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome de usuário deve ter entre 3 e 20 caracteres'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $newUsername)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome de usuário só pode conter letras, números e _'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt->execute([$newUsername, $_SESSION['user_id']]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Este nome de usuário já está em uso'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── Password change (optional – only when new_password is provided) ─────────
if ($newPassword !== '') {
    // Require current password
    if ($curPassword === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Informe sua senha atual para alterá-la'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($newPassword !== $confPassword) {
        http_response_code(400);
        echo json_encode(['error' => 'Nova senha e confirmação não conferem'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (mb_strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Nova senha deve ter pelo menos 6 caracteres'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Verify current password
    $pwStmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $pwStmt->execute([$_SESSION['user_id']]);
    $hash = $pwStmt->fetchColumn();
    if (!$hash || !password_verify($curPassword, $hash)) {
        http_response_code(400);
        echo json_encode(['error' => 'Senha atual incorreta'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Validate email
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'E-mail inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Check email uniqueness (excluding current user)
if ($email !== '') {
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $_SESSION['user_id']]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Este e-mail já está em uso por outra conta'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Handle avatar upload
$newAvatarPath = null;
if (!empty($_FILES['avatar']['tmp_name'])) {
    $file = $_FILES['avatar'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Erro no upload da foto (' . $file['error'] . ')'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($file['size'] > MAX_AVATAR_SIZE) {
        http_response_code(400);
        echo json_encode(['error' => 'Foto muito grande (máx. 3 MB)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    if (!isset($allowed[$mime])) {
        http_response_code(400);
        echo json_encode(['error' => 'Formato inválido (use JPEG, PNG, GIF ou WebP)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!is_dir(AVATARS_DIR)) {
        mkdir(AVATARS_DIR, 0755, true);
    }

    $ext      = $allowed[$mime];
    $filename = 'avatar_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dest     = AVATARS_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível salvar a foto de perfil'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Delete old avatar file
    $oldStmt = $db->prepare("SELECT avatar_path FROM users WHERE id = ?");
    $oldStmt->execute([$_SESSION['user_id']]);
    $oldAvatar = $oldStmt->fetchColumn();
    if ($oldAvatar && is_file(AVATARS_DIR . '/' . $oldAvatar)) {
        @unlink(AVATARS_DIR . '/' . $oldAvatar);
    }

    $newAvatarPath = $filename;
}

// Build and run the UPDATE
$sets   = ['display_name = ?', 'email = ?'];
$params = [
    $displayName !== '' ? $displayName : null,
    $email       !== '' ? $email       : null,
];

if ($newUsername !== '' && $newUsername !== $currentUsername) {
    $sets[]   = 'username = ?';
    $params[] = $newUsername;
}

if ($newPassword !== '') {
    $sets[]   = 'password = ?';
    $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
}

if ($newAvatarPath !== null) {
    $sets[]   = 'avatar_path = ?';
    $params[] = $newAvatarPath;
}

$params[] = $_SESSION['user_id'];

$db->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")
   ->execute($params);

// Update session username if it changed
if ($newUsername !== '' && $newUsername !== $currentUsername) {
    $_SESSION['username'] = $newUsername;
}

// Return the new avatar URL (either just-uploaded or existing)
$avatarUrl = null;
if ($newAvatarPath) {
    $avatarUrl = AVATARS_URL . $newAvatarPath;
} else {
    $stmt = $db->prepare("SELECT avatar_path FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $current = $stmt->fetchColumn();
    if ($current) {
        $avatarUrl = AVATARS_URL . $current;
    }
}

echo json_encode([
    'ok'           => true,
    'username'     => $newUsername !== '' ? $newUsername : $currentUsername,
    'display_name' => $displayName !== '' ? $displayName : null,
    'email'        => $email       !== '' ? $email       : null,
    'avatar_url'   => $avatarUrl,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
