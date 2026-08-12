/**
 * The debug toolbar's ajax panel — the JavaScript half.
 *
 * This exists because of a real failure: a column was added to the table
 * markup and the field that fills it was not, so every row threw while being
 * rendered. The whole panel is wrapped in try/catch — instrumentation must
 * never break the page it measures — which meant the panel simply went blank
 * with nothing anywhere saying why.
 *
 * PHP tests cannot catch that: they assert the script is emitted, not that it
 * runs. So the script is extracted from DebugBar::ajaxJs() and executed here,
 * against stub globals, with fetch and XMLHttpRequest actually driven.
 *
 * Run:
 *   node --test tests/js/debugbar-ajax.test.js
 *
 * Zero npm dependencies: node:test, node:assert, node:vm, node:child_process.
 */
'use strict';

const { test, describe }   = require('node:test');
const assert               = require('node:assert/strict');
const vm                   = require('node:vm');
const path                 = require('node:path');
const { execFileSync }     = require('node:child_process');

const ROOT = path.join(__dirname, '..', '..');

// ─── Extract the script from the PHP that emits it ──────────────────────────

/**
 * Ask PHP for the panel's JavaScript, exactly as a page would receive it.
 *
 * Reading it out of the PHP source with a regex would test a copy; this tests
 * what ships.
 */
function loadPanelScript() {
    const php = `
        require "vendor/autoload.php";
        // No setAccessible(): it has had no effect since PHP 8.1 and is
        // deprecated in 8.5, and the CLI writes that deprecation to STDOUT —
        // where it lands in front of the script and is parsed as JavaScript.
        $m = new ReflectionMethod("Pramnos\\\\Debug\\\\DebugBar", "ajaxJs");
        echo $m->invoke(Pramnos\\Debug\\DebugBar::getInstance());
    `;

    return execFileSync('php', ['-r', php], { cwd: ROOT, encoding: 'utf8' });
}

// ─── A DOM small enough to run the panel against ────────────────────────────

/**
 * The handful of DOM behaviours the panel uses: getElementById, innerHTML,
 * createElement().textContent for escaping, and a document click listener.
 */
function makeDom() {
    const elements = {};
    const listeners = {};

    const makeElement = (id) => ({
        id,
        innerHTML: '',
        textContent: '',
        style: {},
        setAttribute() {},
        getAttribute() { return null; },
        addEventListener() {},
        closest() { return null; },
    });

    for (const id of ['pdb-ajax-rows', 'pdb-ajax-table', 'pdb-ajax-empty', 'pdb-ajax-tab']) {
        elements[id] = makeElement(id);
    }

    return {
        elements,
        listeners,
        document: {
            getElementById: (id) => elements[id] || null,
            createElement: (tag) => {
                const el = makeElement(tag);
                Object.defineProperty(el, 'textContent', {
                    set(value) { el.innerHTML = String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); },
                    get() { return el.innerHTML; },
                });
                return el;
            },
            addEventListener: (name, fn) => { listeners[name] = fn; },
            querySelectorAll: () => [],
        },
    };
}

/**
 * Reach the panel's private helpers.
 *
 * They live inside an IIFE, which is right for a script injected into somebody
 * else's page and inconvenient for a test. Appending one line inside that scope
 * exposes them without changing what ships.
 */
function withHelpersExposed(script) {
    return script.replace(
        '})();',
        'globalThis.__formatBody=formatBody;globalThis.__maskSecrets=maskSecrets;'
        + 'globalThis.__sizeOf=sizeOf;globalThis.__captureBody=captureBody;})();'
    );
}

/**
 * Run the panel script in a sandbox and return the sandbox.
 *
 * @param {object} extras Globals to add (fetch, XMLHttpRequest, ...)
 */
function runPanel(script, extras = {}) {
    const dom = makeDom();
    const sandbox = {
        document: dom.document,
        window: {},
        location: { origin: 'http://localhost:8082' },
        performance: { now: () => 0 },
        console,
        setTimeout,
        Date,
        Math,
        JSON,
        String,
        Number,
        parseFloat,
        RegExp,
        ...extras,
    };
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;

    vm.createContext(sandbox);
    vm.runInContext(script, sandbox);

    return { sandbox, dom };
}

