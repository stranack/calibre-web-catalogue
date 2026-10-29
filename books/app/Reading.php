<?php
declare(strict_types=1);

/**
 * Where the owner stopped in each book, and their reader settings, kept on
 * the server so any signed-in device picks up in the same place. Stored as
 * one small JSON file in the data folder (blocked from the web).
 */

const READER_THEMES = ['paper', 'sepia', 'mist', 'night'];
const READER_FONTS = ['original', 'literata', 'georgia', 'sans'];
const READER_PAGES = ['auto', 'one', 'two'];

function reading_file(): string
{
    return DATA_DIR . '/reading.json';
}

function reader_default_settings(): array
{
    return ['theme' => 'paper', 'font' => 'original', 'size' => 100, 'pages' => 'auto'];
}

/** Keep only known settings with sensible values. */
function clean_reader_settings(array $in): array
{
    $s = reader_default_settings();
    if (in_array($in['theme'] ?? null, READER_THEMES, true)) {
        $s['theme'] = $in['theme'];
    }
    if (in_array($in['font'] ?? null, READER_FONTS, true)) {
        $s['font'] = $in['font'];
    }
    if (isset($in['size']) && is_numeric($in['size'])) {
        $s['size'] = max(70, min(200, (int) round((float) $in['size'] / 10) * 10));
    }
    if (in_array($in['pages'] ?? null, READER_PAGES, true)) {
        $s['pages'] = $in['pages'];
    }
    return $s;
}

function read_reading_data(): array
{
    $f = reading_file();
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    $d = is_array($d) ? $d : [];
    return [
        'settings'  => clean_reader_settings(is_array($d['settings'] ?? null) ? $d['settings'] : []),
        'books'     => is_array($d['books'] ?? null) ? $d['books'] : [],
        'bookmarks' => is_array($d['bookmarks'] ?? null) ? $d['bookmarks'] : [],
        'finished'  => is_array($d['finished'] ?? null) ? $d['finished'] : [],
    ];
}

/** Position saved for one book, or null. */
function reading_position(int $bookId): ?array
{
    $p = read_reading_data()['books'][(string) $bookId] ?? null;
    return is_array($p) && !empty($p['cfi']) ? $p : null;
}

/**
 * Change the saved data under an exclusive lock, so two devices saving at the
 * same moment can't clobber each other's books.
 */
function update_reading_data(callable $change): void
{
    ensure_data_dir();
    $fh = fopen(reading_file(), 'c+');
    if (!$fh) {
        throw new RuntimeException('Cannot open the reading file in the data folder.');
    }
    try {
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $d = json_decode((string) $raw, true);
        $d = is_array($d) ? $d : [];
        $d['settings'] = clean_reader_settings(is_array($d['settings'] ?? null) ? $d['settings'] : []);
        $d['books'] = is_array($d['books'] ?? null) ? $d['books'] : [];
        $d['finished'] = is_array($d['finished'] ?? null) ? $d['finished'] : [];
        $d = $change($d);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    @chmod(reading_file(), 0600);
}

/**
 * Books opened in the reader, most recent first.
 * @return array<int, ?float> book id => fraction read (null if not yet known)
 */
function recently_opened(int $limit): array
{
    $data = read_reading_data();
    $books = array_diff_key($data['books'], $data['finished']);
    uasort($books, fn($a, $b) => strcmp((string) ($b['updated'] ?? ''), (string) ($a['updated'] ?? '')));
    $out = [];
    foreach ($books as $id => $p) {
        if (is_array($p) && !empty($p['cfi'])) {
            $out[(int) $id] = isset($p['pct']) && is_numeric($p['pct']) ? (float) $p['pct'] : null;
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

const MAX_BOOKMARKS_PER_BOOK = 300;

/** A position in a book as epub.js writes it, e.g. epubcfi(/6/10!/4/2/1:0). */
function valid_cfi($cfi): bool
{
    return is_string($cfi) && strlen($cfi) <= 600 && preg_match('/^epubcfi\(.+\)$/sD', $cfi) === 1;
}

/** Plain one-line text of at most $max characters, for labels and snippets. */
function clean_line($s, int $max): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $s) ?? '';
    $s = trim((string) preg_replace('/\s+/u', ' ', $s));
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
}

/**
 * Bookmarks for one book, in reading order as saved.
 * Each: cfi, label (chapter), pct (fraction or null), text (opening words), created.
 */
function book_bookmarks(int $bookId): array
{
    $list = read_reading_data()['bookmarks'][(string) $bookId] ?? [];
    return is_array($list) ? array_values(array_filter($list, fn($b) => is_array($b) && valid_cfi($b['cfi'] ?? null))) : [];
}

/**
 * Books marked as read, newest first.
 * @return array<int, string> book id => date marked (ISO 8601)
 */
function finished_books(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (read_reading_data()['finished'] as $id => $f) {
            if ((int) $id > 0) {
                $cache[(int) $id] = is_array($f) ? (string) ($f['at'] ?? '') : '';
            }
        }
        arsort($cache);
    }
    return $cache;
}

/** Mark a book as read (or not). $how is 'end' when the reader got to the last page, 'manual' for the button. */
function set_finished(int $bookId, bool $done, string $how = 'manual'): void
{
    update_reading_data(function (array $d) use ($bookId, $done, $how) {
        if ($done) {
            if (!isset($d['finished'][(string) $bookId])) {
                $d['finished'][(string) $bookId] = ['at' => date('c'), 'how' => $how === 'end' ? 'end' : 'manual'];
            }
        } else {
            unset($d['finished'][(string) $bookId]);
        }
        return $d;
    });
}
