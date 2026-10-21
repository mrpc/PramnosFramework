/**
 * `window.pfRealtime` over a SharedWorker: one connection for every tab of the site.
 *
 * What has to hold: two tabs open one WebSocket, and both hear `notification.created` on the
 * channel they listen on; a private channel is signed by a tab, with that tab's CSRF token; a tab
 * that goes leaves the socket to the other; when the last tab on a channel goes the channel is
 * left; a reconnect after a drop tells each tab once that it may have missed something; and
 * without SharedWorker a page keeps a connection of its own, as before.
 *
 * Run:
 *   node --test tests/js/pf-realtime-shared.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');
const fs       = require('node:fs');
const vm       = require('node:vm');
const { MessageChannel } = require('node:worker_threads');

const { createDom, loadScript, stubFetch } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');
const CONFIG = { transport: 'websocket', scheme: 'ws', host: 'rt.test', port: 6001, appKey: 'k1', authEndpoint: '/broadcasting/auth' };

const settle = async () => { for (let i = 0; i < 12; i++) { await new Promise((r) => setImmediate(r)); } };

/** The worker: pf-utils.js run with no document, a fake WebSocket, and the sockets it opened. */
function worker() {
    const sockets = [];
    class FakeSocket {
        constructor(url) { this.url = url; this.sent = []; sockets.push(this); }

        /** What the worker sent, as JSON. */
        send(raw) { this.sent.push(JSON.parse(raw)); }

        /** The connection goes. */
        close() { if (this.onclose) { this.onclose({}); } }

        /** A frame from the server. */
        receive(event, data, channel) {
            this.onmessage({ data: JSON.stringify({ event, data: typeof data === 'string' ? data : JSON.stringify(data), channel }) });
        }
    }
    const timers = [];
    const scope = {
        WebSocket: FakeSocket,
        location: { hostname: 'app.test' },
        setTimeout: (fn, ms) => { timers.push({ fn, ms }); return timers.length; },
        clearTimeout: () => {},
        JSON, Math, Object, String, Number, isFinite, encodeURIComponent,
    };
    scope.self = scope;
    vm.runInContext(fs.readFileSync(SOURCE, 'utf8'), vm.createContext(scope), { filename: SOURCE });

    return { scope, sockets, timers };
}

/** A tab on the site: the page, its CSRF token, and a SharedWorker that reaches `shared`. */
function tab(shared, csrf, { hidden = false } = {}) {
    const { window, document } = createDom({ meta: { csrf, 'pf-realtime': JSON.stringify(CONFIG) } });
    document.hidden = hidden;
    document.currentScript = { src: 'https://app.test/assets/js/pf-utils.js' };
    window.fetch = stubFetch({ 'POST /broadcasting/auth': (req) => ({ auth: 'k1:signed-by-' + req.headers['X-CSRF-Token'] }) });
    window.SharedWorker = class {
        constructor(src, options) {
            const channel = new MessageChannel();
            this.port = channel.port1;
            window.startedWorker = { src, name: options && options.name, channel };
            shared.scope.onconnect({ ports: [channel.port2] });
        }
    };
    const refreshes = [];
    document.addEventListener('pf:notifications:refresh', () => refreshes.push(1));
    loadScript(SOURCE, window);
    // Not kept alive by the test's ports — a handler set on one refs it: the run ends when the
    // tests do.
    window.startedWorker.channel.port1.unref();
    window.startedWorker.channel.port2.unref();

    /** The tab goes: what the browser fires on leaving it. */
    const leave = () => window.dispatchEvent(new window.Event('pagehide'));

    return { window, document, refreshes, leave };
}

const handshake = (socket) => socket.receive('pusher:connection_established', { socket_id: '1.2', activity_timeout: 120 });

