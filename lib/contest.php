<?php

declare(strict_types=1);

function contest_close_expired(PDO $pdo): void
{
    $st = $pdo->prepare(
        "UPDATE contests
         SET status = 'finished', finished_at = ?
         WHERE status = 'active' AND ends_at <= ?"
    );
    $now = app_now();
    $st->execute([$now, $now]);
}

function contest_find_active(PDO $pdo): ?array
{
    $st = $pdo->query(
        "SELECT id, title, starts_at, ends_at, status, require_accept, created_at, finished_at
         FROM contests
         WHERE status = 'active'
         ORDER BY id DESC
         LIMIT 1"
    );
    $row = $st->fetch();
    return $row ?: null;
}

function contest_find(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        'SELECT id, title, starts_at, ends_at, status, require_accept, created_at, finished_at
         FROM contests WHERE id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function contest_list(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, title, starts_at, ends_at, status, require_accept, created_at, finished_at
         FROM contests
         ORDER BY id DESC
         LIMIT 24'
    )->fetchAll();
}

function contest_start(PDO $pdo, array $post): ?string
{
    $settings = settings_all($pdo);
    $blocked = settings_requirements_error($settings);
    if ($blocked !== null) {
        return $blocked;
    }

    $title = trim(preg_replace('/\s+/u', ' ', (string) ($post['title'] ?? '')) ?? '');
    if ($title === '') {
        $title = 'Конкурс';
    }
    if (mb_strlen($title) > 200) {
        return 'Название длиннее 200 символов.';
    }

    $starts = parse_local_datetime((string) ($post['starts_at'] ?? ''));
    $ends = parse_local_datetime((string) ($post['ends_at'] ?? ''));
    if ($starts === null || $ends === null) {
        return 'Укажите дату и время начала и окончания.';
    }
    if ($ends <= $starts) {
        return 'Окончание должно быть позже начала.';
    }
    if ($ends <= app_now()) {
        return 'Окончание уже прошло.';
    }
    $span = (new DateTimeImmutable($ends))->getTimestamp() - (new DateTimeImmutable($starts))->getTimestamp();
    if ($span > 400 * 86400) {
        return 'Срок конкурса не больше 400 дней.';
    }

    $requireAccept = !empty($post['require_accept']);
    if ($requireAccept && !settings_miniapp_ready($settings)) {
        return 'Сначала укажите мини-приложение на странице «Подключение».';
    }

    db_lock($pdo, 'konkurser');
    try {
        contest_close_expired($pdo);
        if (contest_find_active($pdo) !== null) {
            return 'Уже есть активный конкурс. Сначала завершите его.';
        }
        $now = app_now();
        $st = $pdo->prepare(
            'INSERT INTO contests (title, starts_at, ends_at, status, require_accept, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$title, $starts, $ends, 'active', $requireAccept ? 1 : 0, $now]);
    } finally {
        db_unlock($pdo, 'konkurser');
    }

    return null;
}

function contest_finish(PDO $pdo): ?string
{
    db_lock($pdo, 'konkurser');
    try {
        $active = contest_find_active($pdo);
        if ($active === null) {
            return 'Активного конкурса нет.';
        }
        $st = $pdo->prepare(
            "UPDATE contests SET status = 'finished', finished_at = ? WHERE id = ? AND status = 'active'"
        );
        $st->execute([app_now(), (int) $active['id']]);
    } finally {
        db_unlock($pdo, 'konkurser');
    }
    return null;
}

function contest_stats(PDO $pdo, int $contestId): array
{
    $st = $pdo->prepare(
        "SELECT COUNT(DISTINCT vk_user_id) AS users, COALESCE(SUM(points), 0) AS points
         FROM actions
         WHERE contest_id = ? AND status = 'active'"
    );
    $st->execute([$contestId]);
    $row = $st->fetch() ?: ['users' => 0, 'points' => 0];
    return [
        'users' => (int) $row['users'],
        'points' => (int) $row['points'],
    ];
}

function contest_leaderboard(PDO $pdo, int $contestId): array
{
    $st = $pdo->prepare(
        "SELECT p.vk_user_id, p.first_name, p.last_name, SUM(a.points) AS points
         FROM actions a
         INNER JOIN participants p ON p.vk_user_id = a.vk_user_id
         WHERE a.contest_id = ? AND a.status = 'active'
         GROUP BY p.vk_user_id, p.first_name, p.last_name
         HAVING SUM(a.points) > 0
         ORDER BY SUM(a.points) DESC, p.vk_user_id ASC"
    );
    $st->execute([$contestId]);
    return scoring_places($st->fetchAll());
}

function contest_refresh_names(PDO $pdo, int $contestId, string $token): ?string
{
    if ($token === '') {
        return 'Нет ключа доступа сообщества.';
    }
    $st = $pdo->prepare(
        'SELECT p.vk_user_id
         FROM participants p
         WHERE p.vk_user_id IN (SELECT vk_user_id FROM actions WHERE contest_id = ?)
         ORDER BY p.profile_updated_at IS NULL DESC, p.profile_updated_at ASC
         LIMIT 300'
    );
    $st->execute([$contestId]);
    $ids = array_map(static fn (array $row): int => (int) $row['vk_user_id'], $st->fetchAll());
    if ($ids === []) {
        return 'В этом конкурсе ещё никого нет.';
    }

    try {
        $users = vk_users($token, $ids);
    } catch (Throwable $e) {
        app_log('refresh names: ' . $e->getMessage());
        return 'ВК не отдал имена. ' . vk_public_error($e);
    }

    $now = app_now();
    $upd = $pdo->prepare(
        'UPDATE participants SET first_name = ?, last_name = ?, profile_updated_at = ? WHERE vk_user_id = ?'
    );
    foreach ($ids as $id) {
        if (!isset($users[$id])) {
            continue;
        }
        $upd->execute([
            $users[$id]['first_name'],
            $users[$id]['last_name'],
            $now,
            $id,
        ]);
    }
    return null;
}

function parse_local_datetime(string $raw): ?string
{
    $raw = trim($raw);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw);
    if (!$dt instanceof DateTimeImmutable) {
        return null;
    }
    $errors = DateTimeImmutable::getLastErrors();
    if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return null;
    }
    if ($dt->format('Y-m-d\TH:i') !== $raw) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

