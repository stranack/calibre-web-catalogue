<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$cat = Catalog::open();
if (!$cat) {
    not_ready_page();
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$b = $id ? $cat->book($id) : null;
if (!$b) {
    http_response_code(404);
    page_top('Book not found');
    echo '<section class="empty"><h1>That book isn\'t in the library</h1><p>It may have been removed from Calibre. <a href="index.php">Back to all books</a></p></section>';
    page_bottom();
    exit;
}

$year = pub_year($b['pubdate']);
$published = nice_date($b['pubdate']);
$added = nice_date($b['timestamp']);
$description = clean_description($b['description']);
$ids = identifier_links($b['identifiers']);
$langs = array_filter(array_map('language_name', $b['languages']));

$more = [];
$moreAuthor = $b['authors'][0] ?? null;
if ($moreAuthor) {
    $more = $cat->moreByAuthor((int) $moreAuthor['id'], (int) $b['id'], 12);
}
$inSeries = [];
if ($b['series']) {
    $r = $cat->search(['series' => (int) $b['series']['id']], 'series', 1, 40);
    $inSeries = array_values(array_filter($r['books'], fn($x) => (int) $x['id'] !== (int) $b['id']));
}

page_top($b['title']);
?>
  <article class="book">
    <div class="book-cover"><?= cover_html($b, true) ?></div>

    <div class="book-main">
      <h1 class="book-title"><?= h($b['title']) ?></h1>
      <p class="book-authors">
        <?php foreach ($b['authors'] as $i => $a): ?><?= $i ? ($i === count($b['authors']) - 1 ? ' and ' : ', ') : '' ?><a href="<?= h(link_to('index.php', ['author' => $a['id']])) ?>"><?= h($a['name']) ?></a><?php endforeach; ?>
      </p>
      <?php if ($b['series']): ?>
      <p class="book-series">Book <?= h(series_number($b['series_index'])) ?> in <a href="<?= h(link_to('index.php', ['series' => (int) $b['series']['id']])) ?>"><?= h($b['series']['name']) ?></a></p>
      <?php endif; ?>
      <?php if ($b['rating'] > 0): $stars = (int) round($b['rating'] / 2); ?>
      <p class="book-rating" aria-label="Rated <?= $stars ?> out of 5"><span aria-hidden="true"><?= str_repeat('★', $stars) . str_repeat('☆', 5 - $stars) ?></span></p>
      <?php endif; ?>

      <?php if ($description !== ''): ?>
      <div class="book-desc" data-clamp><?= $description ?></div>
      <?php else: ?>
      <p class="book-nodesc">No description in the catalogue for this book.</p>
      <?php endif; ?>

      <?php if ($b['tags']): ?>
      <ul class="chips book-tags" aria-label="Subjects">
        <?php foreach ($b['tags'] as $t): ?>
        <?php [$broad, $narrow] = split_subject((string) $t['name']); ?>
        <li><a class="chip" href="<?= h(link_to('index.php', $narrow === null ? ['subject' => $broad] : ['tag' => (int) $t['id']])) ?>"><?php if ($narrow !== null): ?><span class="chip-k"><?= h($broad) ?> ›</span> <?= h($narrow) ?><?php else: ?><?= h($broad) ?><?php endif; ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <section class="record" aria-labelledby="rec-h">
        <h2 id="rec-h">Catalogue record</h2>
        <dl>
          <div><dt>Title</dt><dd><?= h($b['title']) ?></dd></div>
          <div><dt><?= count($b['authors']) > 1 ? 'Authors' : 'Author' ?></dt><dd><?= h(author_names($b, 10)) ?></dd></div>
          <?php if ($b['series']): ?><div><dt>Series</dt><dd><?= h($b['series']['name']) ?>, book <?= h(series_number($b['series_index'])) ?></dd></div><?php endif; ?>
          <?php if ($b['publisher']): ?><div><dt>Publisher</dt><dd><a href="<?= h(link_to('index.php', ['publisher' => (int) $b['publisher']['id']])) ?>"><?= h($b['publisher']['name']) ?></a></dd></div><?php endif; ?>
          <?php if ($published): ?><div><dt>Published</dt><dd><?= h($published) ?></dd></div><?php endif; ?>
          <?php if ($langs): ?><div><dt>Language</dt><dd><?= h(implode(', ', $langs)) ?></dd></div><?php endif; ?>
          <?php foreach ($ids as $idf): ?>
          <div><dt><?= h($idf['label']) ?></dt><dd><?= h($idf['value']) ?>
            <span class="ext"><?php foreach ($idf['links'] as $j => [$label, $href]): ?><?= $j ? ', ' : '' ?><a href="<?= h($href) ?>" rel="noopener noreferrer" target="_blank"><?= h($label) ?></a><?php endforeach; ?></span>
          </dd></div>
          <?php endforeach; ?>
          <?php $files = array_values(array_filter($b['files'], fn($x) => stripos((string) $x['format'], 'ORIGINAL_') !== 0)); ?>
          <?php if ($files && show_dropbox_links() && $b['path'] !== ''): ?>
          <div><dt>Files</dt><dd class="dropbox-links">
            <?php foreach ($files as $j => $fl): $fname = $fl['name'] . '.' . strtolower((string) $fl['format']); ?><?= $j ? ' ' : '' ?><a class="file-link" href="<?= h(dropbox_web_url($b['path'], $fname)) ?>" target="_blank" rel="noopener noreferrer" title="Open <?= h($fname) ?> in Dropbox"><?= h($fl['format']) ?></a><?php endforeach; ?>
            <a class="ext" href="<?= h(dropbox_web_url($b['path'])) ?>" target="_blank" rel="noopener noreferrer">Open the book's folder in Dropbox</a>
          </dd></div>
          <?php elseif ($files): ?><div><dt>Formats</dt><dd><?= h(implode(', ', array_column($files, 'format'))) ?></dd></div><?php endif; ?>
          <?php if ($added): ?><div><dt>Added</dt><dd><?= h($added) ?></dd></div><?php endif; ?>
        </dl>
      </section>
    </div>
  </article>

  <?php if ($inSeries): ?>
  <section class="shelf" aria-labelledby="series-h">
    <div class="section-head">
      <h2 id="series-h">Also in <?= h($b['series']['name']) ?></h2>
    </div>
    <div class="shelf-track"><ul class="shelf-row"><?php foreach ($inSeries as $x) echo book_card($x); ?></ul></div>
  </section>
  <?php endif; ?>

  <?php if ($more): ?>
  <section class="shelf" aria-labelledby="more-h">
    <div class="section-head">
      <h2 id="more-h">More by <?= h($moreAuthor['name']) ?></h2>
      <?php if (count($more) >= 12): ?><a href="<?= h(link_to('index.php', ['author' => $moreAuthor['id']])) ?>">See all</a><?php endif; ?>
    </div>
    <div class="shelf-track"><ul class="shelf-row"><?php foreach ($more as $x) echo book_card($x); ?></ul></div>
  </section>
  <?php endif; ?>
<?php
page_bottom();
