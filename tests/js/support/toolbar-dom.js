/**
 * A DOM small enough to run the debug toolbar against, and the loader for it.
 *
 * The toolbar has two deliveries and one source. `spa-debug-panel.test.js`
 * drives the ES-module delivery; the two tests that use this helper drive the
 * other one — the classic script `DebugBar::render()` inlines into a
 * server-rendered page, next to a `<div id="pramnos-debug-data">` holding that
 * request's collector data.
 *
 * What is different about that delivery, and therefore what is worth testing
 * here: the script boots itself from the data island, and it wraps `fetch` and
 * `XMLHttpRequest` so the requests a page makes *after* it renders are recorded
 * too. A SPA does neither — its API client calls `record()` itself.
 *
 * The script is fetched from PHP rather than read off disk, so what runs here is
 * what ships.
 */
'use strict';

const vm               = require('node:vm');
const path             = require('node:path');
const { execFileSync } = require('node:child_process');

const ROOT = path.join(__dirname, '..', '..', '..');

/**
 * The toolbar source, exactly as a server-rendered page receives it.
 *
 * @returns {string} A classic script — no ESM export, or it would not parse
 *                   inside a `<script>` tag.
 */
function loadToolbarScript() {
    const php = 'require "vendor/autoload.php";'
        + ' echo Pramnos\\Debug\\DebugBarAsset::source();';

    return execFileSync('php', ['-r', php], { cwd: ROOT, encoding: 'utf8' });
}

/**
 * A document with the handful of behaviours the toolbar uses.
 *
 * Elements register themselves by id — both when `id` is assigned and when an
 * id appears in assigned `innerHTML` — because that is how the toolbar finds
 * the parts of itself it redraws.
 *
 * @param {object}  options
 * @param {?string} options.island     JSON for the data island, or null for none
 * @param {?string} options.nonce      CSP nonce on the script element, or null
 * @param {string}  options.readyState Document readiness at load time
 */
function makeDom({ island = null, nonce = null, readyState = 'complete' } = {}) {
    const byId = {};
    const pending = {};

    function makeElement(tag) {
        const attributes = {};
        const el = {
            tagName: tag,
            children: [],
            style: {},
            dataset: {},
            attributes,
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
                // Register the ids the markup declares, as a browser would once
                // the markup is in the document.
                const re = /id="([^"]+)"/g;
                let m;
                while ((m = re.exec(String(value))) !== null) {
                    byId[m[1]] = byId[m[1]] || makeElement('div');
                }
            },
            get innerHTML() { return this._html; },
            setAttribute(name, value) { attributes[name] = String(value); },
            getAttribute(name) { return name in attributes ? attributes[name] : null; },
            append(...nodes) { el.children.push(...nodes); },
            appendChild(node) { el.children.push(node); return node; },
            removeChild(node) {
                const i = el.children.indexOf(node);
                if (i > -1) { el.children.splice(i, 1); }
                return node;
            },
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

    // The data island, if this page has one. A SPA shell has not: its markup
    // never went through the middleware that emits it.
    if (island !== null) {
        const el = makeElement('div');
        el.id = 'pramnos-debug-data';
        el.textContent = island;
    }

    // The script element the toolbar reads its nonce from. A page without a
    // strict CSP has no nonce, which is the ordinary development case.
    const currentScript = makeElement('script');
    if (nonce !== null) {
        currentScript.setAttribute('nonce', nonce);
    }

    return {
        byId,
        pending,
        document: {
            createElement: makeElement,
            body,
            head,
            currentScript,
            readyState,
            listeners: {},
            addEventListener(name, fn) { this.listeners[name] = fn; },
            getElementById: (id) => byId[id] || null,
            execCommand: () => true,
        },
    };
}

/**
 * A `Response` with only the parts the toolbar touches.
 *
 * `clone()` matters: the toolbar must never consume the body the application is
 * about to read, so it only ever reads a clone. Handing back a separate object
 * is what lets a test assert the original is still intact.
 *
 * @param {object} options
 * @param {number} options.status
 * @param {string} options.body
 * @param {object} options.headers Header name → value, matched case-insensitively
 */
