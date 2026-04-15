<?php
require_once __DIR__ . '/config.php';

function get_db(): PDO {
    static $db = null;
    if ($db) return $db;

    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    init_schema($db);
    return $db;
}

function init_schema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            `key`   VARCHAR(100) PRIMARY KEY,
            `value` TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS api_keys (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            label      VARCHAR(255) NOT NULL,
            api_key    VARCHAR(255) NOT NULL UNIQUE,
            enabled    TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS entities (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            entity_id       VARCHAR(255) NOT NULL UNIQUE,
            friendly_name   VARCHAR(255) NOT NULL DEFAULT '',
            allowed_actions VARCHAR(100) NOT NULL DEFAULT 'get',
            enabled         TINYINT(1) NOT NULL DEFAULT 1,
            notes           TEXT NOT NULL DEFAULT '',
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS audit_log (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            api_key_id INT,
            api_label  VARCHAR(255),
            entity_id  VARCHAR(255),
            action     VARCHAR(100),
            payload    TEXT,
            result     TEXT,
            status     VARCHAR(20),
            ip         VARCHAR(45),
            ts         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Default settings
    $defaults = [
        'kill_switch' => '0',
        'ha_url'      => HA_URL,
        'ha_token'    => HA_TOKEN,
    ];
    $stmt = $db->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)");
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }
}

function get_setting(string $key): ?string {
    $db   = get_db();
    $stmt = $db->prepare("SELECT `value` FROM settings WHERE `key` = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : null;
}

function set_setting(string $key, string $value): void {
    get_db()->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
            ->execute([$key, $value]);
}
