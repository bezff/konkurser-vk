<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
app_boot(true);

if (!is_file(APP_ROOT . '/config.php')) {
    http_response_code(500);
    exit;
}

$pdo = app_require_ready();
if (!auth_logged_in()) {
    redirect_to('index.php');
}

$contestId = (int) ($_GET['id'] ?? 0);
$contest = $contestId > 0 ? contest_find($pdo, $contestId) : null;
if ($contest === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Конкурс не найден.\n";
    exit;
}

$rows = contest_leaderboard($pdo, $contestId);
$filename = 'konkurs-' . $contestId . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo "\xEF\xBB\xBF";
echo csv_line(['Место', 'Имя', 'Фамилия', 'Ссылка', 'Баллы']) . "\r\n";
foreach ($rows as $row) {
    $userId = (int) $row['vk_user_id'];
    echo csv_line([
        (string) $row['place'],
        (string) $row['first_name'],
        (string) $row['last_name'],
        'https://vk.com/id' . $userId,
        (string) (int) $row['points'],
    ]) . "\r\n";
}