function scoring_apply(
    PDO $pdo,
    int $contestId,
    array $decision,
    int $points,
    string $now,
    ?array $profile,
    string $awardStatus = 'active'
): string {
    $st = $pdo->prepare(
        'SELECT vk_user_id, points, status FROM actions WHERE contest_id = ? AND action_key = ? FOR UPDATE'
    );
    $st->execute([$contestId, $decision['key']]);
    $row = $st->fetch() ?: null;
    $next = scoring_transition($row, $decision['action'] === 'award', $points, $awardStatus);

    if ($next['op'] === 'insert') {
        $userId = (int) $decision['user_id'];
        if ($userId <= 0) {
            return 'ignored';
        }
        $ins = $pdo->prepare(
            'INSERT INTO actions
                (contest_id, vk_user_id, action_type, action_key, points, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $ins->execute([
                $contestId,
                $userId,
                $decision['type'],
                $decision['key'],
                $next['points'],
                $next['status'],
                $now,
                $now,
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return 'duplicate';
            }
            throw $e;
        }
    } elseif ($next['op'] === 'update') {
        $upd = $pdo->prepare(
            'UPDATE actions SET status = ?, points = ?, updated_at = ? WHERE contest_id = ? AND action_key = ?'
        );
        $upd->execute([$next['status'], $next['points'], $now, $contestId, $decision['key']]);
    }

    if ($next['result'] === 'awarded' || $next['result'] === 'held') {
        participant_save($pdo, (int) $decision['user_id'], $profile, $now);
    }

    return $next['result'];
}

function contest_user_lock_name(int $contestId, int $userId): string
{
    return 'konkurser_u_' . $contestId . '_' . $userId;
}

function contest_has_accepted(PDO $pdo, int $contestId, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT 1 FROM acceptances WHERE contest_id = ? AND vk_user_id = ? LIMIT 1'
    );
    $st->execute([$contestId, $userId]);
    return (bool) $st->fetchColumn();
}

function contest_accept_count(PDO $pdo, int $contestId): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM acceptances WHERE contest_id = ?');
    $st->execute([$contestId]);
    return (int) $st->fetchColumn();
}

function contest_accept_url(array $settings): string
{
    $appId = (int) ($settings['vk_app_id'] ?? 0);
    $groupId = (int) ($settings['vk_group_id'] ?? 0);
    if ($appId <= 0 || $groupId <= 0) {
        return '';
    }
    return 'https://vk.com/app' . $appId . '_-' . $groupId;
}

function contest_invite_url(array $settings, int $userId): string
{
    $base = contest_accept_url($settings);
    if ($base === '' || $userId <= 0) {
        return '';
    }
    return $base . '?ref=' . $userId;
}

function referral_remember_click(PDO $pdo, int $contestId, int $userId, int $referrerId, string $now): void
{
    if ($userId <= 0 || $referrerId <= 0 || $referrerId === $userId) {
        return;
    }
    $st = $pdo->prepare(
        'INSERT INTO referral_clicks (contest_id, vk_user_id, referrer_vk_user_id, created_at)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE referrer_vk_user_id = ?, created_at = ?'
    );
    $st->execute([$contestId, $userId, $referrerId, $now, $referrerId, $now]);
}

