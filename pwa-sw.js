const CACHE_VERSION = 'bujanaya-pwa-v14';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;

function appScopePath() {
  return new URL(self.registration.scope).pathname.replace(/\/$/, '') + '/';
}

function scopeUrl(path) {
  return appScopePath() + path.replace(/^\/+/, '');
}

function isStaticAsset(pathname) {
  return /\.(?:css|js|png|jpg|jpeg|svg|webp|gif|ico|woff2?)$/i.test(pathname);
}

self.addEventListener('install', (event) => {
  const precache = [
    scopeUrl('offline.html'),
    scopeUrl('manifest.json'),
    scopeUrl('manifest.webmanifest'),
    scopeUrl('pwa/icon-192.png'),
    scopeUrl('pwa/icon-512.png'),
    scopeUrl('assets/css/custom.css'),
    scopeUrl('assets/js/custom.js'),
    scopeUrl('assets/js/app-ui.js')
  ];

  event.waitUntil(
    caches.open(STATIC_CACHE)
      .then((cache) => cache.addAll(precache))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      const deletions = keys
        .filter((key) => key !== STATIC_CACHE && key !== RUNTIME_CACHE)
        .map((key) => caches.delete(key));
      return Promise.all(deletions);
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  const isSameOrigin = url.origin === self.location.origin;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone();
          caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, copy)).catch(() => {});
          return response;
        })
        .catch(async () => {
          const cachedPage = await caches.match(request);
          if (cachedPage) {
            return cachedPage;
          }
          const offline = await caches.match(scopeUrl('offline.html'));
          return offline || Response.error();
        })
    );
    return;
  }

  if (isSameOrigin && isStaticAsset(url.pathname)) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) {
          return cached;
        }
        return fetch(request).then((response) => {
          const copy = response.clone();
          caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, copy)).catch(() => {});
          return response;
        });
      })
    );
    return;
  }

  if (isSameOrigin) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone();
          caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, copy)).catch(() => {});
          return response;
        })
        .catch(() => caches.match(request))
    );
  }
});
