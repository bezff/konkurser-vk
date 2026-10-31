<?php

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
app_boot(false);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'method';
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false) {
    $raw = '';
}

try {
    $data = callback_parse_body($raw);
} catch (InvalidArgumentException) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'bad request';
    exit;
}

try {
    $pdo = db();
} catch (Throwable $e) {
    app_log($e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'error';
    exit;
}

callback_handle($pdo, $data);