/** Wait for pending promise callbacks to run. */
const settle = () => new Promise((resolve) => setImmediate(resolve));

// ─── Tests ──────────────────────────────────────────────────────────────────

describe('debug toolbar ajax panel', () => {
    const script = loadPanelScript();

    test('the emitted script is syntactically valid', () => {
        // A parse error would take the whole inline <script> with it, including
        // anything the toolbar emitted after it.
        assert.doesNotThrow(() => new vm.Script(script));
    });

    test('it wraps fetch and leaves the response untouched', async () => {
        // Arrange
        const original = { status: 200, headers: { get: () => null }, marker: 'the real response' };
        let calledWith = null;
        const nativeFetch = (...args) => { calledWith = args; return Promise.resolve(original); };

        const { sandbox } = runPanel(script, { fetch: nativeFetch, XMLHttpRequest: null });

        // Act
        const returned = await sandbox.fetch('/users/data', { method: 'POST' });

        // Assert — the caller must get back exactly what the server sent
        assert.equal(returned, original, 'the original response object is returned');
        assert.equal(calledWith[0], '/users/data', 'the original arguments are passed through');
        assert.equal(calledWith[1].method, 'POST');
    });

    test('a completed request is rendered as a row with a timestamp', async () => {
        // Arrange
        const response = {
            status: 200,
            headers: {
                get: (name) => (name.toLowerCase() === 'content-type' ? 'application/json' : null),
            },
            clone: () => ({ text: () => Promise.resolve('{"data":[],"_debug":{"request":{"time":74.92,"memory":2.5}}}') }),
        };
        const { sandbox, dom } = runPanel(script, {
            fetch: () => Promise.resolve(response),
            XMLHttpRequest: null,
        });

        // Act
        await sandbox.fetch('/users/data', { method: 'POST' });
        await settle();
        await settle();

        // Assert — this is the regression: a missing field made every row throw,
        // and the panel rendered nothing at all.
        const html = dom.elements['pdb-ajax-rows'].innerHTML;
        assert.ok(html.length > 0, 'the row was rendered');
        assert.ok(html.includes('POST'), 'the method is shown');
        assert.ok(html.includes('/users/data'), 'the url is shown');
        assert.ok(/\d{2}:\d{2}:\d{2}\.\d{3}/.test(html), 'the time of the request is shown');
        assert.ok(!html.includes('row 0:'), 'no row reported an error: ' + html);
    });

    test('the server time comes from the payload, not the client clock', async () => {
        // Arrange
        const response = {
            status: 200,
            headers: { get: (n) => (n.toLowerCase() === 'content-type' ? 'application/json' : null) },
            clone: () => ({ text: () => Promise.resolve('{"_debug":{"time":74.92,"request":{"time":74.92,"memory":2.5}}}') }),
        };
        const { sandbox, dom } = runPanel(script, {
            fetch: () => Promise.resolve(response),
            XMLHttpRequest: null,
        });

        // Act
        await sandbox.fetch('/users/data');
        await settle();
        await settle();

        // Assert
        assert.ok(dom.elements['pdb-ajax-rows'].innerHTML.includes('74.92ms'));
    });

    test('memory is never rendered as [object Object]', async () => {
        // Arrange — the payload shape that caused it: a collector named `memory`
        // overwrites the scalar with its own object.
        const body = JSON.stringify({
            _debug: {
                time: 10,
                memory: { peak_bytes: 123, peak_human: '2 MB' },
                request: { time: 10, memory: 2.5 },
            },
        });
        const response = {
            status: 200,
            headers: { get: (n) => (n.toLowerCase() === 'content-type' ? 'application/json' : null) },
            clone: () => ({ text: () => Promise.resolve(body) }),
        };
        const { sandbox, dom } = runPanel(script, {
            fetch: () => Promise.resolve(response),
            XMLHttpRequest: null,
        });

        // Act — open the row so the detail, which prints memory, is rendered
        await sandbox.fetch('/users/data');
        await settle();
        await settle();

        // Assert
        const html = dom.elements['pdb-ajax-rows'].innerHTML;
        assert.ok(!html.includes('[object Object]'), 'no object stringified into the output');
    });

    test('a response with no debug data still produces a row', async () => {
        // Arrange — a plain 204, the shape of a save
        const response = { status: 204, headers: { get: () => null } };
        const { sandbox, dom } = runPanel(script, {
            fetch: () => Promise.resolve(response),
            XMLHttpRequest: null,
        });

        // Act
        await sandbox.fetch('/orders/save', { method: 'POST' });
        await settle();

        // Assert — the request happened, so it must be visible even with nothing
        // attached to it
        const html = dom.elements['pdb-ajax-rows'].innerHTML;
        assert.ok(html.includes('204'), 'the status is shown');
        assert.ok(!html.includes('row 0:'), 'and it did not fail to render');
    });

    test('a rejected fetch is recorded and the rejection still propagates', async () => {
        // Arrange
        const failure = new Error('network down');
        const { sandbox, dom } = runPanel(script, {
            fetch: () => Promise.reject(failure),
            XMLHttpRequest: null,
        });

        // Act
        let caught = null;
        try {
            await sandbox.fetch('/users/data');
        } catch (error) {
            caught = error;
        }
        await settle();

        // Assert — the caller's error handling must not be swallowed by the panel
        assert.equal(caught, failure, 'the rejection reached the caller');
        assert.ok(dom.elements['pdb-ajax-rows'].innerHTML.length > 0, 'and the attempt was recorded');
    });

    test('XMLHttpRequest is wrapped and its send() still runs', async () => {
        // Arrange — a minimal XHR whose send() fires loadend synchronously
        class FakeXhr {
            constructor() { this.status = 200; this.responseType = ''; this.responseText = '{}'; this._handlers = []; this.sent = false; }
            open() {}
            send() { this.sent = true; this._handlers.forEach((fn) => fn()); }
            addEventListener(name, fn) { if (name === 'loadend') this._handlers.push(fn); }
            getResponseHeader() { return null; }
        }
        FakeXhr.prototype.open = FakeXhr.prototype.open;

        const { sandbox, dom } = runPanel(script, { fetch: null, XMLHttpRequest: FakeXhr });

        // Act
        const xhr = new sandbox.XMLHttpRequest();
        xhr.open('GET', '/api/meters');
        xhr.send();
        await settle();

        // Assert
        assert.ok(xhr.sent, 'the original send() ran');
        const html = dom.elements['pdb-ajax-rows'].innerHTML;
        assert.ok(html.includes('/api/meters'), 'the request was recorded');
    });

    test('the request body is captured and shown', async () => {
        // Arrange — a datatables POST, opened so the detail renders
        const body = JSON.stringify({ draw: 1, start: 0, length: 50 });
        const response = { status: 200, headers: { get: () => null } };
        const { sandbox, dom } = runPanel(script, {
            fetch: () => Promise.resolve(response),
            XMLHttpRequest: null,
            URLSearchParams: global.URLSearchParams,
        });

        // Act
        await sandbox.fetch('/users/data', { method: 'POST', body });
        await settle();
        dom.document.addEventListener; // listener registered by the panel
        // Open row 0 the way a click would
        sandbox.__openRow ? sandbox.__openRow(0) : null;
        await settle();

        // Assert — the body is in the captured entry even before the row opens
        const html = dom.elements['pdb-ajax-rows'].innerHTML;
        assert.ok(html.includes('/users/data'));
        assert.ok(!html.includes('row 0:'), 'the row rendered cleanly');
    });

    test('secrets in a body are masked before display', () => {
        // Arrange
        const { sandbox } = runPanel(withHelpersExposed(script), {
            fetch: null, XMLHttpRequest: null,
        });

        // Act
        const json  = sandbox.__maskSecrets('{"username":"bob","password":"hunter2"}');
        const query = sandbox.__maskSecrets('user=bob&api_key=abc123&page=2');

        // Assert — the panel gets screenshotted and pasted into bug reports
        assert.ok(!json.includes('hunter2'), 'the password is gone');
        assert.ok(json.includes('bob'), 'and the rest survives');
        assert.ok(!query.includes('abc123'), 'the key is gone from the query string');
        assert.ok(query.includes('page=2'), 'and the rest of it survives');
    });

    test('a form-urlencoded body is decoded into its structure', () => {
        // Arrange — a real datatables body, which is what made this necessary:
        // as raw text it is two kilobytes of double-escaped column metadata.
        const body = 'draw=1&columns%5B0%5D%5Bdata%5D=0&columns%5B0%5D%5Bsearchable%5D=true'
            + '&order%5B0%5D%5Bdir%5D=desc&start=0&length=50&search%5Bvalue%5D=';
        const { sandbox } = runPanel(withHelpersExposed(script), {
            fetch: null, XMLHttpRequest: null,
        });

        // Act
        const formatted = sandbox.__formatBody(body);
        const parsed    = JSON.parse(formatted);

        // Assert
        assert.equal(parsed.draw, '1');
        assert.equal(parsed.columns['0'].data, '0', 'the nesting is rebuilt');
        assert.equal(parsed.columns['0'].searchable, 'true');
        assert.equal(parsed.order['0'].dir, 'desc');
        assert.equal(parsed.length, '50');
        assert.ok(!formatted.includes('%5B'), 'nothing is left percent-encoded');
    });

    test('a JSON body is pretty-printed and anything else is left alone', () => {
        // Arrange
        const { sandbox } = runPanel(withHelpersExposed(script), {
            fetch: null, XMLHttpRequest: null,
        });

        // Act
        const json  = sandbox.__formatBody('{"a":1,"b":{"c":2}}');
        const plain = sandbox.__formatBody('just some text');

        // Assert
        assert.ok(json.includes('\n'), 'the JSON was indented');
        assert.equal(plain, 'just some text', 'a plain body is untouched');
    });

    test('the body is described rather than decoded when it is binary', () => {
        // Arrange — reading a Blob is asynchronous, and the object belongs to
        // the application; describing it is the honest answer.
        const { sandbox } = runPanel(withHelpersExposed(script), {
            fetch: null, XMLHttpRequest: null, Blob: class FakeBlob { constructor() { this.size = 42; } },
        });

        // Act
        const described = sandbox.__captureBody(new sandbox.Blob());
        const nothing   = sandbox.__captureBody(null);

        // Assert
        assert.ok(described.includes('42 bytes'));
        assert.equal(nothing, null);
    });

    test('the body starts collapsed', async () => {
        // Arrange — a big body, the case the collapse exists for
        const body = 'a=1&' + 'x%5B0%5D%5By%5D=1&'.repeat(200);
        const response = { status: 200, headers: { get: () => null } };
        const { sandbox, dom } = runPanel(script, {
            fetch: () => Promise.resolve(response),
            XMLHttpRequest: null,
        });

        // Act
        await sandbox.fetch('/users/data', { method: 'POST', body });
        await settle();
        dom.listeners.click({
            target: {
                closest: (s) => (s === '.pdb-ajax-row' ? { getAttribute: () => '0' } : null),
            },
        });

        // Assert — <details> without `open` is collapsed, and the size is on the
        // summary so it can be judged before opening
        const html = dom.elements['pdb-ajax-rows'].innerHTML;
        assert.ok(html.includes('<details>'), 'the body is behind a disclosure');
        assert.ok(!html.includes('<details open'), 'and it starts closed');
        assert.ok(/Request body · [\d.]+ (B|KB)/.test(html), 'with its size shown: ' + html.slice(0, 400));
    });

    test('a copy button is rendered for every query', async () => {
        // Arrange — a response carrying two queries
        const payload = JSON.stringify({
            _debug: {
                time: 5,
                request: { time: 5, memory: 1 },
                queries: { count: 2, queries: [
                    { sql: "SELECT * FROM users WHERE name = 'o''brien'", time: 0.4 },
                    { sql: 'UPDATE orders SET status = 1', time: 1.1 },
                ] },
            },
        });
        const response = {
            status: 200,
            headers: { get: (n) => (n.toLowerCase() === 'content-type' ? 'application/json' : null) },
            clone: () => ({ text: () => Promise.resolve(payload) }),
        };
        const { sandbox, dom } = runPanel(script, {
            fetch: () => Promise.resolve(response),
            XMLHttpRequest: null,
        });

        // Act — capture, then open the row through the panel's own click handler
        await sandbox.fetch('/users/data');
        await settle();
        await settle();

        const clickHandler = dom.listeners.click;
        assert.ok(clickHandler, 'the panel registered a click handler');
        clickHandler({
            target: {
                closest: (selector) => (selector === '.pdb-ajax-row'
                    ? { getAttribute: () => '0' }
                    : null),
            },
        });

        // Assert
        const html = dom.elements['pdb-ajax-rows'].innerHTML;
        assert.ok(html.includes('pdb-copy'), 'a copy button was rendered');
        assert.ok(html.includes('data-sql='), 'carrying the statement to copy');
        assert.ok(html.includes('UPDATE orders'), 'the second query is there too');
        // The delegated handler in the toolbar's other script reads data-sql, so
        // a quote that escaped the attribute would break the copy silently.
        assert.ok(!/data-sql='[^']*'[^>]*'/.test(html), 'no unescaped quote broke the attribute');
    });

    test('one button copies every statement, annotated and in order', async () => {
        // Arrange
        const payload = JSON.stringify({
            _debug: {
                time: 5, request: { time: 5, memory: 1 },
                queries: { count: 2, queries: [
                    { sql: 'SELECT 1', time: 0.4 },
                    { sql: 'UPDATE orders SET status = 1', time: 1.1 },
                ] },
            },
        });
        const response = {
            status: 200,
            headers: { get: (n) => (n.toLowerCase() === 'content-type' ? 'application/json' : null) },
            clone: () => ({ text: () => Promise.resolve(payload) }),
        };
        const { sandbox, dom } = runPanel(script, {
            fetch: () => Promise.resolve(response),
            XMLHttpRequest: null,
        });

        // Act
        await sandbox.fetch('/users/data');
        await settle();
        await settle();
        dom.listeners.click({
            target: { closest: (s) => (s === '.pdb-ajax-row' ? { getAttribute: () => '0' } : null) },
        });

        // Assert — the payload of the "copy all" button carries both statements
        // with their timings, which is the form somebody pastes into a report
        const html = dom.elements['pdb-ajax-rows'].innerHTML;
        assert.ok(html.includes('pdb-copy-all'), 'the button is rendered');
        assert.ok(html.includes('Copy all'));

        const match = /class='pdb-copy pdb-copy-all'[^>]*data-sql='([^']*)'/.exec(html);
        assert.ok(match, 'the button carries a payload: ' + html.slice(0, 300));
        assert.ok(match[1].includes('SELECT 1'), 'the first statement');
        assert.ok(match[1].includes('UPDATE orders'), 'and the second');
        assert.ok(match[1].includes('0.4ms'), 'with its timing');
        assert.ok(
            match[1].indexOf('SELECT 1') < match[1].indexOf('UPDATE orders'),
            'in the order they ran'
        );
    });

    test('a cached statement is labelled, not timed as zero', async () => {
        // Arrange — one statement that ran, one served from cache
        const payload = JSON.stringify({
            _debug: {
                time: 5, request: { time: 5, memory: 1 },
                queries: { count: 2, cached: 1, queries: [
                    { sql: 'SELECT * FROM users WHERE userid = 2', time: 0, from_cache: true },
                    { sql: 'SELECT * FROM userdetails WHERE userid = 2', time: 2.08, from_cache: false },
                ] },
            },
        });
        const response = {
            status: 200,
            headers: { get: (n) => (n.toLowerCase() === 'content-type' ? 'application/json' : null) },
            clone: () => ({ text: () => Promise.resolve(payload) }),
        };
        const { sandbox, dom } = runPanel(script, {
            fetch: () => Promise.resolve(response),
            XMLHttpRequest: null,
        });

        // Act
        await sandbox.fetch('/users/data');
        await settle();
        await settle();
        dom.listeners.click({
            target: { closest: (s) => (s === '.pdb-ajax-row' ? { getAttribute: () => '0' } : null) },
        });

        // Assert — "0ms" reads as instant; the statement did not run at all
        const html = dom.elements['pdb-ajax-rows'].innerHTML;
        assert.ok(html.includes('CACHE'), 'the cached statement is labelled');
        assert.ok(html.includes('2.08ms'), 'the one that ran keeps its time');
        assert.ok(html.includes('1 live · 1 from cache'), 'and the split is summarised');

        // ...and the copy payload says so too, since that is what gets pasted
        const match = /class='pdb-copy pdb-copy-all'[^>]*data-sql='([^']*)'/.exec(html);
        assert.ok(match, 'the copy-all button is there');
        assert.ok(match[1].includes('-- CACHE'), 'the paste distinguishes them');
        assert.ok(match[1].includes('-- 2.08ms'));
    });

    test('the newest request is at the top', async () => {
        // Arrange
        const response = { status: 200, headers: { get: () => null } };
        const { sandbox, dom } = runPanel(script, {
            fetch: () => Promise.resolve(response),
            XMLHttpRequest: null,
        });

        // Act — three requests, in order
        await sandbox.fetch('/first');
        await sandbox.fetch('/second');
        await sandbox.fetch('/third');
        await settle();

        // Assert — the one you just triggered is the one you are looking for,
        // and at the bottom of a growing list it scrolls away
        const html = dom.elements['pdb-ajax-rows'].innerHTML;
        assert.ok(
            html.indexOf('/third') < html.indexOf('/second'),
            'the newest row is rendered first'
        );
        assert.ok(html.indexOf('/second') < html.indexOf('/first'));
    });

    test('opening a row still opens the row that was clicked', async () => {
        // Arrange — reversing the display must not reverse the identity: the
        // click handler works against capture order, not draw order.
        const withQueries = (n) => ({
            status: 200,
            headers: { get: (h) => (h.toLowerCase() === 'content-type' ? 'application/json' : null) },
            clone: () => ({ text: () => Promise.resolve(JSON.stringify({
                _debug: { time: n, request: { time: n, memory: 1 },
                          queries: { count: 1, queries: [{ sql: 'SELECT ' + n, time: 0.1 }] } },
            })) }),
        });
        let call = 0;
        const { sandbox, dom } = runPanel(script, {
            fetch: () => Promise.resolve(withQueries(++call)),
            XMLHttpRequest: null,
        });

        await sandbox.fetch('/first');
        await settle(); await settle();
        await sandbox.fetch('/second');
        await settle(); await settle();

        // Act — open index 0, which is /first and is drawn last
        dom.listeners.click({
            target: { closest: (s) => (s === '.pdb-ajax-row' ? { getAttribute: () => '0' } : null) },
        });

        // Assert
        const html = dom.elements['pdb-ajax-rows'].innerHTML;
        assert.ok(html.includes('SELECT 1'), 'the first request opened');
        assert.ok(!html.includes('SELECT 2'), 'and not the second');
    });

    test('the tab shows how many requests were captured', async () => {
        // Arrange
        const response = { status: 200, headers: { get: () => null } };
        const { sandbox, dom } = runPanel(script, {
            fetch: () => Promise.resolve(response),
            XMLHttpRequest: null,
        });

        // Act
        await sandbox.fetch('/a');
        await sandbox.fetch('/b');
        await settle();

        // Assert
        assert.ok(dom.elements['pdb-ajax-tab'].innerHTML.includes('2'), 'the count is on the tab');
    });
});
