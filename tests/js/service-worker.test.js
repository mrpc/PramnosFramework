/**
 * The scaffolded service worker, run against fake caches, network and clients.
 *
 * What has to hold, so an application can replace a worker of its own with this one: the caches
 * a previous worker left (`v1.32::static`, `::pages`, `::images`) are deleted on activation, and
 * an asset is read from this worker's cache only, never from theirs; a page always comes from the
 * network and is never stored, with the offline page standing in only when there is no network; an
 * image with no copy and no network is a placeholder; a `broadcast` reaches every page; and the
 * worker's own errors are reported to `ClientErrors`.
 *
 * Run:
 *   node --test tests/js/service-worker.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const fs       = require('node:fs');
const path     = require('node:path');
const vm       = require('node:vm');

/** Node's own, the one the worker answers with. */
const { Response } = globalThis;

const STUB = path.join(__dirname, '..', '..', 'scaffolding', 'templates', 'service-worker.js.stub');

/** One fake cache: url → Response. */
function fakeCache(store, fetch) {
    return {
        store,
        async match(request) { const r = store.get(typeof request === 'string' ? request : request.url); return r ? r.clone() : undefined; },
        async put(request, response) { store.set(typeof request === 'string' ? request : request.url, response); },
        async add(url) { const r = await fetch({ url, method: 'GET' }); if (!r.ok) { throw new Error('add failed'); } store.set(url, r); },
        async keys() { return [...store.keys()].map((url) => ({ url })); },
        async delete(request) { return store.delete(typeof request === 'string' ? request : request.url); },
    };
}

/**
 * The worker, loaded with its tokens filled, over a network answered by `routes`.
 *
 * @param {object} routes url → Response body (string), or an Error to fail with
 * @param {string} location The worker's own URL
 * @param {string} scope    The registration's scope
 */
function worker(routes = {}, location = 'https://app.test/sw.js', scope = 'https://app.test/') {
    const listeners = {};
    const names = new Map();
    const posted = [];
    const fetched = [];
    const fetch = async (request, init) => {
        const url = typeof request === 'string' ? request : request.url;
        fetched.push({ url, init });
        const answer = routes[url];
        if (answer === undefined || answer instanceof Error) { throw answer || new TypeError('offline'); }
        return new Response(answer, { status: 200 });
    };
    const caches = {
        async open(name) { if (!names.has(name)) { names.set(name, fakeCache(new Map(), fetch)); } return names.get(name); },
        async keys() { return [...names.keys()]; },
        async delete(name) { return names.delete(name); },
        async match() { throw new Error('the worker must read its own cache only'); },
    };
    const clients = [{ postMessage: (m) => posted.push(m) }, { postMessage: (m) => posted.push(m) }];
    const self = {
        location: new URL(location),
        registration: { scope, pushManager: {}, showNotification: async () => {} },
        clients: { claim: async () => {}, matchAll: async () => clients, openWindow: async () => {} },
        skipWaiting: () => {},
        addEventListener: (type, fn) => { listeners[type] = fn; },
    };
    const source = fs.readFileSync(STUB, 'utf8').replace(/\{\{\s*APP_SLUG\s*\}\}/g, 'app').replace(/\{\{\s*SPA_BUILD_DIR_REGEX\s*\}\}/g, 'assets\\/spa').replace(/\{\{\s*\w+\s*\}\}/g, '');
    vm.runInNewContext(source, { self, caches, fetch, URL, Response, console, setTimeout, Promise });

    /** Fire an event and settle what it asked to wait for and to respond with. */
    async function fire(type, init = {}) {
        let responded = null;
        const waits = [];
        const event = Object.assign({
            respondWith: (p) => { responded = p; },
            waitUntil: (p) => { waits.push(p); },
        }, init);
        listeners[type](event);
        const response = responded ? await responded : null;
        await Promise.all(waits);
        return response;
    }

    return { fire, names, posted, fetched, caches, listeners };
}

/** A previous worker's hand-versioned caches are deleted on activation; this one's and others stay. */
test('activation deletes the caches a previous worker left', async () => {
    // Arrange
    const sw = worker();
    for (const name of ['v1.32::static', 'v1.32::pages', 'v1.31::images', 'app-assets', 'something-else']) { await sw.caches.open(name); }

    // Act
    await sw.fire('activate');

    // Assert
    assert.deepEqual([...sw.names.keys()].sort(), ['app-assets', 'something-else']);
});

/** An asset is read from this worker's cache: a stale copy in another cache is never served. */
test('an asset comes from this worker\'s cache, not another\'s', async () => {
    // Arrange
    const url = 'https://app.test/assets/vendor/jquery/3/jquery.min.js';
    const sw = worker({ [url]: 'fresh' });
    (await sw.caches.open('v1.32::static')).store.set(url, new Response('stale'));

    // Act — cached by this worker on the first request, then served from it
    const first = await sw.fire('fetch', { request: { url, method: 'GET', mode: 'no-cors' } });
    const second = await sw.fire('fetch', { request: { url, method: 'GET', mode: 'no-cors' } });

    // Assert
    assert.equal(await first.text(), 'fresh');
    assert.equal(await second.text(), 'fresh');
    assert.equal(sw.fetched.filter((f) => f.url === url).length, 1, 'immutable: the second never asked the network');
});

