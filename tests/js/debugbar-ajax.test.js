/**
 * The toolbar as a server-rendered page receives it.
 *
 * `DebugBar::render()` emits two things: a hidden `<div id="pramnos-debug-data">`
 * holding the request's collector data, and the framework's one toolbar source.
 * Everything drawn from that data is covered by `spa-debug-panel.test.js`, which
 * drives the same source through its module delivery. What is only true *here*
 * is the delivery itself, and this file tests that:
 *
 *   - the script boots from the data island, so the page's own request is the
 *     first entry and every collector it carries becomes a tab;
 *   - `fetch` and `XMLHttpRequest` are wrapped, so what the page does *after* it
 *     renders is recorded too — a datatable paging, a form saving, a widget
 *     polling. Those requests ran queries nobody was watching;
 *   - the application's own response is never disturbed: same object back, body
 *     unread, read only through `clone()`;
 *   - the `<style>` the toolbar injects carries the script's CSP nonce, or a
 *     strict policy leaves the toolbar as an unreadable column of text.
 *
 * This file exists because of a real failure: a column was added to the row
 * markup and the field that fills it was not, so every row threw while being
 * drawn. The whole panel is wrapped in try/catch — instrumentation must never
 * break the page it measures — so it simply went blank with nothing saying why.
 * A PHP test cannot catch that; it asserts the script is emitted, not that it
 * runs.
 *
 * Run:
 *   node --test tests/js/debugbar-ajax.test.js
 *
 * Zero npm dependencies: node:test, node:assert, node:vm, node:child_process.
 */
'use strict';

const { test, describe } = require('node:test');
const assert             = require('node:assert/strict');

const { loadToolbar, makeResponse, clickInBar, settle } = require('./support/toolbar-dom');

/** An island payload of the shape `DebugBar::render()` writes. */
function island(extra = {}) {
    return Object.assign({
        time: 12.5,
        memory: { peak_bytes: 1, peak_human: '1 B' },   // the collector's shape
        request: { time: 12.5, memory: 2.5 },
        queries: { count: 1, total_ms: 3, queries: [{ sql: 'SELECT 1', time: 3 }] },
        request_method: 'GET',
        request_path: '/dashboard',
        status_code: 200,
    }, extra);
}

/** The `_debug` envelope an API response carries. */
function body(payload) {
    return JSON.stringify({ data: [1, 2], _debug: payload });
}

/** Open one of the toolbar's tabs. */
function openTab(dom, panel) {
    clickInBar(dom, '.pdb-tab', { dataset: { panel } });
}

/** Pick a request from the requests list, by the index it was recorded at. */
function selectRequest(dom, index) {
    clickInBar(dom, '.pdb-row', { dataset: { entry: String(index) } });
}

