/**
 * Client-side unit tests for pramnos-echo.js — presence channels, client events
 * (whisper) and the socket id used for toOthers().
 *
 * Uses only Node.js built-ins (node:test + node:assert/strict + node:vm + node:fs),
 * matching the other suites in this directory: zero npm dependencies.
 *
 * The Pusher SDK is replaced with a minimal fake that records what the wrapper asked
 * it to do. That is the right seam here — every property under test is about what
 * pramnos-echo.js does with a Pusher channel, not about Pusher itself.
 *
 * Run:
 *   ./testjs
 *   node --test tests/js/pramnos-echo.test.js
 */
'use strict';

const { test, describe } = require('node:test');
const assert             = require('node:assert/strict');
const vm                 = require('node:vm');
const fs                 = require('node:fs');
const path               = require('node:path');

/**
 * Normalise a value that crossed the vm realm boundary.
 *
 * Objects and arrays built inside a `node:vm` context have that context's
 * prototypes, so assert/strict reports "same structure but not reference-equal" on
 * a comparison that is otherwise correct. Round-tripping through JSON rebuilds them
 * with this realm's intrinsics, which is what the assertions are actually about.
 */
const plain = (value) => JSON.parse(JSON.stringify(value));

const ECHO_JS = path.join(
    __dirname, '..', '..',
    'scaffolding', 'resources', 'vendor', 'pramnos-echo', 'pramnos-echo.js'
);

// ─── Fakes ──────────────────────────────────────────────────────────────────

/** A Pusher channel that records bindings and triggers. */
function FakeChannel(name) {
    this.name     = name;
    this.bindings = {};
    this.triggered = [];
}

FakeChannel.prototype.bind = function (event, callback) {
    (this.bindings[event] = this.bindings[event] || []).push(callback);
};

FakeChannel.prototype.unbind = function (event, callback) {
    if (!callback) {
        delete this.bindings[event];
        return;
    }
    this.bindings[event] = (this.bindings[event] || []).filter((cb) => cb !== callback);
};

FakeChannel.prototype.trigger = function (event, data) {
    this.triggered.push({ event, data });
};

/** Fire a bound event as the server would. */
FakeChannel.prototype.emit = function (event, payload) {
    (this.bindings[event] || []).forEach((cb) => cb(payload));
};

/** A Pusher client that hands out FakeChannels. */
function FakePusher(key, options) {
    this.key        = key;
    this.options    = options;
    this.channels   = {};
    this.connection = { socket_id: '123.456' };
}

FakePusher.prototype.subscribe = function (name) {
    return (this.channels[name] = this.channels[name] || new FakeChannel(name));
};

FakePusher.prototype.unsubscribe = function (name) {
    delete this.channels[name];
};

FakePusher.prototype.disconnect = function () {
    this.connection = {};
};

/**
 * Load pramnos-echo.js into a fresh sandbox and configure it.
 *
 * A fresh context per test matters: the module keeps its Pusher instance and its
 * channel cache in closure state, so a shared one would leak subscriptions between
 * tests.
 */
function loadEcho(options) {
    const sandbox = {
        window: {},
        document: { querySelector: () => null },
        Object,
        String,
        Error,
    };
    sandbox.window.Pusher = FakePusher;

    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(ECHO_JS, 'utf8'), sandbox);

    const Echo = sandbox.window.PramnosEcho;
    Echo.configure(Object.assign({ key: 'test-key' }, options || {}));

    return Echo;
}

// ─── Tests ──────────────────────────────────────────────────────────────────

