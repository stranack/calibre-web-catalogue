<?php
declare(strict_types=1);

function cfg(string $key, $default = null)
{
    return $GLOBALS['CONFIG'][$key] ?? $default;
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Relative link with a query string, dropping empty values. */
function link_to(string $page, array $params = []): string
{
    $params = array_filter($params, fn($v) => $v !== null && $v !== '' && $v !== 0 && $v !== false);
    if (($params['page'] ?? null) === 1) {
        unset($params['page']);
    }
    return $page . ($params ? '?' . http_build_query($params) : '');
}

/**
 * The site owner is recognised by a long-lived cookie set when signing in to
 * admin.php. It is derived from the admin password, so changing the password
 * signs out every browser.
 */
function owner_token(): string
{
    $p = (string) cfg('admin_password', '');
    return strlen($p) < 8 ? '' : hash_hmac('sha256', 'library-owner-v1', $p);
}

function is_owner(): bool
{
    $t = owner_token();
    return $t !== '' && hash_equals($t, (string) ($_COOKIE['library_owner'] ?? ''));
}

function set_owner_cookie(bool $on): void
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    setcookie('library_owner', $on ? owner_token() : '', [
        'expires'  => $on ? time() + 365 * 86400 : time() - 3600,
        'path'     => $dir,
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Whether book pages should show "open in Dropbox" links to this visitor. */
function show_dropbox_links(): bool
{
    if (!empty(cfg('local_library_path'))) {
        return false;
    }
    $mode = (string) cfg('dropbox_links', 'owner');
    return $mode === 'everyone' || ($mode === 'owner' && is_owner());
}

/**
 * Address of a folder (or a file within it) on the Dropbox website.
 * $relPath is relative to the Calibre library, e.g. "Will Ferguson/Road Trip Rwanda (1414)".
 */
function dropbox_web_url(string $relPath, ?string $file = null): string
{
    $full = trim((string) cfg('dropbox_library_path', ''), '/') . '/' . trim($relPath, '/');
    $parts = array_map('rawurlencode', array_filter(explode('/', $full), fn($x) => $x !== ''));
    $url = 'https://www.dropbox.com/home/' . implode('/', $parts);
    return $file === null ? $url : $url . '?preview=' . rawurlencode($file);
}

/**
 * Address of file.php for one of a book's formats. The file itself is only
 * ever handed to the signed-in owner; file.php checks that on every request.
 */
function book_file_url(array $b, string $format): string
{
    return link_to('file.php', ['id' => (int) $b['id'], 'format' => strtoupper($format)]);
}

/**
 * Media type for an ebook format. Sending the right type is what lets a
 * tablet offer the matching reader app (Moon+ Reader, Apple Books and so on)
 * when the download finishes.
 */
function ebook_mime(string $format): string
{
    return [
        'EPUB' => 'application/epub+zip',
        'KEPUB' => 'application/epub+zip',
        'PDF'  => 'application/pdf',
        'MOBI' => 'application/x-mobipocket-ebook',
        'AZW'  => 'application/vnd.amazon.ebook',
        'AZW3' => 'application/vnd.amazon.ebook',
        'FB2'  => 'application/x-fictionbook+xml',
        'CBZ'  => 'application/vnd.comicbook+zip',
        'CBR'  => 'application/vnd.comicbook-rar',
        'DJVU' => 'image/vnd.djvu',
        'RTF'  => 'application/rtf',
        'TXT'  => 'text/plain; charset=utf-8',
        'DOCX' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ][strtoupper($format)] ?? 'application/octet-stream';
}

function cover_url(array $b): ?string
{
    if (empty($b['has_cover'])) {
        return null;
    }
    $f = cover_file((int) $b['id']);
    if (!is_file($f)) {
        return null;
    }
    return 'covers/' . (int) $b['id'] . '.jpg?v=' . substr(md5((string) $b['last_modified']), 0, 8);
}

/**
 * Split a Calibre tag into its broad heading and narrower heading.
 * "History.Canada" gives ["History", "Canada"]; "Poetry" gives ["Poetry", null].
 */
function split_subject(string $tag): array
{
    $pos = strpos($tag, '.');
    if ($pos === false) {
        return [trim($tag), null];
    }
    $narrow = trim(substr($tag, $pos + 1));
    return [trim(substr($tag, 0, $pos)), $narrow === '' ? null : $narrow];
}

/** "History.Canada" becomes "History › Canada" for display. */
function subject_label(string $tag): string
{
    [$broad, $narrow] = split_subject($tag);
    return $narrow === null ? $broad : $broad . ' › ' . $narrow;
}

function author_names(array $b, int $max = 3): string
{
    $names = array_column($b['authors'] ?? [], 'name');
    if (count($names) > $max) {
        return implode(', ', array_slice($names, 0, $max)) . ' and others';
    }
    if (count($names) > 1) {
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }
    return $names[0] ?? 'Unknown author';
}

/** The cover image, or a cloth-bound stand-in when there isn't one yet. */
function cover_html(array $b, bool $detail = false): string
{
    $url = cover_url($b);
    if ($url) {
        $alt = $detail ? 'Cover of ' . $b['title'] : '';
        return '<img src="' . h($url) . '" alt="' . h($alt) . '" loading="' . ($detail ? 'eager' : 'lazy') . '" decoding="async">';
    }
    $cloth = ((int) $b['id'] * 7) % 6;
    return '<span class="blank cloth-' . $cloth . '"' . ($detail ? '' : ' aria-hidden="true"') . '>'
        . '<span class="blank-title">' . h(mb_strimwidth((string) $b['title'], 0, 70, '…')) . '</span>'
        . '<span class="blank-author">' . h(author_names($b, 1)) . '</span>'
        . '</span>';
}

function book_card(array $b): string
{
    $series = '';
    if (!empty($b['series_name'])) {
        $series = '<span class="card-series">' . h($b['series_name']) . ' ' . h(series_number($b['series_index'])) . '</span>';
    }
    return '<li class="card"><a href="' . h(link_to('book.php', ['id' => (int) $b['id']])) . '">'
        . '<span class="cover">' . cover_html($b) . '</span>'
        . '<span class="card-title">' . h($b['title']) . '</span>'
        . '<span class="card-author">' . h(author_names($b, 2)) . '</span>'
        . $series
        . '</a></li>';
}

function series_number($idx): string
{
    $f = (float) $idx;
    return floor($f) == $f ? (string) (int) $f : rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
}

/** Calibre stores "no date" as the year 101. */
function pub_year(?string $d): ?string
{
    if (!$d) {
        return null;
    }
    $y = (int) substr($d, 0, 4);
    return $y > 1000 ? (string) $y : null;
}

function nice_date(?string $d): ?string
{
    if (!$d || (int) substr($d, 0, 4) <= 1000) {
        return null;
    }
    $t = strtotime($d);
    return $t ? date('F j, Y', $t) : null;
}

function language_name(string $code): string
{
    static $names = [
        'eng' => 'English', 'fra' => 'French', 'fre' => 'French', 'spa' => 'Spanish', 'deu' => 'German', 'ger' => 'German',
        'ita' => 'Italian', 'por' => 'Portuguese', 'nld' => 'Dutch', 'swe' => 'Swedish', 'nor' => 'Norwegian', 'dan' => 'Danish',
        'fin' => 'Finnish', 'isl' => 'Icelandic', 'jpn' => 'Japanese', 'zho' => 'Chinese', 'pli' => 'Pali', 'san' => 'Sanskrit',
        'tha' => 'Thai', 'rus' => 'Russian', 'pol' => 'Polish', 'und' => '',
    ];
    if (class_exists('Locale')) {
        $n = Locale::getDisplayLanguage($code, 'en');
        if ($n && $n !== $code) {
            return $n;
        }
    }
    return $names[$code] ?? $code;
}

/**
 * Calibre descriptions are HTML from many sources (publishers, Amazon, Google).
 * Keep simple formatting only: no links, images, styles or scripts.
 */
function clean_description(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    if (strip_tags($html) === $html) {
        $paras = preg_split('/\n\s*\n/u', $html) ?: [];
        return implode('', array_map(fn($p) => '<p>' . nl2br(h(trim($p))) . '</p>', $paras));
    }
    if (!class_exists('DOMDocument')) {
        return '<p>' . nl2br(h(strip_tags($html))) . '</p>';
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementsByTagName('div')->item(0);
    $out = $root ? clean_node_children($root) : h(strip_tags($html));
    // Many descriptions use line breaks instead of paragraphs; turn those into real paragraphs.
    if (!preg_match('#<(p|ul|ol|blockquote|h3|h4)\b#', $out)) {
        $parts = preg_split('#(\s*<br>\s*)+#', $out) ?: [$out];
        $out = implode('', array_map(fn($p) => '<p>' . trim($p) . '</p>', $parts));
    }
    // Drop empty paragraphs left behind by cleaning.
    return preg_replace('#<p>(\s|<br>)*</p>#u', '', $out) ?? $out;
}

function clean_node_children(DOMNode $node): string
{
    static $keep = [
        'p' => 'p', 'div' => 'p', 'br' => 'br', 'b' => 'strong', 'strong' => 'strong', 'i' => 'em', 'em' => 'em',
        'cite' => 'em', 'ul' => 'ul', 'ol' => 'ol', 'li' => 'li', 'blockquote' => 'blockquote',
        'h1' => 'h3', 'h2' => 'h3', 'h3' => 'h3', 'h4' => 'h4', 'h5' => 'h4', 'h6' => 'h4',
    ];
    static $drop = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'img', 'svg', 'head', 'title', 'meta', 'link', 'noscript'];
    $out = '';
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            $out .= h($child->nodeValue);
        } elseif ($child instanceof DOMElement) {
            $tag = strtolower($child->tagName);
            if (in_array($tag, $drop, true)) {
                continue;
            }
            if ($tag === 'br') {
                $out .= '<br>';
                continue;
            }
            $inner = clean_node_children($child);
            if (isset($keep[$tag])) {
                $t = $keep[$tag];
                // A <p> can't sit inside another <p>; flatten nested block wrappers.
                if ($t === 'p' && preg_match('#<(p|ul|ol|blockquote|h3|h4)\b#', $inner)) {
                    $out .= $inner;
                } else {
                    $out .= "<$t>$inner</$t>";
                }
            } else {
                $out .= $inner;
            }
        }
    }
    return $out;
}

/** Outbound links for identifiers worth showing. */
function identifier_links(array $ids): array
{
    $out = [];
    if (!empty($ids['isbn'])) {
        $isbn = preg_replace('/[^0-9Xx]/', '', $ids['isbn']);
        $out[] = ['label' => 'ISBN', 'value' => $isbn, 'links' => [
            ['Open Library', 'https://openlibrary.org/isbn/' . rawurlencode($isbn)],
            ['Google Books', 'https://books.google.com/books?vid=ISBN' . rawurlencode($isbn)],
            ['WorldCat', 'https://search.worldcat.org/isbn/' . rawurlencode($isbn)],
        ]];
    }
    if (!empty($ids['doi'])) {
        $out[] = ['label' => 'DOI', 'value' => $ids['doi'], 'links' => [['doi.org', 'https://doi.org/' . $ids['doi']]]];
    }
    if (!empty($ids['goodreads']) && ctype_digit((string) $ids['goodreads'])) {
        $out[] = ['label' => 'Goodreads', 'value' => $ids['goodreads'], 'links' => [['Goodreads', 'https://www.goodreads.com/book/show/' . $ids['goodreads']]]];
    }
    return $out;
}

function pagination(int $total, int $page, int $perPage, callable $linkFor): string
{
    $pages = (int) ceil($total / $perPage);
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav class="pager" aria-label="Pages">';
    $html .= $page > 1 ? '<a rel="prev" href="' . h($linkFor($page - 1)) . '">Previous</a>' : '<span class="pager-off">Previous</span>';
    $html .= '<span class="pager-pages">';
    $shown = [];
    foreach ([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages] as $p) {
        if ($p >= 1 && $p <= $pages) {
            $shown[$p] = true;
        }
    }
    ksort($shown);
    $last = 0;
    foreach (array_keys($shown) as $p) {
        if ($p - $last > 1) {
            $html .= '<span class="pager-gap">…</span>';
        }
        $html .= $p === $page
            ? '<span class="pager-current" aria-current="page">' . $p . '</span>'
            : '<a href="' . h($linkFor($p)) . '">' . $p . '</a>';
        $last = $p;
    }
    $html .= '</span>';
    $html .= $page < $pages ? '<a rel="next" href="' . h($linkFor($page + 1)) . '">Next</a>' : '<span class="pager-off">Next</span>';
    return $html . '</nav>';
}

function page_top(string $title, array $opts = []): void
{
    $site = (string) cfg('site_title', 'Library');
    $full = $title === '' ? $site : "$title – $site";
    $q = (string) ($opts['q'] ?? '');
    $active = $opts['active'] ?? '';
    $showSearch = $opts['search'] ?? true;
    $bodyClass = $opts['class'] ?? '';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($full) ?></title>
<?php if (!cfg('allow_search_engines') || !empty($opts['noindex'])): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Literata:opsz,wght@7..72,400;7..72,500;7..72,600&family=Public+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="assets/style.css?v=<?= h((string) @filemtime(APP_ROOT . '/assets/style.css')) ?>">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
</head>
<body class="<?= h($bodyClass) ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="masthead">
  <div class="masthead-inner">
    <a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true"></span><?= h($site) ?></a>
    <?php if ($showSearch): ?>
    <form class="search search-compact" action="index.php" method="get" role="search">
      <label class="visually-hidden" for="q-top">Search the library</label>
      <input id="q-top" name="q" type="search" value="<?= h($q) ?>" placeholder="Search titles, authors, subjects" autocomplete="off">
      <button type="submit" aria-label="Search"><?= search_icon() ?></button>
    </form>
    <?php endif; ?>
    <nav class="mainnav" aria-label="Browse">
      <a href="browse.php?by=authors"<?= $active === 'authors' ? ' aria-current="page"' : '' ?>>Authors</a>
      <a href="browse.php?by=subjects"<?= $active === 'subjects' ? ' aria-current="page"' : '' ?>>Subjects</a>
      <a href="browse.php?by=series"<?= $active === 'series' ? ' aria-current="page"' : '' ?>>Series</a>
    </nav>
  </div>
</header>
<main id="main">
<?php
}

function page_bottom(): void
{
    $state = read_state();
    $when = !empty($state['last_change']) ? date('F j, Y', strtotime($state['last_change'])) : null;
    ?>
</main>
<footer class="foot">
  <p><?= h(cfg('site_title')) ?>.<?php if ($when): ?> Catalogue last updated <?= h($when) ?>.<?php endif; ?></p>
</footer>
<script src="assets/site.js?v=<?= h((string) @filemtime(APP_ROOT . '/assets/site.js')) ?>" defer></script>
</body>
</html>
<?php
}

function search_icon(): string
{
    return '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M15.5 15.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
}

function not_ready_page(): void
{
    page_top('Not set up yet', ['search' => false]);
    echo '<section class="empty"><h1>The catalogue hasn\'t been loaded yet</h1>';
    if (!CONFIG_LOADED) {
        echo '<p>Copy <code>app/config.sample.php</code> to <code>app/config.php</code> and fill in your settings.</p>';
    } else {
        echo '<p>Sign in to the <a href="admin.php">admin page</a>, connect Dropbox and run the first sync.</p>';
    }
    echo '</section>';
    page_bottom();
}
