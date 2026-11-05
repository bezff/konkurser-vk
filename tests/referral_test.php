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
    foreach ([
        'actions',
        'acceptances',
        'callback_events',
        'callback_log',
        'contests',
        'participants',
        'login_attempts',
        'settings',
        'referral_clicks',
        'referrals',
    ] as $table) {
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

function points_of_type(PDO $pdo, int $contestId, int $userId, string $type): int
{
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(points), 0) FROM actions
         WHERE contest_id = ? AND vk_user_id = ? AND action_type = ? AND status = 'active'"
    );
    $st->execute([$contestId, $userId, $type]);
    return (int) $st->fetchColumn();
}

function click_referrer(PDO $pdo, int $contestId, int $userId): int
{
    $st = $pdo->prepare(
        'SELECT referrer_vk_user_id FROM referral_clicks WHERE contest_id = ? AND vk_user_id = ?'
    );
    $st->execute([$contestId, $userId]);
    $value = $st->fetchColumn();
    return $value === false ? 0 : (int) $value;
}

function action_rows(PDO $pdo, int $contestId, string $type): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM actions WHERE contest_id = ? AND action_type = ?');
    $st->execute([$contestId, $type]);
    return (int) $st->fetchColumn();
}

check(
    contest_invite_url(['vk_app_id' => '15', 'vk_group_id' => '10'], 77) === 'https://vk.com/app15_-10?ref=77',
    'invite url uses app and group'
);
check(contest_invite_url(['vk_app_id' => '', 'vk_group_id' => '10'], 77) === '', 'invite url needs app id');
check(contest_invite_url(['vk_app_id' => '15', 'vk_group_id' => '0'], 77) === '', 'invite url needs group id');
check(contest_invite_url(['vk_app_id' => '15', 'vk_group_id' => '10'], 0) === '', 'invite url needs user');

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
    'points_referral' => '4',
    'points_referral_friend' => '3',
]);

$start = (new DateTimeImmutable('-1 hour'))->format('Y-m-d\TH:i');
$end = (new DateTimeImmutable('+30 days'))->format('Y-m-d\TH:i');
check(
    contest_start($pdo, ['title' => 'Рефералы', 'starts_at' => $start, 'ends_at' => $end]) === null,
    'start open contest'
);
$contest = contest_find_active($pdo);
$contestId = (int) $contest['id'];
$now = app_now();

referral_remember_click($pdo, $contestId, 200, 100, $now);
check(click_referrer($pdo, $contestId, 200) === 100, 'click stores inviter');

$join = post_event($pdo, [
    'type' => 'group_join',
    'event_id' => 'ref-join-1',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['user_id' => 200, 'join_type' => 'join'],
]);
check($join === 'ok', 'referred join acknowledged');
check(points_of($pdo, $contestId, 200) === 8, 'friend gets join 5 plus bonus 3');
check(points_of_type($pdo, $contestId, 200, 'join') === 5, 'friend join points are 5');
check(points_of_type($pdo, $contestId, 200, 'referral_friend') === 3, 'friend bonus is 3');
check(points_of($pdo, $contestId, 100) === 4, 'inviter gets 4');
check(points_of_type($pdo, $contestId, 100, 'referral') === 4, 'inviter points are referral');
check(contest_referral_count($pdo, $contestId) === 1, 'one referrals row');

$again = post_event($pdo, [
    'type' => 'group_join',
    'event_id' => 'ref-join-2',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['user_id' => 200, 'join_type' => 'join'],
]);
check($again === 'ok', 'second join acknowledged');
check(points_of($pdo, $contestId, 200) === 8, 'second join is not a second bonus');
check(points_of($pdo, $contestId, 100) === 4, 'second join does not pay inviter again');
check(contest_referral_count($pdo, $contestId) === 1, 'second join does not add a referral row');
check(action_rows($pdo, $contestId, 'referral') === 1, 'one inviter action');
check(action_rows($pdo, $contestId, 'referral_friend') === 1, 'one friend bonus action');

post_event($pdo, [
    'type' => 'group_leave',
    'event_id' => 'ref-leave-1',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['user_id' => 200, 'self' => 1],
]);
check(points_of($pdo, $contestId, 200) === 3, 'leave removes join points and keeps the bonus');
check(points_of_type($pdo, $contestId, 200, 'referral_friend') === 3, 'leave does not revoke friend bonus');
check(points_of($pdo, $contestId, 100) === 4, 'leave does not revoke inviter points');
check(contest_referral_count($pdo, $contestId) === 1, 'leave keeps the referrals row');

post_event($pdo, [
    'type' => 'group_join',
    'event_id' => 'ref-rejoin-1',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['user_id' => 200, 'join_type' => 'join'],
]);
check(points_of($pdo, $contestId, 200) === 8, 'rejoin restores join plus the original bonus');
check(points_of_type($pdo, $contestId, 200, 'join') === 5, 'rejoin awards join again');
check(points_of_type($pdo, $contestId, 200, 'referral_friend') === 3, 'rejoin does not add friend bonus');
check(points_of($pdo, $contestId, 100) === 4, 'rejoin does not add inviter points');
check(contest_referral_count($pdo, $contestId) === 1, 'rejoin stays one referral');
check(action_rows($pdo, $contestId, 'referral_friend') === 1, 'rejoin does not insert another friend bonus');

referral_remember_click($pdo, $contestId, 300, 300, $now);
referral_remember_click($pdo, $contestId, 300, 0, $now);
check(click_referrer($pdo, $contestId, 300) === 0, 'self ref stores nothing');

