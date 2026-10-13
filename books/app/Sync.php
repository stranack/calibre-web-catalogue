<?php
declare(strict_types=1);

/**
 * Keeps data/library.db and covers/ in step with the Calibre library.
 *
 * Each run:
 *  1. asks the source whether metadata.db has changed, and downloads it if so;
 *  2. works out which covers are new, changed or gone;
 *  3. fetches covers 25 at a time until done or the time budget runs out.
 * Unfinished work carries over to the next run, so a short budget is safe.
 */
final class Sync
{
    private const BATCH = 25;

    /** @var callable */
    private $log;

    public function __construct(private LibrarySource $source, ?callable $log = null)
    {
        $this->log = $log ?? static function (string $m): void {};
    }

    public function run(float $budgetSeconds, bool $force = false): array
    {
        $started = microtime(true);
        ensure_data_dir();
        if (!is_dir(COVER_DIR) && !@mkdir(COVER_DIR, 0755, true)) {
            throw new RuntimeException('Cannot create the covers folder: ' . COVER_DIR);
        }

        $lock = fopen(DATA_DIR . '/sync.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->say('Another sync is already running. Skipping this one.');
            return ['status' => 'busy'];
        }

        $state = read_state();
        $summary = ['status' => 'ok', 'db_updated' => false, 'covers_fetched' => 0, 'covers_failed' => 0, 'covers_removed' => 0, 'pending' => 0];

        try {
            $state['last_check'] = date('c');

            // 1. Catalogue
            $version = $this->source->dbVersion();
            if ($force || $version !== ($state['db_version'] ?? null) || !is_file(LIBRARY_DB)) {
                $this->say('metadata.db has changed. Downloading it.');
                $this->replaceDb();
                $state['db_version'] = $version;
                $state['last_change'] = date('c');
                $summary['db_updated'] = true;
            } else {
                $this->say('metadata.db is unchanged.');
            }

            // 2. Which covers need work?
            $db = new PDO('sqlite:' . LIBRARY_DB, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);
            $wanted = [];
            foreach ($db->query('SELECT id, path, last_modified FROM books WHERE has_cover = 1') as $r) {
                $wanted[(int) $r['id']] = ['path' => $r['path'] . '/cover.jpg', 'v' => $r['last_modified']];
            }
            $state['book_count'] = (int) $db->query('SELECT COUNT(*) FROM books')->fetchColumn();
            $state['series_flags'] = check_series_watchlist($db);
            $db = null;

            $have = $state['covers'] ?? [];
            $failed = $state['failed'] ?? [];

            foreach (array_keys($have) as $id) {
                if (!isset($wanted[$id])) {
                    @unlink(cover_file((int) $id));
                    unset($have[$id], $failed[$id]);
                    $summary['covers_removed']++;
                }
            }
            foreach (array_keys($failed) as $id) {
                if (!isset($wanted[$id])) {
                    unset($failed[$id]);
                }
            }

            $todo = [];
            foreach ($wanted as $id => $w) {
                $current = ($have[$id] ?? null) === $w['v'] && is_file(cover_file($id));
                $gaveUp = !$force && ($failed[$id] ?? null) === $w['v'];
                if (!$current && !$gaveUp) {
                    $todo[$id] = $w;
                }
            }
            if ($todo) {
                $this->say(count($todo) . ' covers to fetch.');
            }

            // 3. Fetch in batches
            foreach (array_chunk($todo, self::BATCH, true) as $chunk) {
                if (microtime(true) - $started > $budgetSeconds) {
                    break;
                }
                $paths = [];
                foreach ($chunk as $id => $w) {
                    $paths[$id] = $w['path'];
                }
                $result = $this->source->fetchCovers(array_values($paths));
                foreach ($paths as $id => $p) {
                    $bytes = $result[$p] ?? null;
                    if ($bytes !== null && $bytes !== '' && write_atomic(cover_file($id), $bytes)) {
                        $have[$id] = $chunk[$id]['v'];
                        unset($failed[$id]);
                        $summary['covers_fetched']++;
                    } else {
                        $failed[$id] = $chunk[$id]['v'];
                        unset($have[$id]);
                        $summary['covers_failed']++;
                    }
                    unset($todo[$id]);
                }
                $state['covers'] = $have;
                $state['failed'] = $failed;
                write_state($state);
            }

            $summary['pending'] = count($todo);
            $state['covers'] = $have;
            $state['failed'] = $failed;
            $state['pending'] = $summary['pending'];
            $state['last_error'] = null;
            $state['last_success'] = date('c');
            $this->say(sprintf(
                'Done: %d covers fetched, %d unavailable, %d removed, %d still to fetch.',
                $summary['covers_fetched'], $summary['covers_failed'], $summary['covers_removed'], $summary['pending']
            ));
        } catch (Throwable $e) {
            $state['last_error'] = $e->getMessage();
            $summary['status'] = 'error';
            $summary['error'] = $e->getMessage();
            $this->say('Error: ' . $e->getMessage());
        } finally {
            write_state($state);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $summary['seconds'] = round(microtime(true) - $started, 1);
        return $summary;
    }

    private function replaceDb(): void
    {
        $tmp = DATA_DIR . '/library.db.download';
        @unlink($tmp);
        $this->source->downloadDb($tmp);

        // Read the download with our own file reader (the server's SQLite may be
        // too old to open Calibre's file) and copy the catalogue tables into a
        // plain database that any SQLite version can query.
        $build = DATA_DIR . '/library.db.build';
        @unlink($build);
        try {
            $count = build_catalogue($tmp, $build);
        } catch (Throwable $e) {
            @unlink($tmp);
            @unlink($build);
            throw new RuntimeException('The downloaded metadata.db could not be read: ' . $e->getMessage() . ' It may have been mid-save; the next run will try again.');
        }
        @unlink($tmp);
        if (!rename($build, LIBRARY_DB)) {
            throw new RuntimeException('Could not replace library.db');
        }
        @chmod(LIBRARY_DB, 0644);
        $this->say("Catalogue updated: $count books.");
    }

    private function say(string $msg): void
    {
        ($this->log)($msg);
        log_line($msg);
    }
}

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0755, true)) {
        throw new RuntimeException('Cannot create the data folder: ' . DATA_DIR);
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
}

