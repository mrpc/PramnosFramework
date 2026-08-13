/**
 * The API playground: calling the application's own API from the toolbar.
 *
 * The endpoint list comes from the OpenAPI document the project already
 * generates, so it costs nothing to keep current — and an endpoint missing from
 * it is a documentation bug this tab makes visible too.
 *
 * What has to be right is narrower than "it works", and each of these has a way
 * of being quietly wrong:
 *
 *   - the **base**: the injected prefix wins over the document's `servers` list,
 *     because a generated document names production URLs and a debug toolbar must
 *     never send a development call there;
 *   - the **path**: a generated document can carry `//status`, and sending that
 *     verbatim produces a 404 that reads as a routing bug in the application;
 *   - the **recording**: the call must appear in the requests list exactly once,
 *     so that Time, SQL and Logs answer for it like any other request;
 *   - the **token**: never printed, only the key it came from, and refusable.
 *
 * Run:
 *   node --test tests/js/debugbar-playground.test.js
 */
'use strict';

const { test, describe } = require('node:test');
const assert             = require('node:assert/strict');

const { loadToolbar, clickInBar, makeResponse, settle } = require('./support/toolbar-dom');

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

/** An OpenAPI document of the shape the project's generator writes. */
function document_() {
    return {
        openapi: '3.0.3',
        servers: [{ url: 'https://api.example.com/1.0' }],
        paths: {
            // The doubled slash is not a typo: the generator produces it.
            '//status': { get: { summary: 'Status', operationId: 'getStatus' } },
            '/account/login': {
                post: {
                    summary: 'Login',
                    requestBody: {
                        content: {
                            'application/json': {
                                schema: {
                                    properties: {
                                        username: { type: 'string' },
                                        password: { type: 'string' },
                                    },
                                },
                            },
                        },
                    },
                },
            },
            '/things/{id}': {
                parameters: [
                    { name: 'id', in: 'path', required: true, description: 'The thing' },
                ],
                get: {
                    summary: 'One thing',
                    parameters: [
                        { name: 'expand', in: 'query', description: 'Related data to include' },
                        { name: 'apiKey', in: 'header' },
                    ],
                },
                delete: { summary: 'Remove it' },
            },
            '/internal': { head: { summary: 'Not a method the playground offers' } },
        },
    };
}

/** Open a tab the way the delegated click listener sees it. */
function openTab(dom, panel) {
    clickInBar(dom, '.pdb-tab', { dataset: { panel } });
}

/** The tab strip's markup, for asserting what a call made available. */
function dom_tabs(loaded) {
    return loaded.dom.byId['pdb-tabs'].innerHTML;
}

/** Click one of the playground's own controls. */
function clickPlayground(dom, selector, extra = {}) {
    clickInBar(dom, selector, extra);
}

/**
 * A toolbar whose fetch answers the OpenAPI request, then whatever is queued.
 *
 * The document fetch and the calls made afterwards go through the same `fetch`,
 * so the fake records every call and answers from a queue — which is also how a
 * test asserts what was *sent*.
 */
function loadPlayground({ doc = document_(), runtime = { apiPrefix: '/api/1.0', apiKey: 'k3y' }, docStatus = 200 } = {}) {
    const calls = [];
    const queue = [];

    const fetch = (url, init) => {
        calls.push({ url, init: init || {} });
        if (String(url).indexOf('openapi.json') > -1) {
            return Promise.resolve(makeResponse({
                status: docStatus,
                body: docStatus === 200 ? JSON.stringify(doc) : 'not found',
            }));
        }
        const next = queue.shift();
        return next || Promise.resolve(makeResponse({ status: 200, body: '{}' }));
    };

    const loaded = loadToolbar({ payload: island(), fetch });
    if (runtime) {
        loaded.sandbox.window.__PRAMNOS__ = runtime;
    }
    loaded.calls = calls;
    loaded.queue = queue;
    return loaded;
}