referral_remember_click($pdo, $contestId, 400, 101, $now);
referral_remember_click($pdo, $contestId, 400, 100, $now);
check(click_referrer($pdo, $contestId, 400) === 100, 'last valid click wins');
check(contest_referral_count($pdo, $contestId) === 1, 'click without join creates no referrals row');
check(points_of($pdo, $contestId, 400) === 0, 'click without join adds no points');
check(points_of($pdo, $contestId, 100) === 4, 'click without join does not pay the inviter');

post_event($pdo, [
    'type' => 'group_join',
    'event_id' => 'plain-join',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['user_id' => 500, 'join_type' => 'join'],
]);
check(points_of($pdo, $contestId, 500) === 5, 'join without click pays only join points');
check(points_of_type($pdo, $contestId, 500, 'referral_friend') === 0, 'join without click has no friend bonus');
check(contest_referral_count($pdo, $contestId) === 1, 'join without click adds no referrals row');

check(contest_finish($pdo) === null, 'finish open contest');

settings_set_many($pdo, [
    'vk_app_id' => '6736218',
    'vk_app_secret' => 'wvl68m4dR1UpLrVRli',
]);
check(
    contest_start($pdo, [
        'title' => 'С принятием',
        'starts_at' => $start,
        'ends_at' => $end,
        'require_accept' => '1',
    ]) === null,
    'start contest with accept'
);
$acceptContest = contest_find_active($pdo);
$acceptId = (int) $acceptContest['id'];
check((int) $acceptContest['require_accept'] === 1, 'require_accept stored');

referral_remember_click($pdo, $acceptId, 600, 700, app_now());
post_event($pdo, [
    'type' => 'group_join',
    'event_id' => 'held-join',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['user_id' => 600, 'join_type' => 'join'],
]);
check(points_of($pdo, $acceptId, 600) === 0, 'join before accept gives 0 active points');
check(points_of($pdo, $acceptId, 700) === 0, 'held join does not pay the inviter');
check(contest_referral_count($pdo, $acceptId) === 0, 'held join does not create a referral');
$heldJoin = $pdo->prepare(
    "SELECT status FROM actions WHERE contest_id = ? AND action_key = 'join:600'"
);
$heldJoin->execute([$acceptId]);
check((string) $heldJoin->fetchColumn() === 'held', 'join stored as held');

$accepted = contest_accept($pdo, $acceptId, 600, app_now(), ['first_name' => 'Анна', 'last_name' => 'Тест']);
check($accepted['code'] === 'accepted', 'accept records user');
check(points_of($pdo, $acceptId, 600) === 8, 'accept releases join and pays the friend bonus');
check(points_of_type($pdo, $acceptId, 600, 'join') === 5, 'released join is 5');
check(points_of_type($pdo, $acceptId, 600, 'referral_friend') === 3, 'accept pays friend bonus 3');
check(points_of($pdo, $acceptId, 700) === 4, 'accept pays the inviter');
check(contest_referral_count($pdo, $acceptId) === 1, 'accept creates one referral');

referral_remember_click($pdo, $acceptId, 800, 701, app_now());
post_event($pdo, [
    'type' => 'like_add',
    'event_id' => 'held-like',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['liker_id' => 800, 'object_type' => 'post', 'object_owner_id' => -10, 'object_id' => 40],
]);
$likeAccept = contest_accept($pdo, $acceptId, 800, app_now(), ['first_name' => 'Борис', 'last_name' => 'Лайк']);
check($likeAccept['code'] === 'accepted', 'like-only user can accept');
check(points_of($pdo, $acceptId, 800) === 1, 'likes-only accept does not pay a referral');
check(points_of($pdo, $acceptId, 701) === 0, 'likes-only accept does not pay the inviter');
check(contest_referral_count($pdo, $acceptId) === 1, 'likes-only accept adds no referral row');

check(contest_finish($pdo) === null, 'finish accept contest');

settings_set_many($pdo, [
    'points_referral' => '0',
    'points_referral_friend' => '0',
]);
check(
    contest_start($pdo, ['title' => 'Нулевые бонусы', 'starts_at' => $start, 'ends_at' => $end]) === null,
    'start contest with zero referral points'
);
$zeroId = (int) contest_find_active($pdo)['id'];
referral_remember_click($pdo, $zeroId, 900, 901, app_now());
post_event($pdo, [
    'type' => 'group_join',
    'event_id' => 'zero-join',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['user_id' => 900, 'join_type' => 'join'],
]);
check(points_of($pdo, $zeroId, 900) === 5, 'zero bonus still awards join');
check(points_of($pdo, $zeroId, 901) === 0, 'zero inviter points are not awarded');
check(points_of_type($pdo, $zeroId, 900, 'referral_friend') === 0, 'zero friend bonus is not awarded');
check(contest_referral_count($pdo, $zeroId) === 1, 'zero points still keep the referrals row');

settings_set_many($pdo, [
    'points_referral' => '4',
    'points_referral_friend' => '3',
]);
post_event($pdo, [
    'type' => 'group_leave',
    'event_id' => 'zero-leave',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['user_id' => 900, 'self' => 1],
]);
post_event($pdo, [
    'type' => 'group_join',
    'event_id' => 'zero-rejoin',
    'group_id' => 10,
    'secret' => 'secret-key',
    'object' => ['user_id' => 900, 'join_type' => 'join'],
]);
check(points_of($pdo, $zeroId, 900) === 5, 'later settings do not pay the friend on rejoin');
check(points_of($pdo, $zeroId, 901) === 0, 'later settings do not pay the inviter on rejoin');
check(contest_referral_count($pdo, $zeroId) === 1, 'rejoin after a zero credit stays one row');

if ($failed > 0) {
    ob_end_flush();
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}

echo "all passed\n";
ob_end_flush();
