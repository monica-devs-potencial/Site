<?php
/**
 * setup.php — Assistente de instalação
 *
 * Acesse este arquivo UMA VEZ para configurar o banco de dados.
 * Após configurar com sucesso, você pode apagar este arquivo por segurança.
 */
declare(strict_types=1);

$localConfig = __DIR__ . '/config.local.php';
$step        = 'form';
$message     = '';
$msgType     = '';

// ── Helpers ───────────────────────────────────────────────────────────────────

function setupTryConnect(string $host, string $port, string $db, string $user, string $pass): array
{
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
        return ['ok' => true, 'pdo' => $pdo];
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function setupCreateTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(20)  NOT NULL,
        password   VARCHAR(255) NOT NULL,
        is_admin   TINYINT(1)   NOT NULL DEFAULT 0,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED NOT NULL,
        username   VARCHAR(20)  NOT NULL,
        content    TEXT         NOT NULL DEFAULT '',
        image_path VARCHAR(255),
        deleted_at DATETIME,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_messages_id (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function setupWriteConfig(string $host, string $port, string $db, string $user, string $pass, string $path): bool
{
    $lines = [
        "<?php",
        "// Gerado automaticamente pelo setup.php",
        "// NÃO compartilhe este arquivo — contém sua senha do banco de dados",
        "define('MYSQL_HOST',     " . var_export($host, true) . ");",
        "define('MYSQL_PORT',     " . var_export($port, true) . ");",
        "define('MYSQL_DATABASE', " . var_export($db,   true) . ");",
        "define('MYSQL_USER',     " . var_export($user, true) . ");",
        "define('MYSQL_PASSWORD', " . var_export($pass, true) . ");",
    ];
    return (bool) file_put_contents($path, implode("\n", $lines) . "\n");
}

// ── Estado atual ──────────────────────────────────────────────────────────────

$alreadyConfigured = is_file($localConfig);

$formValues = [
    'host' => 'localhost',
    'port' => '3306',
    'db'   => '',
    'user' => '',
    'pass' => '',
];

// Preenche o formulário com valores existentes para reconfiguração
if ($alreadyConfigured) {
    $src = file_get_contents($localConfig);
    foreach (['host' => 'MYSQL_HOST', 'port' => 'MYSQL_PORT', 'db' => 'MYSQL_DATABASE', 'user' => 'MYSQL_USER'] as $field => $const) {
        if (preg_match("/define\(\s*'$const'\s*,\s*'([^']*)'\s*\)/", $src, $m)) {
            $formValues[$field] = $m[1];
        }
    }
}

// ── Processar formulário ──────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host   = trim($_POST['host']   ?? 'localhost');
    $port   = trim($_POST['port']   ?? '3306');
    $db     = trim($_POST['db']     ?? '');
    $user   = trim($_POST['user']   ?? '');
    $pass   = $_POST['pass']        ?? '';
    $action = $_POST['action']      ?? 'test';

    $formValues = compact('host', 'port', 'db', 'user', 'pass');

    if ($db === '' || $user === '') {
        $message = 'Preencha o nome do banco de dados e o usuário.';
        $msgType = 'error';
    } else {
        $result = setupTryConnect($host, $port, $db, $user, $pass);

        if (!$result['ok']) {
            $message = $result['error'];
            $msgType = 'error';
        } elseif ($action === 'save') {
            setupCreateTables($result['pdo']);
            if (setupWriteConfig($host, $port, $db, $user, $pass, $localConfig)) {
                $step = 'done';
            } else {
                $message = 'Não foi possível criar config.local.php. Verifique se a pasta tem permissão de escrita (chmod 775 ou 777 temporariamente para diagnóstico).';
                $msgType = 'error';
            }
        } else {
            $message = 'Conexão bem-sucedida! Clique em "Salvar e instalar" para criar as tabelas e finalizar.';
            $msgType = 'success';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💬 Chat – Configuração</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .setup-note  { font-size: .82rem; color: #64748b; margin-top: .4rem; }
        .setup-steps { margin: 1rem 0 1.5rem; padding-left: 1.25rem; color: #1e293b; font-size: .92rem; line-height: 1.8; }
        .setup-steps li { margin-bottom: .2rem; }
        code { background: #f1f5f9; padding: .15rem .4rem; border-radius: 4px; font-size: .88rem; }
        .btn-row { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: .75rem; }
        .btn-secondary { background: #f1f5f9; color: #1e293b; border: 1.5px solid #e2e8f0; }
        .btn-secondary:hover { background: #e2e8f0; }
        .badge-ok   { display: inline-block; padding: .2rem .7rem; background: #dcfce7; color: #15803d; border-radius: 99px; font-size: .78rem; font-weight: 700; }
        .badge-warn { display: inline-block; padding: .2rem .7rem; background: #fef3c7; color: #92400e; border-radius: 99px; font-size: .78rem; font-weight: 700; }
    </style>
</head>
<body class="auth-page">
<div class="auth-container" style="max-width:480px">
    <div class="auth-box">

<?php if ($step === 'done'): ?>

        <h1 class="auth-title">✅ Configurado!</h1>
        <p style="text-align:center;color:#64748b;margin-bottom:1.5rem">
            Tabelas criadas e credenciais salvas em <code>config.local.php</code>.
        </p>

        <div class="alert alert-success" style="margin-bottom:1.25rem">
            <strong>Próximos passos:</strong>
            <ol class="setup-steps">
                <li>Acesse o site e <a href="register.php">crie sua conta</a>.</li>
                <li>Para dar privilégio de admin ao primeiro usuário, execute no <strong>phpMyAdmin</strong>:<br>
                    <code>UPDATE users SET is_admin = 1 WHERE username = 'seu_usuario';</code>
                </li>
                <li>Por segurança, você pode <strong>apagar <code>setup.php</code></strong> do servidor após terminar.</li>
            </ol>
        </div>

        <div class="btn-row" style="justify-content:center">
            <a href="login.php" class="btn btn-primary">Ir para o Chat ›</a>
        </div>

<?php else: ?>

        <h1 class="auth-title">💬 Chat</h1>
        <h2 class="auth-subtitle">Assistente de instalação</h2>

        <?php if ($alreadyConfigured): ?>
            <p style="text-align:center;margin-bottom:1rem">
                <span class="badge-ok">✔ Já configurado</span>
            </p>
        <?php else: ?>
            <p style="text-align:center;margin-bottom:1rem">
                <span class="badge-warn">⚠ Configuração pendente</span>
            </p>
        <?php endif; ?>

        <p style="font-size:.88rem;color:#64748b;margin-bottom:.25rem">
            Preencha com os dados criados no <strong>hPanel → Databases → MySQL Databases</strong>.<br>
            Na Hostinger, banco e usuário têm prefixo (<code>u123456789_</code>).
        </p>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?= $msgType === 'error' ? 'error' : 'success' ?>" style="margin-top:.9rem">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="setup.php" class="auth-form" style="margin-top:1.1rem">
            <div class="form-group">
                <label for="host">Host</label>
                <input type="text" id="host" name="host" required
                       value="<?= htmlspecialchars($formValues['host']) ?>"
                       placeholder="localhost">
                <span class="setup-note">Quase sempre <code>localhost</code> na Hostinger</span>
            </div>
            <div class="form-group">
                <label for="port">Porta</label>
                <input type="text" id="port" name="port" required
                       value="<?= htmlspecialchars($formValues['port']) ?>"
                       placeholder="3306">
            </div>
            <div class="form-group">
                <label for="db">Nome do banco de dados</label>
                <input type="text" id="db" name="db" required
                       value="<?= htmlspecialchars($formValues['db']) ?>"
                       placeholder="u123456789_chat">
            </div>
            <div class="form-group">
                <label for="user">Usuário do banco</label>
                <input type="text" id="user" name="user" required
                       value="<?= htmlspecialchars($formValues['user']) ?>"
                       placeholder="u123456789_user">
            </div>
            <div class="form-group">
                <label for="pass">Senha</label>
                <input type="password" id="pass" name="pass"
                       placeholder="Sua senha">
            </div>

            <div class="btn-row">
                <button type="submit" name="action" value="test" class="btn btn-secondary">
                    🔌 Testar conexão
                </button>
                <button type="submit" name="action" value="save" class="btn btn-primary">
                    💾 Salvar e instalar
                </button>
            </div>
        </form>

        <?php if ($alreadyConfigured): ?>
            <p style="text-align:center;margin-top:1.25rem">
                <a href="login.php" class="btn btn-secondary" style="width:100%">← Voltar ao site</a>
            </p>
        <?php endif; ?>

<?php endif; ?>

    </div>
</div>
</body>
</html>
