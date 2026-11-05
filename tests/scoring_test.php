<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/scoring.php';
require dirname(__DIR__) . '/lib/vk.php';

$failed = 0;

function check(bool $ok, string $name): void
{
    global $failed;
    if ($ok) {
        echo "ok  $name\n";
        return;
    }
    $failed++;
    echo "FAIL $name\n";
}

$join = scoring_decide('group_join', ['user_id' => 5, 'join_type' => 'join'], 10);
check($join['action'] === 'award' && $join['key'] === 'join:5', 'join counts');

$approved = scoring_decide('group_join', ['user_id' => 5, 'join_type' => 'approved'], 10);
check($approved['action'] === 'award', 'approved join counts');

$request = scoring_decide('group_join', ['user_id' => 5, 'join_type' => 'request'], 10);
check($request['action'] === 'ignore' && $request['reason'] === 'join_pending', 'request does not count');

$unsure = scoring_decide('group_join', ['user_id' => 5, 'join_type' => 'unsure'], 10);
check($unsure['reason'] === 'join_unsure', 'unsure does not count');

$leave = scoring_decide('group_leave', ['user_id' => 5, 'self' => 1], 10);
check($leave['action'] === 'revoke' && $leave['key'] === 'join:5', 'leave revokes join');

$like = scoring_decide('like_add', [
    'liker_id' => 7,
    'object_type' => 'post',
    'object_owner_id' => -10,
    'object_id' => 99,
], 10);
check($like['action'] === 'award' && $like['key'] === 'like:-10:99:7', 'post like counts');

$unlike = scoring_decide('like_remove', [
    'liker_id' => 7,
    'object_type' => 'post',
    'object_owner_id' => -10,
    'object_id' => 99,
], 10);
check($unlike['action'] === 'revoke' && $unlike['key'] === $like['key'], 'unlike revokes same like');

$photo = scoring_decide('like_add', [
    'liker_id' => 7,
    'object_type' => 'photo',
    'object_owner_id' => -10,
    'object_id' => 3,
], 10);
check($photo['reason'] === 'not_post', 'photo like ignored');

$other = scoring_decide('like_add', [
    'liker_id' => 7,
    'object_type' => 'post',
    'object_owner_id' => -11,
    'object_id' => 3,
], 10);
check($other['reason'] === 'not_group', 'other wall like ignored');

$comment = scoring_decide('wall_reply_new', [
    'id' => 50,
    'from_id' => 7,
    'post_id' => 99,
    'post_owner_id' => -10,
], 10);
check($comment['action'] === 'award' && $comment['key'] === 'comment:-10:50', 'wall comment counts');

$groupComment = scoring_decide('wall_reply_new', [
    'id' => 51,
    'from_id' => -10,
    'post_owner_id' => -10,
], 10);
check($groupComment['reason'] === 'community_author', 'community comment ignored');

$edit = scoring_decide('wall_reply_edit', ['id' => 50, 'from_id' => 7, 'post_owner_id' => -10], 10);
check($edit['reason'] === 'edit', 'edit does not add points');

$delete = scoring_decide('wall_reply_delete', ['owner_id' => -10, 'id' => 50, 'deleter_id' => 1, 'post_id' => 99], 10);
check($delete['action'] === 'revoke' && $delete['key'] === 'comment:-10:50', 'delete revokes comment');

$restore = scoring_decide('wall_reply_restore', ['id' => 50, 'from_id' => 7, 'post_owner_id' => -10], 10);
check($restore['action'] === 'award' && $restore['key'] === $delete['key'], 'restore uses same comment key');

$repost = scoring_decide('wall_repost', [
    'from_id' => 7,
    'id' => 1,
    'copy_history' => [
        ['id' => 40, 'owner_id' => -10],
    ],
], 10);
check(
    $repost['action'] === 'award'
        && $repost['type'] === 'repost'
        && $repost['key'] === 'repost:7'
        && $repost['user_id'] === 7,
    'repost of this community counts'
);

$repostAgain = scoring_decide('wall_repost', [
    'from_id' => 7,
    'id' => 2,
    'copy_history' => [
        ['id' => 41, 'owner_id' => -10],
    ],
], 10);
check($repostAgain['action'] === 'award' && $repostAgain['key'] === 'repost:7', 'second post keeps the same repost key');

$repostOther = scoring_decide('wall_repost', [
    'from_id' => 7,
    'id' => 3,
    'copy_history' => [
        ['id' => 1, 'owner_id' => -11],
    ],
], 10);
check($repostOther['action'] === 'ignore' && $repostOther['reason'] === 'not_group', 'other community repost ignored');

$repostCommunity = scoring_decide('wall_repost', [
    'from_id' => -10,
    'id' => 4,
    'copy_history' => [
        ['id' => 40, 'owner_id' => -10],
    ],
], 10);
check($repostCommunity['action'] === 'ignore' && $repostCommunity['reason'] === 'bad_payload', 'community author repost ignored');

$repostZero = scoring_decide('wall_repost', [
    'from_id' => 0,
    'id' => 5,
    'copy_history' => [
        ['id' => 40, 'owner_id' => -10],
    ],
], 10);
check($repostZero['action'] === 'ignore' && $repostZero['reason'] === 'bad_payload', 'zero from_id ignored');

$repostBare = scoring_decide('wall_repost', ['from_id' => 7, 'id' => 1], 10);
check($repostBare['action'] === 'ignore' && $repostBare['reason'] === 'bad_payload', 'missing copy_history ignored');

$fresh = scoring_transition(null, true, 2);
check($fresh['op'] === 'insert' && $fresh['result'] === 'awarded' && $fresh['points'] === 2, 'first award');

