/**
 * `data-pf-tool` and `window.pfTools` in `pf-utils.js`: a page's forms as tools for an AI agent
 * in the browser (WebMCP), and the list the debug bar reads.
 *
 * What has to hold: a marked form is a tool whose input schema comes from its fields — typed by
 * control, described by label, `required` kept, the CSRF field and other hidden plumbing left
 * out; a call fills the form and sends it as the page would (its hidden fields, its method,
 * `X-Pf-Fragment: 1`) and answers with the page's text; a POST form is consequential and a GET
 * one read-only; where the browser has WebMCP each tool is registered with it and unregistered
 * through its signal; every call is kept and announced, whether an agent or the page made it.
 *
 * Run:
 *   node --test tests/js/pf-tools.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, stubFetch } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

const settle = async () => { for (let i = 0; i < 8; i++) { await new Promise((r) => setImmediate(r)); } };

/** An element with attributes and properties, appended to `parent`. */
function el(document, parent, tag, attrs = {}, props = {}) {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
    Object.assign(node, props);
    parent.appendChild(node);
    return node;
}

/**
 * A page with the invoice-move form, a GET search form, and — unless `webmcp` is false — a
 * browser that has WebMCP on `document.modelContext`.
 */
function page({ webmcp = true } = {}) {
    const { window, document } = createDom({ meta: { csrf: 't0ken' } });
    window.AbortController = AbortController;
    const registered = [];
    if (webmcp) {
        document.modelContext = {
            registerTool: (tool, options) => { registered.push({ tool, options }); return Promise.resolve(); },
        };
    }
    window.fetch = stubFetch({
        'POST /admin/Invoices/move': '<div><h2>Moved</h2><script>x()</script><p>Invoice 7 &amp; its lines moved. Wrote &amp;lt;b&amp;gt; to the notes field.</p></div>',
        '/admin/Invoices': (req) => '<p>found ' + req.path + '</p>',
    });
    const calls = [];
    document.addEventListener('pf:tool:call', (e) => calls.push(e.detail));

    const move = el(document, document.body, 'form', {
        method: 'post', action: '/admin/Invoices/move', 'data-pf-tool': 'move_invoice',
        'data-pf-tool-description': 'Move an invoice to another customer',
    });
    el(document, move, 'input', { type: 'hidden', name: 'a'.repeat(64), value: 'csrf' });
    el(document, move, 'label', { for: 'inv' }, { textContent: 'Invoice number' });
    el(document, move, 'input', { type: 'number', name: 'invoice', id: 'inv', min: '1', required: '' }, { required: true, value: '' });
    el(document, move, 'input', { type: 'text', name: 'customer', 'data-pf-tool-param': 'The customer\'s code', maxlength: '20' }, { value: '' });
    el(document, move, 'input', { type: 'checkbox', name: 'notify', value: '1' }, { checked: false });
    const select = el(document, move, 'select', { name: 'reason' });
    el(document, select, 'option', { value: 'error' }, { selected: false });
    el(document, select, 'option', { value: 'merge' }, { selected: false });

    const search = el(document, document.body, 'form', { method: 'get', action: '/admin/Invoices', 'data-pf-tool': 'find_invoices' });
    el(document, search, 'input', { type: 'search', name: 'q', 'aria-label': 'Words to look for' }, { value: '' });

    loadScript(SOURCE, window);

    return { window, document, registered, calls };
}

test('a marked form is a tool whose schema comes from its fields', () => {
    // Arrange / Act
    const ui = page();
    // Through JSON: the page's objects belong to another realm, and compare by shape only so.
    const tool = JSON.parse(JSON.stringify(ui.window.pfTools.list().find((t) => t.name === 'move_invoice')));

    // Assert
    assert.ok(tool, 'the form is not a tool');
    assert.equal(tool.description, 'Move an invoice to another customer');
    assert.equal(tool.source, 'form');
    assert.deepEqual(Object.keys(tool.inputSchema.properties), ['invoice', 'customer', 'notify', 'reason'], 'the CSRF field reached the agent');
    assert.deepEqual(tool.inputSchema.properties.invoice, { description: 'Invoice number', type: 'number', minimum: 1 });
    assert.deepEqual(tool.inputSchema.properties.customer, { description: 'The customer\'s code', type: 'string', maxLength: 20 });
    assert.equal(tool.inputSchema.properties.notify.type, 'boolean');
    assert.deepEqual(tool.inputSchema.properties.reason.enum, ['error', 'merge']);
    assert.deepEqual(tool.inputSchema.required, ['invoice']);
    assert.deepEqual(tool.annotations, { readOnlyHint: false, consequentialHint: true }, 'a POST is not marked consequential');
    assert.deepEqual(JSON.parse(JSON.stringify(ui.window.pfTools.list().find((t) => t.name === 'find_invoices').annotations)), { readOnlyHint: true, consequentialHint: false });
});

