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

$repost = scoring_decide('wall_repost', ['from_id' => 7, 'id' => 1], 10);
check($repost['reason'] === 'repost', 'repost ignored');

$fresh = scoring_transition(null, true, 2);
check($fresh['op'] === 'insert' && $fresh['result'] === 'awarded' && $fresh['points'] === 2, 'first award');

$again = scoring_transition(['status' => 'active', 'points' => 2], true, 9);
check($again['result'] === 'duplicate' && $again['points'] === 2, 'duplicate keeps old points');

$revoked = scoring_transition(['status' => 'active', 'points' => 2], false, 9);
check($revoked['op'] === 'update' && $revoked['result'] === 'revoked' && $revoked['points'] === 2, 'revoke keeps stored points');

$back = scoring_transition(['status' => 'revoked', 'points' => 2], true, 4);
check($back['result'] === 'awarded' && $back['points'] === 4, 'reactivation uses current points');

$missing = scoring_transition(null, false, 1);
check($missing['result'] === 'noop', 'revoke without award is noop');

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
