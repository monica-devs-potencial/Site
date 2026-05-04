<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$error = '';

// Rate-limit constants
const LOGIN_MAX_ATTEMPTS = 10;
const LOGIN_WINDOW_SECS  = 300; // 5 minutes

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['_csrf'] ?? null)) {
        $error = 'Token de segurança inválido. Recarregue a página.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Por favor, preencha todos os campos.';
        } else {
            $db     = getDB();
            $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');

            // ── Check rate limit ──────────────────────────────────────────────
            $rlStmt = $db->prepare(
                "SELECT attempts, first_attempt FROM login_rate_limit
                 WHERE ip_hash = ? AND username = ?
                   AND first_attempt >= DATE_SUB(NOW(), INTERVAL ? SECOND)"
            );
            $rlStmt->execute([$ipHash, $username, LOGIN_WINDOW_SECS]);
            $rl = $rlStmt->fetch();

            if ($rl && (int)$rl['attempts'] >= LOGIN_MAX_ATTEMPTS) {
                $error = 'Muitas tentativas de login. Aguarde alguns minutos.';
            } else {
                $stmt = $db->prepare("SELECT id, username, password, is_admin FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    // Clear rate-limit entries on success
                    $db->prepare("DELETE FROM login_rate_limit WHERE ip_hash = ? AND username = ?")
                       ->execute([$ipHash, $username]);

                    // Regenerate session ID to prevent session fixation
                    session_regenerate_id(true);

                    $_SESSION['user_id']  = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['is_admin'] = (bool)(int)$user['is_admin'];

                    $db->prepare("UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE id = ?")
                       ->execute([$user['id']]);

                    redirect('index.php');
                } else {
                    // Record failed attempt
                    if ($rl) {
                        $db->prepare(
                            "UPDATE login_rate_limit SET attempts = attempts + 1, last_attempt = NOW()
                             WHERE ip_hash = ? AND username = ?
                               AND first_attempt >= DATE_SUB(NOW(), INTERVAL ? SECOND)"
                        )->execute([$ipHash, $username, LOGIN_WINDOW_SECS]);
                    } else {
                        $db->prepare(
                            "INSERT INTO login_rate_limit (ip_hash, username) VALUES (?, ?)"
                        )->execute([$ipHash, $username]);
                    }
                    $error = 'Usuário ou senha inválidos.';
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – Chat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="auth-page">
    <div class="auth-container">
        <div class="auth-box">
            <h1 class="auth-title">💬 Chat</h1>
            <h2 class="auth-subtitle">Entrar</h2>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post" action="login.php" class="auth-form">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="form-group">
                    <label for="username">Usuário</label>
                    <input type="text" id="username" name="username" required
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           placeholder="Seu nome de usuário">
                </div>
                <div class="form-group">
                    <label for="password">Senha</label>
                    <input type="password" id="password" name="password" required
                           placeholder="Sua senha">
                </div>
                <button type="submit" class="btn btn-primary btn-full">Entrar</button>
            </form>
        </div>
    </div>
</body>
</html>