test('two tabs share one socket and both hear an event on their channel', async () => {
    // Arrange
    const shared = worker();
    const a = tab(shared, 'tokA');
    const b = tab(shared, 'tokB');
    const heard = { a: [], b: [] };

    // Act
    a.window.pfRealtime.channel('private-users.5').listen('notification.created', (d) => heard.a.push(d.id));
    await settle();
    b.window.pfRealtime.channel('private-users.5').listen('notification.created', (d) => heard.b.push(d.id));
    await settle();
    handshake(shared.sockets[0]);
    await settle();
    shared.sockets[0].receive('notification.created', { id: 9 }, 'private-users.5');
    await settle();
    const subscribes = shared.sockets[0].sent.filter((f) => f.event === 'pusher:subscribe');

    // Assert
    assert.equal(a.window.startedWorker.name, 'pf-realtime');
    assert.equal(a.window.startedWorker.src, 'https://app.test/assets/js/pf-utils.js', 'the worker is not this file');
    assert.equal(a.window.pfRealtime.shared, true);
    assert.equal(shared.sockets.length, 1, 'each tab opened a socket of its own');
    assert.equal(subscribes.length, 1, 'the channel was joined once per tab');
    assert.equal(subscribes[0].data.auth, 'k1:signed-by-tokA', 'not signed by a tab, with its own token');
    assert.equal(a.window.fetch.calls.length + b.window.fetch.calls.length, 1, 'the channel was signed twice');
    assert.deepEqual(heard, { a: [9], b: [9] });
});

test('a tab that goes leaves the socket to the other; the last one on a channel leaves it', async () => {
    // Arrange
    const shared = worker();
    const a = tab(shared, 'tokA');
    const b = tab(shared, 'tokB');
    a.window.pfRealtime.channel('news');
    b.window.pfRealtime.channel('news');
    await settle();
    handshake(shared.sockets[0]);
    await settle();

    // Act
    a.leave();
    await settle();
    const afterFirst = shared.sockets[0].sent.filter((f) => f.event === 'pusher:unsubscribe').length;
    b.leave();
    await settle();
    const unsubscribes = shared.sockets[0].sent.filter((f) => f.event === 'pusher:unsubscribe');

    // Assert
    assert.equal(afterFirst, 0, 'the channel was left while a tab still listened');
    assert.equal(unsubscribes.length, 1);
    assert.equal(unsubscribes[0].data.channel, 'news');
    assert.equal(shared.sockets.length, 1, 'a page change opened another socket');
});

test('the tab asked to sign goes first: the next tab on the channel signs it', async () => {
    // Arrange — the worker asks tab A; A is gone before it answers
    const shared = worker();
    const a = tab(shared, 'tokA');
    const b = tab(shared, 'tokB');
    a.window.fetch = () => new Promise(() => {}); // never answers
    a.window.pfRealtime.channel('private-users.5');
    b.window.pfRealtime.channel('private-users.5');
    await settle();
    handshake(shared.sockets[0]);
    await settle();

    // Act
    a.leave();
    await settle();
    const subscribe = shared.sockets[0].sent.find((f) => f.event === 'pusher:subscribe');

    // Assert
    assert.equal(subscribe && subscribe.data.auth, 'k1:signed-by-tokB', 'the channel was never joined');
});

test('after a drop and a reconnect, each tab is told once that it may have missed something', async () => {
    // Arrange
    const shared = worker();
    const a = tab(shared, 'tokA');
    const b = tab(shared, 'tokB');
    a.window.pfRealtime.channel('news');
    b.window.pfRealtime.channel('news');
    await settle();
    handshake(shared.sockets[0]);
    await settle();

    // Act — the connection drops; the backoff timer opens the next socket
    shared.sockets[0].close();
    shared.timers[shared.timers.length - 1].fn();
    handshake(shared.sockets[1]);
    await settle();

    // Assert
    assert.equal(a.refreshes.length, 1);
    assert.equal(b.refreshes.length, 1);
    assert.equal(shared.sockets[1].sent.filter((f) => f.event === 'pusher:subscribe').length, 1, 'the channel was not joined again');
});

