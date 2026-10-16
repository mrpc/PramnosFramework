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
    // The pages this worker controls, by client id, as `clients.get()` answers.
    const pages = { admin: { url: 'https://app.test/admin/Users' }, site: { url: 'https://app.test/orders' } };
    const self = {
        location: new URL(location),
        registration: { scope, pushManager: {}, showNotification: async () => {}, navigationPreload: { enabled: false, async enable() { this.enabled = true; } } },
        clients: { claim: async () => {}, matchAll: async () => clients, openWindow: async () => {}, get: async (id) => pages[id] },
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

    return { fire, names, posted, fetched, caches, listeners, self };
}

/** A previous worker's hand-versioned caches are deleted on activation; this one's and others stay. */
test('activation deletes the caches a previous worker left', async () => {
    // Arrange
    const sw = worker();
    for (const name of ['v1.32::static', 'v1.32::pages', 'v1.31::images', 'app-assets', 'something-else']) { await sw.caches.open(name); }

    // Act
    await sw.fire('activate');

    // Assert — and this worker's own asset cache, which an earlier version filled from admin pages
    assert.deepEqual([...sw.names.keys()].sort(), ['something-else']);
});

/**
 * Navigation preload is switched on when the worker activates, and a navigation answers with the
 * preloaded response when the browser started one — without a second request; with none, the
 * network; with a preload that failed, the offline page, as a failed fetch does.
 */
test('a navigation uses the preloaded response', async () => {
    // Arrange
    const offlineUrl = 'https://app.test/offline.html';
    const sw = worker({ [offlineUrl]: 'you are offline', 'https://app.test/orders': 'from the network' });
    await sw.fire('install');
    const page = { url: 'https://app.test/orders', method: 'GET', mode: 'navigate' };

    // Act
    await sw.fire('activate');
    const preloaded = await sw.fire('fetch', { request: page, preloadResponse: Promise.resolve(new Response('preloaded')) });
    const none      = await sw.fire('fetch', { request: page, preloadResponse: Promise.resolve(undefined) });
    const failed    = await sw.fire('fetch', { request: { ...page, url: 'https://app.test/away' }, preloadResponse: Promise.reject(new TypeError('offline')) });

    // Assert
    assert.equal(sw.self.registration.navigationPreload.enabled, true);
    assert.equal(await preloaded.text(), 'preloaded');
    assert.equal(sw.fetched.filter((f) => f.url === page.url).length, 1, 'only the request without a preload reached the network');
    assert.equal(await none.text(), 'from the network');
    assert.equal(await failed.text(), 'you are offline');
});

/**
 * A navigation into a skipped area takes its preloaded answer too: the browser asked for it
 * already, and leaving it unused makes the browser ask a second time. With no preload it is still
 * left to the browser.
 */
test('a skipped navigation uses the preload rather than a second request', async () => {
    // Arrange
    const sw = worker({ 'https://app.test/admin/Users': 'from the network' });
    const admin = { url: 'https://app.test/admin/Users', method: 'GET', mode: 'navigate' };

    // Act
    const preloaded = await sw.fire('fetch', { request: admin, preloadResponse: Promise.resolve(new Response('preloaded admin')) });
    const empty     = await sw.fire('fetch', { request: admin, preloadResponse: Promise.resolve(undefined) });
    const none      = await sw.fire('fetch', { request: admin });

    // Assert
    assert.equal(await preloaded.text(), 'preloaded admin');
    assert.equal(await empty.text(), 'from the network');
    assert.equal(none, null, 'no preload: the browser\'s own');
});

/**
 * An asset an administration page asks for is neither read from nor written to the cache: by its
 * referrer, the worker does not respond at all; a font a stylesheet asks for has the stylesheet as
 * its referrer, so the page it is for decides, and it comes from the network. A site page's assets
 * are still cached.
 */
