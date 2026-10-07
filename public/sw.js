// Service worker de Escríbelo. Existe sobre todo para que la app sea instalable
// (Chrome exige un SW con handler de fetch); la app necesita el server, así que
// no intenta funcionar offline.
//
// Si cambian íconos o manifest, subir CACHE (activate borra las otras) y el ?v=
// de los íconos en el manifest y en app.blade.php.
const CACHE = 'escribelo-v1';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((c) => c.addAll(['/manifest.webmanifest'])).catch(() => {}),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))),
        ),
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Audio con Range (seek) y respuestas Inertia (misma URL, HTML o JSON según
    // X-Inertia) van directo a la red: cachearlas rompe el seek o muestra el JSON crudo.
    if (request.headers.has('range') || request.headers.has('x-inertia')) return;

    // Cache-first SOLO para assets de Vite, que llevan hash en el nombre.
    // Cualquier URL fija (íconos, manifest, páginas) queda en la red.
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then(
                (hit) =>
                    hit ||
                    fetch(request).then((res) => {
                        if (res.ok) {
                            const copy = res.clone();
                            caches.open(CACHE).then((c) => c.put(request, copy));
                        }
                        return res;
                    }),
            ),
        );
    }
});
