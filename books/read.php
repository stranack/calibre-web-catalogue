<?php
declare(strict_types=1);
/**
 * A simple EPUB reader for the signed-in owner: one or two pages, a choice of
 * fonts, text size and background, and a saved place that follows you from
 * device to device. The book itself comes from file.php, which checks the
 * sign-in again, so this page never exposes a file to anyone else.
 */
require __DIR__ . '/app/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');

function reader_problem(int $status, string $title, string $html): void
{
    http_response_code($status);
    page_top($title, ['search' => false]);
    echo '<section class="empty"><h1>' . h($title) . '</h1><p>' . $html . '</p></section>';
    page_bottom();
    exit;
}

if (!is_owner()) {
    reader_problem(403, 'Sign in to read', 'The reader is only available to the owner of this library. If that\'s you, <a href="admin.php">sign in on the admin page</a> on this device and try again.');
}

$cat = Catalog::open();
if (!$cat) {
    not_ready_page();
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$b = $id ? $cat->book($id) : null;
$hasEpub = $b && in_array('EPUB', array_map('strtoupper', $b['formats']), true);
if (!$hasEpub) {
    reader_problem(404, 'No EPUB for this book', 'The reader opens EPUB files, and the catalogue doesn\'t list one for this book. <a href="' . h($b ? link_to('book.php', ['id' => (int) $b['id']]) : 'index.php') . '">Back</a>');
}

$data = read_reading_data();
$config = [
    'id'       => (int) $b['id'],
    'title'    => (string) $b['title'],
    'version'  => substr(md5((string) $b['last_modified']), 0, 8),
    'fileUrl'  => book_file_url($b, 'EPUB'),
    'bookUrl'  => link_to('book.php', ['id' => (int) $b['id']]),
    'saveUrl'  => 'reading.php',
    'settings' => $data['settings'],
    'position' => reading_position((int) $b['id']),
    'bookmarks' => book_bookmarks((int) $b['id']),
    'finished' => isset(finished_books()[(int) $b['id']]),
];
$v = fn(string $f) => h((string) @filemtime(APP_ROOT . '/' . $f));
$theme = $data['settings']['theme'];
?>
<!doctype html>
<html lang="en" data-reader-theme="<?= h($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= h($b['title']) ?> – <?= h(cfg('site_title', 'Library')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,600;1,7..72,400&family=Public+Sans:ital,wght@0,400;0,600;1,400&display=swap">
<link rel="stylesheet" href="assets/reader.css?v=<?= $v('assets/reader.css') ?>">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
</head>
<body class="reader">
<header class="rd-bar">
  <a class="rd-back" href="<?= h($config['bookUrl']) ?>" aria-label="Back to the book's page">
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <span class="rd-back-label">Book</span>
  </a>
  <div class="rd-heading">
    <h1 class="rd-title"><?= h($b['title']) ?></h1>
    <p class="rd-chapter" id="rd-chapter"></p>
  </div>
  <button type="button" class="rd-tool" id="rd-mark-btn" aria-pressed="false" disabled>
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path class="rd-ribbon" d="M6.5 3.5h11v17l-5.5-4-5.5 4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
    <span class="rd-tool-label" id="rd-mark-label">Bookmark</span>
  </button>
  <button type="button" class="rd-tool" id="rd-toc-btn" aria-controls="rd-toc" aria-expanded="false">
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    <span class="rd-tool-label">Contents</span>
  </button>
  <button type="button" class="rd-tool" id="rd-set-btn" aria-controls="rd-settings" aria-expanded="false">
    <span class="rd-aa" aria-hidden="true">Aa</span>
    <span class="rd-tool-label">Display</span>
  </button>
  <button type="button" class="rd-tool" id="rd-full-btn" aria-pressed="false" hidden>
    <svg class="rd-icon-enter" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <svg class="rd-icon-exit" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <span class="rd-tool-label" id="rd-full-label">Full screen</span>
  </button>
</header>

<div class="rd-stage">
  <button type="button" class="rd-turn rd-prev" id="rd-prev" aria-label="Previous page">
    <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </button>
  <div class="rd-viewer" id="rd-viewer"></div>
  <button type="button" class="rd-turn rd-next" id="rd-next" aria-label="Next page">
    <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </button>
  <div class="rd-status" id="rd-status" role="status">Opening the book…</div>
</div>

<div class="rd-toast" id="rd-toast" role="status" aria-live="polite" hidden></div>

<footer class="rd-foot">
  <div class="rd-progress" aria-hidden="true"><div class="rd-progress-fill" id="rd-progress-fill"></div></div>
  <p class="rd-where" id="rd-where"></p>
</footer>

<nav class="rd-panel rd-panel-toc" id="rd-toc" aria-label="Contents and bookmarks" hidden>
  <div class="rd-panel-head">
    <div class="rd-tabs" role="tablist">
      <button type="button" role="tab" id="rd-tab-toc" aria-selected="true" aria-controls="rd-toc-list">Contents</button>
      <button type="button" role="tab" id="rd-tab-marks" aria-selected="false" aria-controls="rd-marks" tabindex="-1">Bookmarks<span class="rd-count" id="rd-marks-count"></span></button>
    </div>
    <button type="button" class="rd-close" data-close aria-label="Close contents and bookmarks">×</button>
  </div>
  <ol class="rd-toc-list" id="rd-toc-list" role="tabpanel" aria-labelledby="rd-tab-toc"><li class="rd-muted">Loading…</li></ol>
  <div class="rd-marks" id="rd-marks" role="tabpanel" aria-labelledby="rd-tab-marks" hidden>
    <ul class="rd-marks-list" id="rd-marks-list"></ul>
    <p class="rd-muted" id="rd-marks-empty">No bookmarks yet. Tap <strong>Bookmark</strong> at the top to mark the page you're on.</p>
  </div>
</nav>

<section class="rd-panel rd-panel-settings" id="rd-settings" aria-label="Display settings" hidden>
  <div class="rd-panel-head"><h2>Display</h2><button type="button" class="rd-close" data-close aria-label="Close display settings">×</button></div>

  <fieldset class="rd-group">
    <legend>Pages</legend>
    <div class="rd-seg" data-setting="pages">
      <button type="button" data-value="auto">Auto</button>
      <button type="button" data-value="one">One</button>
      <button type="button" data-value="two">Two</button>
    </div>
    <p class="rd-hint">Auto shows two pages side by side on wide screens. Two also keeps them on a tablet held upright.</p>
  </fieldset>

  <fieldset class="rd-group">
    <legend>Font</legend>
    <div class="rd-seg rd-fonts" data-setting="font">
      <button type="button" data-value="original">Book's own</button>
      <button type="button" data-value="literata" style="font-family: Literata, Georgia, serif">Literata</button>
      <button type="button" data-value="georgia" style="font-family: Georgia, 'Times New Roman', serif">Georgia</button>
      <button type="button" data-value="sans" style="font-family: 'Public Sans', system-ui, sans-serif">Sans serif</button>
    </div>
  </fieldset>

  <fieldset class="rd-group">
    <legend>Text size</legend>
    <div class="rd-size">
      <button type="button" id="rd-smaller" aria-label="Smaller text"><span style="font-size: 0.85em">A</span>−</button>
      <output id="rd-size-out" aria-live="polite">100%</output>
      <button type="button" id="rd-larger" aria-label="Larger text"><span style="font-size: 1.2em">A</span>+</button>
    </div>
  </fieldset>

  <fieldset class="rd-group">
    <legend>Background</legend>
    <div class="rd-seg rd-swatches" data-setting="theme">
      <button type="button" data-value="paper"><span class="rd-sw rd-sw-paper" aria-hidden="true"></span>White</button>
      <button type="button" data-value="sepia"><span class="rd-sw rd-sw-sepia" aria-hidden="true"></span>Sepia</button>
      <button type="button" data-value="mist"><span class="rd-sw rd-sw-mist" aria-hidden="true"></span>Grey</button>
      <button type="button" data-value="night"><span class="rd-sw rd-sw-night" aria-hidden="true"></span>Night</button>
    </div>
  </fieldset>
</section>

<script type="application/json" id="reader-config"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="assets/vendor/jszip-3.10.1.min.js"></script>
<script src="assets/vendor/epub-0.3.93.min.js"></script>
<script src="assets/reader.js?v=<?= $v('assets/reader.js') ?>"></script>
</body>
</html>
