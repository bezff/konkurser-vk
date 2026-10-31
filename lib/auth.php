<?php

declare(strict_types=1);

function auth_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_name('konkurser');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secure,
    ]);
    session_start();

    $last = (int) ($_SESSION['last'] ?? 0);
    if (!empty($_SESSION['admin']) && $last > 0 && (time() - $last) > 8 * 3600) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    if (!empty($_SESSION['admin'])) {
        $_SESSION['last'] = time();
    }
}

function auth_logged_in(): bool
{
    return !empty($_SESSION['admin']);
}

function auth_mark_logged_in(): void
{
    session_regenerate_id(true);
    $_SESSION['admin'] = 1;
    $_SESSION['last'] = time();
    unset($_SESSION['csrf']);
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || !is_string($known) || $known === '' || !hash_equals($known, $sent)) {
        http_response_code(419);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Сессия устарела. Обновите страницу и повторите.';
        exit;
    }
}

function auth_login(PDO $pdo, string $password, string $ip): ?string
{
    $row = login_attempt_row($pdo, $ip);
    if ($row !== null && $row['locked_until'] !== null && $row['locked_until'] > app_now()) {
        return 'Слишком много попыток. Подождите 15 минут.';
    }

    $hash = settings_get($pdo, 'admin_password_hash');
    if ($hash !== '' && password_verify($password, $hash)) {
        $st = $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?');
        $st->execute([$ip]);
        auth_mark_logged_in();
        return null;
    }

    login_attempt_fail($pdo, $ip, $row);
    return 'Неверный пароль.';
}

function auth_password_rules(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Пароль должен быть не короче 8 символов.';
    }
    if (strlen($password) > 200) {
        return 'Пароль слишком длинный.';
    }
    return null;
}

function login_attempt_row(PDO $pdo, string $ip): ?array
{
    $st = $pdo->prepare('SELECT failures, locked_until, updated_at FROM login_attempts WHERE ip = ?');
    $st->execute([$ip]);
    $row = $st->fetch();
    return $row ?: null;
}

function login_attempt_fail(PDO $pdo, string $ip, ?array $row): void
{
    $now = app_now();
    $failures = 1;
    if ($row !== null) {
        $recent = $row['updated_at'] >= (new DateTimeImmutable('-15 minutes'))->format('Y-m-d H:i:s');
        $locked = $row['locked_until'] !== null && $row['locked_until'] > $now;
        if ($recent || $locked) {
            $failures = ((int) $row['failures']) + 1;
        }
    }
    $lockedUntil = $failures >= 8
        ? (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s')
        : null;

    $st = $pdo->prepare(
        'INSERT INTO login_attempts (ip, failures, locked_until, updated_at) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE failures = ?, locked_until = ?, updated_at = ?'
    );
    $st->execute([$ip, $failures, $lockedUntil, $now, $failures, $lockedUntil, $now]);
}
