/* Home Library reader: opens an EPUB from file.php with epub.js, applies the
   chosen pages, font, size and background, and saves your place to the site
   so another device can carry on from it. */
(function () {
  'use strict';

  var cfg = JSON.parse(document.getElementById('reader-config').textContent);

  // Colours inside the book. The page around it uses the same names in reader.css.
  var THEMES = {
    // strip: also clear the book's own background shading, which would clash
    // with a tinted or dark page. link: the underline colour for links.
    paper: { bg: '#ffffff', fg: '#1f2a29', link: '#1f6a58', strip: false },
    sepia: { bg: '#f6eedd', fg: '#3a2e21', link: '#8a4b12', strip: true },
    mist:  { bg: '#e7ebe9', fg: '#1f2726', link: '#1f6a58', strip: true },
    night: { bg: '#151a19', fg: '#ffffff', link: '#74c4ab', strip: true }
  };
  var FONTS = {
    original: null,
    literata: '"Literata", Georgia, "Times New Roman", serif',
    georgia: 'Georgia, "Times New Roman", serif',
    sans: '"Public Sans", system-ui, -apple-system, "Segoe UI", sans-serif'
  };
  var FONT_CSS = 'https://fonts.googleapis.com/css2?family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,600;1,7..72,400&family=Public+Sans:ital,wght@0,400;0,600;1,400&display=swap';
  var MIN_SPREAD = 800; // "Auto" shows two pages from this width up

  var settings = Object.assign({ theme: 'paper', font: 'original', size: 100, pages: 'auto' }, cfg.settings || {});
  var book = null;
  var rendition = null;
  var lastLocation = null;
  var bookmarks = Array.isArray(cfg.bookmarks) ? cfg.bookmarks.slice() : [];
  var notes = Array.isArray(cfg.notes) ? cfg.notes.slice() : [];
  var $ = function (id) { return document.getElementById(id); };

  // ---- Status messages ---------------------------------------------------

  function status(text, isError, html) {
    var el = $('rd-status');
    el.hidden = !text;
    el.classList.toggle('is-error', !!isError);
    if (html) { el.innerHTML = html; } else { el.textContent = text || ''; }
  }

  function failed(message) {
    var back = '<a href="' + escapeAttr(cfg.bookUrl) + '">Back to the book</a>';
    var dl = '<a href="' + escapeAttr(cfg.fileUrl) + '">download the EPUB</a>';
    status(message, true, '<p>' + escapeHtml(message) + '<br>' + back + ', or ' + dl + ' to open it in another app.</p>');
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  var escapeAttr = escapeHtml;

  // ---- Styling the book -------------------------------------------------

  function bookCss() {
    var t = THEMES[settings.theme] || THEMES.paper;
    var f = FONTS[settings.font];
    var css = 'html, body { background: ' + t.bg + ' !important; }\n' +
      'body { color: ' + t.fg + ' !important; font-size: ' + settings.size + '% !important; }\n';
    // Every bit of text takes the chosen colour, whatever the book asks for
    // (class colours, <font color>, text-fill colours). Links keep that colour
    // too and are marked with an underline instead, so a book that turns whole
    // paragraphs into links doesn't come out in a different colour.
    css += 'body *, body *::before, body *::after { color: ' + t.fg + ' !important; -webkit-text-fill-color: ' + t.fg + ' !important; }\n' +
      'a[href] { text-decoration: underline !important; text-decoration-color: ' + t.link + ' !important; text-decoration-thickness: 1px !important; text-underline-offset: 0.18em; }\n';
    if (t.strip) {
      css += 'body * { background-color: transparent !important; }\n';
    }
    if (f) {
      css += 'body, body *:not(code):not(pre):not(kbd):not(samp):not(tt) { font-family: ' + f + ' !important; }\n';
    }
    if (settings.size > 100) {
      // Larger text gets more room between lines: 1.54 at 110%, 1.7 at 150%,
      // 1.9 at 200%. At 100% and below the book keeps its own spacing.
      var lh = Math.min(1.9, 1.5 + (settings.size - 100) * 0.004).toFixed(2);
      css += 'body, body * { line-height: ' + lh + ' !important; }\n' +
        'h1, h2, h3, h4, h5, h6 { line-height: 1.25 !important; }\n';
    }
    if (settings.theme === 'night') {
      css += 'img { opacity: 0.88; }\n';
    }
    return css;
  }

  function styleContents(contents) {
    var doc = contents.document;
    if (!doc || !doc.head) { return; }
    var el = doc.getElementById('kl-reader-style');
    if (!el) {
      el = doc.createElement('style');
      el.id = 'kl-reader-style';
      doc.head.appendChild(el);
    }
    el.textContent = bookCss();
    // A colour written on an element with !important beats any stylesheet,
    // so take those off (only on screen; the book file isn't changed).
    var strip = (THEMES[settings.theme] || THEMES.paper).strip;
    Array.prototype.forEach.call(doc.querySelectorAll('[style]'), function (node) {
      ['color', '-webkit-text-fill-color'].concat(strip ? ['background-color', 'background'] : []).forEach(function (prop) {
        if (node.style.getPropertyValue(prop)) { node.style.removeProperty(prop); }
      });
    });
  }

  function applyLook() {
    document.documentElement.setAttribute('data-reader-theme', settings.theme);
    if (rendition) {
      rendition.getContents().forEach(styleContents);
    }
  }

  // ---- Pages ------------------------------------------------------------

  function spreadArgs() {
    if (settings.pages === 'one') { return ['none', MIN_SPREAD]; }
    // "Two" keeps a pair of pages on a tablet held upright, but not on a phone.
    if (settings.pages === 'two') { return ['always', 500]; }
    return ['auto', MIN_SPREAD];
  }

  /**
   * A position for the first full line of text on screen. Re-laying the pages
   * from the reader's own "start of page" marker can land a page or two
   * early, so re-layouts aim at the words you can actually see instead.
   */
  function findAnchor() {
    try {
      var v = $('rd-viewer');
      var fr = v.querySelector('iframe');
      var doc = fr.contentDocument;
      var contents = rendition.getContents()[0];
      var vr = v.getBoundingClientRect();
      var fb = fr.getBoundingClientRect();
      var walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
      var node;
      while ((node = walker.nextNode())) {
        if (!node.textContent.trim()) { continue; }
        var range = doc.createRange();
        range.selectNodeContents(node);
        var rects = range.getClientRects();
        for (var i = 0; i < rects.length; i++) {
          var b = rects[i];
          var x = fb.left + b.left + 1;
          if (b.width > 1 && x >= vr.left && x < vr.right && fb.top + b.top >= vr.top - 1 && fb.top + b.bottom <= vr.bottom + 1) {
            // Aim a little way into the line: a spot at the very start of a
            // line is measured as the end of the line before, which can be
            // on the previous page.
            var at = doc.caretRangeFromPoint ? doc.caretRangeFromPoint(b.left + Math.min(b.width / 2, 40), b.top + b.height / 2) : null;
            if (!at || at.startContainer.nodeType !== 3) {
              at = doc.createRange();
              var off = 0;
              while (off < node.length - 1 && /\s/.test(node.textContent.charAt(off))) { off++; }
              at.setStart(node, Math.min(off + 3, node.length));
              at.collapse(true);
            }
            return contents.cfiFromRange(at);
          }
        }
      }
    } catch (e) { /* fall back below */ }
    return lastLocation && lastLocation.start ? lastLocation.start.cfi : null;
  }

  // The spot you're reading, noted after each page turn or jump you make,
  // and kept through any number of re-layouts (text size steps, full screen,
  // repairs) until you move on. Re-anchoring after every re-layout would
  // creep backwards, because the re-laid page usually starts a little earlier.
  var readingAnchor = null;
  var noteNextPage = true;
  var movedSinceOpen = false;
  // Counts the reader's own moves, so a return trip planned before one of
  // them can tell it has been overtaken and stand down.
  var moves = 0;
  function userMoved() { noteNextPage = true; readingAnchor = null; movedSinceOpen = true; moves++; }
  function anchorCfi() {
    if (!readingAnchor) { readingAnchor = findAnchor(); }
    return readingAnchor;
  }
  /**
   * Go back to the reading spot once a re-layout epub.js has started is done.
   *
   * The trip back is planned now but taken up to half a second later, and in
   * that time the reader may have turned the page. Taking it anyway would
   * undo the turn - the page flips, then snaps back - so it only goes ahead
   * if nothing has moved since it was planned.
   */
  function returnTo(spot) {
    if (!spot) { return; }
    var done = false;
    var planned = moves;
    function go() {
      if (done) { return; }
      done = true;
      if (moves !== planned) { return; }
      rendition.display(spot);
    }
    rendition.once('relocated', function () { setTimeout(go, 60); });
    setTimeout(go, 500);
  }

  /** Re-lay the book after a change that alters how much fits on a page. */
  function relayout(anchor) {
    if (!rendition) { return; }
    var cfi = anchor || anchorCfi();
    var s = spreadArgs();
    rendition.spread(s[0], s[1]);
    rendition.resize();
    if (cfi) {
      rendition.display(cfi);
    }
  }

  /**
   * Jump to a place in the book. A book re-made in Calibre can leave an old
   * bookmark or contents entry pointing at a spot that no longer exists, and
   * epub.js answers that with a rejected promise; say so rather than looking
   * like the tap did nothing.
   */
  function goTo(spot, what) {
    if (!rendition || !spot) { return; }
    var r = rendition.display(spot);
    if (r && r.catch) {
      r.catch(function () { toast('That ' + what + ' doesn\'t point anywhere in this book any more.'); });
    }
  }

  // ---- Saving -----------------------------------------------------------

  function post(body, keepalive) {
    return fetch(cfg.saveUrl, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: !!keepalive,
      headers: { 'Content-Type': 'application/json', 'X-Library-Reader': '1' },
      body: JSON.stringify(body)
    }).catch(function () { /* offline: the next page turn tries again */ });
  }

  var saveTimer = null;
  var pending = null;
  function savePosition(loc) {
    if (!loc || !loc.start || !loc.start.cfi) { return; }
    pending = { id: cfg.id, cfi: loc.start.cfi, pct: percentFor(loc) };
    clearTimeout(saveTimer);
    saveTimer = setTimeout(flushPosition, 1200);
  }
  function flushPosition(keepalive) {
    clearTimeout(saveTimer);
    if (!pending) { return; }
    var body = pending;
    pending = null;
    post(body, keepalive);
  }

  // Reaching the last page marks the book as read (once; the book page can undo it).
  function noteFinished(loc) {
    if (cfg.finished || !loc || !loc.atEnd) { return; }
    cfg.finished = true;
    flushPosition();
    post({ id: cfg.id, finished: true, how: 'end' });
    toast('Finished \u2014 marked as read');
  }

  var settingsTimer = null;
  function saveSettings() {
    clearTimeout(settingsTimer);
    settingsTimer = setTimeout(function () { post({ settings: settings }); }, 400);
  }

  // ---- Where you are ----------------------------------------------------

  function percentFor(loc) {
    if (book && book.locations && book.locations.length() > 0 && loc && loc.start) {
      return book.locations.percentageFromCfi(loc.start.cfi);
    }
    // Counting the pages takes a moment after the book opens. Until it's
    // done the only percentage we can stand behind is the one saved with the
    // place we opened at, and only while we're still sitting on it; guessing
    // with it after a page turn would save that old figure against the new
    // position and leave the shelf showing a number that never moved.
    if (!movedSinceOpen && cfg.position && typeof cfg.position.pct === 'number') {
      return cfg.position.pct;
    }
    return null;
  }

  function chapterLabel(href) {
    if (!book || !book.navigation || !href) { return ''; }
    var path = href.split('#')[0];
    var found = '';
    (function walk(items) {
      items.forEach(function (it) {
        if (!found && it.href && it.href.split('#')[0].replace(/^.*\//, '') === path.replace(/^.*\//, '')) {
          found = (it.label || '').trim();
        }
        if (!found && it.subitems && it.subitems.length) { walk(it.subitems); }
      });
    })(book.navigation.toc || []);
    return found;
  }

  function showWhere(loc) {
    var pct = percentFor(loc);
    $('rd-chapter').textContent = chapterLabel(loc && loc.start ? loc.start.href : '');
    $('rd-where').textContent = pct !== null ? Math.round(pct * 100) + '%' : (countsFailed ? 'Progress unavailable' : '');
    $('rd-progress-fill').style.width = pct === null ? '0' : (pct * 100).toFixed(1) + '%';
    $('rd-prev').disabled = !!loc.atStart;
    $('rd-next').disabled = !!loc.atEnd;
    markTocCurrent(loc && loc.start ? loc.start.href : '');
    showMarkButton();
  }

  // ---- Contents ---------------------------------------------------------

  function buildToc(items) {
    var list = $('rd-toc-list');
    list.innerHTML = '';
    if (!items || !items.length) {
      list.innerHTML = '<li class="rd-muted">This book has no table of contents.</li>';
      return;
    }
    (function add(parent, entries) {
      entries.forEach(function (it) {
        var li = document.createElement('li');
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = (it.label || 'Untitled').trim();
        b.dataset.href = it.href;
        b.addEventListener('click', function () {
          closePanels();
          userMoved();
          goTo(it.href, 'chapter');
        });
        li.appendChild(b);
        if (it.subitems && it.subitems.length) {
          var ol = document.createElement('ol');
          add(ol, it.subitems);
          li.appendChild(ol);
        }
        parent.appendChild(li);
      });
    })(list, items);
  }

  function markTocCurrent(href) {
    var file = (href || '').split('#')[0].replace(/^.*\//, '');
    var done = false;
    Array.prototype.forEach.call(document.querySelectorAll('#rd-toc-list button'), function (b) {
      var match = !done && file && (b.dataset.href || '').split('#')[0].replace(/^.*\//, '') === file;
      if (match) { done = true; b.setAttribute('aria-current', 'true'); } else { b.removeAttribute('aria-current'); }
    });
  }

  // ---- Bookmarks --------------------------------------------------------

  function cfiCompare(a, b) {
    try { return new ePub.CFI().compare(a, b); } catch (e) { return a < b ? -1 : (a > b ? 1 : 0); }
  }

  /** The bookmark on the page (or pair of pages) now showing, if any. */
  function markHere() {
    var loc = lastLocation;
    if (!loc || !loc.start || !loc.end) { return null; }
    for (var i = 0; i < bookmarks.length; i++) {
      var c = bookmarks[i].cfi;
      if (c === loc.start.cfi || (cfiCompare(c, loc.start.cfi) >= 0 && cfiCompare(c, loc.end.cfi) <= 0)) {
        return bookmarks[i];
      }
    }
    return null;
  }

  function showMarkButton() {
    var on = !!markHere();
    var btn = $('rd-mark-btn');
    btn.setAttribute('aria-pressed', String(on));
    btn.setAttribute('aria-label', on ? 'Remove the bookmark on this page' : 'Bookmark this page');
    $('rd-mark-label').textContent = on ? 'Bookmarked' : 'Bookmark';
  }

  /** The first words on the page, so the list shows what each bookmark is. */
  function snippetAt(cfi) {
    try {
      var range = rendition.getRange(cfi);
      if (!range) { return ''; }
      var doc = range.startContainer.ownerDocument;
      var walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
      var node = range.startContainer.nodeType === 3 ? range.startContainer : null;
      var text = '';
      if (node) {
        walker.currentNode = node;
        text = node.textContent.slice(range.startOffset);
      } else {
        walker.currentNode = range.startContainer;
      }
      while (text.replace(/\s+/g, ' ').trim().length < 140 && (node = walker.nextNode())) {
        text += ' ' + node.textContent;
      }
      text = text.replace(/\s+/g, ' ').trim();
      return text.length > 140 ? text.slice(0, 140).replace(/\s+\S*$/, '') + '…' : text;
    } catch (e) {
      return '';
    }
  }

  var toastTimer = null;
  function toast(text) {
    var el = $('rd-toast');
    el.textContent = text;
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 1800);
  }

  function saveMark(body) {
    body.id = cfg.id;
    return post(body).then(function (res) {
      return res && res.ok ? res.json() : null;
    }).then(function (data) {
      if (data && Array.isArray(data.bookmarks)) {
        bookmarks = data.bookmarks;
        renderMarks();
        showMarkButton();
      }
    }).catch(function () { /* kept locally; the next change tries again */ });
  }

  function toggleMark() {
    if (!rendition || !lastLocation || !lastLocation.start) { return; }
    var here = markHere();
    if (here) {
      bookmarks = bookmarks.filter(function (b) { return b !== here; });
      toast('Bookmark removed');
      saveMark({ removeBookmark: here.cfi });
    } else {
      var loc = lastLocation;
      var mark = {
        cfi: loc.start.cfi,
        label: chapterLabel(loc.start.href) || '',
        pct: percentFor(loc),
        text: snippetAt(loc.start.cfi)
      };
      bookmarks.push(mark);
      toast('Page bookmarked');
      saveMark({ bookmark: mark });
    }
    renderMarks();
    showMarkButton();
  }

  function removeMark(cfi) {
    bookmarks = bookmarks.filter(function (b) { return b.cfi !== cfi; });
    renderMarks();
    showMarkButton();
    saveMark({ removeBookmark: cfi });
  }

  function renderMarks() {
    var list = $('rd-marks-list');
    // Furthest into the book first, so the place you most recently
    // bookmarked is at the top of the list rather than buried under the
    // early ones.
    var sorted = bookmarks.slice().sort(function (a, b) { return cfiCompare(b.cfi, a.cfi); });
    list.innerHTML = '';
    $('rd-marks-empty').hidden = sorted.length > 0;
    $('rd-marks-count').textContent = sorted.length ? ' ' + sorted.length : '';
    sorted.forEach(function (m) {
      var li = document.createElement('li');
      var go = document.createElement('button');
      go.type = 'button';
      go.className = 'rd-mark-go';
      var head = document.createElement('span');
      head.className = 'rd-mark-head';
      var pct = typeof m.pct === 'number' ? Math.round(m.pct * 100) + '%' : '';
      head.textContent = (m.label || 'Bookmark') + (pct ? ' · ' + pct : '');
      go.appendChild(head);
      if (m.text) {
        var snip = document.createElement('span');
        snip.className = 'rd-mark-text';
        snip.textContent = m.text;
        go.appendChild(snip);
      }
      go.addEventListener('click', function () {
        closePanels();
        userMoved();
        goTo(m.cfi, 'bookmark');
      });
      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'rd-mark-del';
      del.setAttribute('aria-label', 'Remove bookmark' + (m.label ? ': ' + m.label : ''));
      del.textContent = '×';
      del.addEventListener('click', function (e) {
        e.stopPropagation();
        removeMark(m.cfi);
        var next = list.querySelector('.rd-mark-del') || $('rd-tab-marks');
        next.focus();
      });
      li.appendChild(go);
      li.appendChild(del);
      list.appendChild(li);
    });
  }

  // ---- Highlights and notes ---------------------------------------------
  //
  // A note is a stretch of text (a range CFI) plus the passage itself and,
  // if you want one, a comment. epub.js draws the highlight: annotations
  // re-attach themselves on its render hook, so each one is added once and
  // survives page turns and relayouts without our help.

  var MAX_NOTE = typeof cfg.maxNote === 'number' ? cfg.maxNote : 2000;
  var drawn = {};

  function noteAt(cfi) {
    for (var i = 0; i < notes.length; i++) {
      if (notes[i].cfi === cfi) { return notes[i]; }
    }
    return null;
  }

  function drawNote(n) {
    if (!rendition || drawn[n.cfi]) { return; }
    try {
      // One class, never two: epub.js hands this to classList.add, which
      // rejects any token with a space in it.
      rendition.annotations.add('highlight', n.cfi, {}, function () {
        openNoteEditor(noteAt(n.cfi) || n);
      }, n.note ? 'rd-hl-note' : 'rd-hl', {});
      drawn[n.cfi] = true;
    } catch (e) {
      // Usually a range the book no longer has, after a re-convert in
      // Calibre: the note still shows in the list, just without its mark.
      // Say so quietly rather than leaving a real fault invisible.
      if (window.console && console.warn) {
        console.warn('Could not draw a highlight at ' + n.cfi + ': ' + (e && e.message));
      }
    }
  }

  function undrawNote(cfi) {
    if (!rendition || !drawn[cfi]) { return; }
    try { rendition.annotations.remove(cfi, 'highlight'); } catch (e) { /* already gone */ }
    delete drawn[cfi];
  }

  function drawAllNotes() {
    notes.forEach(drawNote);
  }

  /** Redraw one note, so a comment added or removed changes its shade. */
  function redrawNote(cfi) {
    undrawNote(cfi);
    var n = noteAt(cfi);
    if (n) { drawNote(n); }
  }

  function saveNote(body) {
    body.id = cfg.id;
    return post(body).then(function (res) {
      return res && res.ok ? res.json() : null;
    }).then(function (data) {
      if (data && Array.isArray(data.notes)) {
        notes = data.notes;
        renderNotes();
      }
      return data;
    }).catch(function () { return null; });
  }

  function addNote(cfiRange, text, comment) {
    var had = noteAt(cfiRange);
    var n = {
      cfi: cfiRange,
      text: text,
      note: comment || '',
      label: (lastLocation && lastLocation.start && chapterLabel(lastLocation.start.href)) || '',
      pct: percentFor(lastLocation)
    };
    // Show it at once; the save only corrects the stored copy.
    notes = notes.filter(function (x) { return x.cfi !== cfiRange; }).concat([n]);
    renderNotes();
    if (had) { redrawNote(cfiRange); } else { drawNote(n); }
    return saveNote({ note: n }).then(function (data) {
      if (!data) { toast('Saved on this device only - the note didn\'t reach the server.'); }
      else { redrawNote(cfiRange); }
    });
  }

  function removeNote(cfi) {
    notes = notes.filter(function (n) { return n.cfi !== cfi; });
    undrawNote(cfi);
    renderNotes();
    saveNote({ removeNote: cfi });
  }

  // ---- What to do with a selection --------------------------------------

  var selNow = null;

  /**
   * The browser draws its own menu beside selected text - Copy, Translate and
   * the rest - and that menu is browser chrome, so it paints over anything
   * this page puts near the selection. These two actions therefore take over
   * the header while a selection is live, where nothing can cover them, and
   * the usual tools step aside until it is let go.
   */
  function showSelBar(on) {
    $('rd-selacts').hidden = !on;
    $('rd-tools').hidden = on;
  }

  function hideSelBar() {
    showSelBar(false);
    selNow = null;
  }

  function onSelected(cfiRange, contents) {
    var win = contents && contents.window;
    var sel = win && win.getSelection && win.getSelection();
    if (!sel || sel.isCollapsed) { hideSelBar(); return; }
    var text = String(sel.toString() || '').replace(/\s+/g, ' ').trim();
    if (!text) { hideSelBar(); return; }
    // Keep the passage and its place now: tapping a header button can clear
    // the selection before the click lands, and by then it's gone.
    selNow = { cfi: cfiRange, text: text, contents: contents };
    var existing = noteAt(cfiRange);
    $('rd-sel-mark').textContent = existing ? 'Remove highlight' : 'Highlight';
    $('rd-sel-note').textContent = existing && existing.note ? 'Edit note' : 'Add note';
    showSelBar(true);
  }

  /** Let go of the selection in the book, so the highlight shows plainly. */
  function dropSelection() {
    var c = selNow && selNow.contents;
    try {
      if (c && c.window && c.window.getSelection) { c.window.getSelection().removeAllRanges(); }
    } catch (e) { /* frame already gone */ }
  }

  // ---- The comment box --------------------------------------------------

  var editing = null;

  function noteLeft() {
    var left = MAX_NOTE - $('rd-noteedit-text').value.length;
    $('rd-noteedit-left').textContent = left < 200 ? left + ' characters left' : '';
  }

  function openNoteEditor(n) {
    if (!n || !n.cfi) { return; }
    editing = { cfi: n.cfi, text: n.text || '' };
    hideSelBar();
    closePanels();
    $('rd-noteedit-quote').textContent = n.text || '';
    $('rd-noteedit-quote').hidden = !n.text;
    $('rd-noteedit-text').value = n.note || '';
    $('rd-noteedit-del').hidden = !noteAt(n.cfi);
    $('rd-noteedit').hidden = false;
    noteLeft();
    $('rd-noteedit-text').focus();
  }

  function closeNoteEditor() {
    $('rd-noteedit').hidden = true;
    editing = null;
  }

  function saveNoteEditor() {
    if (!editing) { return; }
    var e = editing;
    var comment = $('rd-noteedit-text').value;
    closeNoteEditor();
    dropSelection();
    addNote(e.cfi, e.text, comment);
    toast(comment.trim() ? 'Note saved' : 'Highlighted');
  }

  // ---- The notes list ---------------------------------------------------

  function renderNotes() {
    var list = $('rd-notes-list');
    // Furthest into the book first, to match the bookmarks list.
    var sorted = notes.slice().sort(function (a, b) { return cfiCompare(b.cfi, a.cfi); });
    list.innerHTML = '';
    $('rd-notes-empty').hidden = sorted.length > 0;
    $('rd-notes-count').textContent = sorted.length ? ' ' + sorted.length : '';
    sorted.forEach(function (n) {
      var li = document.createElement('li');
      if (n.note) { li.className = 'rd-has-note'; }

      var go = document.createElement('button');
      go.type = 'button';
      go.className = 'rd-note-go';

      var head = document.createElement('span');
      head.className = 'rd-note-head';
      var pct = typeof n.pct === 'number' ? Math.round(n.pct * 100) + '%' : '';
      head.textContent = (n.label || 'Note') + (pct ? ' · ' + pct : '');
      go.appendChild(head);

      if (n.text) {
        var quote = document.createElement('span');
        quote.className = 'rd-note-quote';
        quote.textContent = n.text;
        go.appendChild(quote);
      }
      if (n.note) {
        var mine = document.createElement('span');
        mine.className = 'rd-note-mine';
        mine.textContent = n.note;
        go.appendChild(mine);
      }
      go.addEventListener('click', function () {
        closePanels();
        userMoved();
        goTo(n.cfi, 'note');
      });

      var edit = document.createElement('button');
      edit.type = 'button';
      edit.className = 'rd-note-edit';
      edit.setAttribute('aria-label', (n.note ? 'Edit the note on' : 'Add a note to') + ' this highlight');
      edit.textContent = n.note ? 'Edit' : 'Note';
      edit.addEventListener('click', function (ev) {
        ev.stopPropagation();
        openNoteEditor(n);
      });

      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'rd-note-del';
      del.setAttribute('aria-label', 'Remove this highlight');
      del.textContent = '×';
      del.addEventListener('click', function (ev) {
        ev.stopPropagation();
        removeNote(n.cfi);
        var next = list.querySelector('.rd-note-del') || $('rd-tab-notes');
        next.focus();
      });

      var acts = document.createElement('div');
      acts.className = 'rd-note-acts';
      acts.appendChild(edit);
      acts.appendChild(del);
      li.appendChild(go);
      li.appendChild(acts);
      list.appendChild(li);
    });
  }

  var TABS = ['toc', 'marks', 'notes'];
  var TAB_PANEL = { toc: 'rd-toc-list', marks: 'rd-marks', notes: 'rd-notes' };
  var tocTab = 'toc';
  function showTab(which) {
    tocTab = TABS.indexOf(which) >= 0 ? which : 'toc';
    TABS.forEach(function (t) {
      var on = t === tocTab;
      var tab = $('rd-tab-' + t);
      tab.setAttribute('aria-selected', String(on));
      tab.tabIndex = on ? 0 : -1;
      $(TAB_PANEL[t]).hidden = !on;
    });
  }

  // ---- Panels -----------------------------------------------------------

  function togglePanel(btnId, panelId) {
    var panel = $(panelId);
    var open = panel.hidden;
    closePanels();
    if (open) {
      panel.hidden = false;
      $(btnId).setAttribute('aria-expanded', 'true');
      var first = panel.querySelector('[aria-current="true"], [aria-pressed="true"], button');
      if (first) { first.focus(); }
    }
  }
  function closePanels() {
    ['rd-toc', 'rd-settings'].forEach(function (id) { $(id).hidden = true; });
    ['rd-toc-btn', 'rd-set-btn'].forEach(function (id) { $(id).setAttribute('aria-expanded', 'false'); });
  }
  function panelsOpen() { return !$('rd-toc').hidden || !$('rd-settings').hidden; }

  function syncControls() {
    Array.prototype.forEach.call(document.querySelectorAll('.rd-seg'), function (group) {
      var key = group.dataset.setting;
      Array.prototype.forEach.call(group.querySelectorAll('button'), function (b) {
        b.setAttribute('aria-pressed', String(b.dataset.value === String(settings[key])));
      });
    });
    $('rd-size-out').textContent = settings.size + '%';
    $('rd-smaller').disabled = settings.size <= 70;
    $('rd-larger').disabled = settings.size >= 200;
  }

  function change(key, value) {
    if (settings[key] === value) { return; }
    // Note the words on screen before the text changes size or shape.
    var anchor = key !== 'theme' && rendition ? anchorCfi() : null;  // before the text changes
    settings[key] = value;
    syncControls();
    applyLook();
    if (key !== 'theme') { relayout(anchor); }
    saveSettings();
  }

  // ---- Turning pages ----------------------------------------------------

  function next() { if (rendition) { userMoved(); rendition.next(); } }
  function prev() { if (rendition) { userMoved(); rendition.prev(); } }

  function onKey(e) {
    if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) { return; }
    if (e.key === 'Escape') {
      if (!$('rd-noteedit').hidden) { closeNoteEditor(); dropSelection(); return; }
      if (!$('rd-selacts').hidden) { dropSelection(); hideSelBar(); return; }
      if (panelsOpen()) { closePanels(); }
      return;
    }
    if (panelsOpen()) { return; }
    var t = e.target;
    if (t && t.closest && t.closest('button, a, input, select, textarea') && t.ownerDocument === document) {
      if (e.key === ' ' || e.key === 'Enter') { return; }
    }
    if (e.key === 'b' || e.key === 'B') { e.preventDefault(); toggleMark(); return; }
    if ((e.key === 'f' || e.key === 'F') && canFull) { e.preventDefault(); toggleFull(); return; }
    if (e.key === 'ArrowRight' || e.key === 'PageDown' || (e.key === ' ' && !e.shiftKey)) { e.preventDefault(); next(); }
    else if (e.key === 'ArrowLeft' || e.key === 'PageUp' || (e.key === ' ' && e.shiftKey)) { e.preventDefault(); prev(); }
  }

  /** Taps near the edges and sideways swipes inside the book turn the page. */
  function wireInput(contents) {
    var doc = contents.document;
    var touch = null;
    var swiped = false;
    /** True while the reader has text selected in this page. */
    function hasSelection() {
      var sel = doc.getSelection && doc.getSelection();
      return !!(sel && !sel.isCollapsed && String(sel).trim());
    }
    doc.addEventListener('keydown', onKey);
    doc.addEventListener('touchstart', function (e) {
      if (e.touches.length !== 1) { touch = null; return; }
      touch = { x: e.touches[0].screenX, y: e.touches[0].screenY, t: Date.now(), sel: hasSelection() };
      swiped = false;
    }, { passive: true });
    doc.addEventListener('touchend', function (e) {
      if (!touch) { return; }
      var dx = e.changedTouches[0].screenX - touch.x;
      var dy = e.changedTouches[0].screenY - touch.y;
      // Dragging the selection handles travels just like a swipe, so a touch
      // that starts or ends with text selected never turns the page.
      var selecting = touch.sel || hasSelection();
      if (!selecting && Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.5 && Date.now() - touch.t < 800) {
        swiped = true;
        if (dx < 0) { next(); } else { prev(); }
      }
      touch = null;
    });
    doc.addEventListener('click', function (e) {
      if (swiped) { swiped = false; return; }
      if (panelsOpen()) { closePanels(); return; }
      if (e.target.closest && e.target.closest('a[href]')) { return; }
      if (hasSelection()) { return; }
      // The tap that clears a selection shouldn't also turn the page: one
      // gesture, one thing happening.
      if (selNow) { hideSelBar(); return; }
      var frame = doc.defaultView && doc.defaultView.frameElement;
      var viewer = $('rd-viewer').getBoundingClientRect();
      var x = (frame ? frame.getBoundingClientRect().left : 0) + e.clientX - viewer.left;
      if (x < viewer.width * 0.25) { prev(); }
      else if (x > viewer.width * 0.75) { next(); }
    });
  }

  // ---- Progress percentages ---------------------------------------------

  var countsFailed = false;
  function locationsKey() { return 'kl-locations-' + cfg.id + '-' + cfg.version; }

  function loadLocations() {
    var saved = null;
    try { saved = localStorage.getItem(locationsKey()); } catch (e) { saved = null; }
    if (saved) {
      try { book.locations.load(saved); refreshWhere(); return; } catch (e) { /* regenerate */ }
    }
    // Counting pages through the whole book takes a moment, so do it after the first page shows.
    setTimeout(function () {
      book.locations.generate(1200).then(function () {
        try { localStorage.setItem(locationsKey(), book.locations.save()); } catch (e) { /* storage full or blocked */ }
        refreshWhere();
      }).catch(function () {
        // No page counts: the bar stays empty and the percentage can't be
        // worked out. The place you're at still saves.
        countsFailed = true;
        refreshWhere();
      });
    }, 300);
  }
  function refreshWhere() {
    var loc = rendition && rendition.currentLocation();
    if (loc && loc.start) { lastLocation = loc; showWhere(loc); }
  }

  // ---- Opening the book -------------------------------------------------

  function open() {
    if (typeof ePub !== 'function') { failed('The reader didn\'t load.'); return; }
    status('Opening the book…');
    fetch(cfg.fileUrl, { credentials: 'same-origin' }).then(function (res) {
      var type = res.headers.get('Content-Type') || '';
      if (!res.ok || type.indexOf('text/html') === 0) {
        throw new Error(res.status === 403 ? 'You\'re no longer signed in on this device.' : 'The book file couldn\'t be fetched from Dropbox.');
      }
      return res.arrayBuffer();
    }).then(function (buffer) {
      book = ePub(buffer);
      var s = spreadArgs();
      rendition = book.renderTo('rd-viewer', {
        width: '100%', height: '100%',
        flow: 'paginated', manager: 'default',
        spread: s[0], minSpreadWidth: s[1],
        allowScriptedContent: false
      });
      rendition.hooks.content.register(function (contents) {
        contents.addStylesheet(FONT_CSS);
        styleContents(contents);
        wireInput(contents);

      });
      rendition.on('selected', onSelected);
      rendition.on('relocated', function (loc) {
        lastLocation = loc;
        hideSelBar();
        showWhere(loc);
        savePosition(loc);
        noteFinished(loc);
        checkFit();
        if (noteNextPage) {
          noteNextPage = false;
          setTimeout(function () { if (!readingAnchor) { readingAnchor = findAnchor(); } }, 80);
        }
      });
      book.loaded.navigation.then(function (nav) { buildToc(nav.toc); });

      var start = cfg.position && cfg.position.cfi ? cfg.position.cfi : undefined;
      return rendition.display(start).catch(function () { return rendition.display(); });
    }).then(function () {
      status('');
      $('rd-mark-btn').disabled = false;
      lastBox = $('rd-viewer').clientWidth + 'x' + $('rd-viewer').clientHeight;
      drawAllNotes();
      renderNotes();
      loadLocations();
    }).catch(function (err) {
      failed(err && err.message ? err.message : 'The book couldn\'t be opened.');
    });
  }

  // ---- Keeping pages the size of the screen ----------------------------

  // Use the height the browser really shows. 100dvh alone can be taller than
  // the screen in full screen on Android, which hides the bottom line.
  function fitHeight() {
    var h = window.visualViewport ? window.visualViewport.height : window.innerHeight;
    if (h > 0) { document.documentElement.style.setProperty('--rd-h', Math.floor(h) + 'px'); }
  }
  fitHeight();
  window.addEventListener('resize', fitHeight);
  window.addEventListener('orientationchange', function () { setTimeout(fitHeight, 300); });
  if (window.visualViewport) { window.visualViewport.addEventListener('resize', fitHeight); }

  // Re-lay the pages whenever the reading area changes size, even when the
  // browser doesn't announce it (toolbars sliding away, full screen settling).
  var fitTimer = null;
  var lastBox = '';
  function refit() {
    clearTimeout(fitTimer);
    fitTimer = setTimeout(function () {
      var v = $('rd-viewer');
      var box = v.clientWidth + 'x' + v.clientHeight;
      if (!rendition || !lastLocation) { return; }
      if (box === lastBox) { checkFit(); return; }
      lastBox = box;
      var spot = anchorCfi();
      rendition.resize(undefined, undefined, spot);
      returnTo(spot);
      checkFit();
    }, 150);
  }
  // After each page turn and layout change, check the page still fits the
  // reading area exactly: the book's frame the same height as the area, not
  // shifted, and not scrolled up or down. Tablets can leave it off by a line
  // (full screen settling, fonts arriving late), which hides the top or
  // bottom line; when that happens, lay the pages out again at the same spot.
  var healing = false;
  function frameOff() {
    var v = $('rd-viewer');
    var fr = v.querySelector('iframe');
    if (!fr || !fr.contentDocument) { return false; }
    var doc = fr.contentDocument;
    var se = doc.scrollingElement || doc.documentElement;
    var scrolled = !!(se.scrollTop || (doc.body && doc.body.scrollTop));
    if (se.scrollTop) { se.scrollTop = 0; }
    if (doc.body && doc.body.scrollTop) { doc.body.scrollTop = 0; }
    var vr = v.getBoundingClientRect();
    var fb = fr.getBoundingClientRect();
    return scrolled || Math.abs(fb.height - v.clientHeight) > 1 || Math.abs(fb.top - vr.top) > 1;
  }
  var heals = [];
  function forceRelayout() {
    if (!rendition || !lastLocation || !lastLocation.start || healing) { return; }
    // Never loop: at most three attempts in any ten seconds.
    var t = Date.now();
    heals = heals.filter(function (x) { return t - x < 10000; });
    if (heals.length >= 3) { return; }
    heals.push(t);
    healing = true;
    if (rendition.manager) { rendition.manager._stageSize = undefined; }
    lastBox = $('rd-viewer').clientWidth + 'x' + $('rd-viewer').clientHeight;
    var spot = anchorCfi();
    rendition.resize(undefined, undefined, spot);
    returnTo(spot);
    setTimeout(function () { healing = false; }, 900);
  }
  var checkTimer = null;
  function checkFit() {
    clearTimeout(checkTimer);
    checkTimer = setTimeout(function () { if (frameOff()) { forceRelayout(); } }, 250);
  }

  if (window.ResizeObserver) {
    new ResizeObserver(refit).observe($('rd-viewer'));
  } else {
    window.addEventListener('resize', refit);
  }

  // ---- Full screen ------------------------------------------------------

  // Phones' Safari can't put a page in full screen, so the button only
  // appears where the browser supports it (desktop browsers, Android, iPad).
  var root = document.documentElement;
  var canFull = !!(document.fullscreenEnabled || document.webkitFullscreenEnabled);

  function isFull() { return !!(document.fullscreenElement || document.webkitFullscreenElement); }

  function toggleFull() {
    if (isFull()) {
      (document.exitFullscreen || document.webkitExitFullscreen).call(document);
    } else {
      var go = root.requestFullscreen || root.webkitRequestFullscreen;
      var r = go.call(root);
      if (r && r.catch) { r.catch(function () { /* refused, e.g. not from a tap */ }); }
    }
  }

  function showFull() {
    var on = isFull();
    $('rd-full-btn').setAttribute('aria-pressed', String(on));
    $('rd-full-btn').setAttribute('aria-label', on ? 'Leave full screen' : 'Full screen');
    $('rd-full-label').textContent = on ? 'Exit full screen' : 'Full screen';
    root.classList.toggle('is-full', on);
    fitHeight();
    [100, 400, 900].forEach(function (ms) { setTimeout(function () { fitHeight(); refit(); }, ms); });
  }

  if (canFull) {
    $('rd-full-btn').hidden = false;
    $('rd-full-btn').addEventListener('click', function () { closePanels(); toggleFull(); });
    document.addEventListener('fullscreenchange', showFull);
    document.addEventListener('webkitfullscreenchange', showFull);
    showFull();
  }

  // ---- Controls ---------------------------------------------------------

  $('rd-prev').addEventListener('click', prev);
  $('rd-next').addEventListener('click', next);
  $('rd-toc-btn').addEventListener('click', function () { togglePanel('rd-toc-btn', 'rd-toc'); });
  $('rd-mark-btn').addEventListener('click', function () { closePanels(); toggleMark(); });
  TABS.forEach(function (t) {
    $('rd-tab-' + t).addEventListener('click', function () { showTab(t); });
  });
  $('rd-toc').querySelector('.rd-tabs').addEventListener('keydown', function (e) {
    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
      e.preventDefault();
      e.stopPropagation();
      var step = e.key === 'ArrowRight' ? 1 : TABS.length - 1;
      var next = TABS[(TABS.indexOf(tocTab) + step) % TABS.length];
      showTab(next);
      $('rd-tab-' + next).focus();
    }
  });
  // Pressing a header button must not take the selection away before the
  // click runs, which is what a plain mousedown on another element does.
  ['rd-sel-mark', 'rd-sel-note'].forEach(function (id) {
    $(id).addEventListener('mousedown', function (e) { e.preventDefault(); });
  });
  $('rd-sel-mark').addEventListener('click', function () {
    if (!selNow) { return; }
    var cfi = selNow.cfi;
    if (noteAt(cfi)) {
      removeNote(cfi);
      toast('Highlight removed');
    } else {
      addNote(cfi, selNow.text, '');
      toast('Highlighted');
    }
    dropSelection();
    hideSelBar();
  });
  $('rd-sel-note').addEventListener('click', function () {
    if (!selNow) { return; }
    openNoteEditor(noteAt(selNow.cfi) || { cfi: selNow.cfi, text: selNow.text });
  });
  $('rd-noteedit-text').addEventListener('input', noteLeft);
  $('rd-noteedit-save').addEventListener('click', saveNoteEditor);
  $('rd-noteedit-cancel').addEventListener('click', function () { closeNoteEditor(); dropSelection(); });
  $('rd-noteedit-del').addEventListener('click', function () {
    if (!editing) { return; }
    var cfi = editing.cfi;
    closeNoteEditor();
    dropSelection();
    removeNote(cfi);
    toast('Highlight removed');
  });
  // Ctrl/Cmd+Enter saves, since Enter has to make paragraphs in the comment.
  $('rd-noteedit-text').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); saveNoteEditor(); }
  });
  // Clicking the backdrop leaves the comment alone rather than losing it.
  $('rd-noteedit').addEventListener('click', function (e) {
    if (e.target === $('rd-noteedit')) { closeNoteEditor(); dropSelection(); }
  });

  $('rd-set-btn').addEventListener('click', function () { togglePanel('rd-set-btn', 'rd-settings'); });
  Array.prototype.forEach.call(document.querySelectorAll('[data-close]'), function (b) {
    b.addEventListener('click', closePanels);
  });
  Array.prototype.forEach.call(document.querySelectorAll('.rd-seg'), function (group) {
    group.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-value]');
      if (b) { change(group.dataset.setting, b.dataset.value); }
    });
  });
  $('rd-smaller').addEventListener('click', function () { change('size', Math.max(70, settings.size - 10)); });
  $('rd-larger').addEventListener('click', function () { change('size', Math.min(200, settings.size + 10)); });
  document.addEventListener('keydown', onKey);
  document.addEventListener('click', function (e) {
    if (!$('rd-noteedit').hidden) { return; }
    if (e.target.closest('#rd-selacts')) { return; }
    if (panelsOpen() && !e.target.closest('.rd-panel, .rd-tool')) { closePanels(); }
  });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') { flushPosition(true); }
  });
  window.addEventListener('pagehide', function () { flushPosition(true); });

  syncControls();
  applyLook();
  renderMarks();
  open();
})();
