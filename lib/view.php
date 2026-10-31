<?php

declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function view_datetime(string $value): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if (!$dt instanceof DateTimeImmutable) {
        return $value;
    }
    return $dt->format('d.m.Y H:i');
}

function view_datetime_local(string $value): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if (!$dt instanceof DateTimeImmutable) {
        return '';
    }
    return $dt->format('Y-m-d\TH:i');
}

function view_start(
    string $title,
    ?string $active = null,
    string $groupName = '',
    bool $allowVkFrame = false,
    bool $needLaunchParams = false
): void {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    if ($allowVkFrame) {
        // Без X-Frame-Options: ВК открывает мини-приложение во iframe / WebView.
        // frame-ancestors * — иначе часть клиентов ВК режет узкий список доменов.
        header(
            "Content-Security-Policy: default-src 'self'; style-src 'self'; "
            . "script-src 'self' https://unpkg.com https://cdn.jsdelivr.net; "
            . "connect-src 'self' https://api.vk.com https://stat.vk-portal.net https://unpkg.com https://cdn.jsdelivr.net; "
            . "base-uri 'none'; form-action 'self'; frame-ancestors *"
        );
    } else {
        header('X-Frame-Options: DENY');
        header(
            "Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; "
            . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
        );
    }

    $heading = $title . ' · Конкурсьер';
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($heading) . '</title>';
    echo '<link rel="stylesheet" href="assets/style.css">';
    $bodyClass = $allowVkFrame ? ' class="join-page"' : '';
    $needAttr = ($allowVkFrame && $needLaunchParams) ? ' data-need-params="1"' : '';
    echo '</head><body' . $bodyClass . $needAttr . '>';
    echo '<a class="skip" href="#content">К содержимому</a>';
    echo '<div id="toast-root" class="toast-root" aria-live="polite" aria-atomic="true"></div>';
    echo '<header class="top">';
    echo '<div class="wrap top-row">';
    echo '<div>';
    if ($allowVkFrame) {
        echo '<span class="brand">Конкурсьер</span>';
    } else {
        echo '<a class="brand" href="index.php">Конкурсьер</a>';
    }
    if ($groupName !== '') {
        echo '<p class="brand-note">' . h($groupName) . '</p>';
    } else {
        echo '<p class="brand-note">Конкурс активности в сообществе</p>';
    }
    echo '</div>';
    if ($active !== null) {
        echo '<nav class="nav" aria-label="Разделы">';
        $links = [
            'contest' => 'Конкурс',
            'connect' => 'Подключение',
            'rating' => 'Рейтинг',
        ];
        foreach ($links as $id => $label) {
            $current = $id === $active ? ' aria-current="page"' : '';
            echo '<a href="index.php?page=' . $id . '"' . $current . '>' . h($label) . '</a>';
        }
        echo '</nav>';
        echo '<form method="post" action="index.php?logout=1">';
        view_csrf();
        echo '<button class="ghost" type="submit">Выйти</button></form>';
    }
    echo '</div></header><main id="content" class="wrap">';
}

function view_end(bool $joinApp = false): void
{
    echo '</main>';
    if ($joinApp) {
        echo '<script src="assets/join.js"></script>';
    } else {
        echo '<script src="assets/app.js"></script>';
    }
    echo '</body></html>';
}

function view_flash(?array $flash): void
{
    if ($flash === null) {
        return;
    }
    $type = $flash['type'] === 'ok' ? 'ok' : 'bad';
    echo '<p class="flash-data" hidden data-flash-type="' . h($type) . '" data-flash-text="' . h($flash['text']) . '"></p>';
}

function view_csrf(): void
{
    echo '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function flash_set(string $type, string $text): void
{
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

function flash_take(): ?array
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function contest_status_view(array $contest, string $now): array
{
    if ($contest['status'] === 'finished') {
        return ['label' => 'Завершён', 'class' => 'is-done'];
    }
    $window = contest_window_open($contest['status'], $contest['starts_at'], $contest['ends_at'], $now);
    if ($window === 'early') {
        return ['label' => 'Ждёт начала', 'class' => 'is-wait'];
    }
    if ($window === 'open') {
        return ['label' => 'Идёт', 'class' => 'is-open'];
    }
    return ['label' => 'Завершён', 'class' => 'is-done'];
}
