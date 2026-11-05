<?php

declare(strict_types=1);

function scoring_decide(string $type, array $object, int $groupId): array
{
    switch ($type) {
        case 'group_join':
            return scoring_join($object);
        case 'group_leave':
            return scoring_leave($object);
        case 'like_add':
        case 'like_remove':
            $parsed = scoring_like($object, $groupId);
            if ($parsed['action'] === 'ignore') {
                return $parsed;
            }
            $parsed['action'] = $type === 'like_add' ? 'award' : 'revoke';
            return $parsed;
        case 'wall_reply_new':
        case 'wall_reply_restore':
            $parsed = scoring_comment($object, $groupId);
            if ($parsed['action'] === 'ignore') {
                return $parsed;
            }
            $parsed['action'] = 'award';
            return $parsed;
        case 'wall_reply_delete':
            return scoring_comment_delete($object, $groupId);
        case 'wall_reply_edit':
            return scoring_ignore('edit');
        case 'wall_repost':
            return scoring_repost($object, $groupId);
        default:
            return scoring_ignore('unsupported');
    }
}

function scoring_ignore(string $reason): array
{
    return ['action' => 'ignore', 'reason' => $reason];
}

function scoring_join(array $object): array
{
    $userId = (int) ($object['user_id'] ?? 0);
    $joinType = (string) ($object['join_type'] ?? '');
    if ($userId <= 0) {
        return scoring_ignore('bad_payload');
    }
    if ($joinType === 'request') {
        return scoring_ignore('join_pending');
    }
    if ($joinType === 'unsure') {
        return scoring_ignore('join_unsure');
    }
    if (!in_array($joinType, ['join', 'accepted', 'approved'], true)) {
        return scoring_ignore('unsupported');
    }
    return [
        'action' => 'award',
        'type' => 'join',
        'key' => 'join:' . $userId,
        'user_id' => $userId,
    ];
}

function scoring_leave(array $object): array
{
    $userId = (int) ($object['user_id'] ?? 0);
    if ($userId <= 0) {
        return scoring_ignore('bad_payload');
    }
    return [
        'action' => 'revoke',
        'type' => 'join',
        'key' => 'join:' . $userId,
        'user_id' => $userId,
    ];
}

function scoring_like(array $object, int $groupId): array
{
    $objectType = (string) ($object['object_type'] ?? '');
    $owner = (int) ($object['object_owner_id'] ?? 0);
    $objectId = (int) ($object['object_id'] ?? 0);
    $userId = (int) ($object['liker_id'] ?? 0);
    if ($userId <= 0 || $objectId <= 0 || $owner === 0) {
        return scoring_ignore('bad_payload');
    }
    if ($objectType !== 'post') {
        return scoring_ignore('not_post');
    }
    if ($groupId <= 0 || $owner !== -$groupId) {
        return scoring_ignore('not_group');
    }
    return [
        'action' => 'award',
        'type' => 'like',
        'key' => 'like:' . $owner . ':' . $objectId . ':' . $userId,
        'user_id' => $userId,
    ];
}

function scoring_comment(array $object, int $groupId): array
{
    $userId = (int) ($object['from_id'] ?? 0);
    $commentId = (int) ($object['id'] ?? 0);
    $postOwner = (int) ($object['post_owner_id'] ?? 0);
    if ($commentId <= 0 || $postOwner === 0) {
        return scoring_ignore('bad_payload');
    }
    if ($groupId <= 0 || $postOwner !== -$groupId) {
        return scoring_ignore('not_group');
    }
    if ($userId < 0) {
        return scoring_ignore('community_author');
    }
    if ($userId === 0) {
        return scoring_ignore('bad_payload');
    }
    return [
        'action' => 'award',
        'type' => 'comment',
        'key' => 'comment:' . $postOwner . ':' . $commentId,
        'user_id' => $userId,
    ];
}

function scoring_repost(array $object, int $groupId): array
{
    $userId = (int) ($object['from_id'] ?? 0);
    if ($userId <= 0) {
        return scoring_ignore('bad_payload');
    }

    $history = $object['copy_history'] ?? null;
    if (!is_array($history) || $history === []) {
        return scoring_ignore('bad_payload');
    }
    if (!array_key_exists(0, $history) || !is_array($history[0])) {
        return scoring_ignore('bad_payload');
    }
    $original = $history[0];

    $owner = (int) ($original['owner_id'] ?? 0);
    if ($groupId <= 0 || $owner !== -$groupId) {
        return scoring_ignore('not_group');
    }

    return [
        'action' => 'award',
        'type' => 'repost',
        'key' => 'repost:' . $userId,
        'user_id' => $userId,
    ];
}

function scoring_comment_delete(array $object, int $groupId): array
{
    $owner = (int) ($object['owner_id'] ?? 0);
    $commentId = (int) ($object['id'] ?? 0);
    if ($commentId <= 0 || $owner === 0) {
        return scoring_ignore('bad_payload');
    }
    if ($groupId <= 0 || $owner !== -$groupId) {
        return scoring_ignore('not_group');
    }
    return [
        'action' => 'revoke',
        'type' => 'comment',
        'key' => 'comment:' . $owner . ':' . $commentId,
        'user_id' => 0,
    ];
}

