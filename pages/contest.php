<?php

declare(strict_types=1);

if (!defined('APP')) {
    http_response_code(404);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'start') {
            $error = contest_start($pdo, $_POST);
            if ($error === null) {
                $starts = parse_local_datetime((string) ($_POST['starts_at'] ?? ''));
                $text = 'Конкурс запущен.';
                if ($starts !== null && $starts < app_now()) {
                    $text .= ' ВК не пришлёт старые события повторно: в рейтинг попадёт только новая активность.';
                }
                flash_set('ok', $text);
            } else {
                flash_set('bad', $error);
            }
        } elseif ($action === 'finish') {
            $error = contest_finish($pdo);
            flash_set($error === null ? 'ok' : 'bad', $error ?? 'Конкурс завершён. Новые действия больше не дают баллы.');
        } else {
            flash_set('bad', 'Неизвестное действие.');
        }
    } catch (Throwable $e) {
        app_log($e->getMessage());
        flash_set('bad', 'Не удалось сохранить конкурс.');
    }
    redirect_to('index.php?page=contest');
}

contest_close_expired($pdo);
$settings = settings_all($pdo);
$active = contest_find_active($pdo);
$history = contest_list($pdo);
$now = app_now();
$flash = flash_take();
$ready = settings_requirements_error($settings);

view_start('Конкурс', 'contest', (string) ($settings['vk_group_name'] ?? ''));
view_flash($flash);

$miniappReady = settings_miniapp_ready($settings);

if ($active !== null) {
    $state = contest_status_view($active, $now);
    $stats = contest_stats($pdo, (int) $active['id']);
    $requireAccept = (int) ($active['require_accept'] ?? 0) === 1;
    echo '<section class="panel">';
    echo '<div class="panel-head"><h1>' . h($active['title']) . '</h1>';
    echo '<p class="status ' . h($state['class']) . '">' . h($state['label']) . '</p></div>';
    echo '<dl class="facts">';
    echo '<div><dt>Начало</dt><dd>' . h(view_datetime($active['starts_at'])) . '</dd></div>';
    echo '<div><dt>Окончание</dt><dd>' . h(view_datetime($active['ends_at'])) . '</dd></div>';
    echo '<div><dt>Участники</dt><dd>' . (int) $stats['users'] . '</dd></div>';
    echo '<div><dt>Сумма баллов</dt><dd>' . (int) $stats['points'] . '</dd></div>';
    if ($requireAccept) {
        echo '<div><dt>Приняли акцию</dt><dd>' . contest_accept_count($pdo, (int) $active['id']) . '</dd></div>';
    }
    echo '</dl>';
    if ($state['class'] === 'is-wait') {
        echo '<p class="hint">Начисление начнётся в указанное время. До этого события приходят, но баллы не ставятся.</p>';
    }
    if ($requireAccept) {
        $acceptUrl = contest_accept_url($settings);
        echo '<h2>Ссылка для записи</h2>';
        echo '<p class="lead">Вставьте её в пост конкурса. Пока человек не откроет ссылку внутри ВК, его лайки и комментарии в рейтинг не попадают.</p>';
        if ($acceptUrl !== '') {
            echo '<div class="copy-row">';
            echo '<input id="accept-url" readonly value="' . h($acceptUrl) . '">';
            echo '<button type="button" class="ghost" data-copy="accept-url">Скопировать</button>';
            echo '</div>';
        } else {
            echo '<p class="flash bad">Мини-приложение не настроено. <a href="index.php?page=connect">Открыть подключение</a></p>';
        }
    }
    echo '<div class="actions">';
    echo '<a class="button" href="index.php?page=rating&id=' . (int) $active['id'] . '">Открыть рейтинг</a>';
    echo '<form method="post" action="index.php?page=contest" data-confirm="Завершить конкурс? После этого новые действия не будут давать баллы.">';
    view_csrf();
    echo '<input type="hidden" name="action" value="finish">';
    echo '<button class="danger" type="submit">Завершить</button>';
    echo '</form></div></section>';
} else {
    echo '<section class="panel">';
    echo '<h1>Новый конкурс</h1>';
    echo '<p class="lead">Один активный конкурс. После завершения можно запустить следующий, старый рейтинг сохранится.</p>';
    if ($ready !== null) {
        echo '<p class="flash bad">' . h($ready) . ' <a href="index.php?page=connect">Перейти к подключению</a></p>';
    }
    $startDefault = (new DateTimeImmutable('now'))->format('Y-m-d\TH:00');
    $endDefault = (new DateTimeImmutable('now'))->modify('+30 days')->format('Y-m-d\TH:00');
    echo '<form method="post" action="index.php?page=contest">';
    view_csrf();
    echo '<input type="hidden" name="action" value="start">';
    echo '<label for="title">Название</label>';
    echo '<input id="title" name="title" maxlength="200" placeholder="Конкурс октября">';
    echo '<div class="grid-2">';
    echo '<div><label for="starts_at">Начало</label>';
    echo '<input id="starts_at" name="starts_at" type="datetime-local" required value="' . h($startDefault) . '"></div>';
    echo '<div><label for="ends_at">Окончание</label>';
    echo '<input id="ends_at" name="ends_at" type="datetime-local" required value="' . h($endDefault) . '"></div>';
    echo '</div>';
    echo '<p class="hint" id="date-hint">В минуту окончания начисление уже останавливается. Чтобы конкурс шёл до конца дня, поставьте 00:00 следующего дня.</p>';
    echo '<label class="check">';
    echo '<input type="checkbox" name="require_accept" value="1"' . ($miniappReady ? '' : ' disabled') . '>';
    echo '<span>Учитывать баллы только после перехода по ссылке</span>';
    echo '</label>';
    if ($miniappReady) {
        echo '<p class="hint">В запись нужно будет вставить ссылку мини-приложения. Пока человек её не откроет, лайки и комментарии в рейтинг не попадут.</p>';
    } else {
        echo '<p class="hint">Чтобы включить, сначала укажите ID и защищённый ключ мини-приложения на странице <a href="index.php?page=connect">Подключение</a>.</p>';
    }
    echo '<button type="submit"' . ($ready !== null ? ' disabled' : '') . '>Запустить</button>';
    echo '</form></section>';
}

