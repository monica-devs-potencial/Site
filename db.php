<?php
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    if (!is_dir(UPLOADS_DIR)) {
        mkdir(UPLOADS_DIR, 0755, true);
    }

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
    } catch (\PDOException $e) {
        $isApi = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
        if ($isApi) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['error' => 'Banco de dados indisponível'], JSON_UNESCAPED_UNICODE));
        }
        http_response_code(503);
        exit(
            '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">' .
            '<title>Erro de conexão</title>' .
            '<style>body{font-family:system-ui,sans-serif;max-width:600px;margin:4rem auto;padding:1rem;color:#1e293b}' .
            'h1{color:#ef4444}pre{background:#fee2e2;padding:1rem;border-radius:8px;white-space:pre-wrap;font-size:.85rem}' .
            '.btn{display:inline-block;margin-top:1rem;padding:.6rem 1.4rem;background:#4f46e5;color:#fff;border-radius:8px;text-decoration:none}</style>' .
            '</head><body>' .
            '<h1>&#x274C; Falha ao conectar ao banco de dados</h1>' .
            '<p>Verifique as credenciais em <code>config.local.php</code> ou <code>.env</code>.</p>' .
            '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>' .
            '<a href="setup.php" class="btn">&#x2699;&#xFE0F; Abrir assistente de configuração</a>' .
            '</body></html>'
        );
    }

    // ── Base tables (all utf8mb4 from the start) ──────────────────────────────

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username     VARCHAR(20)  NOT NULL,
        password     VARCHAR(255) NOT NULL,
        is_admin     TINYINT(1)   NOT NULL DEFAULT 0,
        last_read_id INT UNSIGNED NOT NULL DEFAULT 0,
        typing_at    DATETIME NULL,
        email        VARCHAR(150) NULL,
        display_name VARCHAR(50)  NULL,
        avatar_path  VARCHAR(255) NULL,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED NOT NULL,
        username   VARCHAR(20)  NOT NULL,
        content    TEXT NOT NULL DEFAULT '',
        image_path VARCHAR(255),
        audio_path VARCHAR(255),
        file_path  VARCHAR(255),
        deleted_at DATETIME,
        edited_at  DATETIME,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_messages_id (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS private_messages (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        from_user_id INT UNSIGNED NOT NULL,
        to_user_id   INT UNSIGNED NOT NULL,
        content      TEXT NOT NULL DEFAULT '',
        image_path   VARCHAR(255),
        audio_path   VARCHAR(255),
        file_path    VARCHAR(255),
        deleted_at   DATETIME,
        edited_at    DATETIME,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pm_pair    (from_user_id, to_user_id),
        KEY idx_pm_reverse (to_user_id, from_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_playlist (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED NOT NULL,
        title      VARCHAR(200) NOT NULL DEFAULT '',
        artist     VARCHAR(200) NOT NULL DEFAULT '',
        file_path  VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_playlist_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_groups (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(100) NOT NULL,
        created_by INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_groups_creator (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS group_members (
        group_id  INT UNSIGNED NOT NULL,
        user_id   INT UNSIGNED NOT NULL,
        role      ENUM('admin','member') NOT NULL DEFAULT 'member',
        joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (group_id, user_id),
        KEY idx_gm_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS group_messages (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_id     INT UNSIGNED NOT NULL,
        from_user_id INT UNSIGNED NOT NULL,
        content      TEXT NOT NULL DEFAULT '',
        image_path   VARCHAR(255),
        audio_path   VARCHAR(255),
        file_path    VARCHAR(255),
        deleted_at   DATETIME,
        edited_at    DATETIME,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_gm_group_id (group_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_rate_limit (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip_hash       VARCHAR(64)  NOT NULL,
        username      VARCHAR(20)  NOT NULL DEFAULT '',
        attempts      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        first_attempt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_attempt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ratelimit_ip      (ip_hash),
        KEY idx_ratelimit_ip_user (ip_hash, username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dm_read_receipts (
        user_id      INT UNSIGNED NOT NULL,
        partner_id   INT UNSIGNED NOT NULL,
        last_read_id INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, partner_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── Schema migrations (run once, version-tracked) ─────────────────────────
    // Increment SCHEMA_VERSION and add a new `if ($v < N)` block for each change.
    $schemaVersion = 3;

    $pdo->exec("CREATE TABLE IF NOT EXISTS db_schema_version (
        version INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $v = (int) $pdo->query("SELECT COALESCE(MAX(version), 0) FROM db_schema_version")->fetchColumn();

    if ($v < 1) {
        // Add columns to legacy installs that pre-date the full column set
        foreach ([
            "ALTER TABLE users ADD COLUMN IF NOT EXISTS last_read_id  INT UNSIGNED NOT NULL DEFAULT 0",
            "ALTER TABLE users ADD COLUMN IF NOT EXISTS typing_at     DATETIME NULL",
            "ALTER TABLE users ADD COLUMN IF NOT EXISTS email         VARCHAR(150) NULL",
            "ALTER TABLE users ADD COLUMN IF NOT EXISTS display_name  VARCHAR(50) NULL",
            "ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_path   VARCHAR(255) NULL",
            "ALTER TABLE messages ADD COLUMN IF NOT EXISTS audio_path VARCHAR(255) NULL AFTER image_path",
            "ALTER TABLE messages ADD COLUMN IF NOT EXISTS edited_at  DATETIME NULL AFTER deleted_at",
        ] as $sql) {
            $pdo->exec($sql);
        }
        // utf8mb4 for any table that was created with a narrower charset
        foreach (['users', 'messages', 'private_messages', 'group_messages'] as $tbl) {
            $pdo->exec("ALTER TABLE `$tbl` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        if ((int) $pdo->query("SELECT COUNT(*) FROM db_schema_version")->fetchColumn() === 0) {
            $pdo->exec("INSERT INTO db_schema_version (version) VALUES (1)");
        } else {
            $pdo->exec("UPDATE db_schema_version SET version = 1");
        }
        $v = 1;
    }

    if ($v < 2) {
        // Ensure unique index on username exists for legacy installs
        $hasUq = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_username'"
        )->fetchColumn();
        if (!$hasUq) {
            $pdo->exec("ALTER TABLE users ADD UNIQUE KEY uq_username (username)");
        }
        $pdo->exec("UPDATE db_schema_version SET version = 2");
    }

    if ($v < 3) {
        // Add file_path column for general file attachments (video, PDF, doc, …)
        foreach ([
            "ALTER TABLE messages ADD COLUMN IF NOT EXISTS file_path VARCHAR(255) NULL AFTER audio_path",
            "ALTER TABLE private_messages ADD COLUMN IF NOT EXISTS file_path VARCHAR(255) NULL AFTER audio_path",
            "ALTER TABLE group_messages ADD COLUMN IF NOT EXISTS file_path VARCHAR(255) NULL AFTER audio_path",
        ] as $sql) {
            $pdo->exec($sql);
        }
        $pdo->exec("UPDATE db_schema_version SET version = 3");
    }

    return $pdo;
}

// ── Message pruning ───────────────────────────────────────────────────────────
// Keeps the database lean by deleting old messages and orphaned uploaded files.
// - Hard-deletes soft-deleted (deleted_at set) messages older than 7 days
// - Hard-deletes non-deleted messages older than MESSAGE_MAX_DAYS days
// - Trims public chat to at most MESSAGE_MAX_PUBLIC rows
// - Trims each DM conversation to at most MESSAGE_MAX_DM rows per pair
// - Trims each group chat to at most MESSAGE_MAX_GROUP rows
// - Deletes uploaded files that are no longer referenced by any message
function pruneMessages(PDO $pdo): array {
    $maxDays   = defined('MESSAGE_MAX_DAYS')   ? MESSAGE_MAX_DAYS   : 90;
    $maxPublic = defined('MESSAGE_MAX_PUBLIC')  ? MESSAGE_MAX_PUBLIC  : 2000;
    $maxDM     = defined('MESSAGE_MAX_DM')      ? MESSAGE_MAX_DM      : 500;
    $maxGroup  = defined('MESSAGE_MAX_GROUP')   ? MESSAGE_MAX_GROUP   : 1000;

    $deleted = ['public' => 0, 'dm' => 0, 'group' => 0, 'files' => 0];

    // 1. Hard-delete soft-deleted messages older than 7 days (all tables)
    foreach (['messages', 'private_messages', 'group_messages'] as $tbl) {
        $stmt = $pdo->query(
            "SELECT image_path, audio_path, file_path FROM `$tbl`
             WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            _deleteFile($row['image_path']);
            _deleteFile($row['audio_path']);
            _deleteFile($row['file_path'] ?? null);
        }
        $cnt = $pdo->exec(
            "DELETE FROM `$tbl` WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $deleted['public'] += ($tbl === 'messages') ? $cnt : 0;
        $deleted['dm']     += ($tbl === 'private_messages') ? $cnt : 0;
        $deleted['group']  += ($tbl === 'group_messages') ? $cnt : 0;
    }

    // 2. Hard-delete messages older than $maxDays days (all tables)
    foreach (['messages', 'private_messages', 'group_messages'] as $tbl) {
        $stmt = $pdo->query(
            "SELECT image_path, audio_path, file_path FROM `$tbl`
             WHERE created_at < DATE_SUB(NOW(), INTERVAL $maxDays DAY)"
        );
        foreach ($stmt->fetchAll() as $row) {
            _deleteFile($row['image_path']);
            _deleteFile($row['audio_path']);
            _deleteFile($row['file_path'] ?? null);
        }
        $cnt = $pdo->exec(
            "DELETE FROM `$tbl` WHERE created_at < DATE_SUB(NOW(), INTERVAL $maxDays DAY)"
        );
        $deleted['public'] += ($tbl === 'messages') ? $cnt : 0;
        $deleted['dm']     += ($tbl === 'private_messages') ? $cnt : 0;
        $deleted['group']  += ($tbl === 'group_messages') ? $cnt : 0;
    }

    // 3. Trim public chat to $maxPublic rows (delete oldest beyond the limit)
    $total = (int) $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();
    if ($total > $maxPublic) {
        $cutoffStmt = $pdo->query(
            "SELECT id FROM messages ORDER BY id DESC LIMIT 1 OFFSET " . ($maxPublic - 1)
        );
        $cutoffId = (int) $cutoffStmt->fetchColumn();
        if ($cutoffId > 0) {
            $rows = $pdo->query("SELECT image_path, audio_path, file_path FROM messages WHERE id < $cutoffId")->fetchAll();
            foreach ($rows as $row) { _deleteFile($row['image_path']); _deleteFile($row['audio_path']); _deleteFile($row['file_path'] ?? null); }
            $deleted['public'] += (int) $pdo->exec("DELETE FROM messages WHERE id < $cutoffId");
        }
    }

    // 4. Trim each DM conversation pair to $maxDM rows
    $pairs = $pdo->query(
        "SELECT LEAST(from_user_id, to_user_id) AS u1, GREATEST(from_user_id, to_user_id) AS u2
         FROM private_messages GROUP BY u1, u2"
    )->fetchAll();
    foreach ($pairs as $pair) {
        $u1 = (int) $pair['u1']; $u2 = (int) $pair['u2'];
        $count = (int) $pdo->query(
            "SELECT COUNT(*) FROM private_messages
             WHERE (from_user_id = $u1 AND to_user_id = $u2)
                OR (from_user_id = $u2 AND to_user_id = $u1)"
        )->fetchColumn();
        if ($count > $maxDM) {
            $cutoffStmt = $pdo->query(
                "SELECT id FROM private_messages
                 WHERE (from_user_id = $u1 AND to_user_id = $u2)
                    OR (from_user_id = $u2 AND to_user_id = $u1)
                 ORDER BY id DESC LIMIT 1 OFFSET " . ($maxDM - 1)
            );
            $cutoffId = (int) $cutoffStmt->fetchColumn();
            if ($cutoffId > 0) {
                $rows = $pdo->query(
                    "SELECT image_path, audio_path, file_path FROM private_messages WHERE id < $cutoffId"
                )->fetchAll();
                foreach ($rows as $row) { _deleteFile($row['image_path']); _deleteFile($row['audio_path']); _deleteFile($row['file_path'] ?? null); }
                $deleted['dm'] += (int) $pdo->exec("DELETE FROM private_messages WHERE id < $cutoffId");
            }
        }
    }

    // 5. Trim each group chat to $maxGroup rows
    $groups = $pdo->query("SELECT id FROM chat_groups")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($groups as $gid) {
        $gid = (int) $gid;
        $count = (int) $pdo->query("SELECT COUNT(*) FROM group_messages WHERE group_id = $gid")->fetchColumn();
        if ($count > $maxGroup) {
            $cutoffStmt = $pdo->query(
                "SELECT id FROM group_messages WHERE group_id = $gid ORDER BY id DESC LIMIT 1 OFFSET " . ($maxGroup - 1)
            );
            $cutoffId = (int) $cutoffStmt->fetchColumn();
            if ($cutoffId > 0) {
                $rows = $pdo->query(
                    "SELECT image_path, audio_path, file_path FROM group_messages WHERE group_id = $gid AND id < $cutoffId"
                )->fetchAll();
                foreach ($rows as $row) { _deleteFile($row['image_path']); _deleteFile($row['audio_path']); _deleteFile($row['file_path'] ?? null); }
                $deleted['group'] += (int) $pdo->exec(
                    "DELETE FROM group_messages WHERE group_id = $gid AND id < $cutoffId"
                );
            }
        }
    }

    return $deleted;
}

/** Delete an uploaded file (image/audio) if it exists. */
function _deleteFile(?string $path): void {
    if ($path === null || $path === '') return;
    $full = UPLOADS_DIR . '/' . basename($path);
    if (is_file($full)) {
        @unlink($full);
    }
}
