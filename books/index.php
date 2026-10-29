<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$cat = Catalog::open();
if (!$cat) {
    not_ready_page();
    exit;
}

$f = [
    'q'         => trim((string) ($_GET['q'] ?? '')),
    'author'    => (int) ($_GET['author'] ?? 0),
    'tag'       => (int) ($_GET['tag'] ?? 0),
    'series'    => (int) ($_GET['series'] ?? 0),
    'publisher' => (int) ($_GET['publisher'] ?? 0),
    'subject'   => trim((string) ($_GET['subject'] ?? '')),
];
$f['q'] = mb_substr($f['q'], 0, 200);
$f['subject'] = mb_substr($f['subject'], 0, 100);
$f['read'] = is_owner() && in_array($_GET['read'] ?? '', ['yes', 'no'], true) ? (string) $_GET['read'] : '';
$filtered = $f['q'] !== '' || $f['author'] || $f['tag'] || $f['series'] || $f['publisher'] || $f['subject'] !== '' || $f['read'] !== '';

// What the catalogue is asked for: the filters, plus the owner's read list when filtering by it.
$fq = $f;
if ($f['read'] === 'yes') {
    $fq['only_ids'] = array_keys(finished_books());
} elseif ($f['read'] === 'no') {
    $fq['not_ids'] = array_keys(finished_books());
}

