<?php
declare(strict_types=1);
/**
 * "Suggested reading" on the home page: books that aren't in the library but
 * sit close to it, found in Open Library (openlibrary.org, run by the
 * Internet Archive; free, no account or key).
 *
 * Three kinds, mixed together:
 *   - New from an author: an author whose series you keep, with a book first
 *     published after the newest one you have.
 *   - More by an author: other well-known books by the authors you have most
 *     of, or have been reading.
 *   - On a subject: widely held books on the library's biggest subjects.
 *
 * The sync does the work (refresh_suggestions), a few lookups per run within
 * whatever time it has left, and keeps each answer for two weeks, so the
 * home page only reads data/suggestions.json. Covers are saved in
 * covers/suggest/ so they load from this site like the library's own.
 */

const SUGGEST_FILE = DATA_DIR . '/suggestions.json';
const SUGGEST_CACHE = DATA_DIR . '/suggest-cache.json';
const SUGGEST_COVER_DIR = COVER_DIR . '/suggest';
const SUGGEST_TTL = 14 * 86400;     // how long one lookup's answer is kept
const SUGGEST_KEEP = 60;            // picks stored; the page shows a daily selection
const SUGGEST_SHOW = 18;
const SUGGEST_AUTHORS = 10;
const SUGGEST_SUBJECTS = 6;

/** Words in a title that mark a bundle or study aid rather than a book to read. */
const SUGGEST_SKIP_TITLE = '/\b(box(ed)? set|boxset|collection|omnibus|books? \d|\d+[- ]book|trilogy set|summary|study guide|sparknotes|cliffsnotes|untitled|calendar)\b/i';

function suggest_api_base(): string
{
    return rtrim((string) cfg('openlibrary_base', 'https://openlibrary.org'), '/');
}

function suggest_cover_base(): string
{
    return rtrim((string) cfg('openlibrary_covers_base', 'https://covers.openlibrary.org'), '/');
}

/** A GET with a short time limit. Returns [status, body]; status 0 on a network error. */
function suggest_http_get(string $url, int $timeout = 10): array
{
    if (!function_exists('curl_init')) {
        return [0, ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $timeout,
        // Open Library asks heavy users to identify themselves.
        CURLOPT_USERAGENT      => 'HomeLibraryCatalogue/1.0 (+https://github.com/stranack/calibre-web-catalogue)',
    ]);
    $body = curl_exec($ch);
    $status = $body === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $body === false ? '' : (string) $body];
}

/** A title reduced to what matters for "do we already have this?". */
function suggest_norm_title(string $t): string
{
    $t = mb_strtolower($t);
    $t = preg_replace('/\s*[:(\[].*$/u', '', $t);               // drop subtitles
    $t = preg_replace('/^(the|a|an)\s+/u', '', $t);
    $t = preg_replace('/[^\p{L}\p{N}]+/u', '', $t);
    return (string) $t;
}

function suggest_norm_name(string $n): string
{
    return (string) preg_replace('/[^\p{L}]+/u', '', mb_strtolower($n));
}

/**
 * What the suggestions grow from: authors and subjects in the library.
 * Returns ['authors' => [name => ['newer_than' => year|null]], 'subjects' => [label => query]].
 */
