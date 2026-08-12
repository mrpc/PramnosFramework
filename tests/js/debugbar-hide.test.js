/**
 * The debug toolbar's hide button — the JavaScript half.
 *
 * This exists because of a real failure, reported from two applications at once:
 * the `✕` did nothing at all. It toggled the *panel's* inline `display` starting
 * from `''`, while the stylesheet already hid the panel — so the first click set
 * `display:none` on something invisible, and the second handed it back to the
 * stylesheet, which hid it again. Two clicks, no effect, in both the
 * server-rendered toolbar and the SPA panel.
 *
 * A PHP test cannot catch that: it can assert the button is emitted, not that
 * clicking it does anything. So the script is extracted from `DebugBar::js()`
 * and executed here, with the buttons actually clicked.
 *
 * Run:
 *   node --test tests/js/debugbar-hide.test.js
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

/**
 * Ask PHP for the toolbar's JavaScript, exactly as a page would receive it.
 *
 * Reading it out of the PHP source with a regex would test a copy; this tests
 * what ships — including the ajax half, which `js()` appends.
 */
function loadToolbarScript() {
    const php = `
        require "vendor/autoload.php";
        // No setAccessible(): it has had no effect since PHP 8.1 and is
        // deprecated in 8.5, and the CLI writes that deprecation to STDOUT —
        // where it lands in front of the script and is parsed as JavaScript.
        $m = new ReflectionMethod("Pramnos\\\\Debug\\\\DebugBar", "js");
        echo $m->invoke(Pramnos\\Debug\\DebugBar::getInstance());
    `;

    return execFileSync('php', ['-r', php], { cwd: ROOT, encoding: 'utf8' });
}

/**
 * A DOM with the elements the toolbar's markup provides, and click listeners
 * that can be fired.
 */
function makeDom() {
    const elements = {};

    const makeElement = (id) => ({
        id,
        innerHTML: '',
        textContent: '',
        style: {},
        dataset: {},
        classList: { add() {}, remove() {} },
        listeners: {},
        setAttribute() {},
        getAttribute() { return null; },
        addEventListener(name, fn) { this.listeners[name] = fn; },
        closest() { return null; },
    });

    for (const id of [
        'pramnos-debugbar', 'pdb-restore', 'pdb-close-btn', 'pdb-panels',
        'pdb-ajax-rows', 'pdb-ajax-table', 'pdb-ajax-empty', 'pdb-ajax-tab',
    ]) {
        elements[id] = makeElement(id);
    }

    const body = makeElement('body');

    return {
        elements,
        body,
        document: {
            body,
            getElementById: (id) => elements[id] || null,
            createElement: (tag) => makeElement(tag),
            addEventListener() {},
            // The tab wiring iterates this; no tabs is enough for the hide path.
            querySelectorAll: () => [],
        },
    };
}

/**
 * Run the toolbar script in a sandbox.
 *
 * @param {object}      options.stored        Value already in storage, or null.
 * @param {boolean}     options.storageThrows Make storage access itself throw.
 */
function runToolbar(script, { stored = null, storageThrows = false } = {}) {
    const dom   = makeDom();
    const store = stored === null ? {} : { 'pramnos.debugbar.hidden': stored };

    const storage = {
        getItem: (k) => (k in store ? store[k] : null),
        setItem: (k, v) => { store[k] = String(v); },
        removeItem: (k) => { delete store[k]; },
    };

    const sandbox = {
        document: dom.document,
        location: { origin: 'http://localhost:8082' },
        performance: { now: () => 0 },
        // The ajax half wraps these; it must find something to wrap.
        fetch: () => Promise.resolve({ status: 200, headers: { get: () => null } }),
        XMLHttpRequest: function XHR() { this.open = () => {}; this.send = () => {}; },
        navigator: {},
        console,
        setTimeout,
        Date,
        Math,
        JSON,
        String,
        Number,
        parseFloat,
        RegExp,
        Error,
    };
    Object.defineProperty(sandbox, 'localStorage', {
        get: () => { if (storageThrows) { throw new Error('access denied'); } return storage; },
        configurable: true,
    });
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;

    vm.createContext(sandbox);
    vm.runInContext(script, sandbox);

    return { sandbox, dom, store };
}

