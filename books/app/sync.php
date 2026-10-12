<?php
/*
 * Command-line sync, for a cPanel cron job:
 *
 *   php -q /home/USERNAME/public_html/books/app/sync.php
 *
 * Add --force to re-download the catalogue and retry every cover.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this from the command line or a cron job.');
}

require __DIR__ . '/bootstrap.php';

$force = in_array('--force', $argv, true);
$quiet = in_array('--quiet', $argv, true);

try {
    $sync = new Sync(make_source(), $quiet ? null : static function (string $m): void {
        echo $m, "\n";
    });
    $result = $sync->run((float) cfg('cron_time_budget', 300), $force);
} catch (Throwable $e) {
    log_line('Error: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
exit(($result['status'] ?? '') === 'error' ? 1 : 0);
