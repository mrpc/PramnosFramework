/**
 * The toolbar as a SPA project receives it.
 *
 * A server-rendered page gets the toolbar injected before `</body>`. A SPA's
 * shell is a static file that never goes through that pipeline, so its panel is
 * an ES module the application imports — generated from the framework's single
 * toolbar source by `DebugBarAsset::spaModule()`. That makes it code the
 * framework ships and never runs itself, the worst kind, so it is driven here
 * against a DOM stub.
 *
 * What the module must get right: show nothing at all in production, never throw
 * while rendering, draw every collector the payload carries (which is what a SPA
 * was missing), and let the bar be hidden and brought back.
 *
 * Run:
 *   node --test tests/js/spa-debug-panel.test.js
 */
'use strict';

const { test, describe } = require('node:test');
const assert             = require('node:assert/strict');
const fs                 = require('node:fs');
const os                 = require('node:os');
const path               = require('node:path');
const { execFileSync }   = require('node:child_process');

const ROOT = path.join(__dirname, '..', '..');

/**
 * Ask PHP for the module a SPA project is given, exactly as the scaffolder
 * writes it. Reading the asset directly would test a different string than the
 * one that ships.
 */
function loadModuleSource() {
    const php = 'require "vendor/autoload.php";'
        + ' echo Pramnos\\Debug\\DebugBarAsset::spaModule("TestApp");';

    return execFileSync('php', ['-r', php], { cwd: ROOT, encoding: 'utf8' });
}

// ─── A DOM stub with just enough behaviour ──────────────────────────────────

/**
 * A document good enough for the toolbar: element creation, innerHTML with id
 * registration, appendChild, querySelector by id, and delegated click listeners.
 */
function makeDom() {
    const byId = {};

    function makeElement(tag) {
        const el = {
            tagName: tag,
            children: [],
            style: {},
            dataset: {},
            classList: { add() {}, remove() {} },
            listeners: {},
            _text: '',
            _html: '',
            set id(value) { this._id = value; byId[value] = el; },
            get id() { return this._id; },
            set textContent(value) { this._text = String(value); },
            get textContent() { return this._text; },
            set innerHTML(value) {
                this._html = String(value);
                // Register ids the markup declares, as a browser would once it is
                // in the document.
                const re = /id="([^"]+)"/g;
                let m;
                while ((m = re.exec(String(value))) !== null) {
                    byId[m[1]] = byId[m[1]] || makeElement('div');
                }
            },
            get innerHTML() { return this._html; },
            append(...nodes) { el.children.push(...nodes); },
            appendChild(node) { el.children.push(node); return node; },
            remove() {},
            addEventListener(name, fn) { el.listeners[name] = fn; },
            querySelector(selector) {
                const id = selector.replace(/^#/, '');
                byId[id] = byId[id] || makeElement('div');
                return byId[id];
            },
            closest() { return null; },
            select() {},
        };
        return el;
    }

    const body = makeElement('body');
    const head = makeElement('head');

    return {
        byId,
        document: {
            createElement: makeElement,
            body,
            head,
            getElementById: (id) => byId[id] || null,
        },
    };
}

/**
 * Load the module with globals wired to a fresh DOM stub.
 *
 * @param {object}  options
 * @param {?string} options.stored        Value already in storage, or null
 * @param {boolean} options.storageThrows Make storage access itself throw
 */
async function loadPanel({ stored = null, storageThrows = false } = {}) {
    const file = path.join(
        fs.mkdtempSync(path.join(os.tmpdir(), 'pramnos-spa-dbg-')), 'debug.mjs'
    );
    fs.writeFileSync(file, loadModuleSource());

    const dom = makeDom();
    global.document = dom.document;
    global.window = { document: dom.document };
    Object.defineProperty(global, 'navigator', {
        value: { clipboard: { writeText: () => Promise.resolve() } },
        configurable: true,
        writable: true,
    });

    const store = stored === null ? {} : { 'pramnos.debugbar.hidden': stored };
    Object.defineProperty(global, 'localStorage', {
        // A blocked origin or Safari's private mode makes *access itself* throw.
        get: () => {
            if (storageThrows) { throw new Error('access denied'); }
            return {
                getItem: (k) => (k in store ? store[k] : null),
                setItem: (k, v) => { store[k] = String(v); },
                removeItem: (k) => { delete store[k]; },
            };
        },
        configurable: true,
    });
    dom.store = store;

    // Cache-busted so each test gets its own module state; the toolbar keeps its
    // history in module scope, exactly as it does in a browser.
    const module = await import('file://' + file + '?t=' + Math.random());

    return { record: module.record, dom };
}