function contest_referral_count(PDO $pdo, int $contestId): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE contest_id = ?');
    $st->execute([$contestId]);
    return (int) $st->fetchColumn();
}

function referral_credit_on_join(PDO $pdo, int $contestId, int $userId, string $now, array $settings): void
{
    if ($userId <= 0) {
        return;
    }

    $st = $pdo->prepare(
        'SELECT referrer_vk_user_id FROM referral_clicks WHERE contest_id = ? AND vk_user_id = ?'
    );
    $st->execute([$contestId, $userId]);
    $referrerRaw = $st->fetchColumn();
    if ($referrerRaw === false) {
        return;
    }
    $referrerId = (int) $referrerRaw;
    if ($referrerId <= 0 || $referrerId === $userId) {
        return;
    }

    $ins = $pdo->prepare(
        'INSERT INTO referrals (contest_id, referred_vk_user_id, referrer_vk_user_id, created_at)
         VALUES (?, ?, ?, ?)'
    );
    try {
        $ins->execute([$contestId, $userId, $referrerId, $now]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            return;
        }
        throw $e;
    }

    $inviterPoints = settings_points($settings, 'referral');
    if ($inviterPoints > 0) {
        scoring_apply($pdo, $contestId, [
            'action' => 'award',
            'user_id' => $referrerId,
            'type' => 'referral',
            'key' => 'referral:' . $referrerId . ':' . $userId,
        ], $inviterPoints, $now, null, 'active');
    }

    $friendPoints = settings_points($settings, 'referral_friend');
    if ($friendPoints > 0) {
        scoring_apply($pdo, $contestId, [
            'action' => 'award',
            'user_id' => $userId,
            'type' => 'referral_friend',
            'key' => 'referral_friend:' . $userId,
        ], $friendPoints, $now, null, 'active');
    }
}

/**
 * @return array{code:string,detail:string}
 */
function contest_accept(PDO $pdo, int $contestId, int $userId, string $now, ?array $profile): array
{
    if ($userId <= 0) {
        return ['code' => 'ignored', 'detail' => 'bad_user'];
    }

    $lock = contest_user_lock_name($contestId, $userId);
    db_lock($pdo, $lock);
    try {
        return db_transaction($pdo, function (PDO $pdo) use ($contestId, $userId, $now, $profile): array {
            if (contest_has_accepted($pdo, $contestId, $userId)) {
                return ['code' => 'already', 'detail' => (string) $userId];
            }

            $ins = $pdo->prepare(
                'INSERT INTO acceptances (contest_id, vk_user_id, accepted_at) VALUES (?, ?, ?)'
            );
            try {
                $ins->execute([$contestId, $userId, $now]);
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    return ['code' => 'already', 'detail' => (string) $userId];
                }
                throw $e;
            }

            $upd = $pdo->prepare(
                "UPDATE actions
                 SET status = 'active', updated_at = ?
                 WHERE contest_id = ? AND vk_user_id = ? AND status = 'held'"
            );
            $upd->execute([$now, $contestId, $userId]);

            $joinActive = $pdo->prepare(
                "SELECT 1 FROM actions
                 WHERE contest_id = ? AND vk_user_id = ? AND action_type = 'join' AND status = 'active'
                 LIMIT 1"
            );
            $joinActive->execute([$contestId, $userId]);
            if ($joinActive->fetchColumn()) {
                referral_credit_on_join($pdo, $contestId, $userId, $now, settings_all($pdo));
            }

            participant_save($pdo, $userId, $profile, $now);

            return ['code' => 'accepted', 'detail' => (string) $userId];
        });
    } finally {
        db_unlock($pdo, $lock);
    }
}

function participant_save(PDO $pdo, int $userId, ?array $profile, string $now): void
{
    if ($userId <= 0) {
        return;
    }
    $first = is_array($profile) ? (string) ($profile['first_name'] ?? '') : '';
    $last = is_array($profile) ? (string) ($profile['last_name'] ?? '') : '';
    $st = $pdo->prepare('SELECT first_name, last_name FROM participants WHERE vk_user_id = ? FOR UPDATE');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) {
        $ins = $pdo->prepare(
            'INSERT INTO participants (vk_user_id, first_name, last_name, profile_updated_at) VALUES (?, ?, ?, ?)'
        );
        try {
            $ins->execute([
                $userId,
                $first,
                $last,
                ($first === '' && $last === '') ? null : $now,
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }
        return;
    }
    if ($first === '' && $last === '') {
        return;
    }
    $upd = $pdo->prepare(
        'UPDATE participants SET first_name = ?, last_name = ?, profile_updated_at = ? WHERE vk_user_id = ?'
    );
    $upd->execute([
        $first !== '' ? $first : $row['first_name'],
        $last !== '' ? $last : $row['last_name'],
        $now,
        $userId,
    ]);
}
