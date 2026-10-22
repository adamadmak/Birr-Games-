/*
 * Service worker for the installable app.
 *
 * Deliberately conservative. This is a real-money wallet: a cached page could show
 * a balance, a bet or an order that is no longer true, so nothing that carries state
 * is ever served from the cache. Only immutable static assets are cached, and every
 * one of those already carries a ?v=<filemtime> string that changes when the file
 * does, so a cached copy can never be the wrong version.
 *
 * Its other job is to exist at all: a service worker with a fetch handler is what
 * makes the browser offer "Install app" / "Add to Home Screen".
 */
const VERSION = 'v1';
const ASSETS = `lucy-assets-${VERSION}`;
const SHELL = `lucy-shell-${VERSION}`;
const OFFLINE_URL = '/offline.html';

/* Same-origin files that are safe to keep: versioned by their query string. */
const CACHEABLE = /\.(?:css|js|png|jpe?g|webp|svg|ico|woff2?|ttf|eot)$/i;

/* Anything that reads or moves money, or that identifies a player, is never cached. */
const NEVER_CACHE = /^\/(?:admin|agent|player|login|logout|register|telegram|games\/[^/]+\/(?:play|provider))/;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL)
            .then((cache) => cache.addAll([OFFLINE_URL, '/icons/icon-192.png']))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== ASSETS && key !== SHELL).map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    const sameOrigin = url.origin === self.location.origin;

    /* Pages always come from the network, so a balance is never a stale one. The
       offline page is shown only when the network genuinely cannot be reached. */
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL).then((r) => r || Response.error()))
        );
        return;
    }

    if (!sameOrigin || NEVER_CACHE.test(url.pathname) || !CACHEABLE.test(url.pathname)) return;

    /* Versioned static asset: serve the cached copy, and refresh it in the background. */
    event.respondWith(
        caches.open(ASSETS).then((cache) => cache.match(request).then((cached) => {
            const network = fetch(request).then((response) => {
                if (response && response.ok && response.type === 'basic') {
                    cache.put(request, response.clone());
                }
                return response;
            }).catch(() => cached);

            return cached || network;
        }))
    );
});
