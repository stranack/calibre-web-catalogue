// Small enhancements only; every page works without JavaScript.
(function () {
  // Sort menus apply as soon as a choice is made.
  document.querySelectorAll('select[data-autosubmit]').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });

  // Press "/" to jump to the search box.
  document.addEventListener('keydown', function (e) {
    if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
    var t = e.target;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
    var box = document.getElementById('q-hero') || document.getElementById('q-top');
    if (box) { e.preventDefault(); box.focus(); box.select(); }
  });

  // Long descriptions start folded, with a button to open them.
  document.querySelectorAll('[data-clamp]').forEach(function (el) {
    var lineHeight = parseFloat(getComputedStyle(el).lineHeight) || 26;
    if (el.scrollHeight < lineHeight * 12) return;
    el.classList.add('is-clamped');
    el.id = el.id || 'desc';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'more-toggle';
    btn.setAttribute('aria-controls', el.id);
    btn.setAttribute('aria-expanded', 'false');
    btn.textContent = 'Read more';
    btn.addEventListener('click', function () {
      var open = el.classList.toggle('is-clamped') === false;
      btn.setAttribute('aria-expanded', String(open));
      btn.textContent = open ? 'Show less' : 'Read more';
    });
    el.insertAdjacentElement('afterend', btn);
  });

  // Book page (owner): mark a book as read or unread.
  document.querySelectorAll('[data-read-toggle]').forEach(function (box) {
    var btn = box.querySelector('button');
    if (!btn || !window.fetch) return;
    btn.hidden = false;
    btn.addEventListener('click', function () {
      var done = box.getAttribute('data-done') === '1';
      btn.disabled = true;
      fetch('reading.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Library-Reader': '1' },
        body: JSON.stringify({ id: parseInt(box.getAttribute('data-id'), 10), finished: !done, how: 'manual' })
      }).then(function (r) {
        if (!r.ok) throw new Error('save failed');
        location.reload();
      }).catch(function () {
        btn.disabled = false;
        btn.textContent = 'Couldn\u2019t save \u2014 try again';
      });
    });
  });

  // Admin: keep a multi-step sync going until every cover is in.
  var cont = document.querySelector('form[data-autocontinue]');
  if (cont) {
    setTimeout(function () { cont.submit(); }, 800);
  }
})();
