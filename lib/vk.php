<?php

declare(strict_types=1);

function vk_parse_group(string $raw): string
{
    $value = trim($raw);
    $value = preg_replace('#^https?://#i', '', $value) ?? '';
    $value = preg_replace('#^(?:www\.|m\.)?vk\.(?:com|ru)/#i', '', $value) ?? '';
    $value = trim($value, "/ \t");
    $value = preg_replace('/\?.*$/', '', $value) ?? '';
    if (preg_match('/^(?:club|public|event)(\d+)$/i', $value, $match)) {
        return $match[1];
    }
    if (preg_match('/^\d{1,15}$/', $value)) {
        return $value;
    }
    if (preg_match('/^[A-Za-z0-9_.]{2,64}$/', $value)) {
        return $value;
    }
    return '';
}

function vk_call(string $token, string $method, array $params): array
{
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $method)) {
        throw new RuntimeException('Некорректный метод VK API.');
    }

    $params['access_token'] = $token;
    $params['v'] = '5.199';

    $ch = curl_init('https://api.vk.com/method/' . $method);
    if ($ch === false) {
        throw new RuntimeException('Не удалось обратиться к VK API.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        throw new RuntimeException('Нет ответа VK API: ' . $error);
    }
    if (!is_string($raw) || strlen($raw) > 1000000) {
        throw new RuntimeException('VK API вернул слишком большой ответ.');
    }

    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('VK API вернул не JSON.');
    }
    if (!is_array($data)) {
        throw new RuntimeException('VK API вернул пустой ответ.');
    }
    if (isset($data['error'])) {
        $code = (int) ($data['error']['error_code'] ?? 0);
        $msg = (string) ($data['error']['error_msg'] ?? 'ошибка');
        throw new RuntimeException('VK API ' . $code . ': ' . vk_clean_name($msg));
    }

    $response = $data['response'] ?? [];
    return is_array($response) ? $response : [];
}

function vk_public_error(Throwable $e): string
{
    $message = $e->getMessage();
    if (str_starts_with($message, 'VK API ')) {
        return $message;
    }
    if (str_starts_with($message, 'Нет ответа VK API')) {
        return 'Сервер ВК сейчас не отвечает.';
    }
    return 'Не получилось связаться с ВК.';
}

function vk_resolve_group(string $token, string $groupKey): array
{
    $response = vk_call($token, 'groups.getById', [
        'group_ids' => $groupKey,
    ]);
    $group = vk_group_from_response($response);
    if ($group === null) {
        throw new RuntimeException('VK API не вернул сообщество.');
    }
    return $group;
}

function vk_group_from_response(array $response): ?array
{
    if (isset($response['groups']) && is_array($response['groups'])) {
        $list = $response['groups'];
    } elseif (isset($response['id'])) {
        $list = [$response];
    } elseif (vk_is_list($response)) {
        $list = $response;
    } else {
        return null;
    }

    $group = $list[0] ?? null;
    if (!is_array($group) || empty($group['id'])) {
        return null;
    }
    $id = (int) $group['id'];
    if ($id <= 0) {
        return null;
    }
    $name = vk_clean_name((string) ($group['name'] ?? ''), 120);
    if ($name === '') {
        $name = 'Сообщество ' . $id;
    }
    return ['id' => $id, 'name' => $name];
}

function vk_user_profile(string $token, int $userId): ?array
{
    if ($userId <= 0 || $token === '') {
        return null;
    }
    try {
        $users = vk_users($token, [$userId]);
    } catch (Throwable $e) {
        app_log('users.get: ' . $e->getMessage());
        return null;
    }
    return $users[$userId] ?? null;
}

function vk_users(string $token, array $ids): array
{
    $clean = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $clean[$id] = $id;
        }
    }
    $clean = array_values($clean);
    $out = [];
    foreach (array_chunk($clean, 100) as $chunk) {
        $response = vk_call($token, 'users.get', [
            'user_ids' => implode(',', $chunk),
        ]);
        if (!vk_is_list($response)) {
            continue;
        }
        foreach ($response as $user) {
            if (!is_array($user) || empty($user['id'])) {
                continue;
            }
            $id = (int) $user['id'];
            $out[$id] = [
                'first_name' => vk_clean_name((string) ($user['first_name'] ?? '')),
                'last_name' => vk_clean_name((string) ($user['last_name'] ?? '')),
            ];
        }
    }
    return $out;
}

function vk_clean_name(string $value, int $limit = 80): string
{
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (mb_strlen($value) > $limit) {
        $value = mb_substr($value, 0, $limit);
    }
    return $value;
}

function vk_is_list(array $value): bool
{
    $index = 0;
    foreach ($value as $key => $_row) {
        if ($key !== $index) {
            return false;
        }
        $index++;
    }
    return true;
}
