<?php

declare(strict_types=1);

function callback_parse_body(string $raw): array
{
    if (strlen($raw) > 1000000) {
        throw new InvalidArgumentException('large');
    }
    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('json');
    }
    if (!is_array($data)) {
        throw new InvalidArgumentException('json');
    }
    return $data;
}

function callback_handle(PDO $pdo, array $data, ?callable $profiles = null): void
{
    header('Content-Type: text/plain; charset=UTF-8');

    $type = substr((string) ($data['type'] ?? ''), 0, 40);
    $groupId = (int) ($data['group_id'] ?? 0);
    $eventId = callback_event_id($data['event_id'] ?? '');
    $settings = settings_all($pdo);
    $configuredGroup = (int) ($settings['vk_group_id'] ?? 0);

    if ($type === 'confirmation') {
        $code = (string) ($settings['vk_confirmation'] ?? '');
        if ($configuredGroup > 0 && $groupId === $configuredGroup && $code !== '') {
            echo $code;
            return;
        }
        http_response_code(404);
        echo 'not configured';
        return;
    }

    $secret = (string) ($settings['vk_secret'] ?? '');
    $given = (string) ($data['secret'] ?? '');
    if ($secret === '' || !hash_equals($secret, $given)) {
        callback_log($pdo, $type !== '' ? $type : 'unknown', 'secret', '');
        echo 'ok';
        return;
    }

    if ($configuredGroup <= 0 || $groupId !== $configuredGroup) {
        callback_log($pdo, $type, 'group', '');
        echo 'ok';
        return;
    }

    $object = $data['object'] ?? [];
    if (!is_array($object)) {
        $object = [];
    }

    try {
        $result = callback_process($pdo, $settings, $type, $object, $eventId, $configuredGroup, $profiles);
        callback_log($pdo, $type, $result['code'], $result['detail']);
        echo 'ok';
    } catch (Throwable $e) {
        app_log('callback: ' . $e->getMessage());
        callback_log($pdo, $type, 'error', '');
        http_response_code(500);
        echo 'error';
    }
}

function callback_process(
    PDO $pdo,
    array $settings,
    string $type,
    array $object,
    string $eventId,
    int $groupId,
    ?callable $profiles = null
): array {
    $decision = scoring_decide($type, $object, $groupId);
    if ($decision['action'] === 'ignore') {
        return ['code' => 'ignored', 'detail' => (string) $decision['reason']];
    }

    contest_close_expired($pdo);
    $contest = contest_find_active($pdo);
    $now = app_now();
    if ($contest === null) {
        return ['code' => 'closed', 'detail' => ''];
    }
    $window = contest_window_open($contest['status'], $contest['starts_at'], $contest['ends_at'], $now);
    if ($window !== 'open') {
        return ['code' => $window === 'early' ? 'early' : 'closed', 'detail' => ''];
    }

    $points = settings_points($settings, (string) $decision['type']);
    if ($decision['action'] === 'award' && $points <= 0) {
        return ['code' => 'disabled', 'detail' => (string) $decision['type']];
    }

    if ($eventId !== '' && callback_event_exists($pdo, $eventId)) {
        return ['code' => 'duplicate', 'detail' => ''];
    }

    $profile = null;
    $userId = (int) ($decision['user_id'] ?? 0);
    if ($decision['action'] === 'award' && $userId > 0) {
        if ($profiles !== null) {
            $profile = $profiles($userId);
        } else {
            $profile = vk_user_profile((string) ($settings['vk_access_token'] ?? ''), $userId);
        }
    }

    $contestId = (int) $contest['id'];
    $requireAccept = (int) ($contest['require_accept'] ?? 0) === 1;

    $run = function (PDO $pdo) use (
        $contestId,
        $decision,
        $points,
        $now,
        $profile,
        $eventId,
        $type,
        $requireAccept,
        $userId
    ): array {
        if ($eventId !== '') {
            if (!callback_remember($pdo, $eventId, $type, $now)) {
                return ['code' => 'duplicate', 'detail' => ''];
            }
        }

        $awardStatus = 'active';
        if ($decision['action'] === 'award' && $requireAccept) {
            $awardUser = (int) ($decision['user_id'] ?? 0);
            if ($awardUser > 0 && !contest_has_accepted($pdo, $contestId, $awardUser)) {
                $awardStatus = 'held';
            }
        }

        $code = scoring_apply($pdo, $contestId, $decision, $points, $now, $profile, $awardStatus);
        $detail = '';
        if (
            ($code === 'awarded' || $code === 'revoked' || $code === 'held')
            && (int) ($decision['user_id'] ?? 0) > 0
        ) {
            $detail = (string) (int) $decision['user_id'];
        }
        return ['code' => $code, 'detail' => $detail];
    };

    if ($userId > 0) {
        $lock = contest_user_lock_name($contestId, $userId);
        db_lock($pdo, $lock);
        try {
            return db_transaction($pdo, $run);
        } finally {
            db_unlock($pdo, $lock);
        }
    }

    return db_transaction($pdo, $run);
}

