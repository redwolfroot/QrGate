// QrGate handheld PWA service worker
// Network-first for the app shell (php/css/js + navigations) so redesigns
// propagate immediately; cache-first for static icons/CDN; POST + API always live.
const CACHE_NAME = 'qr-gate-handheld-v9';

const PRECACHE = [
  './',
  './index.php',
  './inspector.php',
  './kasse.php',
  './handheld.css',
  './scanner.js',
  './manifest.json',
  './success.mp3',
  './error.mp3',
  './icon-72x72.png',
  './icon-96x96.png',
  './icon-128x128.png',
  './icon-144x144.png',
  './icon-152x152.png',
  './icon-192x192.png',
  './icon-384x384.png',
  './icon-512x512.png',
  'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js'
];

self.addEventListener('install', function (event) {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      // tolerate individual failures (e.g. CDN offline at install time)
      return Promise.allSettled(PRECACHE.map(function (u) { return cache.add(u); }));
    })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (k) {
        if (k !== CACHE_NAME) return caches.delete(k);
      }));
    }).then(function () {
      // Best-effort backfill: if the CDN (or any asset) was unreachable at
      // install time, try once more now that we're activating — the device may
      // have regained connectivity. Failures are swallowed so this never blocks
      // activation, mirroring the install-time allSettled tolerance.
      return caches.open(CACHE_NAME).then(function (cache) {
        return Promise.allSettled(PRECACHE.map(function (u) {
          return cache.match(u).then(function (hit) {
            return hit ? null : cache.add(u).catch(function () {});
          });
        }));
      });
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  const req = event.request;

  // never intercept non-GET (ticket validate/get POSTs) or API calls
  if (req.method !== 'GET' || req.url.indexOf('/api/') !== -1) {
    return; // let the browser handle it live
  }

  const url = new URL(req.url);
  const isShell = req.mode === 'navigate' ||
    /\.(php|css|js)$/.test(url.pathname);

  // only ever cache successful, basic/CORS (non-opaque) responses so a
  // transient error page or an opaque 3rd-party error can't poison the shell
  function cacheable(res) {
    return res && res.ok && (res.type === 'basic' || res.type === 'cors');
  }

  if (isShell) {
    // network-first: fresh shell when online, cached fallback when offline
    event.respondWith(
      fetch(req).then(function (res) {
        if (cacheable(res)) {
          const copy = res.clone();
          caches.open(CACHE_NAME).then(function (c) { c.put(req, copy); });
        }
        return res;
      }).catch(function () {
        return caches.match(req).then(function (hit) {
          return hit || caches.match('./index.php');
        });
      })
    );
  } else {
    // cache-first: icons, sounds, CDN libs
    event.respondWith(
      caches.match(req).then(function (cached) {
        return cached || fetch(req).then(function (res) {
          if (cacheable(res)) {
            const copy = res.clone();
            caches.open(CACHE_NAME).then(function (c) { c.put(req, copy); });
          }
          return res;
        });
      })
    );
  }
});
