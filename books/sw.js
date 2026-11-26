/* Home Library service worker.

   It keeps copies of the pages, styles, scripts, fonts and covers you've
   used, so the site opens quickly and still works with no connection. The
   books themselves are kept by the reader (assets/reader.js) in the
   "hl-books" cache; this worker never touches book files or saves.

   Pages: network first, falling back to the saved copy after a few seconds
   or when offline, then to offline.html.
   Styles, scripts, covers, fonts: saved copy first (their addresses change
   when they do), fetched and kept when missing. */
'use strict';

var VERSION = 'hl-v1';
var SHELL = VERSION + '-shell';   // assets, fonts, offline page
var PAGES = VERSION + '-pages';   // catalogue and reader pages
var COVERS = VERSION + '-covers';
var KEEP = [SHELL, PAGES, COVERS, 'hl-books'];
var MAX_PAGES = 300;
var MAX_COVERS = 1500;
var PAGE_WAIT = 4000; // ms to wait for the network before using a saved page

var PRECACHE = [
  'offline.html',
  'assets/style.css',
  'assets/reader.css',
  'assets/reader.js',
  'assets/site.js',
  'assets/app.js',
  'assets/icon.svg',
  'assets/icon-192.png',
  'assets/vendor/jszip-3.10.1.min.js',
  'assets/vendor/epub-0.3.93.min.js'
];

// Pages worth keeping a copy of. Admin, sync and file downloads never are.
var PAGE_RE = /\/(index|book|browse|read)\.php$|\/$/;

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(SHELL).then(function (c) {
    // One missing file shouldn't stop the rest being saved.
    return Promise.all(PRECACHE.map(function (u) {
      return c.add(new Request(u, { cache: 'reload' })).catch(function () {});
    }));
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return KEEP.indexOf(k) < 0; })
      .map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('message', function (e) {
  // Sent by app.js when the browser is no longer signed in as the owner.
  if (e.data === 'forget-private') {
    e.waitUntil(Promise.all([caches.delete(PAGES), caches.delete('hl-books')]));
  }
});

function trim(cacheName, max) {
  return caches.open(cacheName).then(function (c) {
    return c.keys().then(function (keys) {
      var extra = keys.length - max;
      if (extra <= 0) { return; }
      return Promise.all(keys.slice(0, extra).map(function (k) { return c.delete(k); }));
    });
  });
}

function isPage(req, url) {
  return req.mode === 'navigate' || (req.headers.get('Accept') || '').indexOf('text/html') === 0
    ? PAGE_RE.test(url.pathname) : false;
}

function pageFetch(e, req) {
  var saved = caches.open(PAGES).then(function (c) { return c.match(req); });
  var net = fetch(req).then(function (res) {
    // Only a real page for the owner is worth keeping: not an error, not a
    // "sign in" page (those come back as 403).
    if (res.ok && res.type === 'basic') {
      var copy = res.clone();
      e.waitUntil(caches.open(PAGES).then(function (c) { return c.put(req, copy); })
        .then(function () { return trim(PAGES, MAX_PAGES); }));
    }
    return res;
  });
  var timeout = new Promise(function (resolve) { setTimeout(resolve, PAGE_WAIT); });

  return Promise.race([net.catch(function () { return null; }), timeout.then(function () { return 'slow'; })])
    .then(function (first) {
      if (first && first !== 'slow') { return first; }
      // Offline or slow: a saved copy if there is one, otherwise keep waiting.
      return saved.then(function (hit) {
        if (hit) { return hit; }
        return net.catch(function () {
          return caches.match('offline.html', { cacheName: SHELL });
        });
      });
    });
}

function keptFetch(e, req, cacheName, max, loose) {
  return caches.open(cacheName).then(function (c) {
    return c.match(req).then(function (hit) {
      if (hit) { return hit; }
      return fetch(req).then(function (res) {
        if (res.ok || res.type === 'opaque') {
          var copy = res.clone();
          e.waitUntil(c.put(req, copy).then(function () { return max ? trim(cacheName, max) : null; }));
        }
        return res;
      }).catch(function (err) {
        // Offline, and this exact version isn't saved: any version will do.
        if (!loose) { throw err; }
        return c.match(req, { ignoreSearch: true }).then(function (any) {
          if (any) { return any; }
          throw err;
        });
      });
    });
  });
}

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') { return; }
  var url = new URL(req.url);

  if (url.origin === 'https://fonts.googleapis.com' || url.origin === 'https://fonts.gstatic.com') {
    e.respondWith(keptFetch(e, req, SHELL, 0, false));
    return;
  }
  if (url.origin !== self.location.origin) { return; }

  var base = self.registration.scope;
  if (req.url.indexOf(base) !== 0) { return; }
  var rel = req.url.slice(base.length);

  if (/^assets\//.test(rel)) {
    e.respondWith(keptFetch(e, req, SHELL, 0, true));
  } else if (/^covers\//.test(rel)) {
    e.respondWith(keptFetch(e, req, COVERS, MAX_COVERS, true));
  } else if (isPage(req, url)) {
    e.respondWith(pageFetch(e, req));
  }
  // Everything else (file.php, reading.php, admin.php, manifest) goes
  // straight to the network as before.
});
