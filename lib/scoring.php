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
        $wallOwner = (int) ($object['owner_id'] ?? 0);
        if ($wallOwner > 0) {
            $userId = $wallOwner;
        }
    }
    if ($userId <= 0) {
        return scoring_ignore('bad_payload');
    }
    if ($groupId <= 0) {
        return scoring_ignore('not_group');
    }

    $owners = scoring_repost_owners($object);
    if ($owners !== [] && !in_array(-$groupId, $owners, true)) {
        return scoring_ignore('not_group');
    }

    return [
        'action' => 'award',
        'type' => 'repost',
        'key' => 'repost:' . $userId,
        'user_id' => $userId,
    ];
}

function scoring_repost_owners(array $node, int $depth = 0): array
{
    if ($depth > 5) {
        return [];
    }

    $owners = [];
    $copied = (int) ($node['copy_owner_id'] ?? 0);
    if ($copied !== 0) {
        $owners[] = $copied;
    }

    $history = $node['copy_history'] ?? null;
    if (is_array($history)) {
        foreach (scoring_repost_items($history) as $item) {
            $owner = (int) ($item['owner_id'] ?? 0);
            if ($owner !== 0) {
                $owners[] = $owner;
            }
            $from = (int) ($item['from_id'] ?? 0);
            if ($from < 0) {
                $owners[] = $from;
            }
            foreach (scoring_repost_owners($item, $depth + 1) as $nested) {
                $owners[] = $nested;
            }
        }
    }

    $attachments = $node['attachments'] ?? null;
    if (is_array($attachments)) {
        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || ($attachment['type'] ?? '') !== 'wall') {
                continue;
            }
            $wall = $attachment['wall'] ?? null;
            if (!is_array($wall)) {
                continue;
            }
            $owner = (int) ($wall['owner_id'] ?? 0);
            if ($owner !== 0) {
                $owners[] = $owner;
            }
            $from = (int) ($wall['from_id'] ?? 0);
            if ($from < 0) {
                $owners[] = $from;
            }
        }
    }

    return $owners;
}

function scoring_repost_items(array $history): array
{
    if ($history === []) {
        return [];
    }
    if (array_key_exists('owner_id', $history) || array_key_exists('from_id', $history) || array_key_exists('id', $history)) {
        return [$history];
    }
    $items = [];
    foreach ($history as $item) {
        if (is_array($item)) {
            $items[] = $item;
        }
    }
    return $items;
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

function referral_id_from_launch(array $query, int $userId): int
{
    $candidates = [];
    if (isset($query['ref']) && is_scalar($query['ref'])) {
        $candidates[] = (string) $query['ref'];
    }
    if (isset($query['hash']) && is_scalar($query['hash'])) {
        $fromHash = referral_ref_in_fragment((string) $query['hash']);
        if ($fromHash !== '') {
            $candidates[] = $fromHash;
        }
    }
    if (isset($query['vk_ref']) && is_scalar($query['vk_ref'])) {
        $candidates[] = (string) $query['vk_ref'];
    }

    foreach ($candidates as $raw) {
        $id = referral_positive_id($raw, $userId);
        if ($id > 0) {
            return $id;
        }
    }
    return 0;
}

function referral_ref_in_fragment(string $fragment): string
{
    $fragment = rawurldecode($fragment);
    if ($fragment !== '' && ($fragment[0] === '#' || $fragment[0] === '?')) {
        $fragment = substr($fragment, 1);
    }
    if (preg_match('/^\d+$/', $fragment) === 1) {
        return $fragment;
    }
    if (preg_match('/(?:^|&)ref=(\d+)/', $fragment, $match) === 1) {
        return $match[1];
    }
    return '';
}

function referral_positive_id(string $raw, int $userId): int
{
    if (preg_match('/^\d+$/', $raw) !== 1) {
        return 0;
    }
    $parsed = (int) $raw;
    $canonical = ltrim($raw, '0');
    if ($parsed <= 0 || (string) $parsed !== $canonical || $parsed === $userId) {
        return 0;
    }
    return $parsed;
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

function scoring_rank_place(int $points, int $better): ?int
{
    if ($points <= 0) {
        return null;
    }
    if ($better < 0) {
        $better = 0;
    }
    return $better + 1;
}

function contest_score_lines(array $sums): array
{
    $order = [
        'join' => 'Вступление',
        'like' => 'Лайки',
        'comment' => 'Комментарии',
        'repost' => 'Репосты',
        'referral' => 'Приглашения',
        'referral_friend' => 'Бонус приглашённому',
    ];
    $lines = [];
    foreach ($order as $type => $label) {
        $points = (int) ($sums[$type] ?? 0);
        if ($points <= 0) {
            continue;
        }
        $lines[] = ['label' => $label, 'points' => $points];
    }
    return $lines;
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
