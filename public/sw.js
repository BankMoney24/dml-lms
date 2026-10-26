// Service worker (ADR 0033).
//
// Two caches:
//  - the shell: public files only (styles, scripts, icon, the offline page);
//  - "dml-saved-<person>": lessons a signed-in person chose to save for offline.
//    app.js puts them there and removes them when that person signs out or someone
//    else signs in on the device. This worker only reads them, when there is no network.
// Pages always come from the network first; a saved copy is used only when offline.
'use strict';

var CACHE = 'dml-shell-v4';
var SHELL = ['/assets/app.css', '/assets/app.js', '/assets/offline.js', '/assets/icon.svg', '/assets/icons.svg', '/offline.html'];

self.addEventListener('install', function (event) {
  event.waitUntil(caches.open(CACHE).then(function (cache) { return cache.addAll(SHELL); }));
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    // Old shells go; saved lessons stay (app.js manages them).
    return Promise.all(keys.filter(function (k) { return k !== CACHE && k.indexOf('dml-saved-') !== 0; }).map(function (k) { return caches.delete(k); }));
  }));
  self.clients.claim();
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') { return; }
  var url = new URL(request.url);
  if (url.origin !== location.origin) { return; }

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(function () {
      return caches.match(url.pathname, { ignoreSearch: true }).then(function (saved) {
        return saved || caches.match('/offline.html');
      });
    }));
    return;
  }
  // The school logo is public and its address changes with it, so it is kept like a static file.
  if (url.pathname.indexOf('/assets/') === 0 || url.pathname === '/logo') {
    event.respondWith(caches.match(request, { ignoreSearch: true }).then(function (hit) {
      var network = fetch(request).then(function (response) {
        if (response.ok) { var copy = response.clone(); caches.open(CACHE).then(function (c) { c.put(request, copy); }); }
        return response;
      });
      return hit || network;
    }));
  }
});
