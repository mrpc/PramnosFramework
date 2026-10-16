/**
 * The browser half of web push, `scaffolding/templates/push-notifications.js.stub`, where it is
 * the site's addresses and where a tapped notification leads.
 *
 * What has to hold: the key and the subscription are fetched from where the site is — two folders
 * above the script, or `data-push-base` — not from the host's root, which in a folder install is
 * another site; and when the worker focuses an open tab and says where a notification leads, the
 * tab goes there, unless a single-page application cancels `pf:notification-click` to route for
 * itself, and never to another origin.
 *
 * Run:
 *   node --test tests/js/push-notifications.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const fs       = require('node:fs');
const path     = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SOURCE = fs.readFileSync(path.join(__dirname, '..', '..', 'scaffolding', 'templates', 'push-notifications.js.stub'), 'utf8');

const settle = async () => { for (let i = 0; i < 8; i++) { await new Promise((r) => setImmediate(r)); } };

/**
 * A page with the script loaded from `src`, a subscribe button, a service worker whose messages
 * the test sends, permission granted, and every fetch recorded.
 *
 * @param {string} src  Where the script tag says it was loaded from
 * @param {string|null} base `data-push-base`, or none
 */
function page(src, base = null) {
    const { window, document } = createDom({ url: 'https://site.test/app/account' });
    const button = document.createElement('button');
    button.setAttribute('data-push-subscribe', '');
    document.body.appendChild(button);

    const fetched = [];
    let message = null;
    const registration = {
        pushManager: {
            getSubscription: () => Promise.resolve(null),
            subscribe: () => Promise.resolve({ toJSON: () => ({ endpoint: 'https://push.test/1' }) }),
        },
    };
    window.navigator.serviceWorker = {
        ready: Promise.resolve(registration),
        addEventListener: (type, handler) => { if (type === 'message') { message = handler; } },
    };
    window.PushManager = function PushManager() {};
    window.isSecureContext = true;
    window.Notification = { permission: 'default', requestPermission: () => Promise.resolve('granted') };
    window.fetch = (url) => {
        fetched.push(String(url));

        return Promise.resolve({ ok: true, json: () => Promise.resolve({ publicKey: 'k' }) });
    };
    document.currentScript = { src, getAttribute: (name) => (name === 'data-push-base' ? base : null) };

    loadScript(SOURCE, window, { source: true });

    return { window, button, fetched, send: (data) => message({ data }) };
}

test('the key and the subscription are fetched from the folder the site is in', async () => {
    // Arrange — the site lives under /app/, the script at /app/assets/js/push.js
    const { button, fetched } = page('https://site.test/app/assets/js/push.js?v=3');

    // Act
    button._fire({ type: 'click', preventDefault() {} });
    await settle();

    // Assert — not https://site.test/push/key, which would be another site's address
    assert.deepEqual(fetched, ['https://site.test/app/push/key', 'https://site.test/app/push/subscribe']);
});

test('data-push-base names the site where the script is loaded from elsewhere', async () => {
    // Arrange
    const { button, fetched } = page('https://cdn.test/push.js', '/shop/');

    // Act
    button._fire({ type: 'click', preventDefault() {} });
    await settle();

    // Assert — the base is resolved against the page, so a path is this site's
    assert.equal(fetched[0], 'https://site.test/shop/push/key');
});

test('a tapped notification takes the open tab where it leads', () => {
    // Arrange
    const { window, send } = page('https://site.test/app/assets/js/push.js');

    // Act
    send({ type: 'notification-click', url: '/app/messages/4' });

    // Assert
    assert.equal(window.navigatedTo, 'https://site.test/app/messages/4');
});

test('a single-page application that routes for itself cancels the navigation', () => {
    // Arrange
    const { window, send } = page('https://site.test/app/assets/js/push.js');
    const routed = [];
    window.addEventListener('pf:notification-click', (event) => { routed.push(event.detail.url); event.preventDefault(); });

    // Act
    send({ type: 'notification-click', url: '/app/messages/4' });

    // Assert — the application was told, and the page stayed where it was
    assert.deepEqual(routed, ['https://site.test/app/messages/4']);
    assert.equal(window.navigatedTo, undefined);
});

test('another origin, another kind of message, or no address is not followed', () => {
    // Arrange
    const { window, send } = page('https://site.test/app/assets/js/push.js');

    // Act
    send({ type: 'notification-click', url: 'https://evil.test/' });
    send({ command: 'broadcastOnRequest', message: 'x' });
    send({ type: 'notification-click' });
    send(null);

    // Assert
    assert.equal(window.navigatedTo, undefined);
});
