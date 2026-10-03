<?php

declare(strict_types=1);

// A stand-in for Trello's attachment download endpoint and a foreign file
// store, served by `php -S` for the real-transfer tests. Every request's
// Authorization header is appended to the log file named in TRANSFER_LOG.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = function_exists('getallheaders') ? getallheaders() : [];
file_put_contents((string) getenv('TRANSFER_LOG'), $_SERVER['HTTP_HOST'].' '.$path.' auth='.($headers['Authorization'] ?? 'none')."\n", FILE_APPEND);

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

if (str_ends_with($path, '/download/big.png')) {
    header('Content-Type: image/png');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    echo str_repeat('x', 65536);
    flush();
    sleep(3);
    for ($i = 0; $i < 64; $i++) {
        echo str_repeat('x', 65536);
        flush();
    }
    file_put_contents((string) getenv('TRANSFER_LOG'), "big finished\n", FILE_APPEND);

    return true;
}

if (str_ends_with($path, '/download/moved.png')) {
    header('Location: '.getenv('FOREIGN_ORIGIN').'/store/moved.png', true, 302);
    echo 'Moved to the file store.';

    return true;
}

if ($path === '/store/moved.png') {
    header('Content-Type: image/png');
    echo $png;

    return true;
}

http_response_code(404);

return true;