function callback_event_id(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $eventId = trim((string) $value);
    if ($eventId === '') {
        return '';
    }
    if (strlen($eventId) > 80) {
        return hash('sha256', $eventId);
    }
    return $eventId;
}

function callback_event_exists(PDO $pdo, string $eventId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM callback_events WHERE event_id = ?');
    $st->execute([$eventId]);
    return (bool) $st->fetchColumn();
}

function callback_remember(PDO $pdo, string $eventId, string $type, string $now): bool
{
    try {
        $st = $pdo->prepare(
            'INSERT INTO callback_events (event_id, event_type, received_at) VALUES (?, ?, ?)'
        );
        $st->execute([$eventId, substr($type, 0, 40), $now]);
        return true;
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            return false;
        }
        throw $e;
    }
}

function callback_log(PDO $pdo, string $type, string $result, string $detail): void
{
    try {
        $st = $pdo->prepare(
            'INSERT INTO callback_log (event_type, result, detail, created_at) VALUES (?, ?, ?, ?)'
        );
        $st->execute([
            substr($type !== '' ? $type : 'unknown', 0, 40),
            substr($result, 0, 32),
            substr($detail, 0, 255),
            app_now(),
        ]);
        if (random_int(1, 20) === 1) {
            callback_cleanup($pdo);
        }
    } catch (Throwable $e) {
        app_log('callback log: ' . $e->getMessage());
    }
}

function callback_cleanup(PDO $pdo): void
{
    $pdo->exec(
        'DELETE FROM callback_log
         WHERE id <= (
            SELECT id FROM (
                SELECT id FROM callback_log ORDER BY id DESC LIMIT 1 OFFSET 200
            ) old_rows
         )'
    );
    $cut = (new DateTimeImmutable('-21 days'))->format('Y-m-d H:i:s');
    $pdo->exec('DELETE FROM callback_events WHERE received_at < ' . $pdo->quote($cut) . ' LIMIT 500');
}

function callback_recent(PDO $pdo): array
{
    return $pdo->query(
        'SELECT event_type, result, detail, created_at
         FROM callback_log
         ORDER BY id DESC
         LIMIT 20'
    )->fetchAll();
}

function callback_result_label(string $code): string
{
    $map = [
        'awarded' => 'балл начислен',
        'held' => 'ждёт принятия акции',
        'revoked' => 'балл снят',
        'duplicate' => 'повтор',
        'ignored' => 'не считается',
        'early' => 'конкурс ещё не начался',
        'closed' => 'конкурс не идёт',
        'disabled' => 'за действие 0 баллов',
        'noop' => 'снимать нечего',
        'secret' => 'секрет не подошёл',
        'group' => 'другое сообщество',
        'error' => 'ошибка',
    ];
    return $map[$code] ?? $code;
}

function callback_detail_label(string $detail): string
{
    if ($detail === '') {
        return '';
    }
    if (preg_match('/^\d+$/', $detail)) {
        return 'id ' . $detail;
    }
    $map = [
        'repost' => 'репост',
        'not_post' => 'не запись стены',
        'not_group' => 'не это сообщество',
        'join_pending' => 'только заявка',
        'join_unsure' => 'ответ «возможно»',
        'community_author' => 'автор: сообщество',
        'bad_payload' => 'неполные данные',
        'unsupported' => 'событие не используется',
        'edit' => 'правка комментария',
        'like' => 'лайк',
        'comment' => 'комментарий',
        'join' => 'вступление',
    ];
    return $map[$detail] ?? $detail;
}