function makeResponse({ status = 200, body = '', headers = {} } = {}) {
    const lower = {};
    Object.keys(headers).forEach((k) => { lower[k.toLowerCase()] = headers[k]; });

    let consumed = false;

    return {
        status,
        get consumed() { return consumed; },
        headers: { get: (name) => (lower[String(name).toLowerCase()] ?? null) },
        clone: () => ({ text: () => Promise.resolve(body) }),
        text() { consumed = true; return Promise.resolve(body); },
    };
}

/**
 * A minimal XMLHttpRequest whose prototype the toolbar can wrap.
 *
 * `respond()` is the test's half: it plays the response back through the `load`
 * listeners the wrapper registered, which is the only way the toolbar hears
 * about an XHR at all.
 *
 * @returns {Function} A constructor, fresh per test so wrapping is not shared
 */
function makeXhrClass() {
    function FakeXhr() {
        this.listeners = {};
        this.status = 0;
        this.responseText = '';
    }

    FakeXhr.prototype.open = function (method, url) {
        this.openedWith = { method, url };
    };

    FakeXhr.prototype.send = function (body) {
        this.sentBody = body === undefined ? null : body;
    };

    FakeXhr.prototype.addEventListener = function (name, fn) {
        (this.listeners[name] = this.listeners[name] || []).push(fn);
    };

    /** Play a response back to whoever is listening. */
    FakeXhr.prototype.respond = function (status, text) {
        this.status = status;
        this.responseText = text;
        (this.listeners.load || []).forEach((fn) => fn());
    };

    return FakeXhr;
}

/**
 * Load the toolbar as a server-rendered page would, and hand back its world.
 *
 * The script is evaluated in its own VM context so each test gets its own
 * toolbar state — history, selection and the wrapped transports all live in
 * module scope, exactly as they do in a browser tab.
 *
 * @param {object}  options
 * @param {?object} options.payload       Data island contents, or null for none
 * @param {?string} options.nonce         CSP nonce on the script element
 * @param {?string} options.stored        Value already in localStorage
 * @param {boolean} options.storageThrows Make storage access itself throw
 * @param {?Function} options.fetch       The page's own fetch, or null for none
 */
function loadToolbar({
    payload = null,
    nonce = null,
    stored = null,
    storageThrows = false,
    fetch = null,
} = {}) {
    const dom = makeDom({
        island: payload === null ? null : JSON.stringify(payload),
        nonce,
    });

    const store = stored === null ? {} : { 'pramnos.debugbar.hidden': stored };
    const storage = {
        getItem: (k) => (k in store ? store[k] : null),
        setItem: (k, v) => { store[k] = String(v); },
        removeItem: (k) => { delete store[k]; },
    };

    const Xhr = makeXhrClass();

    const sandbox = {
        document: dom.document,
        navigator: { clipboard: { writeText: () => Promise.resolve() } },
        performance: { now: () => Date.now() },
        setTimeout,
        clearTimeout,
        console,
        XMLHttpRequest: Xhr,
    };

    Object.defineProperty(sandbox, 'localStorage', {
        // A blocked origin or Safari's private mode makes *access itself* throw.
        get: () => {
            if (storageThrows) { throw new Error('access denied'); }
            return storage;
        },
        configurable: true,
    });

    if (fetch) {
        sandbox.fetch = fetch;
    }

    // `window` is the global in a browser, and the toolbar assigns to it —
    // `window.fetch = wrapped` has to be the same binding the page then calls.
    sandbox.window = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(loadToolbarScript(), sandbox);

    return { dom, sandbox, store, Xhr, byId: dom.byId };
}

/** Click something the delegated listener will recognise by selector. */
function clickInBar(dom, selector, extra = {}) {
    dom.byId['pramnos-debugbar'].listeners.click({
        target: {
            closest: (want) => (want === selector ? Object.assign({ id: selector.slice(1) }, extra) : null),
        },
        stopPropagation() {},
    });
}

/** Let queued promise callbacks run — the toolbar records a fetch in a `.then`. */
function settle() {
    return new Promise((resolve) => setImmediate(resolve));
}

module.exports = {
    ROOT,
    loadToolbarScript,
    loadToolbar,
    makeDom,
    makeResponse,
    makeXhrClass,
    clickInBar,
    settle,
};
