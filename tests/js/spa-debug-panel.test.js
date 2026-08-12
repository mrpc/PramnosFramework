/**
 * The SPA debug panel — the scaffolded equivalent of the server-rendered toolbar.
 *
 * A server-rendered page gets the toolbar injected before `</body>`. A SPA's
 * shell is a static file that never goes through that pipeline, so its panel is
 * a module the scaffolded application imports. That makes it code the framework
 * ships and never runs — the worst kind — so it is tested here against a DOM
 * stub, the same way the toolbar's own script is.
 *
 * The properties that matter are the two it is easy to get wrong: it must show
 * nothing at all in production, and it must not throw while rendering, because
 * a debug panel that breaks the application it measures is worse than none.
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

const STUB = path.join(
    __dirname, '..', '..', 'scaffolding', 'templates', 'spa-debug-panel.js.stub'
);

// ─── A DOM stub with just enough behaviour ──────────────────────────────────

/**
 * Build a document good enough for the panel: element creation, innerHTML,
 * append, querySelector by id, and a delegated click listener.
 */
function makeDom() {
    const byId = {};

    function makeElement(tag) {
        const el = {
            tagName: tag,
            children: [],
            style: { cssText: '', display: '' },
            dataset: {},
            classList: { add() {}, remove() {} },
            _text: '',
            _html: '',
            listeners: {},
            set id(value) { this._id = value; byId[value] = el; },
            get id() { return this._id; },
            set textContent(value) {
                this._text = String(value);
                this._html = String(value)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            },
            get textContent() { return this._text; },
            set innerHTML(value) {
                this._html = String(value);
                // Register any id="..." the markup declares, the way a browser
                // would once it is in the document.
                const re = /id="([^"]+)"/g;
                let m;
                while ((m = re.exec(String(value))) !== null) {
                    byId[m[1]] = byId[m[1]] || makeElement('div');
                }
            },
            get innerHTML() { return this._html; },
            append(...nodes) { el.children.push(...nodes); },
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
 * Load the stub as a module, with globals wired to a fresh DOM stub.
 *
 * The stub is a template with `{{ appName }}` placeholders, so it is rendered
 * to a temporary file first — which is also what the scaffolder does.
 */
async function loadPanel({ stored = null, storageThrows = false } = {}) {
    const source = fs.readFileSync(STUB, 'utf8').replace(/\{\{ appName \}\}/g, 'TestApp');
    const file   = path.join(
        fs.mkdtempSync(path.join(os.tmpdir(), 'pramnos-spa-dbg-')), 'debug.mjs'
    );
    fs.writeFileSync(file, source);

    const dom = makeDom();
    global.document = dom.document;

    // Storage, because the hidden/shown choice has to survive navigation — a bar
    // that reappears on the next page reads as the button not working.
    const store = stored === null ? {} : { 'pramnos.debugbar.hidden': stored };
    const storage = {
        getItem: (k) => (k in store ? store[k] : null),
        setItem: (k, v) => { store[k] = String(v); },
        removeItem: (k) => { delete store[k]; },
    };
    Object.defineProperty(global, 'localStorage', {
        // A blocked origin or Safari's private mode makes *access itself* throw,
        // not just the call — so the failure is simulated on the property.
        get: () => { if (storageThrows) { throw new Error('access denied'); } return storage; },
        configurable: true,
    });
    dom.store = store;
    // `navigator` is a getter-only global in modern Node, so it is redefined
    // rather than assigned. The panel only reads `navigator.clipboard`.
    Object.defineProperty(global, 'navigator', {
        value: { clipboard: { writeText: () => Promise.resolve() } },
        configurable: true,
        writable: true,
    });

    // Cache-busted so each test gets its own module state; the panel keeps its
    // history in module scope, exactly as it does in a browser.
    const module = await import('file://' + file + '?t=' + Math.random());

    return { record: module.record, dom };
}

/** A payload of the shape the API attaches. */
function payload({ time = 12.5, memory = 2.5, queries = [] } = {}) {
    return {
        time,
        memory: { peak_bytes: 1, peak_human: '1 B' },   // the collector's shape
        request: { time, memory },
        queries: { count: queries.length, queries },
    };
}

// ─── Tests ──────────────────────────────────────────────────────────────────

describe('SPA debug panel', () => {
    test('it records nothing, and builds nothing, in production', async () => {
        // Arrange — production attaches no _debug to anything
        const { record, dom } = await loadPanel();

        // Act
        record('GET', '/api/status', 200, null);
        record('POST', '/api/save', 204, null);

        // Assert — the panel must never touch the DOM of an application that is
        // not in development
        assert.equal(dom.byId['spa-debugbar'], undefined, 'no panel was created');
        assert.equal(dom.document.body.children.length, 0, 'nothing was appended');
    });

    test('a payload brings the panel into existence', async () => {
        // Arrange
        const { record, dom } = await loadPanel();

        // Act
        record('GET', '/api/meters', 200, payload({ time: 74.92 }));

        // Assert
        assert.ok(dom.byId['spa-dbg-tabs'], 'the bar was built');
        assert.ok(
            dom.byId['spa-dbg-tabs'].innerHTML.includes('requests'),
            'with a requests tab'
        );
        assert.ok(
            dom.byId['spa-dbg-info'].innerHTML.includes('74.92ms'),
            'and the last timing on the bar'
        );
    });

    test('memory comes from the copy no collector can overwrite', async () => {
        // Arrange — `memory` at the top level is the MemoryCollector's object;
        // reading it as a number is what printed "[object Object]MB".
        const { record, dom } = await loadPanel();

        // Act
        record('GET', '/api/meters', 200, payload({ memory: 3.25 }));

        // Assert
        const bar = dom.byId['spa-dbg-info'].innerHTML;
        assert.ok(bar.includes('3.25MB'));
        assert.ok(!bar.includes('[object Object]'));
    });

    test('once active, a 204 with no payload is still recorded', async () => {
        // Arrange — a save carries no body to put a payload in, and it is
        // exactly the call somebody wants to see
        const { record, dom } = await loadPanel();
        record('GET', '/api/meters', 200, payload());

        // Act
        record('POST', '/api/save', 204, null, { ms: 15 });
        openPanel(dom);
        record('GET', '/api/again', 200, payload());   // triggers a redraw

        // Assert
        const html = dom.byId['spa-dbg-panel'].innerHTML;
        assert.ok(html.includes('/api/save'), 'the save is listed');
        assert.ok(html.includes('204'));
    });

    test('requests are listed newest first, with their times', async () => {
        // Arrange
        const { record, dom } = await loadPanel();

        // Act
        record('GET', '/first', 200, payload({ time: 1 }));
        openPanel(dom);
        record('GET', '/second', 200, payload({ time: 2 }), { ms: 40 });

        // Assert
        const html = dom.byId['spa-dbg-panel'].innerHTML;
        assert.ok(html.indexOf('/second') < html.indexOf('/first'), 'newest at the top');
        assert.ok(/\d{2}:\d{2}:\d{2}\.\d{3}/.test(html), 'each row carries a wall clock');
        assert.ok(html.includes('40ms'), 'the client time is shown');
        assert.ok(html.includes('2ms'), 'and the server time');
    });

    test('the queries tab lists every statement with a copy-all', async () => {
        // Arrange
        const { record, dom } = await loadPanel();
        record('GET', '/api/meters', 200, payload({ queries: [
            { sql: 'SELECT 1', time: 0.4 },
            { sql: 'UPDATE orders SET status = 1', time: 1.1 },
        ] }));

        // Act
        openPanel(dom, 'queries');
        record('GET', '/api/again', 200, payload());

        // Assert
        const html = dom.byId['spa-dbg-panel'].innerHTML;
        assert.ok(html.includes('SELECT 1'));
        assert.ok(html.includes('UPDATE orders'));
        assert.ok(html.includes('Copy all'), 'one paste for a bug report');
        assert.ok(html.includes('spa-dbg-copy'), 'and one per statement');
    });

    test('a cached statement is labelled, not timed as zero', async () => {
        // Arrange
        const { record, dom } = await loadPanel();
        record('GET', '/api/users', 200, payload({ queries: [
            { sql: 'SELECT * FROM users WHERE userid = 2', time: 0, from_cache: true },
            { sql: 'SELECT * FROM userdetails WHERE userid = 2', time: 2.08, from_cache: false },
        ] }));

        // Act
        openPanel(dom, 'queries');
        record('GET', '/api/again', 200, payload());

        // Assert
        const html = dom.byId['spa-dbg-panel'].innerHTML;
        assert.ok(html.includes('CACHE'), 'the cached statement is labelled');
        assert.ok(html.includes('2.08ms'), 'the one that ran keeps its time');
        assert.ok(html.includes('from cache'), 'and the split is summarised');
    });

    test('a body is masked before it can be screenshotted', async () => {
        // Arrange
        const { record, dom } = await loadPanel();
        record('POST', '/api/login', 200, payload(), {
            ms: 30,
            body: JSON.stringify({ username: 'bob', password: 'hunter2' }),
        });

        // Act — open the row so the body is rendered
        openPanel(dom);
        const click = dom.byId['spa-debugbar'].listeners.click;
        click({ target: { closest: (s) => (s === '.spa-dbg-row' ? { dataset: { index: '0' } } : null) } });

        // Assert
        const html = dom.byId['spa-dbg-panel'].innerHTML;
        assert.ok(!html.includes('hunter2'), 'the password never reaches the screen');
        assert.ok(html.includes('bob'), 'the rest of the body does');
        assert.ok(html.includes('***'));
    });

    test('rendering survives a payload with nothing in it', async () => {
        // Arrange — a collector that returned little, or an older server
        const { record, dom } = await loadPanel();

        // Act & Assert — reaching the end without throwing is the assertion
        assert.doesNotThrow(() => {
            record('GET', '/api/thin', 200, { time: 1 });
            openPanel(dom);
            record('GET', '/api/thinner', 500, {});
        });

        assert.ok(dom.byId['spa-dbg-panel'].innerHTML.length > 0);
    });

    /**
     * The ✕ hides the bar itself — the bug it replaced hid nothing.
     *
     * It used to toggle the panel's inline `display`, starting from `''`: the
     * first click set `none` on something the stylesheet already hid, and the
     * second handed it back to the stylesheet, which hid it again. Two clicks,
     * no visible effect. So the assertion is on the *bar's* display, plus the
     * page padding, which is the other half of "hidden" the user can see.
     */
    test('the close button hides the whole bar and frees the page', async () => {
        // Arrange
        const { record, dom } = await loadPanel();
        record('GET', '/api/status', 200, payload());
        assert.equal(dom.byId['spa-debugbar'].style.display, '', 'visible to begin with');
        assert.equal(dom.document.body.style.paddingBottom, '32px');

        // Act
        clickClose(dom);

        // Assert
        assert.equal(dom.byId['spa-debugbar'].style.display, 'none', 'the bar goes, not the panel');
        assert.equal(dom.document.body.style.paddingBottom, '', 'the reserved strip goes with it');
        // Without a handle the button is a one-way door: the panel is only built
        // when debug data arrives, so nothing else can bring it back.
        assert.equal(dom.byId['spa-dbg-restore'].style.display, 'block');
        assert.equal(dom.store['pramnos.debugbar.hidden'], '1', 'and the choice is remembered');
    });

    test('the restore handle brings it back and forgets the choice', async () => {
        // Arrange — hidden, with the handle showing
        const { record, dom } = await loadPanel();
        record('GET', '/api/status', 200, payload());
        clickClose(dom);

        // Act — the handle has its own listener, not the delegated one
        dom.byId['spa-dbg-restore'].listeners.click();

        // Assert
        assert.equal(dom.byId['spa-debugbar'].style.display, '');
        assert.equal(dom.byId['spa-dbg-restore'].style.display, 'none');
        assert.equal(dom.document.body.style.paddingBottom, '32px');
        assert.equal('pramnos.debugbar.hidden' in dom.store, false, 'nothing left to re-hide it');
    });

    /**
     * A SPA navigates without reloading, but a full reload still happens — and
     * the server-rendered pages of the same application reload every time. A bar
     * that came back on its own would read as the hide button not working, which
     * is the complaint this whole change came from.
     */
    test('a bar hidden earlier is still hidden when the panel is rebuilt', async () => {
        // Arrange — the choice was made on a previous page
        const { record, dom } = await loadPanel({ stored: '1' });

        // Act — data arrives, so the panel is built
        record('GET', '/api/status', 200, payload());

        // Assert
        assert.equal(dom.byId['spa-debugbar'].style.display, 'none');
        assert.equal(dom.byId['spa-dbg-restore'].style.display, 'block');
        assert.equal(dom.document.body.style.paddingBottom, '', 'no padding for a bar nobody sees');
    });

    /**
     * Storage that throws must not take the panel with it.
     *
     * Reading `localStorage` at all throws in Safari's private mode and on a
     * blocked origin. Instrumentation is never a good reason for a page to break,
     * so the bar still hides — it just cannot remember that it did.
     */
    test('storage that throws costs the memory, not the button', async () => {
        // Arrange
        const { record, dom } = await loadPanel({ storageThrows: true });

        // Act & Assert
        assert.doesNotThrow(() => {
            record('GET', '/api/status', 200, payload());
            clickClose(dom);
        });
        assert.equal(dom.byId['spa-debugbar'].style.display, 'none', 'hiding still works');
    });

    /** Click the ✕ the way the delegated listener sees it. */
    function clickClose(dom) {
        dom.byId['spa-debugbar'].listeners.click({
            target: {
                closest: (selector) => (selector === '#spa-dbg-close' ? { id: 'spa-dbg-close' } : null),
            },
        });
    }

    /**
     * Open the panel the way a click on a tab would.
     */
    function openPanel(dom, tab = 'requests') {
        const click = dom.byId['spa-debugbar'].listeners.click;
        click({
            target: {
                closest: (selector) => (selector === '.spa-dbg-tab'
                    ? { dataset: { tab } }
                    : null),
            },
        });
    }
});
