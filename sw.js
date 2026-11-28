const APP_CACHE = 'overworld-app-0.15';
const DATA_CACHE = 'overworld-data-v1';

// App shell + CDN libraries to pre-cache on install
const PRECACHE = [
  './',
  './assets/manifest.json',
  './assets/favicon.png',
  './assets/icon-192.png',
  './assets/icon-512.png',
  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
  'https://unpkg.com/leaflet-kml@1.0.1/L.KML.js',
  'https://unpkg.com/leaflet-omnivore@0.3.4/leaflet-omnivore.min.js',
  'https://unpkg.com/jszip@3.10.1/dist/jszip.min.js',
  'https://unpkg.com/geotiff@2.1.3/dist-browser/geotiff.js',
  'https://unpkg.com/georaster@1.6.0/dist/georaster.browser.bundle.min.js',
  'https://unpkg.com/georaster-layer-for-leaflet@4.1.2/dist/georaster-layer-for-leaflet.min.js',
];

// URL prefixes that should always go to the network (tiles, geocoding, routing)
const NETWORK_ONLY_PREFIXES = [
  'https://tile-cyclosm.',
  'https://tile.openstreetmap.org',
  'https://a.tile.openstreetmap.org',
  'https://b.tile.openstreetmap.org',
  'https://c.tile.openstreetmap.org',
  'https://a.basemaps.cartocdn.com',
  'https://b.basemaps.cartocdn.com',
  'https://c.basemaps.cartocdn.com',
  'https://nominatim.openstreetmap.org',
  'https://api.heigit.org',
  'https://geo.dot.gov',
];

// Same-origin paths under a data folder are heavy data files — cached separately so they
// work offline: the app's own data/ (local roots, samples) and the host's shared /overworld-data/
function isDataFile(url) {
  const path = new URL(url).pathname;
  return path.includes('/data/') || path.startsWith('/overworld-data/');
}

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(APP_CACHE).then(cache => cache.addAll(PRECACHE))
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(k => k.startsWith('overworld-app-') && k !== APP_CACHE)
          .map(k => caches.delete(k))
      )
    )
  );
  self.clients.claim();
});

// Config JSON — always fetch fresh so new layers and data files appear immediately:
// config.json, layer sets (any */layers/*.json in a data root),
// and the _files.json listing of each data root
function isLayerConfig(url) {
  const path = new URL(url).pathname;
  return path.endsWith('.json') &&
    (path.includes('/layers/') || path.endsWith('/config.json') || path.endsWith('/_files.json'));
}

// The status page (status/ on the server, status.html locally) is a live report behind
// a password: never cache it, and let the browser handle its login prompt
function isStatusPage(url) {
  return url.startsWith(self.registration.scope + 'status');
}

self.addEventListener('fetch', event => {
  const url = event.request.url;

  if (isStatusPage(url)) return;

  // Always use network for tiles and external APIs
  if (NETWORK_ONLY_PREFIXES.some(p => url.startsWith(p))) {
    return;
  }

  // Network-first for layer configs: always get the latest, fall back to cache offline
  if (isLayerConfig(url)) {
    event.respondWith(
      fetch(event.request)
        .then(response => {
          if (response.ok && event.request.method === 'GET') {
            const clone = response.clone();
            caches.open(APP_CACHE).then(cache => cache.put(event.request, clone));
          }
          return response;
        })
        .catch(() => caches.match(event.request))
    );
    return;
  }

  // Data files change when they're regenerated: revalidate with the server (a cheap
  // 304 when unchanged) and use the cached copy only when offline
  if (isDataFile(url) && url.startsWith(self.location.origin)) {
    event.respondWith(
      fetch(event.request, { cache: 'no-cache' })
        .then(response => {
          if (response.ok && event.request.method === 'GET') {
            const clone = response.clone();
            caches.open(DATA_CACHE).then(cache => cache.put(event.request, clone));
          }
          return response;
        })
        .catch(() => caches.match(event.request))
    );
    return;
  }

  const cacheName = isDataFile(url) ? DATA_CACHE : APP_CACHE;

  event.respondWith(
    caches.match(event.request).then(cached => {
      if (cached) return cached;
      return fetch(event.request).then(response => {
        if (
          response.ok &&
          event.request.method === 'GET' &&
          (url.startsWith(self.location.origin) || url.startsWith('https://unpkg.com'))
        ) {
          const clone = response.clone();
          caches.open(cacheName).then(cache => cache.put(event.request, clone));
        }
        return response;
      });
    })
  );
});