echo '<section class="panel">';
echo '<h2>Что приносит баллы</h2>';
echo '<ul class="rules">';
echo '<li><strong>Вступление, ' . settings_points($settings, 'join') . '.</strong> Считается реальный вход: обычное вступление, принятое приглашение или одобренная заявка. Сама заявка и ответ «возможно, пойду» не считаются. Выход или исключение снимает эти баллы. Кто уже был в сообществе, балл за вход не получит: ВК не сообщает дату старой подписки.</li>';
echo '<li><strong>Лайк записи, ' . settings_points($settings, 'like') . '.</strong> Только отметка «Мне нравится» на записи стены сообщества. Лайк комментария, фото, видео или товара не считается. Снятие лайка забирает балл.</li>';
echo '<li><strong>Комментарий, ' . settings_points($settings, 'comment') . '.</strong> Комментарий или ответ под записью стены сообщества. Удаление снимает баллы, восстановление возвращает их. Правка текста счёт не меняет. Комментарий от имени сообщества не считается.</li>';
echo '<li><strong>Репост, ' . settings_points($settings, 'repost') . '.</strong> Репост записи стены этого сообщества считается один раз на человека за весь конкурс. Ещё один репост баллы не добавляет. Если репост удалить, баллы остаются.</li>';
echo '<li><strong>Принятие по ссылке.</strong> Если при запуске включена галочка, баллы появляются только после открытия ссылки мини-приложения из записи. Действия до перехода сохраняются и засчитываются в момент принятия; снятие лайка или удаление комментария до принятия отменяет их.</li>';
echo '</ul>';
echo '<p class="hint">Повтор одного и того же действия не удваивает баллы. Ноль в настройках выключает действие. Уже поставленные баллы при смене настроек не пересчитываются. <a href="index.php?page=connect">Изменить баллы</a></p>';
echo '</section>';

if ($history !== []) {
    echo '<section class="panel">';
    echo '<h2>Конкурсы</h2>';
    echo '<div class="table-wrap"><table><thead><tr><th>Название</th><th>Период</th><th>Состояние</th></tr></thead><tbody>';
    foreach ($history as $contest) {
        $state = contest_status_view($contest, $now);
        echo '<tr><td><a href="index.php?page=rating&id=' . (int) $contest['id'] . '">' . h($contest['title']) . '</a></td>';
        echo '<td>' . h(view_datetime($contest['starts_at'])) . ' – ' . h(view_datetime($contest['ends_at'])) . '</td>';
        echo '<td>' . h($state['label']) . '</td></tr>';
    }
    echo '</tbody></table></div></section>';
}

view_end();
