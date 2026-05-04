<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['_csrf'] ?? null)) {
        $error = 'Token de segurança inválido. Recarregue a página.';
    } else {
    $username    = trim($_POST['username'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $password    = $_POST['password'] ?? '';
    $confirm     = $_POST['confirm'] ?? '';

    if ($username === '' || $email === '' || $password === '' || $confirm === '') {
        $error = 'Por favor, preencha todos os campos obrigatórios.';
    } elseif (strlen($username) < 3 || strlen($username) > 20) {
        $error = 'O nome de usuário deve ter entre 3 e 20 caracteres.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $error = 'O nome de usuário só pode conter letras, números e _.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Por favor, informe um e-mail válido.';
    } elseif (strlen($password) < 6) {
        $error = 'A senha deve ter pelo menos 6 caracteres.';
    } elseif ($password !== $confirm) {
        $error = 'As senhas não coincidem.';
    } else {
        $db = getDB();

        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = 'Este nome de usuário já está em uso.';
        } else {
            $stmt2 = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt2->execute([$email]);
            if ($stmt2->fetch()) {
                $error = 'Este e-mail já está cadastrado.';
            } else {
                // Handle optional avatar upload
                $avatarPath = null;
                if (!empty($_FILES['avatar']['tmp_name'])) {
                    $file = $_FILES['avatar'];
                    if ($file['error'] === UPLOAD_ERR_OK) {
                        if ($file['size'] > MAX_AVATAR_SIZE) {
                            $error = 'A foto deve ter no máximo 3 MB.';
                        } else {
                            $finfo   = new finfo(FILEINFO_MIME_TYPE);
                            $mime    = $finfo->file($file['tmp_name']);
                            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png',
                                        'image/gif'  => 'gif', 'image/webp' => 'webp'];
                            if (!isset($allowed[$mime])) {
                                $error = 'Formato de foto inválido (use JPEG, PNG, GIF ou WebP).';
                            } else {
                                if (!is_dir(AVATARS_DIR)) {
                                    mkdir(AVATARS_DIR, 0755, true);
                                }
                                $ext      = $allowed[$mime];
                                $filename = 'avatar_' . bin2hex(random_bytes(12)) . '.' . $ext;
                                $dest     = AVATARS_DIR . '/' . $filename;
                                if (!move_uploaded_file($file['tmp_name'], $dest)) {
                                    $error = 'Não foi possível salvar a foto de perfil.';
                                } else {
                                    $avatarPath = $filename;
                                }
                            }
                        }
                    } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                        $error = 'Erro no upload da foto (' . $file['error'] . ').';
                    }
                }

                if ($error === '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $dn   = $displayName !== '' ? $displayName : null;
                    $db->prepare(
                        "INSERT INTO users (username, password, email, display_name, avatar_path)
                         VALUES (?, ?, ?, ?, ?)"
                    )->execute([$username, $hash, $email, $dn, $avatarPath]);
                    $success = true;
                }
            }
        }
    }
    } // end validateCsrfToken else
}
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro – Chat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-box">
            <h1 class="auth-title">💬 Chat</h1>
            <h2 class="auth-subtitle">Cadastro</h2>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success">
                    Conta criada com sucesso! Você já pode <a href="login.php">entrar</a>.
                </div>
            <?php endif; ?>
            <form method="post" action="register.php" class="auth-form" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">

                <!-- Avatar preview + upload -->
                <div class="form-group avatar-upload-group">
                    <label>Foto de Perfil <span class="field-optional">(opcional)</span></label>
                    <div class="avatar-upload-row">
                        <div class="avatar-upload-preview" id="avatar-preview-wrap">
                            <span class="avatar-upload-initial" id="avatar-initial">?</span>
                        </div>
                        <label class="btn btn-outline btn-avatar-pick" for="avatar-input">
                            📷 Escolher foto
                        </label>
                        <input type="file" id="avatar-input" name="avatar" accept="image/*"
                               class="visually-hidden-input">
                    </div>
                </div>

                <div class="form-group">
                    <label for="username">Usuário <span class="field-required">*</span></label>
                    <input type="text" id="username" name="username" required
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           placeholder="3–20 caracteres, letras/números/_">
                </div>

                <div class="form-group">
                    <label for="display_name">Nome Completo <span class="field-optional">(opcional)</span></label>
                    <input type="text" id="display_name" name="display_name"
                           value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>"
                           placeholder="Como quer ser chamado(a)">
                </div>

                <div class="form-group">
                    <label for="email">E-mail <span class="field-required">*</span></label>
                    <input type="email" id="email" name="email" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           placeholder="seu@email.com">
                </div>

                <div class="form-group">
                    <label for="password">Senha <span class="field-required">*</span></label>
                    <input type="password" id="password" name="password" required
                           placeholder="Mínimo 6 caracteres">
                </div>
                <div class="form-group">
                    <label for="confirm">Confirmar senha <span class="field-required">*</span></label>
                    <input type="password" id="confirm" name="confirm" required
                           placeholder="Repita a senha">
                </div>
                <button type="submit" class="btn btn-primary btn-full">Cadastrar</button>
            </form>
            <p class="auth-footer">Já tem conta? <a href="login.php">Entrar</a></p>
        </div>
    </div>
    <script>
    (function () {
        var input         = document.getElementById('avatar-input');
        var wrap          = document.getElementById('avatar-preview-wrap');
        var initialEl     = document.getElementById('avatar-initial');
        var usernameInput = document.getElementById('username');

        usernameInput.addEventListener('input', function () {
            if (!wrap.querySelector('img')) {
                initialEl.textContent = usernameInput.value.charAt(0).toUpperCase() || '?';
            }
        });

        input.addEventListener('change', function () {
            var file = input.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function (ev) {
                initialEl.style.display = 'none';
                var existing = wrap.querySelector('img');
                if (existing) existing.remove();
                var img = document.createElement('img');
                img.src = ev.target.result;
                img.className = 'avatar-upload-img';
                img.alt = 'Prévia da foto';
                wrap.appendChild(img);
            };
            reader.readAsDataURL(file);
        });
    })();
    </script>
</body>
</html>
