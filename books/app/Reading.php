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
        'settings' => clean_reader_settings(is_array($d['settings'] ?? null) ? $d['settings'] : []),
        'books'    => is_array($d['books'] ?? null) ? $d['books'] : [],
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
