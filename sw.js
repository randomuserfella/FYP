// ProcraTrack Service Worker
// Handles background push notifications

self.addEventListener('install', e => {
    self.skipWaiting();
});

self.addEventListener('activate', e => {
    e.waitUntil(clients.claim());
});

self.addEventListener('push', e => {
    let data = {};
    try {
        data = e.data.json();
    } catch (err) {
        data = { title: 'ProcraTrack', body: e.data ? e.data.text() : 'You have a new reminder.' };
    }

    const title   = data.title || 'ProcraTrack';
    const options = {
        body:    data.body  || 'Check your tasks!',
        icon:    data.icon  || '/icon-192.png',
        badge:   data.badge || '/icon-72.png',
        tag:     data.tag   || 'procratrack-notif',
        data:    { url: data.url || '/tasks.php' },
        vibrate: [200, 100, 200],
        requireInteraction: false
    };

    e.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', e => {
    e.notification.close();
    const target = (e.notification.data && e.notification.data.url)
        ? e.notification.data.url
        : '/tasks.php';

    e.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
            for (const c of list) {
                if (c.url.includes('/procratrack') && 'focus' in c) {
                    c.navigate(target);
                    return c.focus();
                }
            }
            if (clients.openWindow) return clients.openWindow(target);
        })
    );
});
