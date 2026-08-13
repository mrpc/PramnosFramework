/**
 * The Errors tab: what the *browser* threw.
 *
 * The server's Exceptions tab has always been there. Its blind spot is
 * everything after the response arrives — a screen that throws while rendering
 * the data it just fetched, a promise nobody caught, an `ApiError` a screen
 * handled by showing a message. All of those leave the panel looking perfectly
 * healthy next to a page that is visibly broken.
 *
 * Three properties are asserted here because each has a way of going wrong that
 * nothing else would catch: the handlers must be passive (never swallow an error
 * the console should show), the tab must not create a toolbar on a production
 * page that merely threw, and identical failures must collapse into a count
 * rather than fill fifty rows from one render loop.
 *
 * Run:
 *   node --test tests/js/debugbar-errors.test.js
 */
'use strict';

const { test, describe } = require('node:test');
const assert             = require('node:assert/strict');

const { loadToolbar, clickInBar } = require('./support/toolbar-dom');

/** A rendered page's data island. */
function island() {
    return {
        request: { time: 12.5, memory: 2.5 },
        queries: { count: 0, total_ms: 0, queries: [] },
        request_method: 'GET',
        request_path: '/dashboard',
        status_code: 200,
    };
}

/** Open a tab the way the delegated click listener sees it. */
function openTab(dom, panel) {
    clickInBar(dom, '.pdb-tab', { dataset: { panel } });
}

/** Fire a window event the toolbar registered a listener for. */
function fire(sandbox, name, event) {
    const listeners = sandbox.__listeners[name] || [];
    assert.ok(listeners.length, `something listens for ${name}`);
    listeners.forEach((fn) => fn(event));
}

/**
 * A toolbar whose window records its event listeners.
 *
 * The DOM stub gives `document` listeners; these are on `window`, and the
 * assertion that the toolbar listens at all is half the feature.
 */
function loadWatching(options = {}) {
    const loaded = loadToolbar(options);
    return loaded;
}

