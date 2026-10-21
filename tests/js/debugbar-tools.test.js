/**
 * The debug bar's Tools tab: the WebMCP tools a page offers an AI agent, and their calls.
 *
 * What has to hold: the tab is there only on a page that offers tools, counted; it shows each
 * tool's description, whether it changes data, its input schema and an input to start from; Run
 * calls the tool through `window.pfTools` with the JSON typed for it; a call — an agent's or the panel's — redraws the bar, and the calls list says who
 * made it and what came back; and it says whether the browser offers the tools at all.
 *
 * Run:
 *   node --test tests/js/debugbar-tools.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');

const { loadToolbar, clickInBar, settle } = require('./support/toolbar-dom');

/** A rendered page's data island. */
function island() {
    return { request: { time: 3, memory: 1 }, request_method: 'GET', request_path: '/admin/Invoices', status_code: 200 };
}

/** A `window.pfTools` with one tool, keeping the calls the panel makes. */
function fakeTools({ supported = false } = {}) {
    const made = [];
    const calls = [];
    return {
        made,
        calls,
        api: {
            supported,
            list: () => [{
                name: 'move_invoice', description: 'Move an invoice', source: 'form', error: null,
                annotations: { readOnlyHint: false, consequentialHint: true },
                inputSchema: { type: 'object', properties: { invoice: { type: 'number', minimum: 1 }, reason: { type: 'string', enum: ['error', 'merge'] } } },
            }],
            calls: () => calls.slice(),
            call: (name, input) => { made.push({ name, input }); return Promise.resolve({ ok: true }); },
        },
    };
}

/** The toolbar, with `tools` as the page's window.pfTools. */
function toolbar(tools) {
    const loaded = loadToolbar({ payload: island() });
    if (tools) { loaded.sandbox.window.pfTools = tools.api; }
    return loaded;
}

/** Open a tab as the delegated click listener sees it; the panel's markup. */
function open(loaded, panel) {
    clickInBar(loaded.dom, '.pdb-tab', { dataset: { panel } });
    return loaded.dom.byId['pdb-panel'].innerHTML;
}

test('the tab is there only on a page that offers tools, and counts them', () => {
    // Arrange
    const none = toolbar(null);
    const some = toolbar(fakeTools());

    // Act — a redraw after the tools appeared
    clickInBar(none.dom, '.pdb-tab', { dataset: { panel: 'requests' } });
    clickInBar(some.dom, '.pdb-tab', { dataset: { panel: 'requests' } });
    const without = none.dom.byId['pdb-tabs'].innerHTML;
    const withTools = some.dom.byId['pdb-tabs'].innerHTML;

    // Assert
    assert.doesNotMatch(without, /data-panel="tools"/, 'a tab for a page with no tools');
    assert.match(withTools, /data-panel="tools"[^>]*>Tools<span class="pdb-tab-count">1</);
});

test('each tool shows what it does, whether it changes data, its schema and an input to start from', () => {
    // Arrange
    const loaded = toolbar(fakeTools());

    // Act
    const html = open(loaded, 'tools');

    // Assert
    assert.match(html, /move_invoice/);
    assert.match(html, /Move an invoice/);
    assert.match(html, /changes data/);
    assert.match(html, /This browser has no WebMCP/);
    assert.match(html, /"minimum": 1/, 'the schema is not shown');
    // The example input: a number at its minimum, an enum at its first value.
    assert.match(html, /"invoice": 1,\s*"reason": "error"/);
});

test('Run calls the tool through window.pfTools', async () => {
    // Arrange
    const tools = fakeTools();
    const loaded = toolbar(tools);
    open(loaded, 'tools');

    // Act — the box untouched: the example's own JSON is sent
    clickInBar(loaded.dom, '.pdb-tool-run', { getAttribute: () => 'move_invoice' });
    await settle();

    // Assert
    assert.equal(tools.made.length, 1, 'Run did not call the tool');
    assert.equal(tools.made[0].name, 'move_invoice');
});

test('a call redraws the bar, and the list says who made it and what came back', () => {
    // Arrange
    const tools = fakeTools({ supported: true });
    const loaded = toolbar(tools);
    open(loaded, 'tools');
    tools.calls.push({ name: 'move_invoice', by: 'agent', at: new Date(), ms: 42, input: { invoice: 7 }, result: { ok: true, text: 'Moved' }, error: null });

    // Act — what pf-utils.js dispatches after a call
    loaded.dom.document.listeners['pf:tool:call']({});
    const html = loaded.dom.byId['pdb-panel'].innerHTML;

    // Assert
    assert.match(html, /Registered with this browser/);
    assert.match(html, /agent/);
    assert.match(html, /42 ms/);
    assert.match(html, /"text": "Moved"/);
});

/**
 * The toolbar's own data as tools for an agent: offered through pfTools once the page has
 * loaded, read-only, and answering with the requests, one request's collected data, and the
 * browser's errors.
 */
test('the toolbar offers its data as read-only tools, through pfTools once the page has loaded', async () => {
    // Arrange — pf-utils.js runs after the toolbar: pfTools appears, then the page loads
    const offered = [];
    const loaded = toolbar(null);
    loaded.sandbox.window.pfTools = { register: (tool) => offered.push(tool), list: () => [], calls: () => [] };

    // Act
    (loaded.sandbox.__listeners.load || []).forEach((fn) => fn({}));
    (loaded.sandbox.__listeners.load || []).forEach((fn) => fn({}));   // a second load offers nothing again
    const byName = Object.fromEntries(offered.map((t) => [t.name, t]));
    const requests = await byName.debug_requests.execute({});
    const one = await byName.debug_request.execute({ index: 0, sections: ['request'] });
    let missing = null;
    try {
        await byName.debug_request.execute({ index: 99 });
    } catch (e) {
        missing = e.message;
    }

    // Assert
    assert.deepEqual(offered.map((t) => t.name), ['debug_requests', 'debug_request', 'debug_client_errors']);
    assert.ok(offered.every((t) => t.annotations.readOnlyHint && !t.annotations.consequentialHint), 'a debug tool is not read-only');
    assert.equal(requests.requests[0].path, '/admin/Invoices');
    assert.equal(requests.requests[0].status, 200);
    assert.equal(one.request.path, '/admin/Invoices');
    assert.deepEqual(Object.keys(one.data), ['request'], 'sections did not narrow what came back');
    assert.match(missing, /No captured request 99/);
});
