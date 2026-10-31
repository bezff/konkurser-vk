<?php

declare(strict_types=1);

if (!defined('APP')) {
    http_response_code(404);
    exit;
}

$settings = settings_all($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'connection') {
            $error = settings_save_connection($pdo, $_POST);
            if ($error === null) {
                unset($_SESSION['suggested_secret']);
                $saved = settings_all($pdo);
                flash_set('ok', 'Сообщество «' . ($saved['vk_group_name'] ?? '') . '» подключено.');
            } else {
                flash_set('bad', $error);
            }
        } else {
            flash_set('bad', 'Неизвестное действие.');
        }
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('bad', 'Не удалось сохранить настройки.');
    }
    redirect_to('index.php?page=connect');
}

$settings = settings_all($pdo);
$flash = flash_take();
$groupId = (string) ($settings['vk_group_id'] ?? '');
$hasToken = ($settings['vk_access_token'] ?? '') !== '';
$hasSecret = strlen((string) ($settings['vk_secret'] ?? '')) >= 8;
$secretValue = (string) ($settings['vk_secret'] ?? '');
if (!$hasSecret) {
    if (empty($_SESSION['suggested_secret']) || !is_string($_SESSION['suggested_secret'])) {
        $_SESSION['suggested_secret'] = bin2hex(random_bytes(16));
    }
    $secretValue = $_SESSION['suggested_secret'];
}
$callbackUrl = app_base_url() . '/callback.php';
$log = callback_recent($pdo);

view_start('Подключение', 'connect', (string) ($settings['vk_group_name'] ?? ''));
view_flash($flash);

echo '<section class="panel">';
echo '<h1>Сообщество ВК</h1>';
echo '<p class="lead">Одна группа. Ключ хранится в базе и наружу не показывается.</p>';
echo '<form method="post" action="index.php?page=connect" autocomplete="off">';
view_csrf();
echo '<input type="hidden" name="action" value="connection">';

echo '<label for="group">Сообщество</label>';
echo '<input id="group" name="group" required value="' . h($groupId) . '" placeholder="123456 или vk.com/club123456" spellcheck="false" aria-describedby="group-hint">';
echo '<p class="hint" id="group-hint">Числовой ID, короткая ссылка или адрес страницы.</p>';

echo '<label for="token">Ключ доступа сообщества</label>';
echo '<input id="token" name="token" type="password" spellcheck="false" autocomplete="off" placeholder="' . h($hasToken ? 'Сохранён. Вставьте новый, чтобы заменить' : 'Вставьте ключ') . '" aria-describedby="token-hint">';
echo '<p class="hint" id="token-hint">Управление сообществом, затем Дополнительно, Работа с API, Создать ключ.</p>';

echo '<label for="confirmation">Строка подтверждения</label>';
echo '<input id="confirmation" name="confirmation" required value="' . h((string) ($settings['vk_confirmation'] ?? '')) . '" spellcheck="false" autocomplete="off" aria-describedby="confirm-hint">';
echo '<p class="hint" id="confirm-hint">Код со вкладки Callback API в настройках сообщества. Код из метода API другой, он здесь не подойдёт.</p>';

echo '<label for="secret">Секретный ключ</label>';
echo '<input id="secret" name="secret" value="' . h($secretValue) . '" spellcheck="false" autocomplete="off" aria-describedby="secret-hint">';
echo '<p class="hint" id="secret-hint">Скопируйте его в настройки сервера Callback API. Если стереть поле и сохранить, старый ключ останется.</p>';

$appId = (string) ($settings['vk_app_id'] ?? '');
$hasAppSecret = ($settings['vk_app_secret'] ?? '') !== '';
echo '<label for="vk_app_id">ID мини-приложения</label>';
echo '<input id="vk_app_id" name="vk_app_id" inputmode="numeric" value="' . h($appId) . '" placeholder="например 51712345" spellcheck="false" aria-describedby="app-id-hint">';
echo '<p class="hint" id="app-id-hint">Нужен, если в конкурсе баллы только после перехода по ссылке. Создайте мини-приложение на <a href="https://dev.vk.com/" target="_blank" rel="noopener">dev.vk.com</a>.</p>';

