<?php
require_once __DIR__ . '/config.php';

// Only accept POST to prevent CSRF via GET (e.g. <img src="logout.php">)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrfToken($_POST['_csrf'] ?? null)) {
    redirect('index.php');
}

// Fully destroy the session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']
    );
}
session_destroy();

redirect('login.php');
