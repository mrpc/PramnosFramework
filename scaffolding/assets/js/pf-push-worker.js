/**
 * Web push for a service worker of an application's own — the conversations' app, say — that
 * has no `push` handler: `importScripts('/assets/js/pf-push-worker.js')` at its top.
 *
 * `push` shows what the server sent (`title`, `body`, `tag`, `url`, `data`), a notification of one
 * tag replacing the last; `silent` shows it without the system's sound (`'ifOpen'`: only while a
 * page of the site is open, hidden or not, to play its own), and `unlessFocused` only for a
 * moment while a window of the site is visible and focused — that page tells of it itself, and
 * a push that shows nothing is one the browser counts against the subscription (Chrome on
 * Android cancels it after a few); `notificationclick` goes to its `url` — the page already open at that
 * address is focused and moved there, any other is left alone, and with none a window is opened,
 * which on a phone is the installed app when the address is in its scope.
 *
 * `notificationclick` and `notificationclose` also report back to `data.report` (an absolute URL
 * the server already put there — see `PushChannel::payload()`) with `{pushid, action}`, which is
 * FW-495's hold-unopened rule's only way to know a push was seen: `pramnos.pushlog.opened_at` /
 * `dismissed_at`. Never for the self-closing `pf-quiet` one below, and never for a push with no
 * `data.pushid` — an installation that has not run the migration, or one with no site URL
 * configured, sends neither, and nothing here fails for its absence.
 *
 * A worker that has its own handlers (the scaffolded `sw.js` has) does not import this, and
 * should report the same way if it wants FW-495's hold-unopened rule to see its own pushes.
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
        const open = payload.unlessFocused || payload.silent === 'ifOpen'
            ? await clients.matchAll({ type: 'window', includeUncontrolled: true }) : [];
        // A page of the site in front tells of it itself. Something is still shown, quietly, and
        // closed at once: a push that shows nothing is counted against the subscription, and Chrome
        // on Android cancels one after a few.
        if (payload.unlessFocused && open.some((client) => client.visibilityState === 'visible' && client.focused)) {
            await self.registration.showNotification(payload.title || '', { tag: 'pf-quiet', silent: true })
                .then(() => self.registration.getNotifications({ tag: 'pf-quiet' }))
                .then((shown) => shown.forEach((one) => one.close()))
                .catch(() => undefined);
            return;
        }
        // Otherwise something is always shown: a push that shows nothing gets the browser's own notice instead.
        await self.registration.showNotification(payload.title || self.registration.scope.replace(/^https?:\/\//, ''), {
            body: payload.body || '',
            icon: payload.icon || undefined,
            badge: payload.badge || undefined,
            tag: payload.tag || undefined,
            renotify: Boolean(payload.tag),
            // 'ifOpen': quiet while a page of the site is open, hidden or not, and plays its own sound.
            silent: payload.silent === 'ifOpen' ? open.length > 0 : Boolean(payload.silent),
            data: { url: payload.url || self.registration.scope, ...(payload.data || {}) },
        }).catch(() => undefined);
    })());
});

// FW-495: the only way the server learns a push was opened or closed, so a device buried under
// unopened ones stops being sent more — never for 'pf-quiet' (it closes itself, unseen) and never
// without data.pushid (no site URL configured, or the migration has not run: nothing to report).
function reportPush(action, notification) {
    const data = notification.data || {};
    if (notification.tag === 'pf-quiet' || !data.report || !data.pushid) { return Promise.resolve(); }

    return fetch(data.report, {
        method: 'POST',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ pushid: data.pushid, action: action }),
    }).catch(() => undefined);
}

self.addEventListener('notificationclick', (event) => {
    const notification = event.notification;
    notification.close();
    const url = new URL((notification.data || {}).url || self.registration.scope, self.registration.scope).href;
    const page = url.split('#')[0];
    event.waitUntil((async () => {
        await reportPush('opened', notification);
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

self.addEventListener('notificationclose', (event) => {
    event.waitUntil(reportPush('dismissed', event.notification));
});