echo '<label for="vk_app_secret">Защищённый ключ мини-приложения</label>';
echo '<input id="vk_app_secret" name="vk_app_secret" type="password" spellcheck="false" autocomplete="off" placeholder="' . h($hasAppSecret ? 'Сохранён. Вставьте новый, чтобы заменить' : 'Из настроек приложения → Ключи доступа') . '" aria-describedby="app-secret-hint">';
echo '<p class="hint" id="app-secret-hint">Разработка → Ключи доступа → Защищённый ключ. Если стереть поле и сохранить, старый ключ останется.</p>';

echo '<div class="grid-3">';
echo '<div><label for="points_join">Баллы за вступление</label><input id="points_join" name="points_join" inputmode="numeric" required value="' . settings_points($settings, 'join') . '"></div>';
echo '<div><label for="points_like">Баллы за лайк</label><input id="points_like" name="points_like" inputmode="numeric" required value="' . settings_points($settings, 'like') . '"></div>';
echo '<div><label for="points_comment">Баллы за комментарий</label><input id="points_comment" name="points_comment" inputmode="numeric" required value="' . settings_points($settings, 'comment') . '"></div>';
echo '</div>';
echo '<p class="hint">Целое число от 0 до 100. Ноль выключает действие для новых событий.</p>';
echo '<button type="submit">Сохранить и проверить</button>';
echo '</form></section>';

$joinUrl = app_base_url() . '/join.php';
echo '<section class="panel">';
echo '<h2>Адрес мини-приложения</h2>';
echo '<p class="lead">Впишите этот URL в настройки мини-приложения как адрес приложения.</p>';
echo '<div class="copy-row">';
echo '<input id="join-url" readonly value="' . h($joinUrl) . '">';
echo '<button type="button" class="ghost" data-copy="join-url">Скопировать</button>';
echo '</div>';
echo '<p class="hint">Ссылку для записи конкурса вида vk.com/app…_-… смотрите на странице «Конкурс» после запуска с галочкой принятия.</p>';
echo '</section>';

echo '<section class="panel">';
echo '<h2>Адрес для Callback API</h2>';
echo '<div class="copy-row">';
echo '<input id="callback-url" readonly value="' . h($callbackUrl) . '">';
echo '<button type="button" class="ghost" data-copy="callback-url">Скопировать</button>';
echo '</div>';
echo '<ol class="steps">';
echo '<li>Откройте сообщество, Управление, Дополнительно, Работа с API, вкладка Callback API.</li>';
echo '<li>Добавьте сервер с адресом выше. Версию API поставьте 5.199. Секретный ключ скопируйте из формы на этой странице.</li>';
echo '<li>Сначала сохраните строку подтверждения здесь, затем нажмите «Подтвердить» в ВК.</li>';
echo '<li>Включите события: вступление, выход, новый комментарий на стене, удаление комментария, восстановление комментария, новая отметка «Мне нравится», снятие отметки.</li>';
echo '<li>Репост не включайте: удаление репоста ВК не присылает, балл нельзя было бы снять.</li>';
echo '</ol>';
echo '<p class="hint">Если секрет не совпадёт, в журнале ниже будет «секрет не подошёл», а баллы не изменятся.</p>';
echo '</section>';

echo '<section class="panel">';
echo '<h2>Последние события</h2>';
if ($log === []) {
    echo '<p class="empty">Пока пусто. После подтверждения сервера ВК начнёт присылать события сюда.</p>';
} else {
    echo '<div class="table-wrap"><table><thead><tr><th>Время</th><th>Событие</th><th>Результат</th><th>Детали</th></tr></thead><tbody>';
    foreach ($log as $row) {
        echo '<tr><td>' . h(view_datetime($row['created_at'])) . '</td>';
        echo '<td>' . h($row['event_type']) . '</td>';
        echo '<td>' . h(callback_result_label($row['result'])) . '</td>';
        echo '<td>' . h(callback_detail_label($row['detail'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

view_end();
