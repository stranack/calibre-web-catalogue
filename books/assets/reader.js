/* Kevin's Library reader: opens an EPUB from file.php with epub.js, applies the
   chosen pages, font, size and background, and saves your place to the site
   so another device can carry on from it. */
(function () {
  'use strict';

  var cfg = JSON.parse(document.getElementById('reader-config').textContent);

  // Colours inside the book. The page around it uses the same names in reader.css.
  var THEMES = {
    paper: { bg: '#ffffff', fg: '#1f2a29', link: '#1f6a58', force: false },
    sepia: { bg: '#f6eedd', fg: '#3a2e21', link: '#8a4b12', force: true },
    mist:  { bg: '#e7ebe9', fg: '#1f2726', link: '#1f6a58', force: true },
    night: { bg: '#151a19', fg: '#ffffff', link: '#74c4ab', force: true }
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
    if (t.force) {
      // Tinted and dark backgrounds only work if the book's own colours give way.
      css += 'body * { color: inherit !important; background-color: transparent !important; }\n' +
        'a, a * { color: ' + t.link + ' !important; }\n';
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

  /** Re-lay the book after a change that alters how much fits on a page. */
  function relayout() {
    if (!rendition) { return; }
    var cfi = lastLocation && lastLocation.start ? lastLocation.start.cfi : null;
    var s = spreadArgs();
    rendition.spread(s[0], s[1]);
    rendition.resize();
    if (cfi) {
      rendition.display(cfi);
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
    return cfg.position && typeof cfg.position.pct === 'number' ? cfg.position.pct : null;
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
    $('rd-where').textContent = pct === null ? '' : Math.round(pct * 100) + '%';
    $('rd-progress-fill').style.width = pct === null ? '0' : (pct * 100).toFixed(1) + '%';
    $('rd-prev').disabled = !!loc.atStart;
    $('rd-next').disabled = !!loc.atEnd;
    markTocCurrent(loc && loc.start ? loc.start.href : '');
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
          rendition.display(it.href);
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
    settings[key] = value;
    syncControls();
    applyLook();
    if (key !== 'theme') { relayout(); }
    saveSettings();
  }

  // ---- Turning pages ----------------------------------------------------

  function next() { if (rendition) { rendition.next(); } }
  function prev() { if (rendition) { rendition.prev(); } }

  function onKey(e) {
    if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) { return; }
    if (e.key === 'Escape') { if (panelsOpen()) { closePanels(); } return; }
    if (panelsOpen()) { return; }
    var t = e.target;
    if (t && t.closest && t.closest('button, a, input, select, textarea') && t.ownerDocument === document) {
      if (e.key === ' ' || e.key === 'Enter') { return; }
    }
    if ((e.key === 'f' || e.key === 'F') && canFull) { e.preventDefault(); toggleFull(); return; }
    if (e.key === 'ArrowRight' || e.key === 'PageDown' || (e.key === ' ' && !e.shiftKey)) { e.preventDefault(); next(); }
    else if (e.key === 'ArrowLeft' || e.key === 'PageUp' || (e.key === ' ' && e.shiftKey)) { e.preventDefault(); prev(); }
  }

  /** Taps near the edges and sideways swipes inside the book turn the page. */
  function wireInput(contents) {
    var doc = contents.document;
    var touch = null;
    var swiped = false;
    doc.addEventListener('keydown', onKey);
    doc.addEventListener('touchstart', function (e) {
      if (e.touches.length !== 1) { touch = null; return; }
      touch = { x: e.touches[0].screenX, y: e.touches[0].screenY, t: Date.now() };
      swiped = false;
    }, { passive: true });
    doc.addEventListener('touchend', function (e) {
      if (!touch) { return; }
      var dx = e.changedTouches[0].screenX - touch.x;
      var dy = e.changedTouches[0].screenY - touch.y;
      if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.5 && Date.now() - touch.t < 800) {
        swiped = true;
        if (dx < 0) { next(); } else { prev(); }
      }
      touch = null;
    });
    doc.addEventListener('click', function (e) {
      if (swiped) { swiped = false; return; }
      if (panelsOpen()) { closePanels(); return; }
      if (e.target.closest && e.target.closest('a[href]')) { return; }
      var sel = doc.getSelection && doc.getSelection();
      if (sel && !sel.isCollapsed) { return; }
      var frame = doc.defaultView && doc.defaultView.frameElement;
      var viewer = $('rd-viewer').getBoundingClientRect();
      var x = (frame ? frame.getBoundingClientRect().left : 0) + e.clientX - viewer.left;
      if (x < viewer.width * 0.25) { prev(); }
      else if (x > viewer.width * 0.75) { next(); }
    });
  }

  // ---- Progress percentages ---------------------------------------------

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
      rendition.on('relocated', function (loc) {
        lastLocation = loc;
        showWhere(loc);
        savePosition(loc);
      });
      book.loaded.navigation.then(function (nav) { buildToc(nav.toc); });

      var start = cfg.position && cfg.position.cfi ? cfg.position.cfi : undefined;
      return rendition.display(start).catch(function () { return rendition.display(); });
    }).then(function () {
      status('');
      loadLocations();
    }).catch(function (err) {
      failed(err && err.message ? err.message : 'The book couldn\'t be opened.');
    });
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
    if (panelsOpen() && !e.target.closest('.rd-panel, .rd-tool')) { closePanels(); }
  });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') { flushPosition(true); }
  });
  window.addEventListener('pagehide', function () { flushPosition(true); });

  syncControls();
  applyLook();
  open();
})();
