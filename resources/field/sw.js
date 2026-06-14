// FreenetIS Field — minimální service worker.
// Cachuje app shell (ikony, manifest, offline fallback). Není offline-first —
// technici mají signál; jde jen o rychlý start a smysluplnou offline stránku.
//
// Cesty jsou RELATIVNÍ k umístění tohoto SW (self.registration.scope), takže
// to funguje jak pod doménovým rootem (is.pvfree.net/field/), tak pod subpath
// (.../freenetis/field/).
const CACHE = 'fnis-field-v1';
const SHELL_REL = ['manifest.json', 'icon-192.png', 'icon-512.png', 'offline.html'];
const SHELL = SHELL_REL.map((p) => new URL(p, self.registration.scope).href);
const OFFLINE = new URL('offline.html', self.registration.scope).href;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    // Shell assety: cache-first.
    if (SHELL.includes(req.url)) {
        event.respondWith(caches.match(req).then((hit) => hit || fetch(req)));
        return;
    }

    // Navigace: network-first, offline fallback. Data/HTML nikdy necachujeme,
    // aby technik neviděl zastaralé saldo/IP.
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
    }
});