function cover_file(int $id): string
{
    return COVER_DIR . '/' . $id . '.jpg';
}

function write_atomic(string $file, string $bytes): bool
{
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $bytes) === false) {
        return false;
    }
    @chmod($tmp, 0644);
    return rename($tmp, $file);
}

function read_state(): array
{
    if (!is_file(STATE_FILE)) {
        return [];
    }
    $s = json_decode((string) file_get_contents(STATE_FILE), true);
    if (!is_array($s)) {
        return [];
    }
    // JSON object keys come back as strings; covers are keyed by book id.
    foreach (['covers', 'failed'] as $k) {
        $fixed = [];
        foreach (($s[$k] ?? []) as $id => $v) {
            $fixed[(int) $id] = $v;
        }
        $s[$k] = $fixed;
    }
    return $s;
}

function write_state(array $state): void
{
    ensure_data_dir();
    write_atomic(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function log_line(string $msg): void
{
    $f = DATA_DIR . '/sync.log';
    if (!is_dir(DATA_DIR)) {
        return;
    }
    // Keep the log small: trim to the last ~300 lines once it passes 100 KB.
    if (is_file($f) && filesize($f) > 100_000) {
        $lines = file($f);
        file_put_contents($f, implode('', array_slice($lines, -300)));
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function recent_log(int $n = 20): array
{
    $f = DATA_DIR . '/sync.log';
    if (!is_file($f)) {
        return [];
    }
    return array_slice(file($f, FILE_IGNORE_NEW_LINES), -$n);
}

/**
 * The Calibre tables and columns the site uses. Anything else in metadata.db
 * (annotations, search indexes, plugin data) is left behind.
 */
const CATALOGUE_TABLES = [
    'books' => ['id INTEGER PRIMARY KEY', 'title TEXT COLLATE NOCASE', 'sort TEXT COLLATE NOCASE', 'timestamp TEXT', 'pubdate TEXT',
                'series_index REAL', 'author_sort TEXT COLLATE NOCASE', 'path TEXT', 'has_cover INTEGER', 'last_modified TEXT'],
    'authors' => ['id INTEGER PRIMARY KEY', 'name TEXT COLLATE NOCASE', 'sort TEXT COLLATE NOCASE'],
    'books_authors_link' => ['id INTEGER PRIMARY KEY', 'book INTEGER', 'author INTEGER'],
    'tags' => ['id INTEGER PRIMARY KEY', 'name TEXT COLLATE NOCASE'],
    'books_tags_link' => ['id INTEGER PRIMARY KEY', 'book INTEGER', 'tag INTEGER'],
    'series' => ['id INTEGER PRIMARY KEY', 'name TEXT COLLATE NOCASE', 'sort TEXT COLLATE NOCASE'],
    'books_series_link' => ['id INTEGER PRIMARY KEY', 'book INTEGER', 'series INTEGER'],
    'publishers' => ['id INTEGER PRIMARY KEY', 'name TEXT COLLATE NOCASE', 'sort TEXT COLLATE NOCASE'],
    'books_publishers_link' => ['id INTEGER PRIMARY KEY', 'book INTEGER', 'publisher INTEGER'],
    'ratings' => ['id INTEGER PRIMARY KEY', 'rating INTEGER'],
    'books_ratings_link' => ['id INTEGER PRIMARY KEY', 'book INTEGER', 'rating INTEGER'],
    'comments' => ['id INTEGER PRIMARY KEY', 'book INTEGER', 'text TEXT'],
    'identifiers' => ['id INTEGER PRIMARY KEY', 'book INTEGER', 'type TEXT', 'val TEXT'],
    'languages' => ['id INTEGER PRIMARY KEY', 'lang_code TEXT'],
    'books_languages_link' => ['id INTEGER PRIMARY KEY', 'book INTEGER', 'lang_code INTEGER', 'item_order INTEGER'],
    'data' => ['id INTEGER PRIMARY KEY', 'book INTEGER', 'format TEXT', 'uncompressed_size INTEGER', 'name TEXT'],
];

const CATALOGUE_INDEXES = [
    'CREATE INDEX books_timestamp ON books (timestamp)',
    'CREATE INDEX books_sort ON books (sort COLLATE NOCASE)',
    'CREATE INDEX bal_book ON books_authors_link (book)',
    'CREATE INDEX bal_author ON books_authors_link (author)',
    'CREATE INDEX btl_book ON books_tags_link (book)',
    'CREATE INDEX btl_tag ON books_tags_link (tag)',
    'CREATE INDEX bsl_book ON books_series_link (book)',
    'CREATE INDEX bsl_series ON books_series_link (series)',
    'CREATE INDEX bpl_book ON books_publishers_link (book)',
    'CREATE INDEX bpl_publisher ON books_publishers_link (publisher)',
    'CREATE INDEX brl_book ON books_ratings_link (book)',
    'CREATE INDEX bll_book ON books_languages_link (book)',
    'CREATE INDEX comments_book ON comments (book)',
    'CREATE INDEX identifiers_book ON identifiers (book)',
    'CREATE INDEX data_book ON data (book)',
];

/**
 * Compares each watched author's current book count (in the freshly-synced
 * catalogue) against the count recorded the last time their series metadata
 * was reviewed (app/series_watchlist.json). A higher count means a book was
 * added since that review and may belong in the series but isn't tagged yet.
 *
 * This only reads and reports - it never changes any data. Fixing a flagged
 * book's series metadata is still a manual step in Calibre.
 */
function check_series_watchlist(PDO $db): array
{
    $file = APP_DIR . '/series_watchlist.json';
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    $authors = $data['authors'] ?? [];
    if (!$authors) {
        return [];
    }

    $flags = [];
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM books_authors_link bal
         JOIN authors a ON a.id = bal.author
         WHERE a.name = :name COLLATE NOCASE'
    );
    foreach ($authors as $entry) {
        $name = (string) ($entry['name'] ?? '');
        $baseline = (int) ($entry['book_count'] ?? 0);
        if ($name === '') {
            continue;
        }
        $stmt->execute(['name' => $name]);
        $current = (int) $stmt->fetchColumn();
        if ($current > $baseline) {
            $flags[] = [
                'name' => $name,
                'series' => (string) ($entry['series'] ?? ''),
                'baseline' => $baseline,
                'current' => $current,
                'added' => $current - $baseline,
                'note' => $entry['note'] ?? null,
            ];
        }
    }
    return $flags;
}

/** Copy the catalogue tables from Calibre's metadata.db into a fresh database. Returns the book count. */
function build_catalogue(string $calibreDb, string $dest): int
{
    $reader = new SqliteFileReader($calibreDb);
    if (!$reader->hasTable('books')) {
        throw new RuntimeException('There is no books table, so this does not look like a Calibre library.');
    }
    $defaults = [
        'books' => ['title' => 'Unknown', 'series_index' => 1.0, 'has_cover' => 0, 'last_modified' => '2000-01-01 00:00:00+00:00'],
    ];

    $out = new PDO('sqlite:' . $dest, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $out->exec('PRAGMA journal_mode = OFF');
    $out->exec('PRAGMA synchronous = OFF');
    $out->beginTransaction();
    $books = 0;
    foreach (CATALOGUE_TABLES as $table => $defs) {
        $cols = array_map(fn($d) => strtok($d, ' '), $defs);
        $out->exec("CREATE TABLE $table (" . implode(', ', $defs) . ')');
        if (!$reader->hasTable($table)) {
            continue;
        }
        $have = array_flip($reader->columns($table));
        $ins = $out->prepare("INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . rtrim(str_repeat('?, ', count($cols)), ', ') . ')');
        foreach ($reader->rows($table) as $row) {
            $vals = [];
            foreach ($cols as $c) {
                $v = isset($have[$c]) ? $row[$c] : null;
                if ($v === null && isset($defaults[$table][$c])) {
                    $v = $defaults[$table][$c];
                }
                $vals[] = $v;
            }
            $ins->execute($vals);
            if ($table === 'books') {
                $books++;
            }
        }
    }
    foreach (CATALOGUE_INDEXES as $sql) {
        $out->exec($sql);
    }
    $out->commit();
    $ok = $out->query('PRAGMA integrity_check')->fetchColumn();
    $out = null;
    if ($ok !== 'ok') {
        throw new RuntimeException('The rebuilt catalogue failed its integrity check.');
    }
    if ($books === 0) {
        throw new RuntimeException('No books were found.');
    }
    return $books;
}
