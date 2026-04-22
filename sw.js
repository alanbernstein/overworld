const CACHE = 'overworld-v1';

// App shell + CDN libraries to pre-cache on install
const PRECACHE = [
  './',
  './manifest.json',
  './favicon.png',
  './icon-192.png',
  './icon-512.png',
  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
  'https://unpkg.com/leaflet-kml/L.KML.js',
  'https://unpkg.com/leaflet-omnivore@0.3.4/leaflet-omnivore.min.js',
  'https://unpkg.com/jszip@3.10.1/dist/jszip.min.js',
  'https://unpkg.com/geotiff@2.1.3/dist-browser/geotiff.js',
  'https://unpkg.com/georaster/dist/georaster.browser.bundle.min.js',
  'https://unpkg.com/georaster-layer-for-leaflet/dist/georaster-layer-for-leaflet.min.js',
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
  'https://api.openrouteservice.org',
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE).then(cache => cache.addAll(PRECACHE))
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const url = event.request.url;

  // Always use network for tiles and external APIs
  if (NETWORK_ONLY_PREFIXES.some(p => url.startsWith(p))) {
    return; // fall through to browser default
  }

  event.respondWith(
    caches.match(event.request).then(cached => {
      if (cached) return cached;
      return fetch(event.request).then(response => {
        // Cache successful GET responses for same-origin and CDN assets
        if (
          response.ok &&
          event.request.method === 'GET' &&
          (url.startsWith(self.location.origin) || url.startsWith('https://unpkg.com'))
        ) {
          const clone = response.clone();
          caches.open(CACHE).then(cache => cache.put(event.request, clone));
        }
        return response;
      });
    })
  );
});
