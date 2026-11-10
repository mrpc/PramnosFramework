/**
 * `lib/live.js` as a SPA receives it: the signed-in user's notifications, the moment they are
 * stored or read, told to the open screens as `pf:notifications:refresh`.
 *
 * What has to hold: on the user's channel — the tag's `userChannel`, or `private-users.{id}` —
 * `notification.created` and `notification.read` each refresh; the signing is given the API's key
 * and token (empty ones left out); stopping stops; and with no socket, or nobody signed in, it does
 * nothing at all.
 *
 * Run:
 *   node --test tests/js/spa-live.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const fs       = require('node:fs');
const os       = require('node:os');
const path     = require('node:path');
const { pathToFileURL } = require('node:url');

const TEMPLATES = path.join(__dirname, '..', '..', 'scaffolding', 'templates');

/** The stub, imported as the ES module a project gets. */
async function load() {
    const dir  = fs.mkdtempSync(path.join(os.tmpdir(), 'pf-live-'));
    const file = path.join(dir, 'live.mjs');
    fs.copyFileSync(path.join(TEMPLATES, 'spa-live.js.stub'), file);
    try {
        return await import(pathToFileURL(file).href + '?' + Math.random());
    } finally {
        fs.rmSync(dir, { recursive: true, force: true });
    }
}

/** A window with a socket (or none), the tag carrying `content`, and a record of what it heard. */
function win({ socket = true, content = null } = {}) {
    const listeners = {};
    const joined = [];
    const fired = [];
    const channel = (name) => {
        joined.push(name);
        return {
            listen(event, fn) { (listeners[name + ' ' + event] ||= []).push(fn); return this; },
            stopListening(event, fn) { listeners[name + ' ' + event] = (listeners[name + ' ' + event] || []).filter((one) => one !== fn); return this; },
        };
    };
    return {
        pfRealtime: socket ? { channel } : undefined,
        document: { querySelector: (selector) => (selector === 'meta[name="pf-realtime"]' && content !== null ? { getAttribute: () => content } : null) },
        dispatchEvent(event) { fired.push(event.type); return true; },
        hear(name, event) { (listeners[name + ' ' + event] || []).forEach((fn) => fn({})); },
        joined,
        fired,
    };
}

test('the user\'s channel: what is stored or read refreshes, and stopping stops', async () => {
    // Arrange
    const { listen, REFRESH } = await load();
    const w = win({ content: JSON.stringify({ transport: 'websocket', userChannel: 'private-team.{id}.alerts' }) });

    // Act
    const stop = listen(7, { win: w });
    w.hear('private-team.7.alerts', 'notification.created');
    w.hear('private-team.7.alerts', 'notification.read');
    stop();
    w.hear('private-team.7.alerts', 'notification.created');

    // Assert
    assert.deepEqual(w.joined, ['private-team.7.alerts'], 'the application\'s pattern, its id filled in');
    assert.deepEqual(w.fired, [REFRESH, REFRESH], 'one each, then nothing once stopped');
    assert.equal(REFRESH, 'pf:notifications:refresh', 'what the bell listens to');
});

test('the signing is given the API key and the token, asked each time; an empty one is left out', async () => {
    // Arrange
    const { listen } = await load();
    const w = win();
    let token = null;

    // Act
    listen(5, { win: w, headers: () => ({ apiKey: 'k', accessToken: token }) });
    const before = w.pfRealtimeHeaders();
    token = 'tok';
    const after = w.pfRealtimeHeaders();

    // Assert
    assert.deepEqual(w.joined, ['private-users.5'], 'the framework\'s channel without a tag');
    assert.deepEqual(before, { apiKey: 'k' }, 'no token yet: none sent');
    assert.deepEqual(after, { apiKey: 'k', accessToken: 'tok' });
});

test('with no socket, or nobody signed in, nothing is joined and stopping is harmless', async () => {
    // Arrange
    const { listen } = await load();
    const none = win({ socket: false });
    const anonymous = win();

    // Act
    const stopNone = listen(5, { win: none });
    const stopAnonymous = listen(0, { win: anonymous });
    stopNone();
    stopAnonymous();

    // Assert
    assert.deepEqual([none.joined, anonymous.joined], [[], []]);
});