test('without SharedWorker a page keeps a connection of its own', () => {
    // Arrange
    const { window } = createDom({ meta: { csrf: 't', 'pf-realtime': JSON.stringify(CONFIG) } });
    const opened = [];
    window.WebSocket = class { constructor(url) { opened.push(url); } send() {} };
    window.setTimeout = () => 1;
    window.clearTimeout = () => {};

    // Act
    loadScript(SOURCE, window);

    // Assert
    assert.equal(window.pfRealtime.shared, false);
    assert.equal(opened.length, 1, 'the page did not connect');
});

/** A tab whose SharedWorker never answers (`silent`) or fails to load (`fails`), with its timers. */
function brokenTab({ fails = false } = {}) {
    const { window, document } = createDom({ meta: { csrf: 't', 'pf-realtime': JSON.stringify(CONFIG) } });
    document.currentScript = { src: 'https://app.test/assets/js/pf-utils.js' };
    const opened = [];
    window.WebSocket = class { constructor(url) { opened.push(url); } send() {} };
    const timers = [];
    window.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
    window.clearTimeout = () => {};
    let worker = null;
    window.SharedWorker = class {
        constructor() {
            worker = this;
            // A port that takes messages and never answers: the script did not run.
            this.port = { postMessage() {}, start() {}, close() {} };
        }
    };
    loadScript(SOURCE, window);
    window.pfRealtime.channel('news');
    if (fails) { worker.onerror({}); }

    return { window, opened, timers };
}

test('a worker that never starts: after the wait the page connects itself, with its channels', () => {
    // Arrange
    const ui = brokenTab();
    const before = ui.opened.length;

    // Act — the watchdog fires
    ui.timers.find((t) => t.ms === 5000).fn();

    // Assert
    assert.equal(before, 0, 'connected before giving the worker its chance');
    assert.equal(ui.opened.length, 1, 'the page was left with no connection');
    assert.equal(ui.window.pfRealtime.shared, false);
    assert.equal(ui.window.pfRealtime.status().fallback, 'The shared worker did not start');
});

test('a worker that fails to load: the page connects itself at once', () => {
    // Act
    const ui = brokenTab({ fails: true });

    // Assert
    assert.equal(ui.opened.length, 1);
    assert.equal(ui.window.pfRealtime.status().fallback, 'The shared worker failed to load');
});

test('each tab can read what the shared connection is doing', async () => {
    // Arrange
    const shared = worker();
    const a = tab(shared, 'tokA');
    a.window.pfRealtime.channel('news');
    await settle();

    // Act
    handshake(shared.sockets[0]);
    shared.sockets[0].receive('pusher_internal:subscription_succeeded', {}, 'news');
    await settle();
    const status = a.window.pfRealtime.status();

    // Assert
    assert.equal(status.shared, true);
    assert.equal(status.socket, 'open');
    assert.equal(status.socketId, '1.2');
    assert.deepEqual([...status.subscribed], ['news']);
});

test('the worker names one tab to act for the browser: the visible one', async () => {
    // Arrange — A in the background, B looked at
    const shared = worker();
    const a = tab(shared, 'tokA', { hidden: true });
    const b = tab(shared, 'tokB', { hidden: false });
    const leads = { a: [], b: [] };
    a.window.pfRealtime.channel('news').listen('notification.created', (d, meta) => leads.a.push(meta.lead));
    b.window.pfRealtime.channel('news').listen('notification.created', (d, meta) => leads.b.push(meta.lead));
    await settle();
    handshake(shared.sockets[0]);
    await settle();

    // Act — one arrival; then B goes to the background and A comes forward; another
    shared.sockets[0].receive('notification.created', { id: 1 }, 'news');
    await settle();
    b.document.hidden = true;
    b.document.dispatchEvent(new b.window.Event('visibilitychange'));
    a.document.hidden = false;
    a.document.dispatchEvent(new a.window.Event('visibilitychange'));
    await settle();
    shared.sockets[0].receive('notification.created', { id: 2 }, 'news');
    await settle();

    // Assert
    assert.deepEqual(leads, { a: [false, true], b: [true, false] }, 'more or fewer than one tab acted, or the wrong one');
});
