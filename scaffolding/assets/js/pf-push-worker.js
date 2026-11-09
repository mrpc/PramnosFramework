/**
 * Web push for a service worker of an application's own — the conversations' app, say — that
 * has no `push` handler: `importScripts('/assets/js/pf-push-worker.js')` at its top.
 *
 * `push` shows what the server sent (`title`, `body`, `tag`, `url`, `data`), a notification of one
 * tag replacing the last; `silent` shows it without the system's sound, and `unlessFocused` not at
 * all while a window of the site is visible and focused — that page tells of it itself; `notificationclick` goes to its `url` — the page already open at that
 * address is focused and moved there, any other is left alone, and with none a window is opened,
 * which on a phone is the installed app when the address is in its scope.
 *
 * A worker that has its own handlers (the scaffolded `sw.js` has) does not import this.
 */
/* global self, clients */
'use strict';

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        payload = {};
    }
    event.waitUntil((async () => {
        if (payload.unlessFocused) {
            // A page of the site in front tells of it itself; the browser asks for no notice then.
            const open = await clients.matchAll({ type: 'window', includeUncontrolled: true });
            if (open.some((client) => client.visibilityState === 'visible' && client.focused)) {
                return;
            }
        }
        // Otherwise something is always shown: a push that shows nothing gets the browser's own notice instead.
        await self.registration.showNotification(payload.title || self.registration.scope.replace(/^https?:\/\//, ''), {
            body: payload.body || '',
            icon: payload.icon || undefined,
            badge: payload.badge || undefined,
            tag: payload.tag || undefined,
            renotify: Boolean(payload.tag),
            silent: Boolean(payload.silent),
            data: { url: payload.url || self.registration.scope, ...(payload.data || {}) },
        }).catch(() => undefined);
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL((event.notification.data || {}).url || self.registration.scope, self.registration.scope).href;
    const page = url.split('#')[0];
    event.waitUntil((async () => {
        const open = await clients.matchAll({ type: 'window', includeUncontrolled: true });
        const same = open.find((client) => client.url.split('#')[0] === page);
        if (!same) {
            await clients.openWindow(url);
            return;
        }
        await same.focus();
        // The page routes on its hash: told where to go, it goes without a reload.
        same.postMessage({ type: 'notification-click', url });
        if (same.url !== url && typeof same.navigate === 'function') {
            await same.navigate(url).catch(() => undefined);
        }
    })());
});