test('with WebMCP each tool is registered, and unregistering aborts its signal', () => {
    // Arrange
    const ui = page();

    // Act
    const names = ui.registered.map((r) => r.tool.name);
    const signal = ui.registered[0].options.signal;
    ui.window.pfTools.unregister('move_invoice');

    // Assert
    assert.equal(ui.window.pfTools.supported, true);
    assert.deepEqual([...names], ['move_invoice', 'find_invoices']);
    assert.equal(signal.aborted, true, 'the browser still offers a tool the page withdrew');
    assert.equal(ui.window.pfTools.list().length, 1);
});

test('an agent\'s call fills the form, sends it as the page would, and answers with the page\'s text', async () => {
    // Arrange
    const ui = page();
    const offered = ui.registered.find((r) => r.tool.name === 'move_invoice').tool;

    // Act — the browser runs the tool for an agent
    const result = await offered.execute({ invoice: 7, customer: 'C-12', notify: true, reason: 'merge' }, {});
    const request = ui.window.fetch.calls[0];

    // Assert
    assert.equal(request.method, 'POST');
    assert.equal(request.headers['X-Pf-Fragment'], '1', 'the answer would be a whole themed page');
    assert.equal(request.body, 'a'.repeat(64) + '=csrf&invoice=7&customer=C-12&notify=1&reason=merge', 'not sent as the form would be');
    assert.equal(result.ok, true);
    assert.equal(result.status, 200);
    assert.equal(
        result.text,
        'Moved Invoice 7 & its lines moved. Wrote &lt;b&gt; to the notes field.',
        // "&amp;lt;b&amp;gt;" is the page's own literal text "&lt;b&gt;" (a notes field that
        // itself holds markup, shown as source): decoding entities one chained .replace() at
        // a time would decode &amp; to & first, leaving &lt;/&gt; for the *next* .replace() to
        // decode a second time, into a real <b> the response never had (CodeQL
        // js/double-escaping) — markup an agent calling this tool would then read as a tag,
        // not as the two words of text they are.
        'a doubly-encoded entity must decode once, not twice into live markup'
    );
    assert.equal(ui.calls.length, 1);
    assert.equal(ui.calls[0].by, 'agent');
    assert.equal(typeof ui.calls[0].ms, 'number');
});

test('a GET tool puts its input in the address', async () => {
    // Arrange
    const ui = page();

    // Act — run from the page, as the debug bar does
    const result = await ui.window.pfTools.call('find_invoices', { q: 'acme ltd' });

    // Assert
    assert.equal(ui.window.fetch.calls[0].path, '/admin/Invoices?q=acme%20ltd');
    assert.match(result.text, /found \/admin\/Invoices\?q=acme%20ltd/);
    assert.equal(ui.window.pfTools.calls()[0].by, 'page');
});

test('without WebMCP the tools are still listed and run, and nothing is offered to an agent', async () => {
    // Arrange
    const ui = page({ webmcp: false });

    // Act
    const listed = ui.window.pfTools.list().length;
    const result = await ui.window.pfTools.call('find_invoices', { q: 'x' });

    // Assert
    assert.equal(ui.window.pfTools.supported, false);
    assert.equal(listed, 2);
    assert.equal(result.ok, true);
});

test('a tool written in script is offered, and a failure is kept with the call', async () => {
    // Arrange
    const ui = page();
    ui.window.pfTools.register({
        name: 'broken', description: 'Always fails', inputSchema: { type: 'object', properties: {} },
        execute: () => { throw new Error('no such order'); },
    });

    // Act
    let caught = null;
    try {
        await ui.window.pfTools.call('broken', {});
    } catch (e) {
        caught = e.message;
    }
    await settle();
    const unknown = await ui.window.pfTools.call('nothing', {}).then(() => null, (e) => e.message);

    // Assert
    assert.equal(caught, 'no such order');
    assert.ok(ui.registered.some((r) => r.tool.name === 'broken'), 'a script tool was not offered');
    assert.equal(ui.window.pfTools.list().find((t) => t.name === 'broken').source, 'script');
    assert.equal(ui.calls.find((c) => c.name === 'broken').error, 'no such order');
    assert.equal(unknown, 'No tool named nothing');
});
