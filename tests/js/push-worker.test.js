/**
 * `pf-push-worker.js`, the push handlers a worker of an application's own imports, run against fake
 * clients.
 *
 * What has to hold: a push is always shown — its title, body and tag, its url kept for the click,
 * a stranger's payload shown under the site's name; a click focuses the page already open at the
 * notification's address and moves it there, and opens a window when none is.
 *
 * Run:
 *   node --test tests/js/push-worker.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const fs       = require('node:fs');
const path     = require('node:path');
const vm       = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-push-worker.js'), 'utf8');

/** The worker, with the windows open now. */
function worker(open = []) {
    const listeners = {};
    const shown = [];
    const opened = [];
    const self = {
        registration: { scope: 'https://app.test/chat', showNotification: async (title, options) => { shown.push({ title, options }); } },
        addEventListener: (type, fn) => { listeners[type] = fn; },
    };
    const clients = { matchAll: async () => open, openWindow: async (url) => { opened.push(url); } };
    vm.runInNewContext(SOURCE, { self, clients, URL });

    /** Fire an event and wait for what it waits on. */
    async function fire(type, event) {
        let waited = Promise.resolve();
        listeners[type]({ ...event, waitUntil: (p) => { waited = p; } });
        await waited;
    }

    return { fire, shown, opened };
}

/** An object made in the worker's realm, as a plain one of this realm. */
const plain = (value) => JSON.parse(JSON.stringify(value));

/** A window client at a URL, recording what it was told. */
function page(url) {
    return { url, focused: false, told: [], went: null,
        async focus() { this.focused = true; }, postMessage(m) { this.told.push(m); }, async navigate(to) { this.went = to; } };
}

test('a push shows its title, body and tag, and keeps its url for the click', async () => {
    // Arrange
    const w = worker();
    const payload = { title: '#ops', body: 'Μαρία: ο server ξανά', tag: 'conversation-7', url: 'https://app.test/chat#/c/7/m/90', data: { messageId: 90 } };

    // Act
    await w.fire('push', { data: { json: () => payload } });

    // Assert
    assert.equal(w.shown.length, 1);
    assert.equal(w.shown[0].title, '#ops');
    assert.equal(w.shown[0].options.tag, 'conversation-7');
    assert.equal(w.shown[0].options.renotify, true, 'a tag replaces the last and still rings');
    assert.deepEqual(plain(w.shown[0].options.data), { url: 'https://app.test/chat#/c/7/m/90', messageId: 90 });
});

test('a push with no readable payload is still shown, under the site', async () => {
    // Arrange
    const w = worker();

    // Act
    await w.fire('push', { data: { json: () => { throw new Error('not json'); } } });

    // Assert — shown anyway: a push showing nothing gets the browser's own notice
    assert.equal(w.shown[0].title, 'app.test/chat');
});

test('a click focuses the page open at its address and moves it to the message', async () => {
    // Arrange — the chat open on another conversation, and an unrelated page
    const chat = page('https://app.test/chat#/c/3');
    const other = page('https://app.test/admin');
    const w = worker([other, chat]);

    // Act
    await w.fire('notificationclick', { notification: { close() {}, data: { url: 'https://app.test/chat#/c/7/m/90' } } });

    // Assert
    assert.equal(chat.focused, true);
    assert.deepEqual(plain(chat.told), [{ type: 'notification-click', url: 'https://app.test/chat#/c/7/m/90' }]);
    assert.equal(chat.went, 'https://app.test/chat#/c/7/m/90');
    assert.equal(other.focused, false, 'another page is left alone');
    assert.deepEqual(w.opened, []);
});

test('a click with no such page open opens one', async () => {
    // Arrange
    const w = worker([page('https://app.test/admin')]);

    // Act
    await w.fire('notificationclick', { notification: { close() {}, data: { url: 'https://app.test/chat#/c/7/m/90' } } });

    // Assert
    assert.deepEqual(w.opened, ['https://app.test/chat#/c/7/m/90']);
});
