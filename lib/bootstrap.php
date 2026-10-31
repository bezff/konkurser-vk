<?php

declare(strict_types=1);

define('APP', true);
define('APP_ROOT', dirname(__DIR__));

require __DIR__ . '/db.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/vk.php';
require __DIR__ . '/scoring.php';
require __DIR__ . '/contest.php';
require __DIR__ . '/callback.php';
require __DIR__ . '/view.php';

function app_boot(bool $session): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    $config = app_config();
    date_default_timezone_set($config['timezone'] ?? 'Europe/Moscow');
    if ($session) {
        auth_start();
    }
}

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = APP_ROOT . '/config.php';
    if (!is_file($path)) {
        $config = ['timezone' => 'Europe/Moscow'];
        return $config;
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('config.php должен возвращать массив.');
    }

    $tz = $loaded['timezone'] ?? 'Europe/Moscow';
    if (!is_string($tz) || !in_array($tz, timezone_identifiers_list(), true)) {
        $tz = 'Europe/Moscow';
    }
    $loaded['timezone'] = $tz;
    $config = $loaded;
    return $config;
}

function app_now(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function app_log(string $message): void
{
    $dir = APP_ROOT . '/storage';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $file = $dir . '/error.log.php';
    if (!is_file($file)) {
        file_put_contents($file, "<?php http_response_code(404); exit; ?>\n", LOCK_EX);
    }
    $line = (new DateTimeImmutable('now'))->format('c') . ' ' . str_replace(["\r", "\n"], ' ', $message);
    file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
}

function app_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!is_string($host) || !preg_match('/^[A-Za-z0-9.-]+(?::\d+)?$/', $host)) {
        $host = 'localhost';
    }
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim($dir, '/');
    if ($dir === '/' || $dir === '.') {
        $dir = '';
    }
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!is_string($ip) || strlen($ip) > 45) {
        return '0.0.0.0';
    }
    return $ip;
}

function db_transaction(PDO $pdo, callable $fn): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function db_lock(PDO $pdo, string $name): void
{
    $st = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $st->execute([$name]);
    if ((string) $st->fetchColumn() !== '1') {
        throw new RuntimeException('Операция уже выполняется. Повторите через несколько секунд.');
    }
}

function db_unlock(PDO $pdo, string $name): void
{
    $st = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $st->execute([$name]);
}

function redirect_to(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function app_database(): PDO
{
    try {
        return db();
    } catch (Throwable $e) {
        app_log($e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Нет соединения с базой. Проверьте config.php.\n";
        exit;
    }
}

function app_require_ready(): PDO
{
    $pdo = app_database();
    try {
        $hash = settings_get($pdo, 'admin_password_hash');
    } catch (Throwable $e) {
        app_log($e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "База ещё не подготовлена. Откройте install.php.\n";
        exit;
    }
    if ($hash === '') {
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Откройте install.php и задайте пароль администратора.\n";
        exit;
    }
    return $pdo;
}