/** Open the API tab and let the document fetch settle. */
async function openPlayground(loaded) {
    openTab(loaded.dom, 'api');
    await settle();
    await settle();
    await settle();
    return loaded.dom.byId['pdb-panel'].innerHTML;
}

describe('the API playground', () => {
    test('it lists the documented endpoints, and only callable methods', async () => {
        // Arrange
        const loaded = loadPlayground();

        // Act
        const html = await openPlayground(loaded);

        // Assert — four operations across three paths; HEAD is not offered
        assert.match(html, /4 documented endpoint\(s\)/);
        assert.match(html, /GET/);
        assert.match(html, /\/account\/login/);
        assert.match(html, /One thing/);
        assert.doesNotMatch(html, /Not a method the playground offers/);
    });

    test('the doubled slash a generator produces is normalised away', async () => {
        // Arrange & Act
        const html = await openPlayground(loadPlayground());

        // Assert — `//status` would 404 in a way that reads as an application bug
        assert.match(html, /\/status/);
        assert.doesNotMatch(html, /\/\/status/);
    });

    test('the document is read from the path derived from the injected prefix', async () => {
        // Arrange
        const loaded = loadPlayground();

        // Act
        await openPlayground(loaded);

        // Assert — /api/1.0 → /api/openapi.json, where the generator writes it
        assert.equal(loaded.calls[0].url, '/api/openapi.json');
    });

    test('with no injected configuration it still tries the conventional path', async () => {
        // Arrange — a server-rendered page has no shell and no __PRAMNOS__
        const loaded = loadPlayground({ runtime: null });

        // Act
        await openPlayground(loaded);

        // Assert
        assert.equal(loaded.calls[0].url, '/api/openapi.json');
    });

    test('a missing document says how to generate one, and offers to retry', async () => {
        // Arrange
        const loaded = loadPlayground({ docStatus: 404 });

        // Act
        const html = await openPlayground(loaded);

        // Assert — the fix is a command, so the panel names it
        assert.match(html, /answered 404/);
        assert.match(html, /docs:build/);
        assert.match(html, /pdb-pg-reload/);
    });

    test('picking an endpoint offers its path, and a body when it takes one', async () => {
        // Arrange
        const loaded = loadPlayground();
        await openPlayground(loaded);

        // Act — the login operation is the second row
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '1' } });
        const html = loaded.dom.byId['pdb-panel'].innerHTML;

        // Assert — a skeleton built from the schema, not an empty box
        assert.match(html, /POST/);
        assert.match(html, /id="pdb-pg-path"/);
        assert.match(html, /id="pdb-pg-body"/);
        assert.match(html, /username/);
        assert.match(html, /&lt;string&gt;/);
    });

    test('a path parameter the document declares is a field, not a brace to edit', async () => {
        // Arrange
        const loaded = loadPlayground();
        await openPlayground(loaded);

        // Act — /things/{id}
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '2' } });
        const html = loaded.dom.byId['pdb-panel'].innerHTML;

        // Assert — a field, with the document's own description as its placeholder
        assert.match(html, /id="pdb-pg-p0"/);
        assert.match(html, /The thing/);
        // And no instruction to edit the path by hand, because there is no need
        assert.doesNotMatch(html, /replace the/);
        // A GET takes no body, so no box is offered for one
        assert.doesNotMatch(html, /id="pdb-pg-body"/);
    });

    /**
     * A brace the document does not declare still has to be dealt with by hand, and
     * the panel says so rather than leaving an unfillable path.
     */
    test('an undeclared brace is called out', async () => {
        // Arrange — a document whose path has a parameter it never declares
        const loaded = loadPlayground({
            doc: {
                openapi: '3.0.3',
                paths: { '/things/{id}': { get: { summary: 'Undeclared' } } },
            },
        });
        await openPlayground(loaded);

        // Act
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '0' } });
        const html = loaded.dom.byId['pdb-panel'].innerHTML;

        // Assert
        assert.match(html, /the document does not/);
        assert.match(html, /\{braces\}/);
    });

    test('sending calls the real endpoint, against the injected prefix', async () => {
        // Arrange
        const loaded = loadPlayground();
        await openPlayground(loaded);
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '0' } });
        loaded.queue.push(Promise.resolve(makeResponse({
            status: 200,
            body: JSON.stringify({ status: 'ok', _debug: { time: 4, queries: { count: 1 } } }),
        })));

        // Act
        clickPlayground(loaded.dom, '#pdb-pg-send');
        await settle();
        await settle();
        await settle();

        // Assert — the prefix, not the document's production server
        const sent = loaded.calls[loaded.calls.length - 1];
        assert.equal(sent.url, '/api/1.0/status');
        assert.equal(sent.init.method, 'GET');
        assert.equal(sent.init.headers.apiKey, 'k3y', 'the application key travels');
        assert.equal(sent.init.credentials, 'same-origin', 'so a session cookie works too');

        // ...the answer is shown...
        const html = loaded.dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /200/);
        assert.match(html, /ok/);

        // ...and the call is in the requests list exactly once, with its payload
        const tabs = loaded.dom.byId['pdb-tabs'].innerHTML;
        assert.match(tabs, /requests<span class="pdb-tab-count">2</);
    });

    test('a typed path and body are what get sent', async () => {
        // Arrange
        const loaded = loadPlayground();
        await openPlayground(loaded);
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '1' } });
        // What the reader typed, as the DOM would hold it
        loaded.dom.byId['pdb-pg-path'].value = '/account/login';
        loaded.dom.byId['pdb-pg-body'].value = '{"username":"admin","password":"x"}';
        loaded.queue.push(Promise.resolve(makeResponse({ status: 401, body: '{"error":"nope"}' })));

        // Act
        clickPlayground(loaded.dom, '#pdb-pg-send');
        await settle();
        await settle();
        await settle();

        // Assert
        const sent = loaded.calls[loaded.calls.length - 1];
        assert.equal(sent.init.method, 'POST');
        assert.equal(sent.init.body, '{"username":"admin","password":"x"}');
        assert.equal(sent.init.headers['Content-Type'], 'application/json');
        // A failure is shown as one, without being an error the page has to handle
        assert.match(loaded.dom.byId['pdb-panel'].innerHTML, /401/);
    });

    test('a stored token is offered by key name, never by value, and can be refused', async () => {
        // Arrange
        const loaded = loadPlayground();
        loaded.storage.setItem('acme-token', 'header.payload.signature');
        await openPlayground(loaded);

        // Act
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '0' } });
        let html = loaded.dom.byId['pdb-panel'].innerHTML;

        // Assert — the key is named; the token is not in the markup
        assert.match(html, /acme-token/);
        assert.doesNotMatch(html, /header\.payload\.signature/);

        // Act — send it, then refuse it and send again
        loaded.queue.push(Promise.resolve(makeResponse({ status: 200, body: '{}' })));
        clickPlayground(loaded.dom, '#pdb-pg-send');
        await settle();
        await settle();
        assert.equal(
            loaded.calls[loaded.calls.length - 1].init.headers.accessToken,
            'header.payload.signature'
        );

        clickPlayground(loaded.dom, '.pdb-pg-token');
        loaded.queue.push(Promise.resolve(makeResponse({ status: 200, body: '{}' })));
        clickPlayground(loaded.dom, '#pdb-pg-send');
        await settle();
        await settle();

        // Assert — an anonymous call is a call worth being able to make: it is how
        // "is this endpoint public?" is answered
        assert.equal(loaded.calls[loaded.calls.length - 1].init.headers.accessToken, undefined);
    });

    /**
     * The answer appears where the button is, and says what the status means.
     *
     * Reported: "when I press send, where do I see the result?" — it was rendered
     * below the endpoint list, which on a real document means off the bottom of the
     * panel. It is now the first thing after the form.
     */
    test('the response is shown next to the form, not below the endpoint list', async () => {
        // Arrange
        const loaded = loadPlayground();
        await openPlayground(loaded);
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '0' } });
        loaded.queue.push(Promise.resolve(makeResponse({
            status: 404, body: '{"error":"nope"}',
        })));

        // Act
        clickPlayground(loaded.dom, '#pdb-pg-send');
        await settle();
        await settle();
        await settle();

        // Assert — the status in words as well as in numbers
        const html = loaded.dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /404 Not Found/);
        assert.match(html, /Response/);
        // …above the endpoint list itself, so pressing Send does not scroll
        // anything out of view
        assert.ok(
            html.indexOf('404 Not Found') < html.indexOf('pdb-pg-op'),
            'the answer comes before the list of things to ask'
        );
        // …and it says where else to look for the same call
        assert.match(html, /requests<\/strong> list/);
    });

    /**
     * The response's own `_debug` payload is not repeated in the response view.
     *
     * In development every JSON response carries one, and here it is pure noise: an
     * order of magnitude larger than most answers, and the same data every other tab
     * of this toolbar is already showing for this exact call. Left in, it pushed the
     * thing the reader pressed Send to see off the top of the box — observed on
     * `/session/info`, where the answer was three lines and the payload forty.
     */
    test('the response view leaves out the debug payload it carries', async () => {
        // Arrange
        const loaded = loadPlayground();
        await openPlayground(loaded);
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '0' } });
        loaded.queue.push(Promise.resolve(makeResponse({
            status: 200,
            body: JSON.stringify({
                user: { id: 2, username: 'admin' },
                _debug: { queries: { count: 3, queries: [{ sql: 'SELECT noise' }] } },
            }),
        })));

        // Act
        clickPlayground(loaded.dom, '#pdb-pg-send');
        await settle();
        await settle();
        await settle();

        // Assert — the answer, without the payload, and the omission stated
        const html = loaded.dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /admin/);
        assert.doesNotMatch(html, /SELECT noise/);
        assert.match(html, /left out of this view/);
        // The tab still shows what the request did — the payload was recorded, not
        // discarded, which is why leaving it out of *this* box costs nothing.
        assert.match(dom_tabs(loaded), /SQL/);
    });

    test('a request that never got a response says so instead of showing nothing', async () => {
        // Arrange
        const loaded = loadPlayground();
        await openPlayground(loaded);
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '0' } });
        loaded.queue.push(Promise.reject(new Error('connection refused')));

        // Act
        clickPlayground(loaded.dom, '#pdb-pg-send');
        await settle();
        await settle();
        await settle();

        // Assert
        const html = loaded.dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /failed before a response arrived/);
        assert.match(html, /connection refused/);
    });

    test('clicking an endpoint row does not hijack the request selection', async () => {
        // Arrange — the rows carry pdb-row for their styling, and the generic row
        // handler would otherwise read the click as "the reader picked request 0"
        const loaded = loadPlayground();
        await openPlayground(loaded);

        // Act
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '0' } });

        // Assert — the panel still shows the playground, not a picked request
        assert.match(loaded.dom.byId['pdb-panel'].innerHTML, /API playground/);
    });

    /**
     * None of the playground's buttons is a copy button.
     *
     * They were written with the `pdb-copy` class for their looks, and the
     * delegated click handler checks for a copy button first — so **Send copied
     * nothing and never sent**, which is indistinguishable from an endpoint that
     * does not answer. The click tests could not see it: the DOM stub matches only
     * the selector a test passes, so `closest('.pdb-copy')` returned null and the
     * button appeared to work.
     */
    test('the action buttons are not shadowed by the copy handler', async () => {
        // Arrange — with a stored token, so the toggle is rendered as well as Send
        const loaded = loadPlayground();
        loaded.storage.setItem('acme-token', 'header.payload.signature');
        await openPlayground(loaded);
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '1' } });

        // Act
        const html = loaded.dom.byId['pdb-panel'].innerHTML;

        // Assert — every button in this panel, and none of them a copy button
        const buttons = html.match(/<button[^>]*>/g) || [];
        assert.equal(buttons.length, 2, 'Send, and the token toggle');
        buttons.forEach((button) => {
            assert.doesNotMatch(button, /pdb-copy/, 'would be shadowed: ' + button);
        });
    });

    /**
     * Declared parameters become fields, and reach the request.
     *
     * Reported: "how do I define parameters?" — and the honest answer had been that
     * you edited `{braces}` inside a path string, which is not a form and says
     * nothing about query parameters at all. The document declares both, so both are
     * offered: path values are substituted where the document said they go, query
     * values are appended.
     */
    test('declared parameters become fields and reach the URL', async () => {
        // Arrange
        const loaded = loadPlayground();
        await openPlayground(loaded);

        // Act — /things/{id}, the third row
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '2' } });
        const html = loaded.dom.byId['pdb-panel'].innerHTML;

        // Assert — a field each, labelled with where it goes, required marked
        assert.match(html, /id="pdb-pg-p0"/);
        assert.match(html, /id="pdb-pg-p1"/);
        assert.match(html, />path</);
        assert.match(html, />query</);
        // A header parameter is the credential machinery, which this tab handles
        // itself rather than asking about.
        assert.doesNotMatch(html, /pdb-pg-p2/);

        // Act — fill them in and send
        loaded.dom.byId['pdb-pg-p0'].value = '42';
        loaded.dom.byId['pdb-pg-p1'].value = 'owner';
        loaded.queue.push(Promise.resolve(makeResponse({ status: 200, body: '{"id":42}' })));
        clickPlayground(loaded.dom, '#pdb-pg-send');
        await settle();
        await settle();
        await settle();

        // Assert — substituted, appended, and encoded
        const sent = loaded.calls[loaded.calls.length - 1];
        assert.equal(sent.url, '/api/1.0/things/42?expand=owner');
    });

    /**
     * A parameter left blank is left out.
     *
     * `?status=` and "no status filter" are different requests, and an empty box
     * means the second.
     */
    test('an empty parameter is not sent', async () => {
        // Arrange
        const loaded = loadPlayground();
        await openPlayground(loaded);
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '2' } });
        loaded.dom.byId['pdb-pg-p0'].value = '7';
        loaded.dom.byId['pdb-pg-p1'].value = '';

        // Act
        loaded.queue.push(Promise.resolve(makeResponse({ status: 200, body: '{}' })));
        clickPlayground(loaded.dom, '#pdb-pg-send');
        await settle();
        await settle();

        // Assert
        assert.equal(loaded.calls[loaded.calls.length - 1].url, '/api/1.0/things/7');
    });

    /**
     * Switching endpoints does not carry the previous one's values across.
     *
     * Two endpoints both taking `id` would otherwise send the first one's value
     * from a field the reader never looked at.
     */
    test('picking another endpoint clears the parameter values', async () => {
        // Arrange
        const loaded = loadPlayground();
        await openPlayground(loaded);
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '2' } });
        loaded.dom.byId['pdb-pg-p0'].value = '42';
        // Reading them into state is what a send or a toggle does
        clickPlayground(loaded.dom, '.pdb-pg-token');

        // Act — DELETE /things/{id}, which declares the same path parameter
        clickPlayground(loaded.dom, '.pdb-pg-op', { dataset: { op: '3' } });
        const html = loaded.dom.byId['pdb-panel'].innerHTML;

        // Assert — the field is offered empty
        assert.match(html, /id="pdb-pg-p0" value=""/);
    });

    test('the document is read once, not on every visit to the tab', async () => {
        // Arrange
        const loaded = loadPlayground();
        await openPlayground(loaded);

        // Act — close the tab and open it again
        openTab(loaded.dom, 'api');
        await openPlayground(loaded);

        // Assert — one document fetch, and it is the only call made
        const docCalls = loaded.calls.filter((c) => String(c.url).indexOf('openapi.json') > -1);
        assert.equal(docCalls.length, 1);
    });
});
