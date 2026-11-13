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

/**
 * The file's raw contents, read under a shared lock so a read can't land in
 * the middle of a write and come back as half a file.
 */
function read_reading_raw(): string
{
    $f = reading_file();
    if (!is_file($f)) {
        return '';
    }
    $fh = @fopen($f, 'r');
    if (!$fh) {
        return '';
    }
    try {
        flock($fh, LOCK_SH);
        $raw = (string) stream_get_contents($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    return $raw;
}

function read_reading_data(): array
{
    $d = json_decode(read_reading_raw(), true);
    $d = is_array($d) ? $d : [];
    return [
        'settings'  => clean_reader_settings(is_array($d['settings'] ?? null) ? $d['settings'] : []),
        'books'     => is_array($d['books'] ?? null) ? $d['books'] : [],
        'bookmarks' => is_array($d['bookmarks'] ?? null) ? $d['bookmarks'] : [],
        'notes'     => is_array($d['notes'] ?? null) ? $d['notes'] : [],
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
        $d['notes'] = is_array($d['notes'] ?? null) ? $d['notes'] : [];
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

const READING_BACKUPS = 14;

/**
 * Keep a dated copy of reading.json.
 *
 * Positions and finished dates could be rebuilt by reading again, but
 * highlights and their notes are the one thing in this app that exists
 * nowhere else - not in Calibre, not in the EPUB. One copy a day, the last
 * fortnight kept, and nothing written when the day's copy already matches.
 */
function backup_reading(): void
{
    $raw = read_reading_raw();
    if (trim($raw) === '') {
        return;
    }
    $dir = DATA_DIR . '/reading-backups';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return;
    }
    $today = $dir . '/reading-' . date('Y-m-d') . '.json';
    if (is_file($today) && @file_get_contents($today) === $raw) {
        return;
    }
    $tmp = $today . '.new';
    if (@file_put_contents($tmp, $raw, LOCK_EX) === false) {
        return;
    }
    @chmod($tmp, 0600);
    // Rename so a reader never sees a half-written backup.
    @rename($tmp, $today);

    $old = glob($dir . '/reading-*.json') ?: [];
    sort($old);
    foreach (array_slice($old, 0, max(0, count($old) - READING_BACKUPS)) as $f) {
        @unlink($f);
    }
}

const MAX_NOTES_PER_BOOK = 500;
const MAX_NOTE_CHARS = 2000;

/**
 * A stretch of text as epub.js writes it for a selection, e.g.
 * epubcfi(/6/10!/4/2,/1:0,/3:48) - one CFI with a start and an end in it.
 * A plain position would highlight nothing, so insist on the comma.
 */
function valid_range_cfi($cfi): bool
{
    return is_string($cfi) && strlen($cfi) <= 1200
        && preg_match('/^epubcfi\(.+,.+,.+\)$/sD', $cfi) === 1;
}

/**
 * A passage or a comment: longer than a label, and kept as the reader typed
 * it apart from control characters and a cap on length. Paragraph breaks in a
 * comment are worth keeping, so only tabs and stray control codes go.
 */
function clean_note_text($s, int $max = MAX_NOTE_CHARS): string
{
    $s = preg_replace('/\r\n?/u', "\n", (string) $s) ?? '';
    $s = preg_replace('/[^\P{C}\n]+/u', ' ', $s) ?? '';
    $s = preg_replace('/[ \t]+/u', ' ', $s) ?? '';
    $s = trim((string) preg_replace('/\n{3,}/u', "\n\n", $s));
    return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
}

/**
 * Highlights for one book, in the order saved.
 * Each: cfi (a range), text (the passage), note (the reader's comment, may be
 * empty), label (chapter), pct (fraction or null), created, updated.
 */
function book_notes(int $bookId): array
{
    $list = read_reading_data()['notes'][(string) $bookId] ?? [];
    return is_array($list)
        ? array_values(array_filter($list, fn($n) => is_array($n) && valid_range_cfi($n['cfi'] ?? null)))
        : [];
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
