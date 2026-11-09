/**
 * Where the person is, in `pf-utils.js`: each tab keeps one random id for itself
 * (`window.pfClientId`, in sessionStorage, so a reload keeps it), and reports "I did something"
 * with it to the conversations' `active` — at once, when the tab comes to front, and on a key or
 * a click at most once a minute — which is how a message is notified in the client its reader is
 * in (Pramnos\Conversations\Clients). Started by itself where the conversations' dock is.
 *
 * Run:
 *   node --test tests/js/pf-activity.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, Event } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

/** A page with the dock (or not), pf-utils over it, and every report it sends. */
function page(withDock, storage = {}) {
    const { window, document } = createDom({ url: 'https://app.test/', meta: { csrf: 't0ken' } });
    const store = Object.assign({}, storage);
    window.sessionStorage = { getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); } };
    if (withDock) {
        const dock = document.createElement('div');
        dock.setAttribute('data-pf-conversations-dock', '');
        dock.setAttribute('data-props', JSON.stringify({ base: '/Conversations/' }));
        document.body.appendChild(dock);
    }
    const sent = [];
    window.fetch = (url, init) => { sent.push({ url, init }); return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) }); };
    loadScript(SOURCE, window);

    return { window, document, sent, store };
}

/** The tab's id is made once and kept: a reload of the same tab finds it again. */
test('a tab keeps one id for itself', () => {
    // Arrange
    const first = page(false);

    // Act
    const again = page(false, first.store);

    // Assert
    assert.ok(/^[A-Za-z0-9_-]{8,64}$/.test(first.window.pfClientId), first.window.pfClientId);
    assert.equal(again.window.pfClientId, first.window.pfClientId, 'the same tab, reloaded');
    assert.equal(first.sent.length, 0, 'no dock: nothing reported');
});

/**
 * With the dock on the page the tab reports at once, with its id, its token and whether it is in
 * front; a key straight after sends nothing more (a minute between), and the tab coming to front
 * sends at once.
 */
test('the tab reports where the person is, at most once a minute', () => {
    // Arrange
    const p = page(true);

    // Act
    const atOnce = p.sent.length;
    p.window.dispatchEvent(new Event('keydown'));
    const afterKey = p.sent.length;
    p.document.dispatchEvent(new Event('visibilitychange'));

    // Assert
    assert.equal(atOnce, 1, 'reported when the page opened');
    assert.equal(p.sent[0].url, '/Conversations/active');
    assert.equal(p.sent[0].init.method, 'POST');
    assert.equal(p.sent[0].init.headers['X-CSRF-Token'], 't0ken');
    assert.deepEqual(JSON.parse(p.sent[0].init.body), { client: p.window.pfClientId, front: true });
    assert.equal(afterKey, 1, 'a key within the minute: nothing more');
    assert.equal(p.sent.length, 2, 'coming to front: at once');
});
