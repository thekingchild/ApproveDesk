const CACHE = 'approvedesk-shell-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll([OFFLINE_URL, '/favicon.svg'])),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    event.respondWith(
        fetch(request).catch(async () => {
            const cached = await caches.match(request);
            return cached || caches.match(OFFLINE_URL);
        }),
    );
});

self.addEventListener('push', (event) => {
    let data = { title: 'ApproveDesk', body: 'You have an update.', url: '/notifications' };
    try {
        if (event.data) {
            data = { ...data, ...event.data.json() };
        }
    } catch (error) {
        // Keep defaults.
    }

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            data: { url: data.url },
            icon: '/apple-touch-icon.png',
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || '/notifications';
    event.waitUntil(self.clients.openWindow(url));
});