/**
 * A page comes from the network and is never stored; with no network the offline page stands in,
 * the one the registration names; with neither, the browser's own error.
 */
test('a page is never stored, and the offline page stands in without a network', async () => {
    // Arrange — the offline page named in the registration, stored on install
    const offlineUrl = 'https://app.test/errors/offline.html';
    const sw = worker({ [offlineUrl]: 'you are offline', 'https://app.test/orders': 'orders page' }, 'https://app.test/sw.js?offline=errors/offline.html');
    await sw.fire('install');

    // Act
    const online  = await sw.fire('fetch', { request: { url: 'https://app.test/orders', method: 'GET', mode: 'navigate' } });
    const offline = await sw.fire('fetch', { request: { url: 'https://app.test/profile', method: 'GET', mode: 'navigate' } });

    // Assert
    assert.equal(await online.text(), 'orders page');
    assert.equal(await offline.text(), 'you are offline');
    const stored = [...(await sw.caches.open('app-assets')).store.keys()];
    assert.deepEqual(stored, [], 'no page in the asset cache');
});

/**
 * The administration area and the API are left to the browser by default — no page, no asset
 * handled — so a deploy never pairs an admin page with last release's admin.js; `skip` names
 * other prefixes, relative to the scope, and `skip=` none.
 */
test('the administration area and the API are left to the browser', async () => {
    // Arrange
    const sw = worker({ 'https://app.test/admin/assets/admin.js': 'new', 'https://app.test/assets/app.css': 'css' });
    const fire = (instance, url, mode = 'no-cors') => instance.fire('fetch', { request: { url, method: 'GET', mode } });

    // Act / Assert — by default
    assert.equal(await fire(sw, 'https://app.test/admin/assets/admin.js'), null, 'an admin asset is not handled');
    assert.equal(await fire(sw, 'https://app.test/admin', 'navigate'), null, 'the area\'s own root too');
    assert.equal(await fire(sw, 'https://app.test/api/v1/users'), null);
    assert.equal(await (await fire(sw, 'https://app.test/assets/app.css')).text(), 'css', 'the rest as before');
    assert.notEqual(await fire(sw, 'https://app.test/administrators', 'navigate'), null, 'a prefix, not a substring');

    // Another prefix, under a scope of its own
    const sub = worker({}, 'https://app.test/shop/sw.js?skip=/backoffice/', 'https://app.test/shop/');
    assert.equal(await fire(sub, 'https://app.test/shop/backoffice/x.js'), null);
    assert.notEqual(await fire(sub, 'https://app.test/shop/admin/', 'navigate'), null, 'the default replaced');

    // None
    const none = worker({ 'https://app.test/admin/a.css': 'a' }, 'https://app.test/sw.js?skip=');
    assert.equal(await (await fire(none, 'https://app.test/admin/a.css')).text(), 'a');
});

/** Without an offline page, a failed navigation is the browser's own error. */
test('without an offline page a failed navigation is a network error', async () => {
    // Arrange — nothing at offline.html: install stores nothing, and does not fail
    const sw = worker();
    await sw.fire('install');

    // Act
    const response = await sw.fire('fetch', { request: { url: 'https://app.test/x', method: 'GET', mode: 'navigate' } });

    // Assert
    assert.equal(response.type, 'error');
});

/** An image with no copy and no network is a grey "offline" box; a script is not. */
test('an image that cannot be had is a placeholder', async () => {
    // Arrange
    const sw = worker();

    // Act
    const image = await sw.fire('fetch', { request: { url: 'https://app.test/media/a.png', method: 'GET', mode: 'no-cors' } });

    // Assert
    assert.equal(image.headers.get('Content-Type'), 'image/svg+xml');
    assert.match(await image.text(), /offline/);
    await assert.rejects(sw.fire('fetch', { request: { url: 'https://app.test/assets/js/app.js', method: 'GET', mode: 'no-cors' } }));
});

/** A page's `broadcast` reaches every page the worker controls. */
test('a broadcast reaches every page', async () => {
    // Arrange
    const sw = worker();

    // Act
    await sw.fire('message', { data: { command: 'broadcast', message: 'signed-out' } });

    // Assert — through JSON: the messages were made in the worker's realm, with its Object
    assert.deepEqual(JSON.parse(JSON.stringify(sw.posted)), [
        { command: 'broadcastOnRequest', message: 'signed-out' },
        { command: 'broadcastOnRequest', message: 'signed-out' },
    ]);
});

/** The worker's own error is reported to the pages' address, as kind `worker`. */
test('the worker reports its own errors', async () => {
    // Arrange
    const sw = worker({ 'https://app.test/ClientErrors': '' });

    // Act
    sw.listeners.error({ error: { message: 'push failed', stack: 'at push' }, message: 'push failed' });
    await new Promise((r) => setImmediate(r));

    // Assert
    const report = sw.fetched.find((f) => f.url === 'https://app.test/ClientErrors');
    assert.ok(report, 'reported');
    assert.deepEqual(JSON.parse(report.init.body), { kind: 'worker', message: 'push failed', source: '/sw.js', stack: 'at push' });
});