$sorts = ['title' => 'Title', 'author' => 'Author', 'added' => 'Recently added', 'published' => 'Newest published'];
if ($f['q'] !== '') {
    $sorts = ['relevance' => 'Best match'] + $sorts;
}
if ($f['series']) {
    $sorts = ['series' => 'Series order'] + $sorts;
}
$sort = (string) ($_GET['sort'] ?? '');
if (!isset($sorts[$sort])) {
    $sort = array_key_first($sorts);
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = max(12, min(120, (int) cfg('per_page', 48)));

$result = $cat->search($fq, $sort, $page, $per);
$params = $f + ['sort' => $sort];
$linkFor = fn(int $p) => link_to('index.php', $params + ['page' => $p]);

// Headings and removable filter chips
$chips = [];
$heading = 'All books';
if ($f['subject'] !== '') {
    $name = $cat->broadHeadings()[mb_strtolower($f['subject'])] ?? $f['subject'];
    $chips[] = ['label' => 'Subject', 'name' => $name, 'href' => link_to('index.php', array_merge($params, ['subject' => '']))];
}
$labels = ['author' => 'Author', 'tag' => 'Subject', 'series' => 'Series', 'publisher' => 'Publisher'];
foreach ($labels as $k => $label) {
    if ($f[$k]) {
        $name = $cat->nameOf($k, $f[$k]) ?? 'Unknown';
        $chips[] = ['label' => $label, 'name' => $name, 'href' => link_to('index.php', array_merge($params, [$k => 0]))];
    }
}
if ($f['read'] !== '') {
    $chips[] = ['label' => 'Reading', 'name' => $f['read'] === 'yes' ? 'Books you\'ve read' : 'Not read yet', 'href' => link_to('index.php', array_merge($params, ['read' => '']))];
}
if ($f['q'] !== '') {
    $heading = 'Results for “' . $f['q'] . '”';
} elseif (count($chips) === 1) {
    $heading = $chips[0]['name'];
    if ($f['author']) {
        $heading = 'Books by ' . $chips[0]['name'];
    }
} elseif ($chips) {
    $heading = 'Filtered books';
}

$stats = $cat->stats();

if (!$filtered) {
    page_top('', ['search' => false, 'class' => 'home']);
    $recent = $cat->recent(18);
    $opened = is_owner() ? recently_opened(18) : [];
    $openedBooks = $opened ? $cat->byIds(array_keys($opened)) : [];
    $finishedCount = is_owner() ? count(finished_books()) : 0;
    $topSubjects = $cat->topSubjects(16);
    ?>
  <section class="hero">
    <h1 class="hero-title"><?= h(cfg('site_title')) ?></h1>
    <p class="hero-sub"><?= number_format($stats['books']) ?> books by <?= number_format($stats['authors']) ?> authors</p>
    <form class="search search-hero" action="index.php" method="get" role="search">
      <label class="visually-hidden" for="q-hero">Search the library</label>
      <input id="q-hero" name="q" type="search" placeholder="Search titles, authors, subjects" autocomplete="off">
      <button type="submit"><?= search_icon() ?><span>Search</span></button>
    </form>
  </section>

  <?php if ($openedBooks || $finishedCount): ?>
  <section class="shelf" aria-labelledby="opened-h">
    <div class="section-head">
      <h2 id="opened-h">Recently opened</h2>
      <?php if ($finishedCount): ?><a href="<?= h(link_to('index.php', ['read' => 'yes'])) ?>">Books you've read (<?= number_format($finishedCount) ?>)</a><?php endif; ?>
    </div>
    <?php if ($openedBooks): ?>
    <div class="shelf-track">
      <ul class="shelf-row"><?php foreach ($openedBooks as $b) echo book_card($b, link_to('read.php', ['id' => (int) $b['id']]), $opened[(int) $b['id']] ?? null); ?></ul>
    </div>
    <?php else: ?>
    <p class="shelf-empty">Nothing in progress. Open a book's EPUB to start reading.</p>
    <?php endif; ?>
  </section>

  <?php endif; ?>
  <section class="shelf" aria-labelledby="recent-h">
    <div class="section-head">
      <h2 id="recent-h">Recently added</h2>
      <a href="<?= h(link_to('index.php', ['sort' => 'added'])) ?>">See all</a>
    </div>
    <div class="shelf-track">
      <ul class="shelf-row"><?php foreach ($recent as $b) echo book_card($b); ?></ul>
    </div>
  </section>

  <?php if ($topSubjects): ?>
  <section class="subjects-home" aria-labelledby="subj-h">
    <div class="section-head">
      <h2 id="subj-h">Browse by subject</h2>
      <a href="browse.php?by=subjects">All subjects</a>
    </div>
    <ul class="chips">
      <?php foreach ($topSubjects as $t): ?>
      <li><a class="chip" href="<?= h(link_to('index.php', $t['params'])) ?>"><?= h($t['label']) ?> <span class="chip-n"><?= (int) $t['n'] ?></span></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="results" aria-labelledby="all-h">
    <div class="section-head">
      <h2 id="all-h">All books</h2>
      <?php read_toggle($params, ''); ?>
      <?php sort_form($sorts, $sort, $f); ?>
    </div>
    <ul class="grid"><?php foreach ($result['books'] as $b) echo book_card($b); ?></ul>
    <?= pagination($result['total'], $page, $per, $linkFor) ?>
  </section>
<?php
    page_bottom();
    exit;
}

// Filtered or searched view
$refine = $cat->subjectsWithin($fq, 14);
$refine = array_values(array_filter($refine, fn($t) => $t['n'] < $result['total']));

page_top($f['q'] !== '' ? 'Search: ' . $f['q'] : $heading, ['q' => $f['q']]);
?>
  <section class="results" aria-labelledby="res-h">
    <div class="results-head">
      <h1 id="res-h"><?= h($heading) ?></h1>
      <p class="count"><?= number_format($result['total']) ?> <?= $result['total'] === 1 ? 'book' : 'books' ?><?php if ($result['total'] > $per): ?>, page <?= $page ?> of <?= (int) ceil($result['total'] / $per) ?><?php endif; ?></p>
    </div>

    <?php if ($chips): ?>
    <ul class="chips chips-active" aria-label="Active filters">
      <?php foreach ($chips as $c): ?>
      <li><a class="chip chip-on" href="<?= h($c['href']) ?>" aria-label="Remove filter: <?= h($c['label'] . ' ' . $c['name']) ?>"><span class="chip-k"><?= h($c['label']) ?></span> <?= h($c['name']) ?> <span class="chip-x" aria-hidden="true">×</span></a></li>
      <?php endforeach; ?>
      <?php if (count($chips) + ($f['q'] !== '' ? 1 : 0) > 1): ?><li><a class="clear-all" href="index.php">Clear all</a></li><?php endif; ?>
    </ul>
    <?php endif; ?>

    <?php if ($result['total'] === 0): ?>
      <div class="empty">
        <p>No books match<?= $f['q'] !== '' ? ' “' . h($f['q']) . '”' : ' these filters' ?>.</p>
        <p>Check the spelling, try fewer words, or <a href="index.php">browse everything</a>.</p>
      </div>
    <?php else: ?>
      <div class="toolbar">
        <?php if ($refine): ?>
        <div class="refine">
          <span class="refine-label">Narrow by subject</span>
          <ul class="chips">
            <?php foreach ($refine as $t): ?>
            <li><a class="chip" href="<?= h(link_to('index.php', array_merge($params, $t['params'], ['page' => 1]))) ?>"><?= h($t['label']) ?> <span class="chip-n"><?= (int) $t['n'] ?></span></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
        <?php read_toggle($params, $f['read']); ?>
        <?php sort_form($sorts, $sort, $f); ?>
      </div>
      <ul class="grid"><?php foreach ($result['books'] as $b) echo book_card($b); ?></ul>
      <?= pagination($result['total'], $page, $per, $linkFor) ?>
    <?php endif; ?>
  </section>
<?php
page_bottom();

function sort_form(array $sorts, string $sort, array $f): void
{
    ?>
    <form class="sort" action="index.php" method="get">
      <?php foreach ($f as $k => $v): if ($v !== '' && $v !== 0): ?>
      <input type="hidden" name="<?= h($k) ?>" value="<?= h((string) $v) ?>">
      <?php endif; endforeach; ?>
      <label for="sort-select">Sort by</label>
      <select name="sort" id="sort-select" data-autosubmit>
        <?php foreach ($sorts as $k => $label): ?>
        <option value="<?= h($k) ?>"<?= $k === $sort ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit">Sort</button></noscript>
    </form>
    <?php
}

/** Owner only: show all books, only the ones you've read, or only the unread ones. */
function read_toggle(array $params, string $current): void
{
    if (!is_owner()) {
        return;
    }
    $opts = ['' => 'All', 'yes' => 'Read', 'no' => 'Not read'];
    echo '<nav class="read-toggle" aria-label="Show books by whether you\'ve read them">';
    foreach ($opts as $k => $label) {
        $href = link_to('index.php', array_merge($params, ['read' => $k, 'page' => 1]));
        echo $k === $current
            ? '<span class="rt-on" aria-current="true">' . h($label) . '</span>'
            : '<a href="' . h($href) . '">' . h($label) . '</a>';
    }
    echo '</nav>';
}
