// Service worker: keeps the app shell for offline starts. Network first, so a new version is
// used at once; the cache only answers when the server cannot be reached. Never caches the API.
const CACHE = 'labeler-shell-v1';
const SHELL = ['./', 'index.html', 'app.css', 'icon.svg', 'manifest.webmanifest', 'js/main.js', 'lib/qrcode.mjs'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.endsWith('/api.php')) return;
  event.respondWith(fetch(event.request)
    .then((response) => {
      if (response.ok) {
        const copy = response.clone();
        caches.open(CACHE).then((cache) => cache.put(event.request, copy));
      }
      return response;
    })
    .catch(() => caches.match(event.request).then((hit) => hit || Response.error())));
});
