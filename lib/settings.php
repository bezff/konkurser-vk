<?php

declare(strict_types=1);

function settings_all(PDO $pdo): array
{
    $rows = $pdo->query('SELECT name, value FROM settings')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['name']] = (string) $row['value'];
    }
    return $out;
}

function settings_get(PDO $pdo, string $name, string $default = ''): string
{
    $st = $pdo->prepare('SELECT value FROM settings WHERE name = ?');
    $st->execute([$name]);
    $value = $st->fetchColumn();
    if ($value === false) {
        return $default;
    }
    return (string) $value;
}

function settings_set_many(PDO $pdo, array $pairs): void
{
    $st = $pdo->prepare(
        'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?'
    );
    foreach ($pairs as $name => $value) {
        $text = (string) $value;
        $st->execute([(string) $name, $text, $text]);
    }
}

function settings_points(array $settings, string $type): int
{
    $defaults = [
        'join' => 5,
        'like' => 1,
        'comment' => 2,
    ];
    $raw = $settings['points_' . $type] ?? (string) ($defaults[$type] ?? 0);
    $value = (int) $raw;
    if ($value < 0) {
        return 0;
    }
    if ($value > 100) {
        return 100;
    }
    return $value;
}

function settings_parse_points(mixed $raw): ?int
{
    if (!is_string($raw) || !preg_match('/^\d{1,3}$/', trim($raw))) {
        return null;
    }
    $value = (int) trim($raw);
    if ($value > 100) {
        return null;
    }
    return $value;
}

function settings_save_connection(PDO $pdo, array $post): ?string
{
    $current = settings_all($pdo);
    $groupRaw = trim((string) ($post['group'] ?? ''));
    $groupKey = vk_parse_group($groupRaw);
    if ($groupKey === '') {
        return 'Укажите сообщество: числовой ID, короткую ссылку или адрес vk.com.';
    }

    $token = trim((string) ($post['token'] ?? ''));
    if ($token === '') {
        $token = (string) ($current['vk_access_token'] ?? '');
    }
    if (strlen($token) < 20 || strlen($token) > 500) {
        return 'Вставьте ключ доступа сообщества.';
    }

    $secret = trim((string) ($post['secret'] ?? ''));
    if ($secret === '') {
        $secret = (string) ($current['vk_secret'] ?? '');
    }
    if (strlen($secret) < 8 || strlen($secret) > 50) {
        return 'Секретный ключ должен быть от 8 до 50 символов. ВК не принимает длиннее.';
    }
    if (preg_match('/\s/', $secret)) {
        return 'Секретный ключ не должен содержать пробелы.';
    }

    $confirmation = trim((string) ($post['confirmation'] ?? ''));
    if ($confirmation === '' || strlen($confirmation) > 100) {
        return 'Вставьте строку подтверждения со вкладки Callback API сообщества.';
    }
    if (preg_match('/\s/', $confirmation)) {
        return 'Строка подтверждения не должна содержать пробелы и переносы.';
    }

    $points = [];
    foreach (['join' => 'вступление', 'like' => 'лайк', 'comment' => 'комментарий'] as $key => $label) {
        $parsed = settings_parse_points($post['points_' . $key] ?? null);
        if ($parsed === null) {
            return 'Баллы за ' . $label . ': целое число от 0 до 100. Ноль отключает действие.';
        }
        $points[$key] = $parsed;
    }

    $appIdRaw = trim((string) ($post['vk_app_id'] ?? ''));
    $appId = 0;
    if ($appIdRaw !== '') {
        if (!preg_match('/^\d{1,12}$/', $appIdRaw) || (int) $appIdRaw <= 0) {
            return 'ID мини-приложения: положительное целое число.';
        }
        $appId = (int) $appIdRaw;
    }

    $appSecret = trim((string) ($post['vk_app_secret'] ?? ''));
    if ($appSecret === '') {
        $appSecret = (string) ($current['vk_app_secret'] ?? '');
    }
    if ($appSecret !== '' && (strlen($appSecret) < 8 || strlen($appSecret) > 200)) {
        return 'Защищённый ключ мини-приложения: от 8 до 200 символов.';
    }
    if ($appSecret !== '' && preg_match('/\s/', $appSecret)) {
        return 'Защищённый ключ не должен содержать пробелы.';
    }
    if ($appId === 0) {
        $appSecret = '';
    } elseif ($appSecret === '') {
        return 'Вставьте защищённый ключ мини-приложения.';
    }

    try {
        $group = vk_resolve_group($token, $groupKey);
    } catch (Throwable $e) {
        app_log('vk group: ' . $e->getMessage());
        return 'ВК не принял ключ или сообщество. ' . vk_public_error($e);
    }

    contest_close_expired($pdo);
    $active = contest_find_active($pdo);
    $currentGroup = (int) ($current['vk_group_id'] ?? 0);
    if ($active !== null && $currentGroup > 0 && $group['id'] !== $currentGroup) {
        return 'Нельзя сменить сообщество, пока конкурс не завершён.';
    }
    if (
        $active !== null
        && (int) ($active['require_accept'] ?? 0) === 1
        && ($appId <= 0 || $appSecret === '')
    ) {
        return 'Пока идёт конкурс с принятием акции, мини-приложение нельзя отключить.';
    }

    settings_set_many($pdo, [
        'vk_group_id' => (string) $group['id'],
        'vk_group_name' => $group['name'],
        'vk_access_token' => $token,
        'vk_secret' => $secret,
        'vk_confirmation' => $confirmation,
        'vk_app_id' => $appId > 0 ? (string) $appId : '',
        'vk_app_secret' => $appSecret,
        'points_join' => (string) $points['join'],
        'points_like' => (string) $points['like'],
        'points_comment' => (string) $points['comment'],
    ]);

    return null;
}

function settings_miniapp_ready(array $settings): bool
{
    return (int) ($settings['vk_app_id'] ?? 0) > 0
        && ($settings['vk_app_secret'] ?? '') !== '';
}

function settings_requirements_error(array $settings): ?string
{
    if ((int) ($settings['vk_group_id'] ?? 0) <= 0) {
        return 'Сначала подключите сообщество.';
    }
    if (($settings['vk_access_token'] ?? '') === '') {
        return 'Нет ключа доступа сообщества.';
    }
    if (($settings['vk_confirmation'] ?? '') === '') {
        return 'Не задана строка подтверждения Callback API.';
    }
    if (strlen((string) ($settings['vk_secret'] ?? '')) < 8) {
        return 'Не задан секретный ключ Callback API.';
    }
    return null;
}
