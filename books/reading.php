<?php
declare(strict_types=1);
/**
 * Saves the reader's place in a book, or its settings, for the signed-in
 * owner. The reader calls it with a JSON POST; nothing else uses it.
 *
 *   {"id": 1414, "cfi": "epubcfi(...)", "pct": 0.42}   where you stopped
 *   {"settings": {"theme": "sepia", "font": "georgia", "size": 110, "pages": "two"}}
 *   {"id": 1414, "bookmark": {"cfi": "...", "label": "Chapter 2", "pct": 0.1, "text": "Opening words"}}
 *   {"id": 1414, "removeBookmark": "epubcfi(...)"}
 *   {"id": 1414, "finished": true, "how": "end"}       mark as read (false to undo)
 * The two bookmark calls reply with the book's full bookmark list.
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

    if (array_key_exists('finished', $in)) {
        if ($id <= 0) {
            reply(400, ['error' => 'Need a book id']);
        }
        set_finished($id, (bool) $in['finished'], (string) ($in['how'] ?? 'manual'));
        reply(200, ['ok' => true, 'finished' => (bool) $in['finished']]);
    }

    if (isset($in['bookmark']) || isset($in['removeBookmark'])) {
        if ($id <= 0) {
            reply(400, ['error' => 'Need a book id']);
        }
        $add = is_array($in['bookmark'] ?? null) ? $in['bookmark'] : null;
        $remove = $in['removeBookmark'] ?? null;
        if (($add && !valid_cfi($add['cfi'] ?? null)) || (!$add && !valid_cfi($remove))) {
            reply(400, ['error' => 'Need a position for the bookmark']);
        }
        $list = [];
        update_reading_data(function (array $d) use ($id, $add, $remove, &$list) {
            $d['bookmarks'] = is_array($d['bookmarks'] ?? null) ? $d['bookmarks'] : [];
            $list = array_values(array_filter((array) ($d['bookmarks'][(string) $id] ?? []), fn($b) => is_array($b) && valid_cfi($b['cfi'] ?? null)));
            if ($add) {
                $list = array_values(array_filter($list, fn($b) => $b['cfi'] !== $add['cfi']));
                $list[] = [
                    'cfi'     => (string) $add['cfi'],
                    'label'   => clean_line($add['label'] ?? '', 120),
                    'pct'     => isset($add['pct']) && is_numeric($add['pct']) ? max(0.0, min(1.0, (float) $add['pct'])) : null,
                    'text'    => clean_line($add['text'] ?? '', 160),
                    'created' => date('c'),
                ];
                $list = array_slice($list, -MAX_BOOKMARKS_PER_BOOK);
            } else {
                $list = array_values(array_filter($list, fn($b) => $b['cfi'] !== $remove));
            }
            if ($list) {
                $d['bookmarks'][(string) $id] = $list;
            } else {
                unset($d['bookmarks'][(string) $id]);
            }
            return $d;
        });
        reply(200, ['ok' => true, 'bookmarks' => $list]);
    }

    $cfi = (string) ($in['cfi'] ?? '');
    $pct = isset($in['pct']) && is_numeric($in['pct']) ? max(0.0, min(1.0, (float) $in['pct'])) : null;
    if ($id <= 0 || !valid_cfi($cfi)) {
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
