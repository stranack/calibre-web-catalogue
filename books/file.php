<?php
declare(strict_types=1);
/**
 * Hands one of a book's files (EPUB, PDF and so on) to the signed-in owner as
 * a download, so a tablet can open it in a reader app such as Moon+ Reader.
 *
 * Everyone else gets a "sign in first" page: the public catalogue stays
 * browse-only. The file path comes from the catalogue, never from the request,
 * so a visitor can't ask for anything outside the library.
 */
require __DIR__ . '/app/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');

function file_problem(int $status, string $title, string $html): void
{
    http_response_code($status);
    page_top($title, ['search' => false]);
    echo '<section class="empty"><h1>' . h($title) . '</h1><p>' . $html . '</p></section>';
    page_bottom();
    exit;
}

if (!is_owner()) {
    file_problem(403, 'Sign in to open books', 'Book files are only available to the owner of this library. If that\'s you, <a href="admin.php">sign in on the admin page</a> on this device and try again.');
}

$cat = Catalog::open();
if (!$cat) {
    not_ready_page();
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$format = strtoupper(trim((string) ($_GET['format'] ?? '')));
$b = $id ? $cat->book($id) : null;
$file = null;
foreach (($b['files'] ?? []) as $f) {
    if (strtoupper((string) $f['format']) === $format) {
        $file = $f;
        break;
    }
}
if (!$b || !$file || $b['path'] === '' || (string) $file['name'] === '') {
    file_problem(404, 'File not found', 'The catalogue has no ' . h($format ?: 'such') . ' file for this book. <a href="' . h($b ? link_to('book.php', ['id' => (int) $b['id']]) : 'index.php') . '">Back</a>');
}

$ext = strtolower((string) $file['format']);
$fileName = $file['name'] . '.' . $ext;
$relPath = trim($b['path'], '/') . '/' . $fileName;
if (in_array('..', explode('/', $relPath), true) || str_contains($relPath, "\0")) {
    file_problem(404, 'File not found', 'That file name can\'t be served.');
}

/** Headers for the download itself, sent only once the file is ready to flow. */
$sendHeaders = function (?int $size) use ($fileName, $format): void {
    $ascii = preg_replace('/[^A-Za-z0-9 ._()-]+/', '_', $fileName);
    header('Content-Type: ' . ebook_mime($format));
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    header('X-Content-Type-Options: nosniff');
    if ($size !== null && $size > 0) {
        header('Content-Length: ' . $size);
    }
};

// Big files shouldn't sit in a buffer or be cut off by the script time limit.
@ini_set('zlib.output_compression', '0');
@set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_clean();
}

$local = (string) cfg('local_library_path', '');
if ($local !== '') {
    $root = realpath($local);
    $full = $root ? realpath($root . '/' . $relPath) : false;
    if (!$full || !str_starts_with($full, $root . DIRECTORY_SEPARATOR) || !is_file($full)) {
        file_problem(404, 'File not found', 'The file isn\'t in the library folder on this server.');
    }
    $sendHeaders((int) filesize($full));
    readfile($full);
    exit;
}

try {
    $src = make_source();
    if (!$src instanceof DropboxSource) {
        throw new RuntimeException('This library has no Dropbox connection.');
    }
    $tmp = $src->temporaryLink($relPath);
} catch (Throwable $e) {
    $missing = str_contains($e->getMessage(), 'not_found');
    file_problem(
        $missing ? 404 : 502,
        $missing ? 'File not found in Dropbox' : 'Dropbox didn\'t respond',
        $missing
            ? 'Calibre lists ' . h($fileName) . ', but it isn\'t in Dropbox. Calibre may still be syncing it, or the file was moved.'
            : 'The file couldn\'t be fetched just now (' . h($e->getMessage()) . '). Try again in a minute.'
    );
}

$ok = stream_url_to_output($tmp['link'], fn() => $sendHeaders($tmp['size']));
if (!$ok) {
    file_problem(502, 'Dropbox didn\'t send the file', 'The download didn\'t start. Try again in a minute.');
}