function scoring_transition(?array $row, bool $activate, int $points, string $awardStatus = 'active'): array
{
    if ($awardStatus !== 'held' && $awardStatus !== 'active') {
        $awardStatus = 'active';
    }

    if ($activate) {
        if ($row === null) {
            return [
                'op' => 'insert',
                'status' => $awardStatus,
                'points' => $points,
                'result' => $awardStatus === 'held' ? 'held' : 'awarded',
            ];
        }
        if (($row['status'] ?? '') === 'revoked') {
            return [
                'op' => 'update',
                'status' => $awardStatus,
                'points' => $points,
                'result' => $awardStatus === 'held' ? 'held' : 'awarded',
            ];
        }
        return [
            'op' => 'none',
            'status' => (string) ($row['status'] ?? 'active'),
            'points' => (int) ($row['points'] ?? $points),
            'result' => 'duplicate',
        ];
    }

    if ($row === null || ($row['status'] ?? '') === 'revoked') {
        return [
            'op' => 'none',
            'status' => 'revoked',
            'points' => (int) ($row['points'] ?? 0),
            'result' => 'noop',
        ];
    }

    return [
        'op' => 'update',
        'status' => 'revoked',
        'points' => (int) $row['points'],
        'result' => 'revoked',
    ];
}

/**
 * Проверка подписи параметров запуска мини-приложения ВК.
 * Алгоритм: https://dev.vk.com/ru/mini-apps/development/launch-params-sign
 *
 * @return array{ok:bool,reason?:string,user_id?:int,app_id?:int,group_id?:int,ts?:int}
 */
function vk_launch_verify(array $params, string $appSecret, int $expectedAppId, int $expectedGroupId): array
{
    if ($appSecret === '' || $expectedAppId <= 0 || $expectedGroupId <= 0) {
        return ['ok' => false, 'reason' => 'not_configured'];
    }

    $sign = (string) ($params['sign'] ?? '');
    if ($sign === '') {
        return ['ok' => false, 'reason' => 'bad_sign'];
    }

    $signParams = [];
    foreach ($params as $name => $value) {
        if (!is_string($name) || strpos($name, 'vk_') !== 0) {
            continue;
        }
        if (!is_scalar($value)) {
            return ['ok' => false, 'reason' => 'bad_sign'];
        }
        $signParams[$name] = (string) $value;
    }

    ksort($signParams);
    $query = http_build_query($signParams);
    $computed = rtrim(
        strtr(base64_encode(hash_hmac('sha256', $query, $appSecret, true)), '+/', '-_'),
        '='
    );
    if (!hash_equals($computed, $sign)) {
        return ['ok' => false, 'reason' => 'bad_sign'];
    }

    $userId = (int) ($signParams['vk_user_id'] ?? 0);
    $appId = (int) ($signParams['vk_app_id'] ?? 0);
    $groupId = (int) ($signParams['vk_group_id'] ?? 0);
    $ts = (int) ($signParams['vk_ts'] ?? 0);

    if ($userId <= 0) {
        return ['ok' => false, 'reason' => 'bad_user'];
    }
    if ($appId !== $expectedAppId) {
        return ['ok' => false, 'reason' => 'bad_app'];
    }
    if ($groupId !== $expectedGroupId) {
        return ['ok' => false, 'reason' => 'bad_group'];
    }
    if ($ts <= 0 || abs(time() - $ts) > 86400) {
        return ['ok' => false, 'reason' => 'stale'];
    }

    return [
        'ok' => true,
        'user_id' => $userId,
        'app_id' => $appId,
        'group_id' => $groupId,
        'ts' => $ts,
    ];
}

function contest_window_open(string $status, string $startsAt, string $endsAt, string $now): string
{
    if ($status !== 'active') {
        return 'closed';
    }
    if ($now < $startsAt) {
        return 'early';
    }
    if ($now >= $endsAt) {
        return 'closed';
    }
    return 'open';
}

function scoring_places(array $rows): array
{
    $place = 0;
    $seen = 0;
    $lastPoints = null;
    foreach ($rows as $index => $row) {
        $seen++;
        $points = (int) $row['points'];
        if ($lastPoints === null || $points !== $lastPoints) {
            $place = $seen;
            $lastPoints = $points;
        }
        $rows[$index]['place'] = $place;
    }
    return $rows;
}

function csv_cell(string $value): string
{
    $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
    $probe = ltrim($value, " \t");
    if ($probe !== '' && preg_match('/^[=+\-@]/', $probe) === 1) {
        $value = "'" . $value;
    }
    return '"' . str_replace('"', '""', $value) . '"';
}

function csv_line(array $cells): string
{
    $encoded = [];
    foreach ($cells as $cell) {
        $encoded[] = csv_cell((string) $cell);
    }
    return implode(';', $encoded);
}
