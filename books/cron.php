<?php
/*
 * Web-triggered sync, for when a cPanel cron job isn't an option.
 * Point a web cron service at:  https://yourdomain/books/cron.php?key=YOUR_CRON_KEY
 * Switched off unless 'cron_key' is set in app/config.php.
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

$key = (string) cfg('cron_key', '');
if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit("Not allowed.\n");
}

@set_time_limit(60);
ignore_user_abort(true);

try {
    $result = (new Sync(make_source()))->run(25.0);
} catch (Throwable $e) {
    log_line('Error: ' . $e->getMessage());
    http_response_code(500);
    exit('Error: ' . $e->getMessage() . "\n");
}
echo json_encode($result, JSON_PRETTY_PRINT), "\n";
