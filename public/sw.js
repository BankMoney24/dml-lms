// Service worker. Caches only public, non-personal files: styles, script,
// icon and an offline page. Personal pages are never stored here, so there is
// nothing private to clean up on sign-out. Bump CACHE when assets change.
'use strict';

var CACHE = 'dml-shell-v3';
var SHELL = ['/assets/app.css', '/assets/app.js', '/assets/icon.svg', '/offline.html'];

self.addEventListener('install', function (event) {
  event.waitUntil(caches.open(CACHE).then(function (cache) { return cache.addAll(SHELL); }));
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }));
  self.clients.claim();
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') { return; }
  var url = new URL(request.url);
  if (url.origin !== location.origin) { return; }

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(function () { return caches.match('/offline.html'); }));
    return;
  }
  if (url.pathname.indexOf('/assets/') === 0) {
    event.respondWith(caches.match(request, { ignoreSearch: true }).then(function (hit) {
      var network = fetch(request).then(function (response) {
        if (response.ok) { caches.open(CACHE).then(function (c) { c.put(request, response.clone()); }); }
        return response;
      });
      return hit || network;
    }));
  }
});