$held = scoring_transition(null, true, 2, 'held');
check($held['op'] === 'insert' && $held['result'] === 'held' && $held['status'] === 'held', 'first award held');

$again = scoring_transition(['status' => 'active', 'points' => 2], true, 9);
check($again['result'] === 'duplicate' && $again['points'] === 2, 'duplicate keeps old points');

$heldDup = scoring_transition(['status' => 'held', 'points' => 2], true, 9, 'held');
check($heldDup['result'] === 'duplicate', 'held duplicate');

$revoked = scoring_transition(['status' => 'active', 'points' => 2], false, 9);
check($revoked['op'] === 'update' && $revoked['result'] === 'revoked' && $revoked['points'] === 2, 'revoke keeps stored points');

$revokeHeld = scoring_transition(['status' => 'held', 'points' => 2], false, 9);
check($revokeHeld['result'] === 'revoked' && $revokeHeld['status'] === 'revoked', 'revoke held');

$back = scoring_transition(['status' => 'revoked', 'points' => 2], true, 4);
check($back['result'] === 'awarded' && $back['points'] === 4, 'reactivation uses current points');

$backHeld = scoring_transition(['status' => 'revoked', 'points' => 2], true, 4, 'held');
check($backHeld['result'] === 'held' && $backHeld['status'] === 'held', 'reactivation held');

$missing = scoring_transition(null, false, 1);
check($missing['result'] === 'noop', 'revoke without award is noop');

$vkParams = [
    'vk_user_id' => '494075',
    'vk_app_id' => '6736218',
    'vk_is_app_user' => '1',
    'vk_are_notifications_enabled' => '1',
    'vk_language' => 'ru',
    'vk_access_token_settings' => '',
    'vk_platform' => 'android',
    // актуальный пример из JS-дока ВК (PHP-фрагмент там со старой подписью)
    'sign' => 'htQFduJpLxz7ribXRZpDFUH-XEUhC9rBPTJkjUFEkRA',
];
$signOnly = [];
foreach ($vkParams as $name => $value) {
    if (strpos($name, 'vk_') === 0) {
        $signOnly[$name] = $value;
    }
}
ksort($signOnly);
$expectedSign = rtrim(
    strtr(
        base64_encode(hash_hmac('sha256', http_build_query($signOnly), 'wvl68m4dR1UpLrVRli', true)),
        '+/',
        '-_'
    ),
    '='
);
check($expectedSign === $vkParams['sign'], 'vk docs sign example');

$vkParams['vk_ts'] = (string) time();
$signOnly['vk_ts'] = $vkParams['vk_ts'];
ksort($signOnly);
$vkParams['sign'] = rtrim(
    strtr(
        base64_encode(hash_hmac('sha256', http_build_query($signOnly), 'wvl68m4dR1UpLrVRli', true)),
        '+/',
        '-_'
    ),
    '='
);
$vkParams['vk_group_id'] = '10';
$signOnly['vk_group_id'] = '10';
ksort($signOnly);
$vkParams['sign'] = rtrim(
    strtr(
        base64_encode(hash_hmac('sha256', http_build_query($signOnly), 'wvl68m4dR1UpLrVRli', true)),
        '+/',
        '-_'
    ),
    '='
);
$wrongGroup = vk_launch_verify($vkParams, 'wvl68m4dR1UpLrVRli', 6736218, 999);
check($wrongGroup['ok'] === false && ($wrongGroup['reason'] ?? '') === 'bad_group', 'launch needs matching group');

$okLaunch = vk_launch_verify($vkParams, 'wvl68m4dR1UpLrVRli', 6736218, 10);
check($okLaunch['ok'] === true && (int) $okLaunch['user_id'] === 494075, 'launch verify ok');

$bad = $vkParams;
$bad['vk_user_id'] = '1';
$badLaunch = vk_launch_verify($bad, 'wvl68m4dR1UpLrVRli', 6736218, 10);
check($badLaunch['ok'] === false && ($badLaunch['reason'] ?? '') === 'bad_sign', 'tampered user fails');

check(contest_window_open('active', '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-15 12:00:00') === 'open', 'window open');
check(contest_window_open('active', '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-10-31 23:00:00') === 'early', 'window early');
check(contest_window_open('active', '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-12-01 00:00:00') === 'closed', 'end minute is closed');
check(contest_window_open('finished', '2026-11-01 00:00:00', '2026-12-01 00:00:00', '2026-11-15 12:00:00') === 'closed', 'finished is closed');

$places = scoring_places([
    ['points' => 10],
    ['points' => 10],
    ['points' => 4],
]);
check($places[0]['place'] === 1 && $places[1]['place'] === 1 && $places[2]['place'] === 3, 'shared place then gap');

check(csv_cell('Анна') === '"Анна"', 'csv plain');
check(csv_cell('=1+1') === '"\'=1+1"', 'csv formula');
check(csv_cell('сказал "да"') === '"сказал ""да"""', 'csv quotes');
check(vk_parse_group('https://vk.com/club321') === '321', 'parse club url');
check(vk_parse_group('public15') === '15', 'parse public');
check(vk_parse_group('vk.com/my_group') === 'my_group', 'parse screen name');
check(vk_parse_group('https://example.com/club1') === '', 'reject foreign url');

$group = vk_group_from_response(['groups' => [['id' => 15, 'name' => 'Тест']]]);
check($group !== null && $group['id'] === 15 && $group['name'] === 'Тест', 'group response object');

$legacy = vk_group_from_response([['id' => 8, 'name' => 'Старый']]);
check($legacy !== null && $legacy['id'] === 8, 'legacy group list');

if ($failed > 0) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}

echo "all passed\n";
