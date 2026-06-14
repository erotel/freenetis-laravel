// FreenetIS Field — minimální service worker.
// ZÁMĚRNĚ neintercaptuje navigace (HTML stránky) — necháváme je čistě na
// prohlížeči, aby SW nemohl zasahovat do přihlašování / session cookies.
// Cachuje jen statický shell (ikony, manifest, offline stránku) kvůli
// instalovatelnosti PWA a rychlému startu.
//
// Cesty jsou RELATIVNÍ ke scope SW → funguje pod rootem i pod subpath.
const CACHE = 'fnis-field-v2';
const SHELL_REL = ['manifest.json', 'icon-192.png', 'icon-512.png', 'offline.html'];
const SHELL = SHELL_REL.map((p) => new URL(p, self.registration.scope).href);

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

    // POUZE statický shell se serveruje z cache (cache-first). Vše ostatní —
    // navigace i API/JSON — jde rovnou na síť bez zásahu SW (kvůli session).
    if (SHELL.includes(req.url)) {
        event.respondWith(caches.match(req).then((hit) => hit || fetch(req)));
    }
});