/** Fire the click listener a button registered, as a real click would. */
const click = (element) => element.listeners.click({ target: element });

describe('debug toolbar hide button', () => {
    const script = loadToolbarScript();

    test('the emitted script is syntactically valid', () => {
        // A parse error takes the whole inline <script> with it — every handler
        // in the toolbar, not just this one.
        assert.doesNotThrow(() => new vm.Script(script));
    });

    /**
     * The bar starts visible, with the page padded to clear it.
     *
     * The padding used to be set by a separate inline script before the markup.
     * It is now part of the same code path that hides the bar, so the two cannot
     * disagree — which is what would leave a 36px gap under a hidden toolbar.
     */
    test('on load the bar is shown and the page is padded for it', () => {
        // Act
        const { dom } = runToolbar(script);

        // Assert
        assert.equal(dom.elements['pramnos-debugbar'].style.display, '');
        assert.equal(dom.elements['pdb-restore'].style.display, 'none');
        assert.equal(dom.body.style.paddingBottom, '36px');
    });

    /**
     * Clicking ✕ hides the bar — the whole bar, which is what the button claims.
     *
     * Asserting on `#pramnos-debugbar` rather than `#pdb-panels` is the point:
     * the old handler moved the panel, which the stylesheet had already hidden,
     * so nothing on screen changed.
     */
    test('the close button hides the bar and frees the page', () => {
        // Arrange
        const { dom, store } = runToolbar(script);

        // Act
        click(dom.elements['pdb-close-btn']);

        // Assert
        assert.equal(dom.elements['pramnos-debugbar'].style.display, 'none');
        assert.equal(dom.body.style.paddingBottom, '', 'the reserved strip goes too');
        assert.equal(dom.elements['pdb-restore'].style.display, 'block', 'and a way back appears');
        assert.equal(store['pramnos.debugbar.hidden'], '1');
    });

    test('the restore handle brings it back and forgets the choice', () => {
        // Arrange — hidden
        const { dom, store } = runToolbar(script);
        click(dom.elements['pdb-close-btn']);

        // Act
        click(dom.elements['pdb-restore']);

        // Assert
        assert.equal(dom.elements['pramnos-debugbar'].style.display, '');
        assert.equal(dom.elements['pdb-restore'].style.display, 'none');
        assert.equal(dom.body.style.paddingBottom, '36px');
        assert.equal('pramnos.debugbar.hidden' in store, false, 'nothing left to re-hide it');
    });

    /**
     * The choice survives navigation.
     *
     * Every server-rendered page injects a fresh toolbar. A bar that came back on
     * the next page would read as the button not working — the same complaint,
     * one step later.
     */
    test('a bar hidden on an earlier page loads hidden', () => {
        // Act
        const { dom } = runToolbar(script, { stored: '1' });

        // Assert
        assert.equal(dom.elements['pramnos-debugbar'].style.display, 'none');
        assert.equal(dom.elements['pdb-restore'].style.display, 'block');
        assert.equal(dom.body.style.paddingBottom, '', 'no gap under a bar nobody sees');
    });

    /**
     * Storage that throws costs the memory, not the button.
     *
     * Reading `localStorage` at all throws in Safari's private mode and on a
     * blocked origin. Instrumentation is never a good reason for a page to break.
     */
    test('storage that throws does not take the toolbar with it', () => {
        // Arrange & Act
        let dom;
        assert.doesNotThrow(() => {
            dom = runToolbar(script, { storageThrows: true }).dom;
            click(dom.elements['pdb-close-btn']);
        });

        // Assert — it still hides; it just cannot remember that it did
        assert.equal(dom.elements['pramnos-debugbar'].style.display, 'none');
    });
});