function suggest_seeds(PDO $db): array
{
    $authors = [];
    // The authors you have most books by.
    $sql = 'SELECT a.name, COUNT(*) AS n FROM books_authors_link l JOIN authors a ON a.id = l.author
            GROUP BY a.id ORDER BY n DESC, a.name LIMIT ' . SUGGEST_AUTHORS;
    foreach ($db->query($sql) as $r) {
        $authors[(string) $r['name']] = ['newer_than' => null];
    }
    // And the authors of what you've been reading lately.
    $ids = array_slice(array_keys(recently_opened(12) + finished_books()), 0, 12);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach ($db->query("SELECT DISTINCT a.name FROM books_authors_link l JOIN authors a ON a.id = l.author WHERE l.book IN ($in)") as $r) {
            if (count($authors) >= SUGGEST_AUTHORS + 6) {
                break;
            }
            $authors[(string) $r['name']] ??= ['newer_than' => null];
        }
    }
    // Authors with a series here: anything newer than their newest book here may be the next one.
    $sql = "SELECT a.name, MAX(substr(b.pubdate, 1, 4)) AS y FROM books b
            JOIN books_series_link s ON s.book = b.id
            JOIN books_authors_link l ON l.book = b.id JOIN authors a ON a.id = l.author
            GROUP BY a.id";
    foreach ($db->query($sql) as $r) {
        $y = (int) $r['y'];
        if (isset($authors[(string) $r['name']]) && $y > 1000) {
            $authors[(string) $r['name']]['newer_than'] = $y;
        }
    }

    // The biggest subjects. Tags here are two-level ("Fiction.Historical");
    // Open Library's subjects are plain phrases, so "Fiction.Historical"
    // becomes "historical fiction" and "History.Europe" becomes "europe history".
    $subjects = [];
    $sql = 'SELECT t.name, COUNT(*) AS n FROM books_tags_link l JOIN tags t ON t.id = l.tag
            GROUP BY t.id ORDER BY n DESC, t.name LIMIT 40';
    foreach ($db->query($sql) as $r) {
        $parts = array_values(array_filter(array_map('trim', explode('.', (string) $r['name']))));
        if (!$parts || count($subjects) >= SUGGEST_SUBJECTS) {
            continue;
        }
        if (count($parts) === 1) {
            if (strcasecmp($parts[0], 'Fiction') === 0) {
                continue; // far too broad on its own
            }
            $label = $parts[0];
            $query = $parts[0];
        } else {
            $broad = $parts[0];
            $narrow = end($parts);
            $label = $narrow . ' (' . $broad . ')';
            $query = stripos($broad, 'fiction') !== false ? "$narrow fiction" : "$narrow $broad";
        }
        $query = trim(preg_replace('/[&\/]+/', ' ', mb_strtolower($query)));
        $subjects[$label] = $query;
    }
    return ['authors' => $authors, 'subjects' => $subjects];
}

/** The lookups the seeds call for: cache key => URL. */
function suggest_queries(array $seeds): array
{
    $base = suggest_api_base() . '/search.json?';
    $fields = 'key,title,author_name,first_publish_year,cover_i,edition_count';
    $q = [];
    foreach ($seeds['authors'] as $name => $_) {
        $q['author:' . $name] = $base . http_build_query(['author' => $name, 'fields' => $fields, 'limit' => 40, 'lang' => 'en']);
    }
    foreach ($seeds['subjects'] as $label => $query) {
        $q['subject:' . $label] = $base . http_build_query(['subject' => $query, 'fields' => $fields, 'limit' => 40, 'lang' => 'en']);
    }
    return $q;
}

