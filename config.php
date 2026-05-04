<?php
// ─────────────────────────────────────────────────────────────────────────────
//  Carregamento de credenciais — ordem de prioridade:
//    1. config.local.php  (criado pelo setup.php ou manualmente)
//    2. .env              (variáveis no formato CHAVE=valor)
//    3. Variáveis de ambiente do servidor (Docker / cPanel)
//    4. Valores padrão — se ainda vazios, redireciona para setup.php
// ─────────────────────────────────────────────────────────────────────────────

// 1. config.local.php
$_localCfg = __DIR__ . '/config.local.php';
if (is_file($_localCfg)) {
    require $_localCfg;
}
unset($_localCfg);

// 2. .env
$_envFile = __DIR__ . '/.env';
if (is_file($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        if ($_line === '' || str_starts_with($_line, '#') || !str_contains($_line, '=')) {
            continue;
        }
        [$_k, $_v] = explode('=', $_line, 2);
        $_k = trim($_k);
        $_v = trim($_v);
        if ($_k !== '' && getenv($_k) === false) {
            putenv("$_k=$_v");
            $_ENV[$_k] = $_v;
        }
    }
    unset($_line, $_k, $_v);
}
unset($_envFile);

// 3 & 4. Define constants (if not already defined by config.local.php)
if (!defined('MYSQL_HOST'))     define('MYSQL_HOST',     getenv('MYSQL_HOST')     ?: 'localhost');
if (!defined('MYSQL_PORT'))     define('MYSQL_PORT',     getenv('MYSQL_PORT')     ?: '3306');
if (!defined('MYSQL_DATABASE')) define('MYSQL_DATABASE', getenv('MYSQL_DATABASE') ?: '');
if (!defined('MYSQL_USER'))     define('MYSQL_USER',     getenv('MYSQL_USER')     ?: '');
if (!defined('MYSQL_PASSWORD')) define('MYSQL_PASSWORD', getenv('MYSQL_PASSWORD') ?: '');

// Se as credenciais não foram configuradas, redireciona para o assistente de instalação
if ((MYSQL_DATABASE === '' || MYSQL_USER === '') && PHP_SAPI !== 'cli') {
    $__script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($__script !== 'setup.php') {
        header('Location: setup.php');
        exit;
    }
    unset($__script);
}

// ── File / upload settings ───────────────────────────────────────────────────
define('DATA_DIR',    __DIR__ . '/data');
define('UPLOADS_DIR', __DIR__ . '/data/uploads');
define('UPLOADS_URL', 'data/uploads/');
define('AVATARS_DIR', __DIR__ . '/data/uploads');
define('AVATARS_URL', 'data/uploads/');
define('MAX_FILE_SIZE',  50 * 1024 * 1024); // 50 MB — general file attachments (video, PDF, doc…)
define('MAX_IMG_SIZE',    5 * 1024 * 1024); // 5 MB
define('MAX_AUDIO_SIZE', 10 * 1024 * 1024); // 10 MB
define('MAX_AVATAR_SIZE', 3 * 1024 * 1024); // 3 MB

// ── Message retention limits ─────────────────────────────────────────────────
if (!defined('MESSAGE_MAX_DAYS'))   define('MESSAGE_MAX_DAYS',   90);   // delete messages older than N days
if (!defined('MESSAGE_MAX_PUBLIC')) define('MESSAGE_MAX_PUBLIC', 2000);  // max rows in public chat
if (!defined('MESSAGE_MAX_DM'))     define('MESSAGE_MAX_DM',     500);   // max rows per DM pair
if (!defined('MESSAGE_MAX_GROUP'))  define('MESSAGE_MAX_GROUP',  1000);  // max rows per group chat

// ── Session security settings (must be set before session_start) ────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_start();

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

function isAdmin(): bool {
    return !empty($_SESSION['is_admin']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

// ── CSRF protection ───────────────────────────────────────────────────────────

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || $token === null || $token === '') {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * For API endpoints (JSON responses): validates CSRF from POST body or
 * X-CSRF-Token request header, and aborts with 403 on failure.
 */
function requireCsrfToken(): void {
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        $isApi = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['error' => 'Token CSRF inválido'], JSON_UNESCAPED_UNICODE));
        }
        exit('<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Erro</title></head>'
            . '<body><h1>403 – Token de segurança inválido</h1>'
            . '<p>Recarregue a página e tente novamente.</p>'
            . '<a href="javascript:history.back()">← Voltar</a></body></html>');
    }
}
