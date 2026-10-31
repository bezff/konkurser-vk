<?php

declare(strict_types=1);

if (!defined('APP')) {
    http_response_code(404);
    exit;
}

$settings = settings_all($pdo);
contest_close_expired($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $contestId = (int) ($_POST['id'] ?? 0);
    try {
        if (($_POST['action'] ?? '') === 'names' && $contestId > 0 && contest_find($pdo, $contestId) !== null) {
            $error = contest_refresh_names($pdo, $contestId, (string) ($settings['vk_access_token'] ?? ''));
            flash_set($error === null ? 'ok' : 'bad', $error ?? 'Имена обновлены. Если участников больше трёхсот, нажмите ещё раз.');
        } else {
            flash_set('bad', 'Не удалось обновить имена.');
        }
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('bad', 'Не удалось обновить имена.');
    }
    redirect_to('index.php?page=rating&id=' . $contestId);
}

$contests = contest_list($pdo);
$requested = (int) ($_GET['id'] ?? 0);
$contest = null;
if ($requested > 0) {
    $contest = contest_find($pdo, $requested);
}
if ($contest === null && $contests !== []) {
    $contest = $contests[0];
}

view_start('Рейтинг', 'rating', (string) ($settings['vk_group_name'] ?? ''));
view_flash(flash_take());

echo '<section class="panel">';
echo '<div class="panel-head"><h1>Рейтинг</h1></div>';

if ($contests === []) {
    echo '<p class="empty">Конкурса ещё нет. <a href="index.php?page=contest">Запустите первый</a>.</p>';
    echo '</section>';
    view_end();
    exit;
}

echo '<form class="inline-form" method="get" action="index.php">';
echo '<input type="hidden" name="page" value="rating">';
echo '<label for="contest-id">Конкурс</label>';
echo '<select id="contest-id" name="id">';
foreach ($contests as $item) {
    $selected = (int) $item['id'] === (int) $contest['id'] ? ' selected' : '';
    echo '<option value="' . (int) $item['id'] . '"' . $selected . '>' . h($item['title']) . '</option>';
}
echo '</select><button class="ghost" type="submit">Показать</button></form>';

$rows = contest_leaderboard($pdo, (int) $contest['id']);
$state = contest_status_view($contest, app_now());
echo '<p class="hint">' . h($contest['title']) . ' · ' . h($state['label']) . ' · ' . h(view_datetime($contest['starts_at'])) . ' – ' . h(view_datetime($contest['ends_at'])) . '</p>';

echo '<div class="actions">';
echo '<a class="button" href="export.php?id=' . (int) $contest['id'] . '">Скачать CSV</a>';
echo '<form method="post" action="index.php?page=rating">';
view_csrf();
echo '<input type="hidden" name="action" value="names">';
echo '<input type="hidden" name="id" value="' . (int) $contest['id'] . '">';
echo '<button class="ghost" type="submit">Обновить имена из ВК</button>';
echo '</form></div>';
echo '<p class="hint">CSV в UTF-8, разделитель: точка с запятой, чтобы Excel открыл кириллицу.</p>';

if ($rows === []) {
    echo '<p class="empty">Баллов пока нет. Они появятся, когда участники начнут действовать в сообществе во время конкурса.</p>';
} else {
    echo '<div class="table-wrap"><table><caption class="sr-only">Рейтинг участников</caption><thead><tr>';
    echo '<th>Место</th><th>Имя</th><th>Фамилия</th><th>Ссылка</th><th>Баллы</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $userId = (int) $row['vk_user_id'];
        $url = 'https://vk.com/id' . $userId;
        $first = $row['first_name'] !== '' ? $row['first_name'] : 'id' . $userId;
        echo '<tr>';
        echo '<td class="num">' . (int) $row['place'] . '</td>';
        echo '<td>' . h($first) . '</td>';
        echo '<td>' . h((string) $row['last_name']) . '</td>';
        echo '<td><a href="' . h($url) . '" rel="noopener noreferrer">' . h($url) . '</a></td>';
        echo '<td class="num">' . (int) $row['points'] . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';
view_end();
