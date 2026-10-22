/**
 * `window.pfRealtime` in `pf-utils.js`: the page's live connection, over the Pusher protocol, from
 * `<meta name="pf-realtime">`.
 *
 * What has to hold: without the tag nothing is defined and the bell keeps polling; a private
 * channel is signed by the auth endpoint with the session's CSRF token and then subscribed; the
 * server's ping is answered; `notification.created` on the bell's channel refreshes the bell; a
 * drop of an established connection reconnects and asks the page to re-read exactly once, and a
 * handshake that never completed asks nothing — it had nothing to miss.
 *
 * Run:
 *   node --test tests/js/pf-realtime.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, stubFetch } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

const settle = async () => { for (let i = 0; i < 8; i++) { await new Promise((r) => setImmediate(r)); } };

/** A WebSocket the test drives: what was sent is kept, and a frame can be delivered. */
function fakeSockets() {
    const sockets = [];
    class FakeSocket {
        constructor(url) {
            this.url = url;
            this.sent = [];
            this.readyState = 1;
            sockets.push(this);
        }

        /** What the page sent, as JSON. */
        send(raw) { this.sent.push(JSON.parse(raw)); }

        /** The connection goes, as the network reports it. */
        close() {
            this.readyState = 3;
            if (this.onclose) { this.onclose({}); }
        }

        /** A frame from the server. */
        receive(event, data, channel) {
            this.onmessage({ data: JSON.stringify({ event, data: typeof data === 'string' ? data : JSON.stringify(data), channel }) });
        }
    }

    return { sockets, FakeSocket };
}

/** A page with the tag (or not), a bell on `private-users.5`, and the script over it. */
function page({ realtime = { transport: 'websocket', scheme: 'ws', host: 'rt.test', port: 6001, appKey: 'k1', authEndpoint: '/broadcasting/auth' }, bell = false } = {}) {
    const meta = { csrf: 't0ken' };
    if (realtime) { meta['pf-realtime'] = JSON.stringify(realtime); }
    const { window, document } = createDom({ meta });
    const { sockets, FakeSocket } = fakeSockets();
    window.WebSocket = FakeSocket;
    window.fetch = stubFetch({
        'POST /broadcasting/auth': { auth: 'k1:signature' },
        'GET /Notifications/count': { unread: 4, groups: {} },
    });
    const timers = [];
    window.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
    window.clearTimeout = () => {};
    window.setInterval = () => 1;
    if (bell) {
        const el = (tag, attrs, parent) => {
            const node = document.createElement(tag);
            Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
            (parent || document.body).appendChild(node);
            return node;
        };
        const root = el('div', { 'data-pf-notifications': '', 'data-url': '/Notifications', 'data-channel': 'private-users.5' });
        const toggle = el('button', { 'data-pf-notifications-toggle': '', 'data-label': 'Notifications' }, root);
        el('span', { 'data-pf-notifications-count': '' }, toggle);
        el('div', { 'data-pf-notifications-panel': '' }, root).hidden = true;
        el('ul', { 'data-pf-notifications-list': '' }, root);
    }
    loadScript(SOURCE, window);

    return { window, document, sockets, timers };
}

/** The handshake, as broadcast:serve sends it. */
const handshake = (socket) => socket.receive('pusher:connection_established', { socket_id: '123.456', activity_timeout: 120 });

test('without the tag nothing is defined and nothing connects', () => {
    // Act
    const ui = page({ realtime: null });

    // Assert
    assert.equal(ui.window.pfRealtime, undefined, 'the bell keeps polling');
    assert.equal(ui.sockets.length, 0);
});

test('a private channel is signed with the CSRF token, then subscribed; a ping is answered', async () => {
    // Arrange
    const ui = page();

    // Act
    ui.window.pfRealtime.channel('private-users.5');
    handshake(ui.sockets[0]);
    await settle();
    ui.sockets[0].receive('pusher:ping', {});
    const auth = ui.window.fetch.calls[0];

    // Assert
    assert.equal(ui.sockets[0].url, 'ws://rt.test:6001/app/k1?protocol=7&client=pf-utils&version=1.0', 'built from RealtimeConfig::forClient()');
    assert.equal(auth.path, '/broadcasting/auth');
    assert.equal(auth.body, 'socket_id=123.456&channel_name=private-users.5');
    assert.equal(auth.headers['X-CSRF-Token'], 't0ken');
    assert.deepEqual(ui.sockets[0].sent[0], { event: 'pusher:subscribe', data: { channel: 'private-users.5', auth: 'k1:signature' } });
    assert.deepEqual(ui.sockets[0].sent[1], { event: 'pusher:pong', data: {} });
});

test('notification.created on the bell channel refreshes the bell', async () => {
    // Arrange
    const ui = page({ bell: true });
    handshake(ui.sockets[0]);
    await settle();
    const before = ui.window.fetch.calls.filter((c) => c.path === '/Notifications/count').length;

    // Act
    ui.sockets[0].receive('notification.created', { id: 'uuid-1' }, 'private-users.5');
    ui.sockets[0].receive('notification.created', { id: 'uuid-2' }, 'private-users.9');
    await settle();

    // Assert
    const after = ui.window.fetch.calls.filter((c) => c.path === '/Notifications/count').length;
    assert.equal(after - before, 1, 'its own channel, and no other');
});