describe('pramnos-echo: presence channels', () => {
    test('here() receives every member already in the channel, self included', () => {
        // Arrange
        const Echo    = loadEcho();
        const channel = Echo.join('room.lobby');
        const raw     = channel._channel;
        let seen      = null;

        channel.here((members) => { seen = members; });

        // Act — the server's subscription_succeeded payload, as pusher-js delivers it
        raw.emit('pusher:subscription_succeeded', {
            members: { 7: { name: 'Ada' }, 9: { name: 'Grace' } },
        });

        // Assert
        assert.equal(seen.length, 2);
        assert.deepEqual(
            plain(seen).map((m) => m.id).sort(),
            ['7', '9'],
            'ids are strings, so a client can compare them against its own'
        );
        assert.deepEqual(plain(seen).find((m) => m.id === '7').info, { name: 'Ada' });
    });

    test('here() uses the members.each() iterator when pusher-js provides one', () => {
        // Arrange
        const Echo    = loadEcho();
        const channel = Echo.join('room.lobby');
        let seen      = null;

        channel.here((members) => { seen = members; });

        // Act — the shape pusher-js actually passes
        channel._channel.emit('pusher:subscription_succeeded', {
            each(cb) {
                cb({ id: 7, info: { name: 'Ada' } });
            },
        });

        // Assert
        assert.deepEqual(plain(seen), [{ id: '7', info: { name: 'Ada' } }]);
    });

    test('joining() and leaving() normalise the member', () => {
        // Arrange
        const Echo    = loadEcho();
        const channel = Echo.join('room.lobby');
        const raw     = channel._channel;
        const joined  = [];
        const left    = [];

        channel.joining((m) => joined.push(m)).leaving((m) => left.push(m));

        // Act
        raw.emit('pusher:member_added', { id: 9, info: { name: 'Grace' } });
        raw.emit('pusher:member_removed', { id: 9, info: { name: 'Grace' } });

        // Assert
        assert.deepEqual(plain(joined), [{ id: '9', info: { name: 'Grace' } }]);
        assert.deepEqual(plain(left), [{ id: '9', info: { name: 'Grace' } }]);
    });

    test('a member with no info is normalised to an empty object', () => {
        // Arrange
        const Echo    = loadEcho();
        const channel = Echo.join('room.lobby');
        let member    = null;

        channel.joining((m) => { member = m; });

        // Act
        channel._channel.emit('pusher:member_added', { id: '3' });

        // Assert — callers can read .info without guarding every access
        assert.deepEqual(plain(member), { id: '3', info: {} });
    });

    test('a missing member is normalised rather than thrown on', () => {
        // Arrange
        const Echo    = loadEcho();
        const channel = Echo.join('room.lobby');
        let member    = 'untouched';

        channel.joining((m) => { member = m; });

        // Act
        channel._channel.emit('pusher:member_added', null);

        // Assert
        assert.deepEqual(plain(member), { id: null, info: {} });
    });

    test('join() and presence() return the same subscription', () => {
        // Arrange
        const Echo = loadEcho();

        // Act & Assert — the cache is by full channel name, so both names reach one
        assert.equal(Echo.join('room.lobby'), Echo.presence('room.lobby'));
    });

    test('a presence channel keeps the plain listen() API', () => {
        // Arrange
        const Echo    = loadEcho();
        const channel = Echo.join('room.lobby');
        let payload   = null;

        channel.listen('message.created', (p) => { payload = p; });

        // Act
        channel._channel.emit('message.created', { id: 1 });

        // Assert
        assert.deepEqual(plain(payload), { id: 1 });
    });

    test('a public channel has no presence callbacks', () => {
        // Arrange
        const Echo = loadEcho();

        // Assert — here() on a non-presence channel would silently never fire, so it
        // is better that it does not exist
        assert.equal(typeof Echo.channel('updates').here, 'undefined');
        assert.equal(typeof Echo.join('room').here, 'function');
    });
});

describe('pramnos-echo: client events', () => {
    test('whisper() prefixes the event name', () => {
        // Arrange
        const Echo    = loadEcho();
        const channel = Echo.presence('room.lobby');

        // Act
        channel.whisper('typing', { user: 'Ada' });

        // Assert — the 'client-' prefix is the protocol's only marker for a
        // browser-sent event, and the server drops anything without it
        assert.deepEqual(plain(channel._channel.triggered), [
            { event: 'client-typing', data: { user: 'Ada' } },
        ]);
    });

    test('whisper() with no payload sends an empty object', () => {
        // Arrange
        const Echo    = loadEcho();
        const channel = Echo.private('room');

        // Act
        channel.whisper('ping');

        // Assert
        assert.deepEqual(plain(channel._channel.triggered[0].data), {});
    });

    test('listenForWhisper() binds the prefixed event', () => {
        // Arrange
        const Echo    = loadEcho();
        const channel = Echo.presence('room.lobby');
        let payload   = null;

        channel.listenForWhisper('typing', (p) => { payload = p; });

        // Act
        channel._channel.emit('client-typing', { user: 'Grace' });

        // Assert
        assert.deepEqual(plain(payload), { user: 'Grace' });
    });

    test('stopListeningForWhisper() unbinds it', () => {
        // Arrange
        const Echo    = loadEcho();
        const channel = Echo.presence('room.lobby');
        let calls     = 0;

        channel.listenForWhisper('typing', () => { calls += 1; });

        // Act
        channel.stopListeningForWhisper('typing');
        channel._channel.emit('client-typing', {});

        // Assert
        assert.equal(calls, 0);
    });

    test('whisper() is chainable', () => {
        // Arrange
        const Echo    = loadEcho();
        const channel = Echo.presence('room.lobby');

        // Act & Assert
        assert.equal(channel.whisper('a').whisper('b'), channel);
        assert.equal(channel._channel.triggered.length, 2);
    });
});

describe('pramnos-echo: socket id for toOthers()', () => {
    test('socketId() reports the connection id', () => {
        // Arrange
        const Echo = loadEcho();

        // Act & Assert
        assert.equal(Echo.socketId(), '123.456');
    });

    test('headers() carries X-Socket-ID', () => {
        // Arrange
        const Echo = loadEcho();

        // Act & Assert
        assert.deepEqual(plain(Echo.headers()), { 'X-Socket-ID': '123.456' });
    });

    test('headers() is empty before there is a connection', () => {
        // Arrange
        const Echo = loadEcho();
        Echo.disconnect();

        // Act & Assert — the honest answer: there is no connection to exclude yet,
        // and sending a stale id would exclude somebody else's connection
        assert.deepEqual(plain(Echo.headers()), {});
        assert.equal(Echo.socketId(), null);
    });
});
