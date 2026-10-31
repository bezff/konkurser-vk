<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
app_boot(true);

function install_fail(string $message): void
{
    http_response_code(500);
    view_start('Установка');
    echo '<section class="login"><h1>Установка</h1><p class="flash bad" role="alert">' . h($message) . '</p></section>';
    view_end();
    exit;
}

function install_schema(PDO $pdo): void
{
    $sql = file_get_contents(APP_ROOT . '/sql/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('Не найден файл sql/schema.sql.');
    }
    $parts = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];
    foreach ($parts as $part) {
        $statement = trim($part);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}

function install_locked(PDO $pdo): bool
{
    if (is_file(APP_ROOT . '/storage/install.lock')) {
        return true;
    }
    try {
        return settings_get($pdo, 'admin_password_hash') !== '';
    } catch (Throwable) {
        return false;
    }
}

$missing = [];
foreach (['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
if ($missing !== []) {
    install_fail('На хостинге не включены расширения PHP: ' . implode(', ', $missing) . '.');
}

if (!is_file(APP_ROOT . '/config.php')) {
    install_fail('Скопируйте config.example.php в config.php и укажите базу MySQL из панели REG.RU.');
}

try {
    $pdo = db();
} catch (Throwable $e) {
    app_log($e->getMessage());
    install_fail('Нет соединения с MySQL. Проверьте имя базы, пользователя и пароль в config.php.');
}

if (install_locked($pdo)) {
    view_start('Установка');
    echo '<section class="login"><h1>Уже установлено</h1>';
    echo '<p class="lead">Пароль администратора уже задан. Удалите install.php с хостинга.</p>';
    echo '<p class="hint">Если пароль потерян: в phpMyAdmin удалите строку admin_password_hash из таблицы settings, удалите файл storage/install.lock и откройте эту страницу снова.</p>';
    echo '<p><a class="button" href="index.php">Перейти ко входу</a></p></section>';
    view_end();
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $password = (string) ($_POST['password'] ?? '');
    $repeat = (string) ($_POST['password_repeat'] ?? '');
    $error = auth_password_rules($password);
    if ($error === null && !hash_equals($password, $repeat)) {
        $error = 'Пароли не совпадают.';
    }
    if ($error === null) {
        $dir = APP_ROOT . '/storage';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            $error = 'Не удалось создать папку storage.';
        } elseif (!is_writable($dir)) {
            $error = 'Папка storage недоступна для записи. Поставьте права 750 или 775.';
        }
    }
    if ($error === null) {
        try {
            install_schema($pdo);
            settings_set_many($pdo, [
                'admin_password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'points_join' => '5',
                'points_like' => '1',
                'points_comment' => '2',
            ]);
            file_put_contents($dir . '/install.lock', app_now() . "\n");
            auth_mark_logged_in();
            redirect_to('index.php?page=connect');
        } catch (Throwable $e) {
            app_log($e->getMessage());
            $error = 'Не удалось создать таблицы. Проверьте, что пользователь базы имеет права на CREATE.';
        }
    }
}

view_start('Установка');
echo '<section class="login"><h1>Установка</h1>';
echo '<p class="lead">Будут созданы таблицы и пароль администратора. Участников регистрировать не нужно.</p>';
if ($error !== null) {
    echo '<p class="flash bad" role="alert">' . h($error) . '</p>';
}
echo '<form method="post" action="install.php" autocomplete="off">';
view_csrf();
echo '<label for="password">Пароль администратора</label>';
echo '<input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">';
echo '<label for="password_repeat">Пароль ещё раз</label>';
echo '<input id="password_repeat" name="password_repeat" type="password" required minlength="8" autocomplete="new-password">';
echo '<p class="hint">Не короче 8 символов. Запомните его: восстановить можно только через базу.</p>';
echo '<button type="submit">Создать</button>';
echo '</form></section>';
view_end();
