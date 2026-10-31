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
            return scoring_ignore('repost');
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

function scoring_transition(?array $row, bool $activate, int $points): array
{
    if ($activate) {
        if ($row === null) {
            return ['op' => 'insert', 'status' => 'active', 'points' => $points, 'result' => 'awarded'];
        }
        if (($row['status'] ?? '') === 'revoked') {
            return ['op' => 'update', 'status' => 'active', 'points' => $points, 'result' => 'awarded'];
        }
        return [
            'op' => 'none',
            'status' => 'active',
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
