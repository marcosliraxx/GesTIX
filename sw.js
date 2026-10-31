/* Caminho: sw.js */
const CACHE_NAME = 'arcoll-static-v3.4.0';
const ROOT = new URL('./', self.location.href);
const STATIC_ROOT = new URL('assets/', ROOT).pathname;
const PRECACHE = ['assets/css/arcoll.css', 'assets/js/arcoll.js', 'assets/vendor/font-awesome/css/font-awesome.min.css', 'assets/vendor/font-awesome/fonts/fontawesome-webfont.woff2', 'assets/img/app-mark.svg', 'assets/img/app-icon-192.png', 'assets/img/app-icon-512.png'];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME)
    .then(cache => Promise.allSettled(PRECACHE.map(path => cache.add(new URL(path, ROOT).href))))
    .then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
  event.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(key => key !== CACHE_NAME && key.startsWith('arcoll-')).map(key => caches.delete(key))))
    .then(() => self.clients.claim()));
});

self.addEventListener('message', event => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

// Somente recursos estáticos públicos entram no cache. HTML e APIs usam a rede.
self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || request.mode === 'navigate' || url.origin !== self.location.origin) return;
  if (!url.pathname.startsWith(STATIC_ROOT) || !/\.(?:css|js|png|jpg|jpeg|webp|svg|ico|woff2?)$/i.test(url.pathname)) return;
  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME);
    try {
      const response = await fetch(request);
      if (response.ok && response.type === 'basic' && !response.redirected) await cache.put(request, response.clone());
      return response;
    } catch {
      return (await cache.match(request)) || Response.error();
    }
  })());
});