describe('the Errors tab', () => {
    test('an uncaught error appears, in red, tied to the last request', () => {
        // Arrange
        const { dom, sandbox } = loadWatching({ payload: island() });

        // Act — what window.onerror sees
        fire(sandbox, 'error', {
            error: Object.assign(new Error('x is not a function'), {
                stack: 'TypeError: x is not a function\n    at Screen (main.js:42)',
            }),
        });

        // Assert — the tab announces itself without being opened
        const tabs = dom.byId['pdb-tabs'].innerHTML;
        assert.match(tabs, /data-panel="errors"/);
        assert.match(tabs, /pdb-tab-alert/, 'an error tab is red, like Exceptions');

        openTab(dom, 'errors');
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /1 browser error\(s\)/);
        assert.match(html, /x is not a function/);
        assert.match(html, /uncaught/);
        // The correlation the roadmap asked for: printed on the row, not a filter.
        assert.match(html, /after GET/);
        assert.match(html, /dashboard/);
    });

    test('there is no tab until something is thrown', () => {
        // Arrange & Act — an ordinary page
        const { dom } = loadWatching({ payload: island() });

        // Assert — a tab reading 0 on every page trains the eye to ignore it
        assert.doesNotMatch(dom.byId['pdb-tabs'].innerHTML, /data-panel="errors"/);
    });

    test('an unhandled rejection is recorded, with its reason', () => {
        // Arrange
        const { dom, sandbox } = loadWatching({ payload: island() });

        // Act
        fire(sandbox, 'unhandledrejection', { reason: new Error('fetch aborted') });

        // Assert
        openTab(dom, 'errors');
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /unhandled rejection/);
        assert.match(html, /fetch aborted/);
    });

    test('a rejection with no reason at all still produces a row', () => {
        // Arrange — `Promise.reject()` carries undefined, and dropping the row
        // would hide the hardest kind of bug to find
        const { dom, sandbox } = loadWatching({ payload: island() });

        // Act
        fire(sandbox, 'unhandledrejection', {});

        // Assert
        openTab(dom, 'errors');
        assert.match(dom.byId['pdb-panel'].innerHTML, /unknown/);
    });

    test('an error event with only a message — a cross-origin script — is kept', () => {
        // Arrange
        const { dom, sandbox } = loadWatching({ payload: island() });

        // Act — no `error` object, which is what a cross-origin failure reports
        fire(sandbox, 'error', { message: 'Script error.' });

        // Assert
        openTab(dom, 'errors');
        assert.match(dom.byId['pdb-panel'].innerHTML, /Script error/);
    });

    test('the same failure twice is one row with a count', () => {
        // Arrange
        const { dom, sandbox } = loadWatching({ payload: island() });

        // Act — a render loop throwing the same thing
        for (let i = 0; i < 4; i++) {
            fire(sandbox, 'error', { error: new Error('cannot read length of null') });
        }

        // Assert — one finding, four occurrences
        assert.match(dom.byId['pdb-tabs'].innerHTML, /Errors<span[^>]*>1</);
        openTab(dom, 'errors');
        assert.match(dom.byId['pdb-panel'].innerHTML, /×4/);
    });

    test('an error a screen caught can be handed over, with its own request', () => {
        // Arrange — the ApiError case: handled, so no global handler ever sees it
        const { dom, sandbox } = loadWatching({ payload: island() });
        const failure = Object.assign(new Error('POST /things failed (422)'), { status: 422 });

        // Act — what the generated API client does
        sandbox.window.__pramnosDebugBar.reportError(failure, {
            kind: 'ApiError',
            request: { method: 'POST', path: '/things', status: 422 },
        });

        // Assert
        openTab(dom, 'errors');
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /ApiError 422/);
        assert.match(html, /after POST/);
        assert.match(html, /things/);
    });

    test('a stack is available but folded away, and masked', () => {
        // Arrange — a stack can carry a URL with a token in it, and this panel
        // gets screenshotted into bug reports
        const { dom, sandbox } = loadWatching({ payload: island() });

        // Act
        sandbox.window.__pramnosDebugBar.reportError(
            Object.assign(new Error('boom'), {
                stack: 'Error: boom\n    at fetch (/api/1.0/x?apiKey=deadbeefcafe:1:1)',
            })
        );

        // Assert
        openTab(dom, 'errors');
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /<details/, 'folded, so one error cannot fill the panel');
        assert.doesNotMatch(html, /deadbeefcafe/, 'the secret in the stack is masked');
    });

    test('a production page that throws gets no toolbar', () => {
        // Arrange — no island, no _debug: production, where this script still ships
        const { dom, sandbox } = loadWatching({ payload: null });

        // Act
        fire(sandbox, 'error', { error: new Error('boom') });

        // Assert — collected in memory, but nothing drawn. Building a bar because
        // the page threw would put a debug toolbar on a live site.
        assert.equal(dom.byId['pramnos-debugbar'], undefined);
        assert.equal(dom.document.body.children.length, 0);
    });

    test('the handlers are passive — nothing is prevented or consumed', () => {
        // Arrange
        const { sandbox } = loadWatching({ payload: island() });
        let prevented = false;
        const event = {
            error: new Error('boom'),
            preventDefault() { prevented = true; },
            stopPropagation() { prevented = true; },
        };

        // Act
        fire(sandbox, 'error', event);

        // Assert — the console must still show it, and other handlers still run
        assert.equal(prevented, false);
    });

    test('reporting something that is not an Error does not throw', () => {
        // Arrange — `throw 'oops'` and `Promise.reject({code: 5})` are both legal
        const { dom, sandbox } = loadWatching({ payload: island() });

        // Act & Assert
        assert.doesNotThrow(() => {
            sandbox.window.__pramnosDebugBar.reportError('oops');
            sandbox.window.__pramnosDebugBar.reportError({ code: 5 });
        });

        openTab(dom, 'errors');
        assert.match(dom.byId['pdb-panel'].innerHTML, /oops/);
    });
});
