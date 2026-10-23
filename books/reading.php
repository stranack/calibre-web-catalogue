<?php
declare(strict_types=1);
/**
 * Saves the reader's place in a book, or its settings, for the signed-in
 * owner. The reader calls it with a JSON POST; nothing else uses it.
 *
 *   {"id": 1414, "cfi": "epubcfi(...)", "pct": 0.42}   where you stopped
 *   {"settings": {"theme": "sepia", "font": "georgia", "size": 110, "pages": "two"}}
 */
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

function reply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['error' => 'POST only']);
}
// The custom header can only be sent by this site's own pages (another site
// would need permission first), which stops other pages saving on your behalf.
if (!is_owner() || ($_SERVER['HTTP_X_LIBRARY_READER'] ?? '') !== '1') {
    reply(403, ['error' => 'Sign in on the admin page first']);
}

$in = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true);
if (!is_array($in)) {
    reply(400, ['error' => 'Expected JSON']);
}

try {
    if (isset($in['settings']) && is_array($in['settings'])) {
        $settings = clean_reader_settings($in['settings']);
        update_reading_data(function (array $d) use ($settings) {
            $d['settings'] = $settings;
            return $d;
        });
        reply(200, ['ok' => true, 'settings' => $settings]);
    }

    $id = (int) ($in['id'] ?? 0);
    $cfi = (string) ($in['cfi'] ?? '');
    $pct = isset($in['pct']) && is_numeric($in['pct']) ? max(0.0, min(1.0, (float) $in['pct'])) : null;
    if ($id <= 0 || strlen($cfi) > 600 || !preg_match('/^epubcfi\(.+\)$/s', $cfi)) {
        reply(400, ['error' => 'Need a book id and a position']);
    }
    update_reading_data(function (array $d) use ($id, $cfi, $pct) {
        $d['books'][(string) $id] = ['cfi' => $cfi, 'pct' => $pct, 'updated' => date('c')];
        return $d;
    });
    reply(200, ['ok' => true]);
} catch (Throwable $e) {
    reply(500, ['error' => 'Could not save: ' . $e->getMessage()]);
}