/** Keep only what's needed from an Open Library search answer. */
function suggest_parse(string $body): ?array
{
    $j = json_decode($body, true);
    if (!is_array($j) || !isset($j['docs']) || !is_array($j['docs'])) {
        return null;
    }
    $out = [];
    foreach ($j['docs'] as $d) {
        if (!is_array($d) || empty($d['title'])) {
            continue;
        }
        $out[] = [
            'key'     => (string) ($d['key'] ?? ''),
            'title'   => (string) $d['title'],
            'author'  => (string) (($d['author_name'] ?? [])[0] ?? ''),
            'year'    => isset($d['first_publish_year']) ? (int) $d['first_publish_year'] : null,
            'cover'   => isset($d['cover_i']) ? (int) $d['cover_i'] : null,
            'editions' => (int) ($d['edition_count'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Bring the suggestions up to date, stopping at $deadline (a microtime).
 * Returns a short summary for the sync log.
 */
function refresh_suggestions(PDO $db, float $deadline, ?callable $say = null): array
{
    $say ??= static function (string $m): void {};
    $cache = is_file(SUGGEST_CACHE) ? (json_decode((string) file_get_contents(SUGGEST_CACHE), true) ?: []) : [];
    $cache['queries'] = is_array($cache['queries'] ?? null) ? $cache['queries'] : [];

    $seeds = suggest_seeds($db);
    $queries = suggest_queries($seeds);

    // Answers for seeds that have gone are no use.
    $cache['queries'] = array_intersect_key($cache['queries'], $queries);

    // Look up what's missing first, then what's oldest.
    $order = array_keys($queries);
    usort($order, fn($a, $b) => (int) ($cache['queries'][$a]['at'] ?? 0) <=> (int) ($cache['queries'][$b]['at'] ?? 0));
    $fetched = 0;
    $failed = 0;
    foreach ($order as $key) {
        $at = (int) ($cache['queries'][$key]['at'] ?? 0);
        if (time() - $at < SUGGEST_TTL) {
            continue;
        }
        if (microtime(true) > $deadline - 5) {
            break;
        }
        [$status, $body] = suggest_http_get($queries[$key]);
        $docs = $status === 200 ? suggest_parse($body) : null;
        if ($docs === null) {
            $failed++;
            if ($failed >= 3) {
                break; // Open Library is down or unreachable: try next run
            }
            continue;
        }
        $cache['queries'][$key] = ['at' => time(), 'docs' => $docs];
        $fetched++;
    }
    $cache['updated'] = date('c');
    write_atomic(SUGGEST_CACHE, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $picks = suggest_pick($db, $seeds, $cache['queries']);
    $covers = suggest_save_covers($picks, $deadline);
    write_atomic(SUGGEST_FILE, json_encode([
        'built'  => date('c'),
        'source' => 'Open Library',
        'picks'  => $picks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $pending = 0;
    foreach ($queries as $key => $_) {
        if (time() - (int) ($cache['queries'][$key]['at'] ?? 0) >= SUGGEST_TTL) {
            $pending++;
        }
    }
    $say(sprintf('Suggested reading: %d lookups made, %d failed, %d still to do; %d books suggested, %d covers saved.',
        $fetched, $failed, $pending, count($picks), $covers));
    return ['fetched' => $fetched, 'failed' => $failed, 'pending' => $pending, 'picks' => count($picks)];
}

/** Choose the suggestions from the cached answers, mixing the three kinds. */
function suggest_pick(PDO $db, array $seeds, array $answers): array
{
    // What the library already has, by title, and who it has.
    $haveTitles = [];
    foreach ($db->query('SELECT title FROM books') as $r) {
        $haveTitles[suggest_norm_title((string) $r['title'])] = true;
    }
    $haveAuthors = [];
    foreach ($db->query('SELECT name FROM authors') as $r) {
        $haveAuthors[suggest_norm_name((string) $r['name'])] = true;
    }

    $usable = function (array $d) use ($haveTitles): bool {
        $t = suggest_norm_title($d['title']);
        return $t !== '' && !isset($haveTitles[$t]) && !empty($d['cover']) && $d['author'] !== ''
            && !preg_match(SUGGEST_SKIP_TITLE, $d['title']);
    };

    $newer = [];
    $more = [];
    $subject = [];
    foreach ($seeds['authors'] as $name => $meta) {
        $docs = $answers['author:' . $name]['docs'] ?? [];
        // Only books really by this author, not ones that merely mention them.
        $docs = array_values(array_filter($docs, fn($d) => suggest_norm_name($d['author']) === suggest_norm_name($name) && $usable($d)));
        $since = $meta['newer_than'];
        $fresh = $since ? array_values(array_filter($docs, fn($d) => ($d['year'] ?? 0) > $since)) : [];
        usort($fresh, fn($a, $b) => ($a['year'] ?? 0) <=> ($b['year'] ?? 0)); // the next one first
        foreach (array_slice($fresh, 0, 2) as $d) {
            $newer[] = $d + ['reason' => 'New from ' . $name, 'kind' => 'new'];
        }
        $taken = array_column($fresh, 'key');
        $rest = array_values(array_filter($docs, fn($d) => !in_array($d['key'], array_slice($taken, 0, 2), true) && $d['editions'] >= 2));
        usort($rest, fn($a, $b) => $b['editions'] <=> $a['editions']);
        foreach (array_slice($rest, 0, 3) as $d) {
            $more[] = $d + ['reason' => 'More by ' . $name, 'kind' => 'author'];
        }
    }
    foreach ($seeds['subjects'] as $label => $_) {
        $docs = $answers['subject:' . $label]['docs'] ?? [];
        // New voices: skip authors the library already has (they're covered above).
        $docs = array_values(array_filter($docs, fn($d) => $usable($d) && $d['editions'] >= 3 && !isset($haveAuthors[suggest_norm_name($d['author'])])));
        usort($docs, fn($a, $b) => $b['editions'] <=> $a['editions']);
        $perAuthor = [];
        $n = 0;
        foreach ($docs as $d) {
            $a = suggest_norm_name($d['author']);
            if (($perAuthor[$a] ?? 0) >= 1 || $n >= 6) {
                continue;
            }
            $perAuthor[$a] = 1;
            $n++;
            $subject[] = $d + ['reason' => 'On ' . $label, 'kind' => 'subject'];
        }
    }

    // Interleave the three kinds so the shelf is a mix, then drop repeats.
    $out = [];
    $seen = [];
    $lists = [$newer, $more, $subject];
    for ($i = 0; count($out) < SUGGEST_KEEP && $i < 200; $i++) {
        $any = false;
        foreach ($lists as &$l) {
            $d = array_shift($l);
            if ($d === null) {
                continue;
            }
            $any = true;
            $k = suggest_norm_title($d['title']);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $d;
        }
        unset($l);
        if (!$any) {
            break;
        }
    }
    return $out;
}

function suggest_cover_file(int $coverId): string
{
    return SUGGEST_COVER_DIR . '/' . $coverId . '.jpg';
}

/** Save the picks' covers here, and remove ones no longer needed. Returns how many were saved this run. */
function suggest_save_covers(array $picks, float $deadline): int
{
    if (!is_dir(SUGGEST_COVER_DIR) && !@mkdir(SUGGEST_COVER_DIR, 0755, true)) {
        return 0;
    }
    $want = [];
    foreach ($picks as $p) {
        if (!empty($p['cover'])) {
            $want[(int) $p['cover']] = true;
        }
    }
    foreach (glob(SUGGEST_COVER_DIR . '/*.jpg') ?: [] as $f) {
        if (!isset($want[(int) basename($f, '.jpg')])) {
            @unlink($f);
        }
    }
    $saved = 0;
    foreach (array_keys($want) as $id) {
        if (is_file(suggest_cover_file($id))) {
            continue;
        }
        if (microtime(true) > $deadline - 3) {
            break;
        }
        [$status, $body] = suggest_http_get(suggest_cover_base() . '/b/id/' . $id . '-M.jpg', 10);
        // Open Library sends a tiny placeholder for a missing cover.
        if ($status === 200 && strlen($body) > 1000 && strncmp($body, "\xFF\xD8", 2) === 0) {
            write_atomic(suggest_cover_file($id), $body);
            $saved++;
        }
    }
    return $saved;
}

/** Today's selection for the home page: a different mix each day, same all day. */
function suggestions_for_today(): array
{
    if (!is_file(SUGGEST_FILE)) {
        return [];
    }
    $data = json_decode((string) file_get_contents(SUGGEST_FILE), true);
    $picks = is_array($data['picks'] ?? null) ? $data['picks'] : [];
    // Every pick has an Open Library cover; it's shown from this site once
    // saved here, and straight from Open Library until then.
    $picks = array_values(array_filter($picks, fn($p) => !empty($p['cover'])));
    if (!$picks) {
        return [];
    }
    $byKind = [];
    foreach ($picks as $p) {
        $byKind[$p['kind'] ?? 'other'][] = $p;
    }
    mt_srand(crc32(date('Y-m-d')));
    foreach ($byKind as &$list) {
        shuffle($list);
    }
    unset($list);
    $out = [];
    while (count($out) < SUGGEST_SHOW && $byKind) {
        foreach (array_keys($byKind) as $k) {
            $p = array_shift($byKind[$k]);
            if ($p === null) {
                unset($byKind[$k]);
                continue;
            }
            $out[] = $p;
            if (count($out) >= SUGGEST_SHOW) {
                break;
            }
        }
    }
    mt_srand();
    return $out;
}

/** Where a suggestion leads: an Amazon.ca book search for its title and author. */
function suggestion_url(array $p): string
{
    return 'https://www.amazon.ca/s?' . http_build_query([
        'k' => trim($p['title'] . ' ' . $p['author']),
        'i' => 'stripbooks',
    ]);
}

function suggestion_card(array $p): string
{
    $id = (int) $p['cover'];
    $img = is_file(suggest_cover_file($id))
        ? 'covers/suggest/' . $id . '.jpg'
        : suggest_cover_base() . '/b/id/' . $id . '-M.jpg';
    return '<li class="card card-suggest"><a href="' . h(suggestion_url($p)) . '" target="_blank" rel="noopener noreferrer nofollow">'
        . '<span class="cover"><img src="' . h($img) . '" alt="" loading="lazy" decoding="async"></span>'
        . '<span class="card-title">' . h($p['title']) . '</span>'
        . '<span class="card-author">' . h($p['author']) . (!empty($p['year']) ? ' · ' . (int) $p['year'] : '') . '</span>'
        . '<span class="card-reason">' . h($p['reason']) . '</span>'
        . '<span class="visually-hidden"> (opens Amazon.ca in a new tab)</span>'
        . '</a></li>';
}
