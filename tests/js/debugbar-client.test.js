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
        assert.match(html, /What the server told the browser/);
        assert.match(html, /apiPrefix/);
        assert.match(html, /Acme/);
        // The key is not a deep secret — a browser has to be given it — but a key
        // in a screenshot is a key that has to be rotated.
        assert.doesNotMatch(html, /066146dfa45ccb5ffbae7714501da0ed/);
    });

    test('on a server-rendered page, no configuration is not a finding', () => {
        // Arrange — a data island means the page came from the server, which is
        // the one fact that settles this: the browser was handed nothing because
        // there was nothing to hand it.
        const html = clientPanel(loadToolbar({ payload: island() }));

        // Assert — said in the reader's terms, and not as a fault. The panel used
        // to report "the page injected no window.__PRAMNOS__", which names an
        // internal variable, describes a non-event, and reads like a defect.
        assert.match(html, /rendered by the server/);
        assert.match(html, /nothing wrong/);
        assert.doesNotMatch(html, /the shell did not run/, 'that is the SPA reading');
    });

    test('in a SPA, missing configuration is a finding, and says what it breaks', () => {
        // Arrange — no data island: the shell is a static file, so this is a SPA
        const loaded = loadToolbar({ payload: null });
        // A recorded call is what brings the bar into existence in a SPA.
        loaded.sandbox.window.__pramnosDebugBar.record('GET', '/api/1.0/status', 200, {
            request: { time: 4, memory: 1 },
            queries: { count: 0, queries: [] },
        });

        // Act
        const html = clientPanel(loaded);

        // Assert — the consequence, not the variable name
        assert.match(html, /built-in defaults/);
        assert.match(html, /wrong (path|address)/);
    });

    test('the tab says what it is for, without naming internals', () => {
        // Arrange
        const loaded = loadToolbar({ payload: island() });
        loaded.sandbox.window.__PRAMNOS__ = { appName: 'Acme', apiPrefix: '/api/1.0' };

        // Act
        const html = clientPanel(loaded);

        // Assert — a lead that answers "what does this tab do", in terms of the
        // application rather than of the framework's plumbing
        assert.match(html, /What the server told the browser/);
        // The variable is findable for somebody who wants it in the page source,
        // but it is not what the section is *about*.
        assert.ok(
            html.indexOf('What the server told the browser') < html.indexOf('__PRAMNOS__'),
            'the explanation comes before the internal name'
        );
    });

    test('the URL is always shown, and the reading depends on the delivery', () => {
        // Arrange — a server-rendered page (it has a data island)
        const mvc = loadToolbar({ payload: island() });
        mvc.sandbox.location = { pathname: '/app/things/42', search: '?page=2', hash: '' };

        // Act
        const mvcHtml = clientPanel(mvc);

        // Assert — the URL, and no complaint about a router that has no business
        // existing here. It used to say "No router has reported a route" and name a
        // framework function, which reads as something missing.
        assert.match(mvcHtml, /\/app\/things\/42\?page=2/);
        assert.match(mvcHtml, /The server decided which page this URL is/);
        assert.match(mvcHtml, /<strong>Route<\/strong> tab/, 'points at where the answer is');
        assert.doesNotMatch(mvcHtml, /reportRoute/, 'not a SPA, not its problem');

        // Arrange — a SPA: no island, and the bar built by a recorded call
        const spa = loadToolbar({ payload: null });
        spa.sandbox.window.__pramnosDebugBar.record('GET', '/api/1.0/status', 200, {
            request: { time: 4, memory: 1 },
            queries: { count: 0, queries: [] },
        });

        // Act & Assert — here a silent router *is* worth reporting, with the call
        // that would fix it
        const spaHtml = clientPanel(spa);
        assert.match(spaHtml, /No router has reported a route/);
        assert.match(spaHtml, /reportRoute/);
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
        assert.match(html, /What the server told the browser/);
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