test('a drop of an established connection reconnects and asks for exactly one re-read', async () => {
    // Arrange
    const ui = page();
    let reread = 0;
    ui.window.addEventListener('pf:notifications:refresh', () => { reread++; });
    handshake(ui.sockets[0]);

    // Act — the connection drops, the backoff runs, the new one is established
    ui.sockets[0].close();
    const retry = ui.timers.filter((t) => t.ms < 2000 && t.ms >= 500).pop();
    retry.fn();
    handshake(ui.sockets[1]);
    handshake(ui.sockets[1]);
    await settle();

    // Assert
    assert.equal(ui.sockets.length, 2, 'it reconnected');
    assert.equal(reread, 1, 'one re-read, not one per frame');
});

test('a handshake that never completed asks for no re-read when it connects', () => {
    // Arrange
    const ui = page();
    let reread = 0;
    ui.document.addEventListener('pf:notifications:refresh', () => { reread++; });

    // Act — the first attempt fails before the handshake, the second succeeds
    ui.sockets[0].close();
    ui.timers[ui.timers.length - 1].fn();
    handshake(ui.sockets[1]);

    // Assert
    assert.equal(reread, 0, 'it had nothing to miss');
});

test('a silent socket is pinged, and closed when nothing answers', () => {
    // Arrange
    const ui = page();
    handshake(ui.sockets[0]);
    const idle = ui.timers.filter((t) => t.ms === 120000).pop();

    // Act
    idle.fn();
    const pong = ui.timers.filter((t) => t.ms === 3000).pop();
    pong.fn();

    // Assert
    assert.deepEqual(ui.sockets[0].sent.pop(), { event: 'pusher:ping', data: {} });
    assert.equal(ui.sockets[0].readyState, 3, 'closed, so the reconnect path takes over');
});

test('a Pusher transport connects to its host, and a page with its own connection keeps it', () => {
    // Arrange
    const ui = page({ realtime: { transport: 'pusher', key: 'pk', cluster: 'eu', forceTLS: true } });
    const own = createDom({ meta: { 'pf-realtime': JSON.stringify({ transport: 'websocket', appKey: 'x' }) } });
    own.window.WebSocket = fakeSockets().FakeSocket;
    const mine = { transport: 'mine' };
    own.window.pfRealtime = mine;

    // Act
    loadScript(SOURCE, own.window);

    // Assert
    assert.equal(ui.sockets[0].url, 'wss://ws-eu.pusher.com:443/app/pk?protocol=7&client=pf-utils&version=1.0');
    assert.equal(own.window.pfRealtime, mine);
});

test('a presence channel tells its listeners who is in it, who came and who went', async () => {
    // Arrange
    const ui = page();
    const heard = [];
    ui.window.pfRealtime.channel('presence-chat')
        .listen('pusher:subscription_succeeded', (d) => heard.push(['here', Object.keys(d.members)]))
        .listen('pusher:member_added', (d) => heard.push(['added', d.id, d.info.name, Object.keys(d.members)]))
        .listen('pusher:member_removed', (d) => heard.push(['removed', d.id, Object.keys(d.members)]));
    handshake(ui.sockets[0]);
    await settle();

    // Act
    ui.sockets[0].receive('pusher_internal:subscription_succeeded', { presence: { hash: { 5: { name: 'Ελένη' }, 7: { name: 'Μαρία' } } } }, 'presence-chat');
    ui.sockets[0].receive('pusher_internal:member_added', { user_id: '9', user_info: { name: 'Νίκος' } }, 'presence-chat');
    ui.sockets[0].receive('pusher_internal:member_removed', { user_id: '7' }, 'presence-chat');

    // Assert
    assert.deepEqual(heard, [
        ['here', ['5', '7']],
        ['added', '9', 'Νίκος', ['5', '7', '9']],
        ['removed', '7', ['5', '9']],
    ], 'the members kept as the server tells them');
});

test('a whisper is a client- event on a confirmed private channel, and nothing before it is confirmed', async () => {
    // Arrange
    const ui = page();
    const channel = ui.window.pfRealtime.channel('private-conversation.7');
    handshake(ui.sockets[0]);
    await settle();

    // Act
    channel.whisper('typing', { id: 5 });
    const before = ui.sockets[0].sent.length;
    ui.sockets[0].receive('pusher_internal:subscription_succeeded', {}, 'private-conversation.7');
    channel.whisper('typing', { id: 5 });
    const heard = [];
    const fn = (d) => heard.push(d.id);
    channel.listen('client-typing', fn);
    ui.sockets[0].receive('client-typing', { id: 9 }, 'private-conversation.7');
    channel.stopListening('client-typing', fn);
    ui.sockets[0].receive('client-typing', { id: 11 }, 'private-conversation.7');

    // Assert
    assert.equal(before, 1, 'only the subscription went before the server confirmed');
    assert.deepEqual(ui.sockets[0].sent[1], { event: 'client-typing', channel: 'private-conversation.7', data: { id: 5 } });
    assert.deepEqual(heard, [9], 'a whisper from another is heard; after stopListening, not');
});
