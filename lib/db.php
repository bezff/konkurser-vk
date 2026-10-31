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

    return $pdo;
}