test('what an administration page loads is not cached', async () => {
    // Arrange
    const script = 'https://app.test/assets/js/pf-utils.js';
    const font   = 'https://app.test/assets/vendor/fonts/a.woff2';
    const sw = worker({ [script]: 'v2', [font]: 'font' });
    const cache = await sw.caches.open('app-assets');
    cache.store.set(script, new Response('v1'));

    // Act
    const byReferrer = await sw.fire('fetch', { request: { url: script, method: 'GET', mode: 'no-cors', referrer: 'https://app.test/admin/Users' } });
    const byClient   = await sw.fire('fetch', { clientId: 'admin', request: { url: font, method: 'GET', mode: 'cors', referrer: 'https://app.test/assets/css/style.css' } });
    const sitePage   = await sw.fire('fetch', { clientId: 'site', request: { url: font, method: 'GET', mode: 'cors', referrer: 'https://app.test/assets/css/style.css' } });

    // Assert
    assert.equal(byReferrer, null, 'left to the browser: no respondWith at all');
    assert.equal(await byClient.text(), 'font', 'from the network');
    assert.equal(await sitePage.text(), 'font');
    assert.equal(await (await cache.match(script)).text(), 'v1', 'the admin page\'s request wrote nothing over it');
    assert.ok(cache.store.has(font), 'the site page\'s font was stored');
    assert.equal(sw.fetched.filter((f) => f.url === font).length, 2, 'the admin one went to the network, then the site one');
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

/**
 * A browser whose CacheStorage is broken — every `caches.open()` rejects — gets its assets from
 * the network and the worker reports the fault once: then it is network-only and leaves requests
 * to the browser. One such browser used to send a report per request and fill a server's PHP
 * pool. Reports go without cookies, each message once, a few a minute in all.
 */
test('a broken CacheStorage is one report, then network-only', async () => {
    // Arrange
    const asset = (i) => 'https://app.test/assets/js/a' + i + '.js';
    const routes = { 'https://app.test/ClientErrors': '' };
    for (let i = 0; i < 100; i++) { routes[asset(i)] = 'js ' + i; }
    const sw = worker(routes);
    sw.caches.open = async () => { throw new Error("Failed to execute 'open' on 'CacheStorage': Unexpected internal error"); };

    // Act — a page with a hundred assets
    const answers = [];
    for (let i = 0; i < 100; i++) {
        answers.push(await sw.fire('fetch', { request: { url: asset(i), method: 'GET', mode: 'no-cors' } }));
    }
    await new Promise((r) => setImmediate(r));
    const reports = sw.fetched.filter((f) => f.url.endsWith('/ClientErrors'));

    // Assert
    assert.equal(await answers[0].text(), 'js 0', 'the first from the network, not a failure');
    assert.equal(answers.slice(1).every((a) => a === null), true, 'then left to the browser');
    assert.equal(reports.length, 1, 'one report for a fault on every request');
    assert.equal(reports[0].init.credentials, 'omit');
});

/** Many different errors are a few reports a minute, and the same one again is not sent again. */
test('the worker\'s reports are capped', async () => {
    // Arrange
    const sw = worker({ 'https://app.test/ClientErrors': '' });

    // Act
    for (let i = 0; i < 20; i++) {
        sw.listeners.error({ error: new Error('boom ' + i), message: 'boom ' + i });
        sw.listeners.error({ error: new Error('boom 0'), message: 'boom 0' });
    }
    await new Promise((r) => setImmediate(r));

    // Assert
    assert.equal(sw.fetched.filter((f) => f.url.endsWith('/ClientErrors')).length, 5);
});

/**
 * The registration adds to what cannot change: a path under the scope, or `?v` for an address
 * that carries the parameter — served from the cache without asking the network again; anything
 * else is still revalidated.
 */
test('the registration adds immutable addresses', async () => {
    // Arrange
    const font = 'https://app.test/assets/fonts/a.woff2';
    const stamped = 'https://app.test/assets/js/app.js?v=17';
    const plain = 'https://app.test/assets/js/other.js';
    const sw = worker({ [font]: 'font', [stamped]: 'app', [plain]: 'other' }, 'https://app.test/sw.js?immutable=assets/fonts/,?v=');

    // Act — each asked for twice
    for (const url of [font, stamped, plain]) {
        await sw.fire('fetch', { request: { url, method: 'GET', mode: 'no-cors' } });
        await sw.fire('fetch', { request: { url, method: 'GET', mode: 'no-cors' } });
    }

    // Assert
    const asked = (url) => sw.fetched.filter((f) => f.url === url).length;
    assert.equal(asked(font), 1, 'a path the registration names');
    assert.equal(asked(stamped), 1, 'an address with ?v=');
    assert.equal(asked(plain), 2, 'the rest are revalidated');
});
