/**
 * The Client tab: what the browser thinks the world is.
 *
 * Three sections, one question asked three ways. The injected configuration is
 * the thing nobody can see by reading the source. The router base printed next to
 * the current path is the whole "why does my deep link 404" failure — an
 * application mounted at `/app` whose router has an empty base resolves every
 * deep link to its home screen and says nothing. And a stale token in
 * `localStorage` survives a deploy, after which every call fails in a way that
 * looks like a server problem.
 *
 * Two properties matter beyond "it draws": secrets must be masked (this panel is
 * screenshotted into bug reports), and storage access that throws — private
 * mode, a blocked origin — must cost the section, not the tab.
 *
 * Run:
 *   node --test tests/js/debugbar-client.test.js
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
        request_path: '/app/things/42',
        status_code: 200,
    };
}

/** Open a tab the way the delegated click listener sees it. */
function openTab(dom, panel) {
    clickInBar(dom, '.pdb-tab', { dataset: { panel } });
}

/** The panel's HTML with the Client tab open. */
function clientPanel(loaded) {
    openTab(loaded.dom, 'client');
    return loaded.dom.byId['pdb-panel'].innerHTML;
}

describe('the Client tab', () => {
    test('it is there on every page, because it answers what is missing', () => {
        // Arrange & Act — no configuration, no router, nothing in storage
        const { dom } = loadToolbar({ payload: island() });

        // Assert — unlike every other tab, absence of data is the reason to look
        assert.match(dom.byId['pdb-tabs'].innerHTML, /data-panel="client"[^>]*>Client/);
        // No badge: a configuration is not a quantity.
        assert.doesNotMatch(
            dom.byId['pdb-tabs'].innerHTML,
            /data-panel="client"[^>]*>Client<span/
        );
    });

    test('the injected runtime configuration is shown, with the key masked', () => {
        // Arrange
        const loaded = loadToolbar({ payload: island() });
        loaded.sandbox.window.__PRAMNOS__ = {
            appName: 'Acme',
            apiPrefix: '/api/1.0',
            apiKey: '066146dfa45ccb5ffbae7714501da0ed',
            features: { auth: true },
        };

        // Act
        const html = clientPanel(loaded);

        // Assert
        assert.match(html, /Runtime configuration/);
        assert.match(html, /apiPrefix/);
        assert.match(html, /Acme/);
        // The key is not a deep secret — a browser has to be given it — but a key
        // in a screenshot is a key that has to be rotated.
        assert.doesNotMatch(html, /066146dfa45ccb5ffbae7714501da0ed/);
    });

    test('a page with no injected configuration says what that means', () => {
        // Arrange & Act
        const html = clientPanel(loadToolbar({ payload: island() }));

        // Assert — the two readings are different and both actionable
        assert.match(html, /injected no <code>window\.__PRAMNOS__<\/code>/);
        assert.match(html, /the shell did not run/);
    });

    test('the URL is always shown, even with no router reporting', () => {
        // Arrange
        const loaded = loadToolbar({ payload: island() });
        loaded.sandbox.location = { pathname: '/app/things/42', search: '?page=2', hash: '' };

        // Act
        const html = clientPanel(loaded);

        // Assert
        assert.match(html, /\/app\/things\/42\?page=2/);
        assert.match(html, /No router has reported a route/);
        assert.match(html, /reportRoute/, 'and how to make it report one');
    });

    test('a router that reports gets its route, base and params printed', () => {
        // Arrange
        const loaded = loadToolbar({ payload: island() });
        loaded.sandbox.location = { pathname: '/app/things', search: '', hash: '' };

        // Act — what the generated lib/router.js does on every navigation
        loaded.sandbox.window.__pramnosDebugBar.reportRoute('things', {
            base: '/app',
            params: { id: 42 },
        });
        const html = clientPanel(loaded);

        // Assert
        assert.match(html, /Route/);
        assert.match(html, /things/);
        assert.match(html, /Router base/);
        assert.match(html, /\/app/);
        assert.match(html, /42/);
    });

    test('a base the path does not start with is named as the deep-link failure', () => {
        // Arrange — the reported bug: mounted at /app, router base empty or wrong
        const loaded = loadToolbar({ payload: island() });
        loaded.sandbox.location = { pathname: '/things/42', search: '', hash: '' };

        // Act
        loaded.sandbox.window.__pramnosDebugBar.reportRoute('home', { base: '/app' });
        const html = clientPanel(loaded);

        // Assert — the panel says the thing, rather than leaving two values side
        // by side for the reader to compare
        assert.match(html, /does not start with the router base/);
    });

    test('an empty base reads as the site root rather than as nothing', () => {
        // Arrange
        const loaded = loadToolbar({ payload: island() });
        loaded.sandbox.location = { pathname: '/things', search: '', hash: '' };

        // Act
        loaded.sandbox.window.__pramnosDebugBar.reportRoute('things', { base: '' });
        const html = clientPanel(loaded);

        // Assert — and no false alarm about a mismatch
        assert.match(html, /\(site root\)/);
        assert.doesNotMatch(html, /does not start with the router base/);
    });

    test('the base falls back to the one the shell injected', () => {
        // Arrange — an older project whose router.js does not report
        const loaded = loadToolbar({ payload: island() });
        loaded.sandbox.window.__PRAMNOS__ = { routerBase: '/app' };
        loaded.sandbox.location = { pathname: '/app/things', search: '', hash: '' };

        // Act
        const html = clientPanel(loaded);

        // Assert
        assert.match(html, /Router base/);
    });

    test('storage is listed, and secret-looking keys are masked by name', () => {
        // Arrange
        const loaded = loadToolbar({ payload: island() });
        loaded.storage.setItem('acme-token', 'header.payload.signature');
        loaded.storage.setItem('acme-theme', 'dark');

        // Act
        const html = clientPanel(loaded);

        // Assert — the key is the finding; the value is what must not be shown
        assert.match(html, /acme-token/);
        assert.match(html, /••••/);
        assert.match(html, /24 chars/, 'its length still says whether something is there');
        assert.doesNotMatch(html, /header\.payload\.signature/);
        // A non-secret value is shown as it is — that is the point of the section
        assert.match(html, /acme-theme/);
        assert.match(html, /dark/);
    });

    test('a very long value is truncated rather than filling the panel', () => {
        // Arrange
        const loaded = loadToolbar({ payload: island() });
        loaded.storage.setItem('acme-draft', 'x'.repeat(500));

        // Act
        const html = clientPanel(loaded);

        // Assert
        assert.match(html, /\(500 chars\)/);
        assert.ok(html.indexOf('x'.repeat(500)) === -1, 'the whole value is not printed');
    });

    test('storage that refuses to be read costs the section, not the tab', () => {
        // Arrange — what a blocked origin and private mode have in common is that
        // *using* storage throws. Enumeration is where this one refuses.
        const loaded = loadToolbar({ payload: island(), hostileStorage: true });
        loaded.sandbox.window.__PRAMNOS__ = { appName: 'Acme' };

        // Act & Assert
        let html;
        assert.doesNotThrow(() => { html = clientPanel(loaded); });
        assert.match(html, /cannot be read here/);
        // The rest of the tab is still there, which is the whole point: one
        // refusing section used to be enough to leave the panel half-drawn.
        assert.match(html, /Runtime configuration/);
        assert.match(html, /Acme/);
        assert.match(html, /sessionStorage/);
    });

    test('storage that is not there at all is reported as absent, not as broken', () => {
        // Arrange — a host with no Storage API: an embedded view, a locked-down
        // origin, or `node --test` itself
        const loaded = loadToolbar({ payload: island(), noStorage: true });

        // Act
        const html = clientPanel(loaded);

        // Assert — "unavailable" and "refused" are different findings
        assert.match(html, /unavailable here/);
    });

    test('an empty storage area says so instead of showing an empty table', () => {
        // Arrange & Act
        const html = clientPanel(loadToolbar({ payload: island() }));

        // Assert
        assert.match(html, /localStorage/);
        assert.match(html, /empty/);
    });
});