/** A payload of the shape ApiDebugPayload::build() attaches. */
function payload(extra = {}) {
    const time = extra.time ?? 12.5;
    return Object.assign({
        time,
        memory: { peak_bytes: 1, peak_human: '1 B' },   // the collector's shape
        request: { time, memory: extra.memory ?? 2.5 },
        queries: { count: (extra.queries || []).length, total_ms: 3, queries: extra.queries || [] },
    }, extra.extraKeys || {});
}

/** Click a tab the way the delegated listener sees it. */
function openTab(dom, panel) {
    dom.byId['pramnos-debugbar'].listeners.click({
        target: {
            closest: (selector) => (selector === '.pdb-tab' ? { dataset: { panel } } : null),
        },
    });
}

/** Click the ✕. */
function clickClose(dom) {
    dom.byId['pramnos-debugbar'].listeners.click({
        target: {
            closest: (selector) => (selector === '#pdb-close-btn' ? { id: 'pdb-close-btn' } : null),
        },
    });
}

// ─── Tests ──────────────────────────────────────────────────────────────────

describe('SPA toolbar module', () => {
    test('it records nothing, and builds nothing, in production', async () => {
        // Arrange — production attaches no _debug to anything
        const { record, dom } = await loadPanel();

        // Act
        record('GET', '/api/1.0/status', 200, null);

        // Assert — no data, no DOM, no panel
        assert.equal(dom.byId['pramnos-debugbar'], undefined);
        assert.equal(dom.document.body.children.length, 0);
    });

    test('a payload brings the toolbar into existence', async () => {
        // Arrange
        const { record, dom } = await loadPanel();

        // Act
        record('GET', '/api/1.0/status', 200, payload());

        // Assert
        assert.ok(dom.byId['pramnos-debugbar'], 'the bar exists');
        assert.match(dom.byId['pdb-tabs'].innerHTML, /requests/);
        // Server time comes from request.time — the top-level copy is overwritten
        // by the memory collector, and reading it printed "[object Object]MB".
        assert.match(dom.byId['pdb-info'].innerHTML, /12\.5ms server/);
        assert.match(dom.byId['pdb-info'].innerHTML, /2\.5MB/);
    });

    /**
     * Every collector in the payload becomes a tab.
     *
     * This is what a SPA was missing: `ApiDebugPayload::build()` has always
     * attached every collector, and the SPA panel drew only the requests and
     * their statements. One renderer means one set of tabs.
     */
    test('every collector the payload carries becomes a tab', async () => {
        // Arrange
        const { record, dom } = await loadPanel();

        // Act
        record('GET', '/api/1.0/things', 200, payload({
            extraKeys: {
                session: { active: true, session_id: 'abc', data: { userid: 7 } },
                logs: { count: 1, entries: [{ level: 'error', message: 'boom', time: 1786237200 }] },
                views: { count: 0, views: [] },
                models: { count: 1, ops: 2, operations: [{ class: 'Thing', table: 'things', op: 'load' }] },
                exceptions: { count: 0, items: [] },
                route: { controller: 'things', action: 'list' },
            },
        }));

        // Assert
        const tabs = dom.byId['pdb-tabs'].innerHTML;
        // `models` is the payload key; Domain is the tab, which also holds the
        // request's services.
        ['SQL', 'Session', 'Logs', 'Views', 'Domain', 'Exceptions', 'Route'].forEach((label) => {
            assert.ok(tabs.includes(label), `${label} tab is present`);
        });
        // A collector the payload does not carry gets no tab, rather than an
        // empty one that reads as "nothing happened".
        assert.equal(tabs.includes('Migrations'), false);
    });

    test('each tab draws its own collector', async () => {
        // Arrange
        const { record, dom } = await loadPanel();
        record('GET', '/api/1.0/things', 200, payload({
            queries: [{ sql: 'SELECT 1', time: 0.4 }, { sql: 'SELECT 2', time: 0, from_cache: true }],
            extraKeys: {
                session: { active: true, session_id: 'abc', data: { userid: 7 } },
                exceptions: { count: 1, items: [{ type: 'exception', class: 'RuntimeException', message: 'boom', file: '/x.php', line: 3 }] },
            },
        }));

        // Act & Assert — SQL
        openTab(dom, 'queries');
        let html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /SELECT 1/);
        // A cached statement did not run; showing it as 0ms reads as "instant".
        assert.match(html, /CACHE/);
        assert.match(html, /Copy all/);

        // Act & Assert — Session
        openTab(dom, 'session');
        html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /userid/);

        // Act & Assert — Exceptions
        openTab(dom, 'exceptions');
        html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /RuntimeException/);
        assert.match(html, /x\.php:3/);
    });

    test('the requests tab lists them newest first, with a wall clock', async () => {
        // Arrange
        const { record, dom } = await loadPanel();
        record('GET', '/api/1.0/first', 200, payload());
        record('POST', '/api/1.0/second', 201, payload());

        // Act
        openTab(dom, 'requests');

        // Assert — in the table; the waterfall above it is a time axis and runs
        // the other way, oldest first
        const html  = dom.byId['pdb-panel'].innerHTML;
        const table = html.split('<table')[1];
        assert.ok(table.indexOf('/api/1.0/second') < table.indexOf('/api/1.0/first'), 'newest first');
        assert.ok(/\d{2}:\d{2}:\d{2}\.\d{3}/.test(table), 'each row carries a wall clock');
    });

    /**
     * A 204 has no body to carry a payload, and it is exactly the call somebody
     * wants to see — so once the toolbar is active, later requests are recorded
     * whether or not they bring their own data.
     */
    test('once active, a 204 with no payload is still recorded', async () => {
        // Arrange
        const { record, dom } = await loadPanel();
        record('GET', '/api/1.0/status', 200, payload());

        // Act
        record('POST', '/api/1.0/things/1', 204, null, { ms: 15 });
        openTab(dom, 'requests');

        // Assert
        assert.match(dom.byId['pdb-panel'].innerHTML, /204/);
    });

    /**
     * A SPA has no page request — its shell never went through the middleware —
     * so the newest call is the one worth showing, and the panel follows it.
     * (A server-rendered page stays on its own request instead; that is driven
     * in `debugbar-ajax.test.js`.)
     */
    test('with no page request, the newest call drives the tabs', async () => {
        // Arrange
        const { record, dom } = await loadPanel();
        record('GET', '/api/1.0/first', 200, payload({ time: 11 }));

        // Act
        record('POST', '/api/1.0/second', 201, payload({ time: 22 }));

        // Assert
        assert.match(dom.byId['pdb-info'].innerHTML, /POST \/api\/1\.0\/second/);
        assert.match(dom.byId['pdb-info'].innerHTML, /22ms server/);
    });

    test('rendering survives a payload with nothing in it', async () => {
        // Arrange — a collector that returned little, or an older server
        const { record, dom } = await loadPanel();

        // Act & Assert — reaching the end without throwing is the assertion
        assert.doesNotThrow(() => {
            record('GET', '/api/thin', 200, { time: 1 });
            openTab(dom, 'requests');
            record('GET', '/api/thinner', 500, {});
        });
        assert.ok(dom.byId['pdb-panel'].innerHTML.length > 0);
    });

    /**
     * A collector that threw is reported, not hidden.
     *
     * The payload carries `{error: …}` in its place; a blank panel would be read
     * as "nothing happened here".
     */
    test('a collector that failed says so', async () => {
        // Arrange
        const { record, dom } = await loadPanel();
        record('GET', '/api/1.0/things', 200, payload({
            extraKeys: { session: { error: 'session_start(): failed' } },
        }));

        // Act
        openTab(dom, 'session');

        // Assert
        assert.match(dom.byId['pdb-panel'].innerHTML, /This collector failed/);
        assert.match(dom.byId['pdb-panel'].innerHTML, /session_start/);
    });

    test('the close button hides the whole bar and frees the page', async () => {
        // Arrange
        const { record, dom } = await loadPanel();
        record('GET', '/api/1.0/status', 200, payload());
        assert.equal(dom.byId['pramnos-debugbar'].style.display, '');
        assert.equal(dom.document.body.style.paddingBottom, '30px');

        // Act
        clickClose(dom);

        // Assert
        assert.equal(dom.byId['pramnos-debugbar'].style.display, 'none', 'the bar goes, not the panel');
        assert.equal(dom.document.body.style.paddingBottom, '', 'the reserved strip goes with it');
        assert.equal(dom.byId['pdb-restore'].style.display, 'block', 'and a way back appears');
        assert.equal(dom.store['pramnos.debugbar.hidden'], '1');
    });

    test('the restore handle brings it back and forgets the choice', async () => {
        // Arrange
        const { record, dom } = await loadPanel();
        record('GET', '/api/1.0/status', 200, payload());
        clickClose(dom);

        // Act — the handle has its own listener, not the delegated one
        dom.byId['pdb-restore'].listeners.click();

        // Assert
        assert.equal(dom.byId['pramnos-debugbar'].style.display, '');
        assert.equal(dom.byId['pdb-restore'].style.display, 'none');
        assert.equal(dom.document.body.style.paddingBottom, '30px');
        assert.equal('pramnos.debugbar.hidden' in dom.store, false, 'nothing left to re-hide it');
    });

    test('a bar hidden earlier is still hidden when the toolbar is rebuilt', async () => {
        // Arrange — the choice was made on a previous page
        const { record, dom } = await loadPanel({ stored: '1' });

        // Act
        record('GET', '/api/1.0/status', 200, payload());

        // Assert
        assert.equal(dom.byId['pramnos-debugbar'].style.display, 'none');
        assert.equal(dom.byId['pdb-restore'].style.display, 'block');
        assert.equal(dom.document.body.style.paddingBottom, '', 'no padding for a bar nobody sees');
    });

    test('storage that throws costs the memory, not the button', async () => {
        // Arrange
        const { record, dom } = await loadPanel({ storageThrows: true });

        // Act & Assert
        assert.doesNotThrow(() => {
            record('GET', '/api/1.0/status', 200, payload());
            clickClose(dom);
        });
        assert.equal(dom.byId['pramnos-debugbar'].style.display, 'none', 'hiding still works');
    });

    /**
     * Signing in changes the Auth tab, without a page refresh.
     *
     * Reported from the browser: after authenticating in a SPA, the tab still
     * said "anonymous" and only a refresh appeared to fix it. Auth is a state,
     * not a property of a request — the call made before signing in is over, and
     * what a reader wants is who they are now.
     */
    test('the Auth tab follows the login rather than the selected request', async () => {
        // Arrange — a call made while anonymous
        const { record, dom } = await loadPanel();
        record('GET', '/api/1.0/status', 200, payload({
            extraKeys: { auth: { user: { authenticated: false }, credential: 'none', source: '', token: null } },
        }));

        openTab(dom, 'auth');
        assert.match(dom.byId['pdb-panel'].innerHTML, /Anonymous/);

        // Act — the user signs in, and the next call carries a token
        record('POST', '/api/1.0/account/login', 200, payload({
            extraKeys: {
                auth: {
                    user: { authenticated: true, userid: 42, username: 'alice', usertype: 90 },
                    credential: 'accessToken',
                    source: 'accessToken header',
                    token: { format: 'jwt', claims: { sub: 'u42' }, expires_at: Math.floor(Date.now() / 1000) + 900 },
                },
            },
        }));

        // Assert — without touching anything else
        openTab(dom, 'auth');
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /alice/);
        assert.match(html, /accessToken/);
        assert.equal(html.includes('Anonymous'), false);
    });

    /**
     * An expired credential is counted down to, and says when it ran out.
     *
     * The countdown is from the token's own absolute expiry rather than from a
     * "seconds remaining" computed on the server: the response may have been
     * sitting in the browser for a while before anybody opened the tab.
     */
    test('the Auth tab counts down, and says so when the credential has expired', async () => {
        // Arrange — a token that ran out a minute ago
        const { record, dom } = await loadPanel();
        record('GET', '/api/1.0/things', 401, payload({
            extraKeys: {
                auth: {
                    user: { authenticated: false },
                    credential: 'accessToken',
                    source: 'Authorization: Bearer',
                    token: { format: 'jwt', claims: {}, expires_at: Math.floor(Date.now() / 1000) - 60 },
                },
            },
        }));

        // Act
        openTab(dom, 'auth');

        // Assert
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /Expired/);
        assert.match(html, /ago/);
        // And the tab itself is drawn as an alarm, so a reader does not have to
        // open it to find out why four calls were refused
        assert.match(dom.byId['pdb-tabs'].innerHTML, /pdb-tab-alert/);
    });

    /**
     * A credential with time left says how much, in something a person reads
     * without counting zeros.
     */
    test('a valid credential shows how long it has left', async () => {
        // Arrange — twenty-five minutes
        const { record, dom } = await loadPanel();
        record('GET', '/api/1.0/things', 200, payload({
            extraKeys: {
                auth: {
                    user: { authenticated: true, userid: 7, username: 'bob', usertype: 10 },
                    credential: 'accessToken',
                    source: 'accessToken header',
                    token: { format: 'jwt', claims: { sub: 'u7' }, expires_at: Math.floor(Date.now() / 1000) + 1500 },
                },
            },
        }));

        // Act
        openTab(dom, 'auth');

        // Assert
        assert.match(dom.byId['pdb-panel'].innerHTML, /Valid for another <strong>2[45]m/);
    });

    /**
     * An opaque credential is reported as such rather than as an empty claims
     * table — a random string looked up in a table is a perfectly good token.
     */
    test('an opaque token says there is nothing inside it', async () => {
        // Arrange
        const { record, dom } = await loadPanel();
        record('GET', '/api/1.0/things', 200, payload({
            extraKeys: {
                auth: {
                    user: { authenticated: true, userid: 3, username: 'carol', usertype: 1 },
                    credential: 'accessToken',
                    source: 'accessToken header',
                    token: { format: 'opaque' },
                },
            },
        }));

        // Act
        openTab(dom, 'auth');

        // Assert
        assert.match(dom.byId['pdb-panel'].innerHTML, /nothing inside it/);
    });

    /**
     * A SPA gets no DevPanel link, because there is no DevPanel behind it.
     *
     * It is a server-rendered page requiring MVC routing and an admin session;
     * a SPA project's server answers JSON and `/devpanel` is a 404. A link to
     * nothing is worse than no link — it reads as a feature that is broken
     * rather than one that is not there.
     */
    test('no DevPanel link is offered in a SPA', async () => {
        // Arrange & Act
        const { record, dom } = await loadPanel();
        record('GET', '/api/1.0/status', 200, payload());

        // Assert
        assert.equal(dom.byId['pramnos-debugbar'].innerHTML.includes('pdb-devpanel'), false);
    });

    /**
     * A login reports the identity it *created*, not the anonymous one it was
     * made with — and describes the token it just handed out.
     *
     * `/account/login` carries no credential: the token is issued *by* that
     * call. Reporting the state it arrived in meant the panel said "anonymous"
     * at the exact moment somebody signed in, and only caught up on the next
     * request — which for a SPA that takes the user from the login response may
     * never come.
     */
    test('a login shows who signed in, and how long the new token lasts', async () => {
        // Arrange
        const { record, dom } = await loadPanel();

        // Act — the login response, as the server now reports it
        record('POST', '/api/1.0/account/login', 200, payload({
            extraKeys: {
                auth: {
                    user: { authenticated: true, userid: 2, username: 'admin', usertype: 90 },
                    credential: 'password',
                    source: 'this request signed in',
                    token: {
                        format: 'jwt',
                        claims: { iss: 'http://localhost/api/' },
                        expires_at: Math.floor(Date.now() / 1000) + 3600,
                    },
                },
            },
        }));

        // Assert — without a refresh, and with the countdown
        openTab(dom, 'auth');
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /admin/);
        assert.match(html, /password/);
        assert.match(html, /Valid for another/);
    });

    /**
     * A logout reports the state it leaves behind.
     *
     * The call itself is authenticated — it has to be, to revoke anything — so
     * reporting the state it arrived in showed "signed in as admin" immediately
     * after signing out, which reads as a logout that did not work.
     */
    test('a logout shows that nobody is signed in any more', async () => {
        // Arrange — signed in first
        const { record, dom } = await loadPanel();
        record('GET', '/api/1.0/me', 200, payload({
            extraKeys: {
                auth: {
                    user: { authenticated: true, userid: 2, username: 'admin', usertype: 90 },
                    credential: 'accessToken',
                    source: 'accessToken header',
                    token: { format: 'jwt', claims: {}, expires_at: Math.floor(Date.now() / 1000) + 3600 },
                },
            },
        }));

        // Act
        record('POST', '/api/1.0/account/logout', 200, payload({
            extraKeys: {
                auth: {
                    user: { authenticated: false },
                    credential: 'none',
                    source: 'this request signed out',
                    token: null,
                },
            },
        }));

        // Assert
        openTab(dom, 'auth');
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /Anonymous/);
        assert.match(html, /signed out/);
        assert.equal(html.includes('Valid for another'), false, 'no countdown for a credential that is gone');
    });
});
