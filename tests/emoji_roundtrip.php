<?php
/**
 * Emoji round-trip test
 *
 * Verifies that strings containing multi-byte Unicode characters (emojis)
 * are stored in MySQL and retrieved without loss or corruption.
 *
 * Usage (CLI):
 *   php tests/emoji_roundtrip.php
 *
 * Returns exit code 0 on success, 1 on failure.
 */

declare(strict_types=1);

mb_internal_encoding('UTF-8');

// ── Load configuration ────────────────────────────────────────────────────────
require_once __DIR__ . '/../config.php';

// ── Connect to MySQL with utf8mb4 ─────────────────────────────────────────────
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    MYSQL_HOST,
    MYSQL_PORT,
    MYSQL_DATABASE
);

try {
    $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "FAIL: Could not connect to MySQL: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Create a temporary test table ─────────────────────────────────────────────
$pdo->exec("CREATE TEMPORARY TABLE emoji_test (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Test cases: strings with 4-byte emoji characters ─────────────────────────
$testCases = [
    '🙂🔥🚀',
    'Hello 👋 World 🌍',
    'Ações com emoji: ✅ feito, ❌ erro',
    '日本語テスト 🎌',
    str_repeat('😀', 50),                    // 50 identical emojis
    "Line1\nLine2\tTabbed 🎉",               // with whitespace
];

$passed = 0;
$failed = 0;

foreach ($testCases as $original) {
    $pdo->prepare("INSERT INTO emoji_test (content) VALUES (?)")->execute([$original]);
    $id = (int)$pdo->lastInsertId();

    $sel = $pdo->prepare("SELECT content FROM emoji_test WHERE id = ?");
    $sel->execute([$id]);
    $row = $sel->fetch();

    $retrieved = $row['content'] ?? '';

    if ($retrieved === $original) {
        echo "PASS: " . mb_substr($original, 0, 40) . "\n";
        $passed++;
    } else {
        echo "FAIL: expected: " . bin2hex($original) . "\n";
        echo "      got:      " . bin2hex($retrieved) . "\n";
        $failed++;
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n$passed passed, $failed failed.\n";

exit($failed > 0 ? 1 : 0);
