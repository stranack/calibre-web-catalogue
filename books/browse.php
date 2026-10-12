<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$cat = Catalog::open();
if (!$cat) {
    not_ready_page();
    exit;
}

$by = (string) ($_GET['by'] ?? 'authors');
if (!in_array($by, ['authors', 'subjects', 'series'], true)) {
    $by = 'authors';
}

if ($by === 'authors') {
    $rows = $cat->authors();
    $param = 'author';
    $title = 'Authors';
    $intro = number_format(count($rows)) . ' authors, sorted by surname.';
    $keyOf = fn($r) => (string) ($r['sort'] ?: $r['name']);
} elseif ($by === 'subjects') {
    $rows = $cat->subjects();
    $param = 'tag';
    $title = 'Subjects';
    $intro = number_format(count($rows)) . ' subjects, taken from the tags in Calibre.';
    $keyOf = fn($r) => (string) $r['name'];
} else {
    $rows = $cat->seriesList();
    $param = 'series';
    $title = 'Series';
    $intro = number_format(count($rows)) . ' series.';
    $keyOf = fn($r) => (string) $r['name'];
}

// Group under initial letters; anything that isn't A–Z goes under "#".
$groups = [];
foreach ($rows as $r) {
    $first = strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_substr(ltrim($keyOf($r)), 0, 1)) ?: '#');
    $letter = preg_match('/^[A-Z]$/', $first) ? $first : '#';
    $groups[$letter][] = $r;
}
ksort($groups);
if (isset($groups['#'])) {
    $hash = $groups['#'];
    unset($groups['#']);
    $groups['#'] = $hash;
}

page_top($title, ['active' => $by]);
?>
  <section class="browse">
    <div class="results-head">
      <h1><?= h($title) ?></h1>
      <p class="count"><?= h($intro) ?></p>
    </div>

    <?php if (!$rows): ?>
      <div class="empty"><p>Nothing here yet. Add <?= $by === 'series' ? 'series' : 'tags' ?> to books in Calibre and they will show up after the next sync.</p></div>
    <?php else: ?>
      <?php if (count($groups) > 4): ?>
      <nav class="letters" aria-label="Jump to letter">
        <?php foreach (array_keys($groups) as $L): ?><a href="#letter-<?= $L === '#' ? 'other' : h($L) ?>"><?= h($L) ?></a><?php endforeach; ?>
      </nav>
      <?php endif; ?>

      <?php foreach ($groups as $L => $items): ?>
      <section class="letter-group" id="letter-<?= $L === '#' ? 'other' : h($L) ?>" aria-label="<?= h($L) ?>">
        <h2 class="letter"><?= h($L) ?></h2>
        <ul class="index-list">
          <?php foreach ($items as $r): ?>
          <li><a href="<?= h(link_to('index.php', [$param => (int) $r['id']])) ?>"><span class="ix-name"><?= h($r['name']) ?></span> <span class="ix-n"><?= (int) $r['n'] ?></span></a></li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
<?php
page_bottom();
