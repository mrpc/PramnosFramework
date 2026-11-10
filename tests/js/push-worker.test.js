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
    const closed = [];
    const self = {
        registration: {
            scope: 'https://app.test/chat',
            showNotification: async (title, options) => { shown.push({ title, options }); },
            // The ones shown under a tag, each closable.
            getNotifications: async ({ tag }) => shown.filter((one) => one.options.tag === tag).map((one) => ({ close: () => closed.push(one.options.tag) })),
        },
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

    return { fire, shown, opened, closed };
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

/**
 * A conversation's push: `silent` shows it without the system's sound (the page or the app plays
 * its own), and `unlessFocused` shows it only for a moment while a window of the site is visible
 * and focused — that page tells of the message itself — but in full when the site is only in a
 * hidden tab or an unfocused window. Without `unlessFocused`, a focused page changes nothing.
 *
 * "Only for a moment", not "nothing": a push that shows nothing is counted against the
 * subscription, and Chrome on Android cancels it after a few (FW-395). So a quiet notice under a
 * tag of its own, closed at once — never the conversation's, which may still be showing.
 */
test('a silent push is quiet, and one unless focused waits for the site to be out of front', async () => {
    // Arrange
    const front  = Object.assign(page('https://app.test/chat'), { visibilityState: 'visible', focused: true });
    const behind = Object.assign(page('https://app.test/chat'), { visibilityState: 'hidden', focused: false });
    const message = { title: 'Μαρία', body: 'γεια', silent: true, unlessFocused: true };

    // Act
    const inFront = worker([front]);
    await inFront.fire('push', { data: { json: () => message } });
    const away = worker([behind]);
    await away.fire('push', { data: { json: () => message } });
    const approval = worker([front]);
    await approval.fire('push', { data: { json: () => ({ title: 'Νέα σύνδεση' }) } });

    // Assert
    assert.equal(inFront.shown.length, 1, 'something is shown, so the browser counts no violation');
    // Field by field: the options object is made in the worker's own context.
    assert.deepEqual([inFront.shown[0].options.tag, inFront.shown[0].options.silent], ['pf-quiet', true], 'quietly, under a tag of its own');
    assert.deepEqual(inFront.closed, ['pf-quiet'], 'and closed at once: the page in front tells of it itself');
    assert.equal(away.shown.length, 1);
    assert.equal(away.shown[0].options.silent, true, 'without the system\'s sound');
    assert.equal(approval.shown.length, 1, 'a push that does not ask is shown whatever is in front');
    assert.equal(approval.shown[0].options.silent, false);
});

/**
 * `silent: 'ifOpen'` — a conversation's push: quiet while a page of the site is open, hidden or
 * not, since that page plays its own sound; with no page open (a phone, mostly) the system's
 * sound plays. `true` and `false` mean what they did.
 */
test('a push quiet if open plays the system\'s sound when no page is open', async () => {
    // Arrange
    const hidden = Object.assign(page('https://app.test/chat'), { visibilityState: 'hidden', focused: false });
    const message = { title: 'Μαρία', body: 'γεια', silent: 'ifOpen', unlessFocused: true };

    // Act
    const open = worker([hidden]);
    await open.fire('push', { data: { json: () => message } });
    const none = worker([]);
    await none.fire('push', { data: { json: () => message } });
    const always = worker([]);
    await always.fire('push', { data: { json: () => ({ title: 'x', silent: true }) } });

    // Assert
    assert.equal(open.shown[0].options.silent, true, 'a page open plays its own');
    assert.equal(none.shown[0].options.silent, false, 'no page: the system\'s sound');
    assert.equal(always.shown[0].options.silent, true);
});
