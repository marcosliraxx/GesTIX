/* Caminho: sw.js */
const CACHE_NAME_PROMISE=fetch(new URL('api/api.php?action=release-info',self.location.href),{cache:'no-store'}).then(r=>r.json()).then(r=>'arcoll-static-'+r.version).catch(()=>'arcoll-static-offline');
const ROOT = new URL('./', self.location.href);
const STATIC_ROOT = new URL('assets/', ROOT).pathname;
const PRECACHE = ['assets/css/arcoll.css', 'assets/js/arcoll.js', 'assets/vendor/font-awesome/css/font-awesome.min.css', 'assets/vendor/font-awesome/fonts/FontAwesome.otf', 'assets/img/app-mark.svg', 'assets/img/app-icon-192.png', 'assets/img/app-icon-512.png'];

self.addEventListener('install', event => {
  event.waitUntil(CACHE_NAME_PROMISE.then(name=>caches.open(name))
    .then(cache => Promise.allSettled(PRECACHE.map(path => cache.add(new URL(path, ROOT).href))))
    .then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
  event.waitUntil(CACHE_NAME_PROMISE.then(name=>caches.keys()
    .then(keys => Promise.all(keys.filter(key => key !== name && key.startsWith('arcoll-')).map(key => caches.delete(key))))
    .then(() => self.clients.claim())));
});

self.addEventListener('message', event => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

// Somente recursos estáticos públicos entram no cache. HTML e APIs usam a rede.
self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || request.mode === 'navigate' || url.origin !== self.location.origin) return;
  if (!url.pathname.startsWith(STATIC_ROOT) || !/\.(?:css|js|png|jpg|jpeg|webp|svg|ico|otf|woff2?)$/i.test(url.pathname)) return;
  event.respondWith((async () => {
    const cache = await CACHE_NAME_PROMISE.then(name=>caches.open(name));
    try {
      const response = await fetch(request);
      if (response.ok && response.type === 'basic' && !response.redirected) await cache.put(request, response.clone());
      return response;
    } catch {
      return (await cache.match(request)) || Response.error();
    }
  })());
});
