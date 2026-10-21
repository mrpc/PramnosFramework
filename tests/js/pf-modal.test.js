/**
 * `data-pf-modal` in `pf-utils.js`: a link whose page opens in a <dialog> over the one it is on.
 *
 * What has to hold: the page is fetched with `X-Pf-Fragment: 1` and shown in the page's one
 * dialog with a close button, named by its heading; closing it empties it and gives focus back to
 * the link; a failed fetch, a modified click, or a browser with no <dialog> is an ordinary
 * navigation — the address is still a page of its own.
 *
 * Run:
 *   node --test tests/js/pf-modal.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, stubFetch } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

const settle = async () => { for (let i = 0; i < 8; i++) { await new Promise((r) => setImmediate(r)); } };

/**
 * A page with one `data-pf-modal` link, the script over it, and a fetch answering `routes`.
 * `withDialog: false` is a browser whose <dialog> has no showModal().
 */
function page(routes, { withDialog = true } = {}) {
    const { window, document } = createDom({ meta: { csrf: 't0ken' } });
    const make = document.createElement;
    document.createElement = (tag) => {
        const node = make(tag);
        if (tag === 'dialog' && withDialog) {
            node.open = false;
            node.showModal = () => { node.open = true; };
            // As a browser does on Escape, the close button, or close(): a `close` event.
            node.close = () => { node.open = false; node.dispatchEvent(new window.Event('close')); };
        }
        return node;
    };
    window.fetch = stubFetch(routes);
    const link = document.createElement('a');
    link.setAttribute('href', '/admin/Queue/history');
    link.setAttribute('data-pf-modal', 'Statistics');
    link.textContent = 'Statistics';
    document.body.appendChild(link);
    loadScript(SOURCE, window);

    return { window, document, link };
}

/** A left click on the link, as the browser dispatches it; whether it was let through. */
function click(link, init = {}) {
    const event = new (link.ownerDocument?.defaultView?.Event || globalThis.Event)('click', { bubbles: true, cancelable: true });
    Object.assign(event, { button: 0 }, init);
    link.dispatchEvent(event);
    return !event.defaultPrevented;
}

test('a click fetches the fragment and shows it in a dialog, named by the link without a heading', async () => {
    // Arrange
    const ui = page({ 'GET /admin/Queue/history': '<table><tr><td>42</td></tr></table>' });

    // Act
    const navigated = click(ui.link);
    await settle();
    const dialog = ui.document.getElementById('pf-modal');
    const call = ui.window.fetch.calls[0];

    // Assert
    assert.equal(navigated, false, 'the link navigated instead of opening the dialog');
    assert.equal(call.headers['X-Pf-Fragment'], '1', 'the server is not told to leave out its theme');
    assert.ok(dialog && dialog.open, 'no open dialog');
    assert.match(dialog.innerHTML, /<td>42<\/td>/);
    assert.match(dialog.innerHTML, /<form method="dialog"/, 'no way to close it but Escape');
    assert.equal(dialog.getAttribute('aria-label'), 'Statistics');
});

test('closing the dialog empties it and gives focus back to the link', async () => {
    // Arrange
    const ui = page({ 'GET /admin/Queue/history': '<p>figures</p>' });
    click(ui.link);
    await settle();
    const dialog = ui.document.getElementById('pf-modal');

    // Act — Escape, the close button and a backdrop click all end in close()
    dialog.close();

    // Assert
    assert.equal(dialog.open, false);
    assert.equal(dialog.innerHTML, '', 'the last fragment stays in the page');
    assert.equal(ui.document.activeElement, ui.link, 'focus was left nowhere');
});

test('a click on the backdrop closes it; one on its content does not', async () => {
    // Arrange
    const ui = page({ 'GET /admin/Queue/history': '<p>figures</p>' });
    click(ui.link);
    await settle();
    const dialog = ui.document.getElementById('pf-modal');
    const inside = ui.document.createElement('p');
    dialog.appendChild(inside);

    // Act
    inside.click();
    const afterInside = dialog.open;
    dialog.click(); // the target is the dialog itself: the backdrop

    // Assert
    assert.equal(afterInside, true, 'a click inside closed it');
    assert.equal(dialog.open, false, 'a backdrop click did not close it');
});

test('a failed fetch navigates to the page instead', async () => {
    // Arrange — no route: the stub answers 404
    const ui = page({});

    // Act
    click(ui.link);
    await settle();

    // Assert
    assert.equal(ui.window.navigatedTo, 'https://app.test/admin/Queue/history');
    assert.ok(!ui.document.getElementById('pf-modal') || !ui.document.getElementById('pf-modal').open);
});

test('a modified click, or a browser without <dialog>, is an ordinary link', async () => {
    // Arrange
    const ctrl = page({ 'GET /admin/Queue/history': '<p>x</p>' });
    const old  = page({ 'GET /admin/Queue/history': '<p>x</p>' }, { withDialog: false });

    // Act
    const ctrlThrough = click(ctrl.link, { ctrlKey: true });
    const oldThrough  = click(old.link);
    await settle();

    // Assert
    assert.equal(ctrlThrough, true, 'a new-tab click was taken over');
    assert.equal(oldThrough, true, 'a browser with no dialog lost the link');
    assert.equal(ctrl.window.fetch.calls.length + old.window.fetch.calls.length, 0);
});
