<?php

declare(strict_types=1);

/**
 * Требует MySQL и базу с именем на _test. Перед проверками очищает её таблицы.
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
app_boot(true);

$config = app_config();
$name = (string) ($config['db_name'] ?? '');
if (!str_ends_with($name, '_test')) {
    fwrite(STDERR, "Отказ: db_name должен заканчиваться на _test, сейчас «{$name}».\n");
    exit(1);
}

$pdo = db();
$failed = 0;
ob_start();

function check(bool $ok, string $label): void
{
    global $failed;
    if ($ok) {
        echo "ok  $label\n";
        return;
    }
    $failed++;
    echo "FAIL $label\n";
}

function reset_tables(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['actions', 'callback_events', 'callback_log', 'contests', 'participants', 'login_attempts', 'settings'] as $table) {
        $pdo->exec('TRUNCATE TABLE ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function post_event(PDO $pdo, array $event): string
{
    ob_start();
    callback_handle($pdo, $event, static function (int $userId): array {
        return ['first_name' => 'Имя' . $userId, 'last_name' => 'Фам' . $userId];
    });
    return (string) ob_get_clean();
}

function points_of(PDO $pdo, int $contestId, int $userId): int
{
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(points), 0) FROM actions
         WHERE contest_id = ? AND vk_user_id = ? AND status = 'active'"
    );
    $st->execute([$contestId, $userId]);
    return (int) $st->fetchColumn();
}

reset_tables($pdo);
settings_set_many($pdo, [
    'admin_password_hash' => password_hash('right-password', PASSWORD_DEFAULT),
    'vk_group_id' => '10',
    'vk_group_name' => 'Тестовая',
    'vk_access_token' => '12345678901234567890',
    'vk_secret' => 'secret-key',
    'vk_confirmation' => 'confirm-me',
    'points_join' => '5',
    'points_like' => '1',
    'points_comment' => '2',
]);

$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
$_SESSION = [];
check(auth_login($pdo, 'wrong-password', '203.0.113.8') === 'Неверный пароль.', 'bad password');
check(auth_login($pdo, 'right-password', '203.0.113.8') === null, 'good password');
check(auth_logged_in(), 'session started');

$blocked = contest_start($pdo, [
    'title' => 'Рано',
    'starts_at' => (new DateTimeImmutable('+10 days'))->format('Y-m-d\TH:00'),
    'ends_at' => (new DateTimeImmutable('+20 days'))->format('Y-m-d\TH:00'),
]);
check($blocked === null, 'future contest can be armed');
$armed = contest_find_active($pdo);
check($armed !== null, 'armed contest exists');

$early = post_event($pdo, [
    'type' => 'like_add',
    'event_id' => 'early-1',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['liker_id' => 7, 'object_type' => 'post', 'object_owner_id' => -10, 'object_id' => 3],
]);
check($early === 'ok', 'early event acknowledged');
check(points_of($pdo, (int) $armed['id'], 7) === 0, 'early like gives nothing');
check(contest_finish($pdo) === null, 'finish armed contest');

$start = (new DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i');
$end = (new DateTimeImmutable('+30 days'))->format('Y-m-d\TH:i');
check(contest_start($pdo, ['title' => 'Ноябрь', 'starts_at' => $start, 'ends_at' => $end]) === null, 'start open contest');
$contest = contest_find_active($pdo);
$contestId = (int) $contest['id'];

$confirm = post_event($pdo, ['type' => 'confirmation', 'group_id' => 10]);
check($confirm === 'confirm-me', 'confirmation code');

$foreign = post_event($pdo, ['type' => 'confirmation', 'group_id' => 99]);
check($foreign === 'not configured', 'foreign confirmation hidden');

$badSecret = post_event($pdo, [
    'type' => 'like_add',
    'event_id' => 'bad-secret',
    'group_id' => 10,
    'secret' => 'nope',
    'object' => ['liker_id' => 7, 'object_type' => 'post', 'object_owner_id' => -10, 'object_id' => 4],
]);
check($badSecret === 'ok' && points_of($pdo, $contestId, 7) === 0, 'wrong secret does not score');

$like = post_event($pdo, [
    'type' => 'like_add',
    'event_id' => 'like-1',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['liker_id' => 7, 'object_type' => 'post', 'object_owner_id' => -10, 'object_id' => 4],
]);
check($like === 'ok' && points_of($pdo, $contestId, 7) === 1, 'like awards 1');

$retry = post_event($pdo, [
    'type' => 'like_add',
    'event_id' => 'like-1',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['liker_id' => 7, 'object_type' => 'post', 'object_owner_id' => -10, 'object_id' => 4],
]);
check($retry === 'ok' && points_of($pdo, $contestId, 7) === 1, 'same event is not doubled');

post_event($pdo, [
    'type' => 'like_remove',
    'event_id' => 'like-1-off',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['liker_id' => 7, 'object_type' => 'post', 'object_owner_id' => -10, 'object_id' => 4],
]);
check(points_of($pdo, $contestId, 7) === 0, 'unlike removes the point');

post_event($pdo, [
    'type' => 'like_add',
    'event_id' => 'like-1-on',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['liker_id' => 7, 'object_type' => 'post', 'object_owner_id' => -10, 'object_id' => 4],
]);
check(points_of($pdo, $contestId, 7) === 1, 'like again restores the point');

post_event($pdo, [
    'type' => 'wall_reply_new',
    'event_id' => 'c-1',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['id' => 50, 'from_id' => 7, 'post_id' => 4, 'post_owner_id' => -10],
]);
check(points_of($pdo, $contestId, 7) === 3, 'comment adds 2');

post_event($pdo, [
    'type' => 'wall_reply_delete',
    'event_id' => 'c-1-del',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['owner_id' => -10, 'id' => 50, 'deleter_id' => 1, 'post_id' => 4],
]);
check(points_of($pdo, $contestId, 7) === 1, 'deleted comment removes 2');

post_event($pdo, [
    'type' => 'wall_reply_restore',
    'event_id' => 'c-1-back',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['id' => 50, 'from_id' => 7, 'post_owner_id' => -10],
]);
check(points_of($pdo, $contestId, 7) === 3, 'restored comment returns 2');

post_event($pdo, [
    'type' => 'group_join',
    'event_id' => 'j-req',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['user_id' => 7, 'join_type' => 'request'],
]);
check(points_of($pdo, $contestId, 7) === 3, 'join request gives nothing');

post_event($pdo, [
    'type' => 'group_join',
    'event_id' => 'j-1',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['user_id' => 7, 'join_type' => 'join'],
]);
check(points_of($pdo, $contestId, 7) === 8, 'join adds 5');

post_event($pdo, [
    'type' => 'group_leave',
    'event_id' => 'j-out',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['user_id' => 7, 'self' => 1],
]);
check(points_of($pdo, $contestId, 7) === 3, 'leave removes join points');

post_event($pdo, [
    'type' => 'wall_repost',
    'event_id' => 'repost-1',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['from_id' => 7, 'id' => 80, 'owner_id' => 7],
]);
check(points_of($pdo, $contestId, 7) === 3, 'repost gives nothing');

post_event($pdo, [
    'type' => 'like_add',
    'event_id' => 'other-user',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['liker_id' => 8, 'object_type' => 'post', 'object_owner_id' => -10, 'object_id' => 4],
]);
post_event($pdo, [
    'type' => 'like_add',
    'event_id' => 'other-user-2',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['liker_id' => 9, 'object_type' => 'comment', 'object_owner_id' => -10, 'object_id' => 50],
]);

$board = contest_leaderboard($pdo, $contestId);
check(count($board) === 2, 'two people on the board');
check((int) $board[0]['vk_user_id'] === 7 && (int) $board[0]['points'] === 3 && (int) $board[0]['place'] === 1, 'leader is user 7');
check((int) $board[1]['vk_user_id'] === 8 && (int) $board[1]['place'] === 2, 'second place is user 8');
check($board[0]['first_name'] === 'Имя7', 'name stored from profile');

check(contest_finish($pdo) === null, 'finish contest');
post_event($pdo, [
    'type' => 'like_add',
    'event_id' => 'after-finish',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['liker_id' => 7, 'object_type' => 'post', 'object_owner_id' => -10, 'object_id' => 70],
]);
check(points_of($pdo, $contestId, 7) === 3, 'finished contest does not accept new points');

$again = contest_start($pdo, ['title' => 'Второй', 'starts_at' => $start, 'ends_at' => $end]);
check($again === null, 'second contest starts after finish');
check((int) contest_find_active($pdo)['id'] !== $contestId, 'new contest has a new id');

ob_start();
callback_handle($pdo, [
    'type' => 'like_add',
    'event_id' => 'no-name',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['liker_id' => 11, 'object_type' => 'post', 'object_owner_id' => -10, 'object_id' => 5],
], static function (): null {
    return null;
});
$nameless = (string) ob_get_clean();
$activeId = (int) contest_find_active($pdo)['id'];
check($nameless === 'ok' && points_of($pdo, $activeId, 11) === 1, 'score stands when VK does not return a name');

for ($i = 0; $i < 8; $i++) {
    auth_login($pdo, 'nope', '203.0.113.9');
}
$locked = auth_login($pdo, 'right-password', '203.0.113.9');
check($locked === 'Слишком много попыток. Подождите 15 минут.', 'lockout blocks even the right password');

if ($failed > 0) {
    ob_end_flush();
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}

echo "all passed\n";
ob_end_flush();
