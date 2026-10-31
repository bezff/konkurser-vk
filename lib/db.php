<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    foreach (['db_host', 'db_name', 'db_user'] as $key) {
        if (empty($config[$key]) || !is_string($config[$key])) {
            throw new RuntimeException('В config.php не заполнено поле ' . $key . '.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $config[$key])) {
            throw new RuntimeException('Некорректное значение ' . $key . ' в config.php.');
        }
    }
    if (!isset($config['db_pass']) || !is_string($config['db_pass'])) {
        throw new RuntimeException('В config.php нет db_pass.');
    }

    $port = (int) ($config['db_port'] ?? 3306);
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('Некорректный db_port в config.php.');
    }

    $dsn = 'mysql:host=' . $config['db_host']
        . ';port=' . $port
        . ';dbname=' . $config['db_name']
        . ';charset=utf8mb4';

    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $offset = (new DateTimeImmutable('now'))->format('P');
    $pdo->exec('SET time_zone = ' . $pdo->quote($offset));

    schema_ensure($pdo);

    return $pdo;
}

function schema_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $pdo->query('SELECT 1 FROM contests LIMIT 1');
    } catch (Throwable) {
        return;
    }

    $done = true;

    if (!schema_has_column($pdo, 'contests', 'require_accept')) {
        $pdo->exec(
            'ALTER TABLE contests
             ADD COLUMN require_accept TINYINT(1) NOT NULL DEFAULT 0
             AFTER status'
        );
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS acceptances (
            contest_id BIGINT UNSIGNED NOT NULL,
            vk_user_id BIGINT UNSIGNED NOT NULL,
            accepted_at DATETIME NOT NULL,
            PRIMARY KEY (contest_id, vk_user_id),
            CONSTRAINT fk_acceptances_contest FOREIGN KEY (contest_id) REFERENCES contests (id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function schema_has_column(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $st->execute([$table, $column]);
    return (bool) $st->fetchColumn();
}
