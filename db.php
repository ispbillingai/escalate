<?php
// MySQL connection + auto-create tables.
// ids are BIGINT UNSIGNED from day one so AUTO_INCREMENT can never hit the
// signed INT ceiling and silently stop storing rows.

function getDB()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    global $db_host, $db_user, $db_password, $db_name;

    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec("CREATE TABLE IF NOT EXISTS escalations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_id VARCHAR(16) NOT NULL,
        company_name VARCHAR(160) NOT NULL,
        subdomain VARCHAR(64) NOT NULL DEFAULT '',
        follow_up_number VARCHAR(32) NOT NULL,
        issue TEXT NOT NULL,
        images_json TEXT NOT NULL,
        support_screenshot VARCHAR(255) NOT NULL DEFAULT '',
        account_manager VARCHAR(120) NOT NULL DEFAULT '',
        topic VARCHAR(60) NOT NULL DEFAULT '',
        router VARCHAR(120) NOT NULL DEFAULT '',
        no_support_reply TINYINT(1) NOT NULL DEFAULT 0,
        source VARCHAR(10) NOT NULL DEFAULT 'web',
        submit_ip VARCHAR(45) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        official_reply TEXT NULL,
        replied_at DATETIME NULL,
        customer_nudged_at DATETIME NULL,
        telegram_message_id VARCHAR(32) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_public_id (public_id),
        KEY idx_status (status),
        KEY idx_topic (topic),
        KEY idx_sub (subdomain),
        KEY idx_created (created_at),
        KEY idx_ip_created (submit_ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS escalation_replies (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        escalation_id BIGINT UNSIGNED NOT NULL,
        author_type VARCHAR(10) NOT NULL DEFAULT 'company',
        author_name VARCHAR(160) NOT NULL DEFAULT '',
        body TEXT NOT NULL,
        images_json TEXT NULL,
        submit_ip VARCHAR(45) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_esc (escalation_id),
        KEY idx_created (created_at),
        KEY idx_ip_created (submit_ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Columns added after first release; heal older installs in place.
    $lateColumns = [
        'account_manager'    => "ADD COLUMN account_manager VARCHAR(120) NOT NULL DEFAULT '' AFTER support_screenshot",
        'topic'              => "ADD COLUMN topic VARCHAR(60) NOT NULL DEFAULT '' AFTER account_manager, ADD KEY idx_topic (topic)",
        'router'             => "ADD COLUMN router VARCHAR(120) NOT NULL DEFAULT '' AFTER topic",
        'customer_nudged_at' => "ADD COLUMN customer_nudged_at DATETIME NULL AFTER replied_at",
    ];
    foreach ($lateColumns as $name => $ddl) {
        try {
            $col = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'escalations' AND COLUMN_NAME = '{$name}'")->fetchColumn();
            if ((int)$col === 0) {
                $pdo->exec("ALTER TABLE escalations {$ddl}");
            }
        } catch (Throwable $e) {
            error_log('[escalate] column heal failed: ' . $e->getMessage());
        }
    }
    try {
        $col = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'escalation_replies' AND COLUMN_NAME = 'images_json'")->fetchColumn();
        if ((int)$col === 0) {
            $pdo->exec("ALTER TABLE escalation_replies ADD COLUMN images_json TEXT NULL AFTER body");
        }
    } catch (Throwable $e) {
        error_log('[escalate] reply column heal failed: ' . $e->getMessage());
    }

    return $pdo;
}