describe('the server-rendered toolbar', () => {
    test('it boots from the data island, and the page is the first request', () => {
        // Arrange & Act — loading the script is the act; boot() runs on load
        const { dom } = loadToolbar({ payload: island() });

        // Assert
        assert.ok(dom.byId['pramnos-debugbar'], 'the bar was built');
        // Server time comes from request.time: the top-level copy is overwritten
        // by the memory collector, and reading it printed "[object Object]MB".
        assert.match(dom.byId['pdb-info'].innerHTML, /12\.5ms server/);
        assert.match(dom.byId['pdb-info'].innerHTML, /GET \/dashboard/);

        openTab(dom, 'requests');
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /\/dashboard/);
        // Marked as the page itself, so it is not mistaken for an API call.
        assert.match(html, /\(page\)/);
    });

    /**
     * The tabs a server-rendered page used to be missing.
     *
     * Before the two renderers became one, the PHP half drew its own tabs and
     * the JavaScript half drew others. Now every collector in the island becomes
     * a tab, and a collector that is absent gets none — an empty tab reads as
     * "nothing happened", which is a different claim.
     */
    test('every collector the island carries becomes a tab', () => {
        // Arrange & Act
        const { dom } = loadToolbar({
            payload: island({
                session: { active: true, session_id: 'abc', data: { userid: 7 } },
                logs: { count: 1, entries: [{ level: 'error', message: 'boom', time: 1786237200 }] },
                models: { count: 1, ops: 2, operations: [{ class: 'Thing', table: 'things', op: 'load' }] },
                route: { controller: 'dashboard', action: 'index' },
            }),
        });

        // Assert
        const tabs = dom.byId['pdb-tabs'].innerHTML;
        ['SQL', 'Session', 'Logs', 'Models', 'Route'].forEach((label) => {
            assert.ok(tabs.includes(label), `${label} tab is present`);
        });
        assert.equal(tabs.includes('Migrations'), false, 'no tab for a collector with no data');
    });

    test('a fetch made after the page rendered is recorded with its statements', async () => {
        // Arrange
        const page = () => Promise.resolve(makeResponse({
            status: 200,
            body: body({
                request: { time: 40, memory: 3 },
                queries: { count: 2, total_ms: 9, queries: [
                    { sql: 'SELECT a', time: 4 },
                    { sql: 'SELECT b', time: 5 },
                ] },
            }),
        }));
        const { dom, sandbox } = loadToolbar({ payload: island(), fetch: page });

        // Act
        await sandbox.fetch('/api/1.0/things');
        await settle();

        // Assert — the request is listed, newest first
        openTab(dom, 'requests');
        const list = dom.byId['pdb-panel'].innerHTML;
        assert.ok(
            list.indexOf('/api/1.0/things') < list.indexOf('/dashboard'),
            'the newest request is at the top'
        );
        assert.match(list, /40ms/, 'its server time came from the _debug payload');

        // Assert — its statements are one click away, once it is picked
        selectRequest(dom, 1);
        openTab(dom, 'queries');
        assert.match(dom.byId['pdb-panel'].innerHTML, /SELECT b/);
    });

    /**
     * The tabs must stay on the page's own request until the reader says
     * otherwise.
     *
     * A datatable fetches its rows the moment it renders. When the newest
     * request was selected automatically, opening the toolbar on such a page
     * showed that JSON call's numbers — `Views 0` on a page that had rendered a
     * template, true of a request nobody had asked about.
     */
    test('the tabs stay on the page until a request is picked', async () => {
        // Arrange — a page whose own request rendered a template
        const datatable = () => Promise.resolve(makeResponse({
            status: 200,
            body: body({ request: { time: 40 }, views: { count: 0, views: [] } }),
        }));
        const { dom, sandbox } = loadToolbar({
            payload: island({ views: { count: 1, cached: 0, views: [{ view: 'users', template: 'users.html.php', render_ms: 4 }] } }),
            fetch: datatable,
        });

        // Act — the page fetches, as a datatable does
        await sandbox.fetch('/users/data', { method: 'POST' });
        await settle();

        // Assert — the bar still describes the page
        assert.match(dom.byId['pdb-info'].innerHTML, /GET \/dashboard/);
        openTab(dom, 'views');
        assert.match(dom.byId['pdb-panel'].innerHTML, /users\.html\.php/);

        // Act — until the reader picks the fetch, which then owns every tab
        selectRequest(dom, 1);
        openTab(dom, 'views');

        // Assert
        assert.match(dom.byId['pdb-info'].innerHTML, /\/users\/data/);
        assert.match(dom.byId['pdb-panel'].innerHTML, /No views rendered/);
    });

    /**
     * A choice, once made, survives the requests that follow it.
     *
     * A polling widget would otherwise pull the panel out from under whoever is
     * reading it, every few seconds.
     */
    test('a picked request is not replaced by later ones', async () => {
        // Arrange
        const later = () => Promise.resolve(makeResponse({
            status: 200, body: body({ request: { time: 9 } }),
        }));
        const { dom, sandbox } = loadToolbar({ payload: island(), fetch: later });

        // Act — pick the page, then let two more requests land
        openTab(dom, 'requests');
        selectRequest(dom, 0);
        await sandbox.fetch('/api/poll');
        await sandbox.fetch('/api/poll');
        await settle();

        // Assert
        assert.match(dom.byId['pdb-info'].innerHTML, /GET \/dashboard/);
    });

    /**
     * Picking a request must not move the reader somewhere else.
     *
     * It used to jump to SQL on every pick, so comparing one tab across two
     * requests meant navigating back to it each time — and a tab changing under
     * a click nobody aimed at it reads as the toolbar losing its place.
     */
    test('picking a request leaves the open tab open', async () => {
        // Arrange
        const other = () => Promise.resolve(makeResponse({
            status: 200,
            body: body({ request: { time: 9 }, session: { active: true, session_id: 'z', data: { userid: 3 } } }),
        }));
        const { dom, sandbox } = loadToolbar({
            payload: island({ session: { active: true, session_id: 'a', data: { userid: 1 } } }),
            fetch: other,
        });
        await sandbox.fetch('/api/thing');
        await settle();

        // Act — reading Session, then picking a different request
        openTab(dom, 'session');
        selectRequest(dom, 1);

        // Assert — still Session, now the other request's
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /Session ID/);
        assert.match(html, /userid/);
        assert.match(dom.byId['pdb-info'].innerHTML, /\/api\/thing/);
    });

    /**
     * A selection must be releasable, or it is a mode with no way out — and
     * "the toolbar is showing the wrong numbers" is what that looks like from
     * the outside. Two ways, because the row is not discoverable on its own.
     */
    test('a picked request can be released, both ways', async () => {
        // Arrange
        const other = () => Promise.resolve(makeResponse({
            status: 200, body: body({ request: { time: 9 } }),
        }));
        const { dom, sandbox } = loadToolbar({ payload: island(), fetch: other });
        await sandbox.fetch('/api/thing');
        await settle();

        // Act — pick the fetch, then click its row again
        openTab(dom, 'requests');
        selectRequest(dom, 1);
        assert.match(dom.byId['pdb-info'].innerHTML, /\/api\/thing/);
        selectRequest(dom, 1);

        // Assert — back to the page, and the chip is no longer a button
        assert.match(dom.byId['pdb-info'].innerHTML, /GET \/dashboard/);
        assert.equal(dom.byId['pdb-info'].innerHTML.includes('pdb-unpick'), false);

        // Act — pick again, and use the chip in the info strip this time
        selectRequest(dom, 1);
        assert.match(dom.byId['pdb-info'].innerHTML, /pdb-unpick/);
        clickInBar(dom, '.pdb-unpick');

        // Assert
        assert.match(dom.byId['pdb-info'].innerHTML, /GET \/dashboard/);
    });

    /**
     * A failed request is found by scanning the list, so the whole row carries
     * the colour — including a 200 that raised something, which nobody would go
     * looking for.
     */
    test('a request that went wrong is red across the whole row', async () => {
        // Arrange — one fetch, answering by URL: replacing sandbox.fetch after
        // load would hand back the unwrapped original, and nothing would record.
        const server = (url) => Promise.resolve(
            String(url).indexOf('/users/data') > -1
                ? makeResponse({ status: 500, body: 'nope' })
                : makeResponse({
                    status: 200,
                    body: body({
                        request: { time: 5 },
                        exceptions: { count: 1, items: [{ type: 'php_error', class: 'E_WARNING', message: 'Undefined key', file: '/x.php', line: 9 }] },
                    }),
                })
        );
        const { dom, sandbox } = loadToolbar({ payload: island(), fetch: server });

        // Act
        await sandbox.fetch('/users/data', { method: 'POST' });
        await sandbox.fetch('/api/ok');
        await settle();
        openTab(dom, 'requests');

        // Assert — two bad rows: the 500, and the 200 that raised a warning
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.equal((html.match(/pdb-row-bad/g) || []).length, 2);
        // The page's own request is untouched
        assert.match(html, /<tr class="pdb-row[^"]*" data-entry="0"/);
    });

    /**
     * Logs and exceptions are streams: an entry happens at a moment, and which
     * request produced it is a detail of it. Until a request is picked, both tabs
     * answer for the whole page — otherwise an error logged by a background call
     * is invisible while the page's own request is in view.
     */
    test('logs and exceptions are aggregated until a request is picked', async () => {
        // Arrange — the page logged one line; the fetch logged another and threw
        const failing = () => Promise.resolve(makeResponse({
            status: 500,
            body: body({
                request: { time: 12 },
                logs: { count: 1, entries: [{ level: 'error', message: 'from the ajax call', time: 1786237201 }] },
                exceptions: { count: 1, items: [{ type: 'exception', class: 'RuntimeException', message: 'kaboom', file: '/x.php', line: 3 }] },
            }),
        }));
        const { dom, sandbox } = loadToolbar({
            payload: island({
                logs: { count: 1, entries: [{ level: 'info', message: 'from the page', time: 1786237200 }] },
            }),
            fetch: failing,
        });

        // Act
        await sandbox.fetch('/api/1.0/boom');
        await settle();
        openTab(dom, 'logs');

        // Assert — both lines, each naming the request it came from
        const logs = dom.byId['pdb-panel'].innerHTML;
        assert.match(logs, /from the page/);
        assert.match(logs, /from the ajax call/);
        assert.match(logs, /\/api\/1\.0\/boom/, 'each row says which request logged it');

        // Assert — an exception raised by another request is still reachable,
        // even though the selected request had none of its own
        openTab(dom, 'exceptions');
        assert.match(dom.byId['pdb-panel'].innerHTML, /RuntimeException/);

        // Act — picking a request narrows both back to it
        selectRequest(dom, 0);
        openTab(dom, 'logs');

        // Assert
        const pageOnly = dom.byId['pdb-panel'].innerHTML;
        assert.match(pageOnly, /from the page/);
        assert.equal(pageOnly.includes('from the ajax call'), false);
    });

    /**
     * A response with no body to carry a payload still has a header.
     *
     * A 204 from a save, a redirect, an HTML fragment — ordinary shapes for the
     * requests a page makes after it renders, and none of them has anywhere to
     * put a `_debug` key. `X-Pramnos-Debug` is a JSON summary; counts and
     * timings only, because a header lands in every access log on the way.
     */
    test('a bodiless response is read from the X-Pramnos-Debug header', async () => {
        // Arrange
        const save = () => Promise.resolve(makeResponse({
            status: 204,
            body: '',
            headers: { 'X-Pramnos-Debug': JSON.stringify({ time: 91.5, memory: 4, queries: 12 }) },
        }));
        const { dom, sandbox } = loadToolbar({ payload: island(), fetch: save });

        // Act
        await sandbox.fetch('/api/1.0/things/1', { method: 'POST' });
        await settle();
        openTab(dom, 'requests');

        // Assert — the row that would otherwise have said "—" twice
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /204/);
        assert.match(html, /91\.5ms/, 'server time survived a response with no body');
        assert.match(html, /12/, 'and so did the query count');
    });

    /**
     * The XHR path must read the debug headers too.
     *
     * It did not, and every datatable is XHR: a call that returned anything but
     * a JSON object — an error page, a 204, an HTML fragment — reported "—" for
     * server time and query count, while the identical call through `fetch`
     * reported both.
     */
    test('an XHR with no JSON body still reads its debug headers', async () => {
        // Arrange
        const { dom, sandbox } = loadToolbar({ payload: island() });
        const xhr = new sandbox.XMLHttpRequest();
        xhr.headers = {
            'X-Pramnos-Debug': JSON.stringify({ time: 44.5, memory: 3, queries: 2, errors: 1 }),
        };
        xhr.getResponseHeader = (name) => xhr.headers[name] ?? null;

        // Act — an error page, which is what an uncaught exception produces
        xhr.open('POST', '/users/data');
        xhr.send();
        xhr.respond(500, '<html><body>Something went wrong</body></html>');
        await settle();

        // Assert
        openTab(dom, 'requests');
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /44\.5ms/, 'server time came from the header');
        assert.match(html, /500/);

        // Assert — and the request is known to have raised something, even
        // though a header can never carry the message itself
        selectRequest(dom, 1);
        openTab(dom, 'exceptions');
        const panel = dom.byId['pdb-panel'].innerHTML;
        assert.match(panel, /1 exception/);
        assert.match(panel, /only the header summary/);
    });

    /**
     * An exception must be visible before anybody goes looking for it.
     *
     * Two things this pins, both reported from a real page: the Exceptions tab
     * counts what *any* request raised while nothing is picked — including a
     * request whose response could only carry the header summary, where the
     * count arrives with no items — and it is drawn as an alarm rather than as
     * the ninth identical tab.
     */
    test('an exception in any request colours the tab and is counted', async () => {
        // Arrange — the page was fine; the call that followed it was not
        const failing = () => Promise.resolve(makeResponse({
            status: 500,
            body: 'not json',
            headers: { 'X-Pramnos-Debug': JSON.stringify({ time: 8, errors: 1 }) },
        }));
        const { dom, sandbox } = loadToolbar({ payload: island(), fetch: failing });

        // Act — nothing is picked; the page's request is still the one in view
        await sandbox.fetch('/users/data', { method: 'POST' });
        await settle();

        // Assert — the tab says so, in red, without anything being clicked
        const tabs = dom.byId['pdb-tabs'].innerHTML;
        assert.match(tabs, /pdb-tab-alert/, 'the tab is drawn as an alarm');
        assert.match(tabs, /Exceptions<span class="pdb-tab-count">1</, 'and counts the one raised');

        // Assert — and opening it names the request, and says why there is no
        // message: a header cannot carry one
        openTab(dom, 'exceptions');
        const panel = dom.byId['pdb-panel'].innerHTML;
        assert.match(panel, /\/users\/data/);
        assert.match(panel, /error log/);
    });

    /**
     * A request that reported an exception it could not describe is chased
     * automatically, and the answer lands on that request's own row.
     *
     * The toolbar already knows which request raised it — the count came back on
     * that response. Making somebody find it in a list and press a button to ask
     * about it is a step with no decision in it.
     */
    test('a missing exception detail is fetched by itself, onto the right row', async () => {
        // Arrange — the page is fine; the call after it dies with only a header
        const server = (url) => {
            if (String(url).indexOf('/devpanel/logs') > -1) {
                return Promise.resolve({
                    status: 200,
                    headers: { get: () => null },
                    clone: () => ({ text: () => Promise.resolve('{}') }),
                    json: () => Promise.resolve({
                        lines: [
                            { timestamp: '12/08/2026 19:35:43', level: 'error', message: 'Deliberate failure in users/data\\nFile: /x.php → 38', file: 'app.log' },
                            { timestamp: '12/08/2026 19:35:43', level: 'info', message: 'not an error', file: 'app.log' },
                        ],
                    }),
                });
            }
            return Promise.resolve(makeResponse({
                status: 500,
                body: 'error page',
                headers: { 'X-Pramnos-Debug': JSON.stringify({ time: 8, errors: 1, id: 'ffee0011aabb2233' }) },
            }));
        };
        const { dom, sandbox } = loadToolbar({
            payload: island(),
            fetch: server,
        });

        // Act — open Exceptions; nothing else is clicked
        await sandbox.fetch('/users/data', { method: 'POST' });
        await settle();
        openTab(dom, 'exceptions');
        await settle();
        await settle();

        // Assert — the real message, on the row of the request that raised it,
        // in place of the "could not carry the details" placeholder
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /Deliberate failure in users\/data/);
        assert.match(html, /\/users\/data/, 'attributed to the failing request');
        assert.equal(html.includes('could not carry'), false, 'the placeholder is gone');
        // Only the error levels are promoted onto rows; the rest stays in the
        // server-log table below.
        assert.equal((html.match(/not an error/g) || []).length, 1);
    });

    /**
     * The one thing a response cannot bring back: the log of a request that died.
     *
     * The toolbar asks the endpoint for it, by request id — and it must ask
     * through the *unwrapped* fetch, or the act of looking becomes one more
     * request to look at, and every look adds another row.
     */
    test('the server can be asked for a request\'s own log lines', async () => {
        // Arrange
        const asked = [];
        const server = (url) => {
            asked.push(String(url));
            if (String(url).indexOf('/devpanel/logs') > -1) {
                return Promise.resolve({
                    status: 200,
                    headers: { get: () => null },
                    clone: () => ({ text: () => Promise.resolve('{}') }),
                    json: () => Promise.resolve({
                        request: 'aabbccddeeff0011',
                        count: 1,
                        lines: [{ timestamp: '12/08/2026 19:35:43', level: 'error', message: 'the real reason', file: 'app.log' }],
                    }),
                });
            }
            return Promise.resolve(makeResponse({ status: 500, body: 'not json' }));
        };
        const { dom, sandbox } = loadToolbar({
            payload: island({
                request: { time: 12.5, memory: 2.5, id: 'aabbccddeeff0011' },
                logs: { count: 0, entries: [] },
            }),
            fetch: server,
        });

        // Act — the offer is drawn because the payload carried a URL and an id
        openTab(dom, 'logs');
        assert.match(dom.byId['pdb-panel'].innerHTML, /Ask the server/);
        clickInBar(dom, '.pdb-fetch-logs', { dataset: { request: 'aabbccddeeff0011' }, textContent: '', disabled: false });
        await settle();
        await settle();

        // Assert — the lines are shown, attributed to the server's log
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /From the server/);
        assert.match(html, /the real reason/);
        assert.match(asked.join(' '), /devpanel\/logs\?request=aabbccddeeff0011/);

        // Assert — and the toolbar's own call did not become a row
        openTab(dom, 'requests');
        assert.equal(
            dom.byId['pdb-panel'].innerHTML.includes('/devpanel/logs'),
            false,
            'looking must not add something to look at'
        );
    });

    /**
     * Picking a request that died must not empty the bar.
     *
     * It did: with no payload there were no tabs, one line of panel text, and no
     * way to see anything — at the exact moment somebody clicked a red row
     * *because* it had gone wrong.
     */
    test('a request that carried nothing keeps the streams and says why', async () => {
        // Arrange — the page logged something; the failing call carried nothing
        const dead = () => Promise.resolve(makeResponse({ status: 500, body: 'not json' }));
        const { dom, sandbox } = loadToolbar({
            payload: island({
                logs: { count: 1, entries: [{ level: 'error', message: 'from the page', time: 1786237200 }] },
            }),
            fetch: dead,
        });

        // Act — with a tab already open, which stays open across the pick
        await sandbox.fetch('/users/data', { method: 'POST' });
        await settle();
        openTab(dom, 'queries');
        selectRequest(dom, 1);

        // Assert — the panel explains itself rather than saying nothing
        assert.match(dom.byId['pdb-panel'].innerHTML, /carried no debug data/);
        // And the streams stay reachable: they are where the reason can still be
        assert.match(dom.byId['pdb-tabs'].innerHTML, /Logs/);
        openTab(dom, 'logs');
        assert.match(dom.byId['pdb-panel'].innerHTML, /from the page/);
    });

    test('the application still gets its own response, unread', async () => {
        // Arrange
        const original = makeResponse({ status: 200, body: body({ request: { time: 5 } }) });
        const { sandbox } = loadToolbar({
            payload: island(),
            fetch: () => Promise.resolve(original),
        });

        // Act
        const returned = await sandbox.fetch('/api/1.0/things');
        await settle();

        // Assert — the same object, with its body still there to be read
        assert.equal(returned, original, 'the response is passed through unchanged');
        assert.equal(original.consumed, false, 'the toolbar read a clone, not the body');
        assert.equal(await returned.text(), body({ request: { time: 5 } }));
    });

    test('an XMLHttpRequest is recorded too', async () => {
        // Arrange — jQuery, datatables and every legacy page still use this
        const { dom, sandbox } = loadToolbar({ payload: island() });
        const xhr = new sandbox.XMLHttpRequest();

        // Act
        xhr.open('POST', '/admin/datatable');
        xhr.send('draw=1');
        xhr.respond(200, body({
            request: { time: 77 },
            queries: { count: 3, queries: [{ sql: 'SELECT c', time: 1 }] },
        }));
        await settle();

        // Assert
        openTab(dom, 'requests');
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /\/admin\/datatable/);
        assert.match(html, /77ms/);
        // The original send still ran: wrapping must never swallow the request.
        assert.equal(xhr.sentBody, 'draw=1');
    });

    /**
     * A page with no island is a SPA shell, and its API client records for
     * itself. Wrapping fetch as well would record every call twice.
     */
    test('with no island, nothing is built and no transport is wrapped', () => {
        // Arrange
        const page = () => Promise.resolve(makeResponse({}));

        // Act
        const { dom, sandbox } = loadToolbar({ payload: null, fetch: page });

        // Assert
        assert.equal(dom.byId['pramnos-debugbar'], undefined, 'no toolbar');
        assert.equal(dom.document.body.children.length, 0, 'nothing added to the page');
        assert.equal(sandbox.fetch, page, 'fetch is exactly what it was');
    });

    /**
     * The stylesheet is created by script, and a strict `style-src` blocks an
     * injected `<style>` just as it would an inline one. The nonce is taken from
     * the script element, which the server already had to nonce for any of this
     * to be running.
     */
    test('the injected stylesheet carries the script tag\'s CSP nonce', () => {
        // Arrange & Act
        const { dom } = loadToolbar({ payload: island(), nonce: 'abc123testNonce' });

        // Assert — the style element is the first thing appended to <head>
        const style = dom.document.head.children[0];
        assert.ok(style, 'a stylesheet was injected');
        assert.equal(style.getAttribute('nonce'), 'abc123testNonce');
        assert.match(style.textContent, /#pramnos-debugbar/);
    });

    test('with no nonce on the page, the stylesheet simply has none', () => {
        // Arrange & Act — the ordinary development case, no CSP configured
        const { dom } = loadToolbar({ payload: island() });

        // Assert
        assert.equal(dom.document.head.children[0].getAttribute('nonce'), null);
    });

    /**
     * A payload missing the keys the toolbar reads must cost a value, not the
     * panel: everything here is instrumentation, and instrumentation that throws
     * takes the page's own scripts with it.
     */
    test('an island with almost nothing in it still renders', () => {
        // Arrange & Act
        assert.doesNotThrow(() => {
            const { dom } = loadToolbar({ payload: { request_path: '/thin' } });
            openTab(dom, 'requests');
            assert.ok(dom.byId['pdb-panel'].innerHTML.length > 0);
        });
    });

    /**
     * The toolbar works out the endpoint itself; the server never sends it.
     *
     * The route is a framework constant — the same path in every installation —
     * so putting it in every debug payload would be sending the client something
     * it could already work out. A response should carry what only it knows.
     */
    test('the log endpoint is resolved from the page, not from the payload', async () => {
        // Arrange
        const asked = [];
        const server = (url) => {
            asked.push(String(url));
            return Promise.resolve({
                status: 200,
                headers: { get: () => null },
                clone: () => ({ text: () => Promise.resolve('{}') }),
                json: () => Promise.resolve({ lines: [] }),
            });
        };
        const { dom, sandbox } = loadToolbar({
            payload: island({ request: { time: 1, memory: 1, id: 'aabbccddeeff0011' } }),
            fetch: server,
        });

        // Act
        openTab(dom, 'logs');
        clickInBar(dom, '.pdb-fetch-logs', { dataset: { request: 'aabbccddeeff0011' }, textContent: '' });
        await settle();

        // Assert — the constant path, with no advertisement anywhere
        assert.match(asked.join(' '), /devpanel\/logs\?request=aabbccddeeff0011/);
    });

    /**
     * An application with the DevPanel switched off answers 404, and the toolbar
     * takes that as the answer: it says so once and stops offering. Feature
     * detection by use, rather than by advertisement.
     */
    test('a 404 from the endpoint retires the offer', async () => {
        // Arrange
        let calls = 0;
        const server = () => {
            calls++;
            return Promise.resolve({
                status: 404,
                headers: { get: () => null },
                clone: () => ({ text: () => Promise.resolve('') }),
                json: () => Promise.resolve({}),
            });
        };
        const { dom, sandbox } = loadToolbar({
            payload: island({ request: { time: 1, memory: 1, id: 'aabbccddeeff0011' } }),
            fetch: server,
        });

        // Act
        openTab(dom, 'logs');
        clickInBar(dom, '.pdb-fetch-logs', { dataset: { request: 'aabbccddeeff0011' }, textContent: '' });
        await settle();
        await settle();

        // Assert — the offer is gone, and asking again does not happen
        assert.equal(dom.byId['pdb-panel'].innerHTML.includes('pdb-fetch-logs'), false);
        clickInBar(dom, '.pdb-fetch-logs', { dataset: { request: 'aabbccddeeff0011' }, textContent: '' });
        await settle();
        assert.equal(calls, 1, 'a refusal is not re-asked on every render');
    });
});
