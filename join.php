<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
app_boot(false);

/**
 * Ответы мини-приложения всегда с кодом 200: клиент ВК часто
 * считает 403/503 «страница не загрузилась» и крутит бесконечный лоадер.
 */
function join_render(
    string $title,
    string $groupName,
    string $state,
    string $message,
    bool $needLaunchParams = false,
    string $inviteUrl = ''
): void {
    view_start($title, null, $groupName, true, $needLaunchParams);
    echo '<section class="panel join-panel">';
    echo '<h1>' . h($title) . '</h1>';
    echo '<p class="flash ' . h($state) . '" id="join-status" role="status">' . h($message) . '</p>';
    if ($inviteUrl !== '') {
        echo '<p class="lead">Отправьте ссылку другу. Когда он вступит в сообщество, баллы получите вы оба.</p>';
        echo '<div class="copy-row">';
        echo '<input id="invite-url" readonly value="' . h($inviteUrl) . '">';
        echo '<button type="button" class="ghost" data-copy="invite-url">Скопировать</button>';
        echo '</div>';
    }
    echo '</section>';
    view_end(true);
}

try {
    $pdo = db();
} catch (Throwable $e) {
    app_log($e->getMessage());
    join_render('Принятие акции', '', 'bad', 'Сервис временно недоступен. Попробуйте позже.');
    exit;
}

$settings = settings_all($pdo);
$groupName = (string) ($settings['vk_group_name'] ?? '');

if (!settings_miniapp_ready($settings)) {
    join_render('Принятие акции', $groupName, 'bad', 'Мини-приложение ещё не настроено в админке конкурса.');
    exit;
}

$hasSign = isset($_GET['sign']) && is_string($_GET['sign']) && $_GET['sign'] !== '';
if (!$hasSign) {
    join_render(
        'Принятие акции',
        $groupName,
        'ok',
        'Открываем… Если окно не обновится, откройте ссылку из записи сообщества внутри ВК.',
        true
    );
    exit;
}

$verified = vk_launch_verify(
    $_GET,
    (string) ($settings['vk_app_secret'] ?? ''),
    (int) ($settings['vk_app_id'] ?? 0),
    (int) ($settings['vk_group_id'] ?? 0)
);

if (!$verified['ok']) {
    $messages = [
        'bad_sign' => 'Не удалось проверить запуск. Откройте ссылку из записи сообщества внутри ВК.',
        'bad_app' => 'Это другое приложение. Откройте ссылку из записи конкурса.',
        'bad_group' => 'Откройте ссылку вида vk.com/app…_-… из записи сообщества, не из каталога приложений.',
        'bad_user' => 'Не удалось определить профиль ВК.',
        'stale' => 'Ссылка устарела. Закройте окно и откройте ссылку из записи снова.',
        'not_configured' => 'Мини-приложение ещё не настроено.',
    ];
    $reason = (string) ($verified['reason'] ?? '');
    join_render(
        'Принятие акции',
        $groupName,
        'bad',
        $messages[$reason] ?? 'Не удалось принять акцию.'
    );
    exit;
}

$userId = (int) $verified['user_id'];
contest_close_expired($pdo);
$contest = contest_find_active($pdo);
$now = app_now();

if ($contest === null) {
    join_render('Принятие акции', $groupName, 'bad', 'Сейчас нет активного конкурса.');
    exit;
}

$contestId = (int) $contest['id'];
$contestOpen = $now < $contest['ends_at'];
if ($contestOpen) {
    $referrerId = 0;
    if (isset($_GET['ref']) && is_string($_GET['ref']) && preg_match('/^\d+$/', $_GET['ref'])) {
        $parsedRef = (int) $_GET['ref'];
        $canonicalRef = ltrim($_GET['ref'], '0');
        if ($parsedRef > 0 && (string) $parsedRef === $canonicalRef && $parsedRef !== $userId) {
            $referrerId = $parsedRef;
        }
    }
    if ($referrerId > 0) {
        try {
            referral_remember_click($pdo, $contestId, $userId, $referrerId, $now);
        } catch (Throwable $e) {
            app_log('referral click: ' . $e->getMessage());
        }
    }
}

$inviteUrl = $contestOpen ? contest_invite_url($settings, $userId) : '';

if ((int) ($contest['require_accept'] ?? 0) !== 1) {
    join_render(
        'Принятие акции',
        $groupName,
        'ok',
        'В этом конкурсе переход по ссылке не нужен. Лайки и комментарии уже учитываются.',
        false,
        $inviteUrl
    );
    exit;
}

if (!$contestOpen) {
    join_render('Принятие акции', $groupName, 'bad', 'Конкурс уже закончился.');
    exit;
}

$profile = vk_user_profile((string) ($settings['vk_access_token'] ?? ''), $userId);

try {
    $result = contest_accept($pdo, $contestId, $userId, $now, $profile);
} catch (Throwable $e) {
    app_log('accept: ' . $e->getMessage());
    join_render('Принятие акции', $groupName, 'bad', 'Не удалось сохранить принятие. Попробуйте ещё раз.');
    exit;
}

if ($result['code'] === 'already') {
    join_render(
        'Акция принята',
        $groupName,
        'ok',
        'Вы уже принимали участие. Лайки, комментарии и вступление учитываются в рейтинге.',
        false,
        $inviteUrl
    );
    exit;
}

join_render(
    'Акция принята',
    $groupName,
    'ok',
    'Готово. Теперь ваши лайки, комментарии и вступление в сообщество учитываются в конкурсе «'
    . $contest['title']
    . '».',
    false,
    $inviteUrl
);
