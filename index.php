<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
app_boot(true);

if (!is_file(APP_ROOT . '/config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Создайте config.php из config.example.php и укажите доступ к MySQL.\n";
    exit;
}

$pdo = app_require_ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['logout'])) {
    csrf_check();
    auth_logout();
    redirect_to('index.php');
}

if (!auth_logged_in()) {
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $error = auth_login($pdo, (string) ($_POST['password'] ?? ''), client_ip());
        if ($error === null) {
            redirect_to('index.php');
        }
    }
    view_start('Вход');
    view_flash($error !== null ? ['type' => 'bad', 'text' => $error] : null);
    echo '<section class="login">';
    echo '<h1>Вход</h1>';
    echo '<p class="lead">Панель конкурса. Участники сообщества сюда не заходят.</p>';
    echo '<form method="post" action="index.php" autocomplete="on">';
    view_csrf();
    echo '<label for="password">Пароль</label>';
    echo '<input id="password" name="password" type="password" required autocomplete="current-password" autofocus>';
    echo '<button type="submit">Войти</button>';
    echo '</form></section>';
    view_end();
    exit;
}

$page = (string) ($_GET['page'] ?? 'contest');
if (!in_array($page, ['contest', 'connect', 'rating'], true)) {
    $page = 'contest';
}

require __DIR__ . '/pages/' . $page . '.php';
