// ДОБАВЛЕНО (06.08.2026): минимальный service worker только для Web Push.
// Никакого офлайн-кеширования не делает — Форма А всегда должна показывать
// свежие данные (акты, тайминги), кеш здесь скорее вреден, чем полезен.

self.addEventListener('install', function(event) {
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function(event) {
    let data = { title: 'Форма А', body: 'Новое уведомление', url: '/notifications' };
    if (event.data) {
        try { data = Object.assign(data, event.data.json()); } catch (e) { /* оставляем дефолт */ }
    }

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: '/assets/icons/icon-192.png',
            badge: '/assets/icons/icon-192.png',
            data: { url: data.url || '/notifications' },
            tag: data.url || undefined,
            renotify: false,
        })
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/notifications';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
            for (const client of clientList) {
                if (client.url.includes(url) && 'focus' in client) {
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});
