// Service worker: installability only. Deliberately caches nothing — every
// request goes straight to the network, and any cache a previous version of
// this worker created is purged on activate.

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const names = await caches.keys();
            await Promise.all(names.map((name) => caches.delete(name)));
            await self.clients.claim();
        })(),
    );
});

self.addEventListener('fetch', (event) => {
    event.respondWith(fetch(event.request));
});
