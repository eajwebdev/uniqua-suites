/*
 * UNIQA SUITES staff app: shows alerts and opens the page they point to.
 * It caches nothing, so every screen always shows live bookings and money.
 * Registered only from the admin pages, so public visitors never install it.
 */

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }

    const show = async () => {
        // Phones and iPhones require every alert to be shown, so there is always one.
        await self.registration.showNotification(data.title || 'UNIQA SUITES', {
            body: data.body || '',
            icon: '/app/icon-192.png',
            badge: '/app/badge-96.png',
            tag: data.tag || undefined,
            data: { url: data.url || '/admin' },
            timestamp: Date.now(),
            vibrate: [120, 60, 120],
        });

        // The number on the app icon, where the device supports it.
        try {
            if (typeof data.badge === 'number' && 'setAppBadge' in self.navigator) {
                await (data.badge > 0 ? self.navigator.setAppBadge(data.badge) : self.navigator.clearAppBadge());
            }
        } catch (e) {
            // Only a convenience.
        }

        // An open window refreshes its bell straight away instead of on its next check.
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        windows.forEach((client) => client.postMessage({ type: 'uniqa:push' }));
    };

    event.waitUntil(show());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL((event.notification.data && event.notification.data.url) || '/admin', self.location.origin).href;

    const open = async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        // Only admin pages listen for this; anywhere else a new window opens instead.
        const admin = windows.find((client) => new URL(client.url).pathname.startsWith('/admin'));
        if (admin) {
            // The page moves itself, keeping the person signed in and the app's own navigation.
            admin.postMessage({ type: 'uniqa:open', url });
            return admin.focus();
        }

        return self.clients.openWindow(url);
    };

    event.waitUntil(open());
});
