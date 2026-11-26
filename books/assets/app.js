/* Shared by every page, the reader included: registers the service worker
   (sw.js) that lets the library install as an app and open without a
   connection, and forgets saved books on a browser that's no longer signed in. */
(function () {
  'use strict';
  if (!('serviceWorker' in navigator)) { return; }

  var owner = document.documentElement.getAttribute('data-owner') === '1';

  if (!owner && navigator.onLine) {
    // Signed out (or never signed in): books and reader pages saved here
    // earlier are private, so drop them, along with the reader's notes to self.
    try {
      Object.keys(localStorage).forEach(function (k) {
        if (/^(hl-offline|hl-outbox|hl-book-|hl-pos-)/.test(k)) { localStorage.removeItem(k); }
      });
    } catch (e) { /* storage blocked */ }
    if (window.caches) {
      caches.delete('hl-books').catch(function () {});
      caches.keys().then(function (keys) {
        keys.forEach(function (k) { if (/-pages$/.test(k)) { caches.delete(k); } });
      }).catch(function () {});
    }
  }

  window.addEventListener('load', function () {
    navigator.serviceWorker.register('sw.js', { scope: './' }).catch(function () {
      /* not served over https, or blocked: the site works as before */
    });
  });
})();
