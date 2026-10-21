/**
 * `data-pf-refresh-on-return` in `pf-utils.js`: figures that change while the reader is away.
 *
 * What has to hold: when the reader comes back — the tab visible again, the window focused — the
 * page is fetched as a fragment and each marked element takes its fresh content and aria-label by
 * id, and nothing else on the page changes; one return is one fetch, though focus and visibility
 * both fire; a page with nothing marked fetches nothing; a failed fetch leaves the page as it is.
 *
 * Run:
 *   node --test tests/js/pf-refresh-on-return.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');
const fs       = require('node:fs');
const { JSDOM } = require('jsdom');

const UTILS = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

const settle = async () => { for (let i = 0; i < 10; i++) { await new Promise((r) => setImmediate(r)); } };

/** A message page whose tracking tab is marked (or not), and a server answering `answer`. */
function page({ marked = true, answer } = {}) {
    const mark = marked ? ' data-pf-refresh-on-return' : '';
    const dom = new JSDOM('<!doctype html><html><body>'
        + '<input type="radio" name="tabs" id="tab" aria-label="Tracking · 0 clicked" checked' + mark + '>'
        + '<div id="panel"' + mark + '><span class="clicks">0</span> clicked</div>'
        + '<p id="other">Message</p>'
        + '</body></html>', { runScripts: 'outside-only', url: 'https://app.test/admin/emails/show/7' });
    const { window } = dom;
    const sent = [];
    window.fetch = (url, init = {}) => {
        sent.push({ url, init });
        return answer ? answer() : Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve(
            '<input id="tab" aria-label="Tracking · 1 clicked"><div id="panel"><span class="clicks">1</span> clicked</div><p id="other">Changed</p>'
        ) });
    };
    Object.defineProperty(window.document, 'readyState', { value: 'complete', configurable: true });
    window.eval(fs.readFileSync(UTILS, 'utf8'));

    return { window, sent, $: (s) => window.document.querySelector(s) };
}

/** The reader comes back: the tab visible, then the window focused — as a browser fires both. */
function comeBack(window) {
    Object.defineProperty(window.document, 'visibilityState', { value: 'visible', configurable: true });
    window.document.dispatchEvent(new window.Event('visibilitychange'));
    window.dispatchEvent(new window.Event('focus'));
}

test('coming back fetches the page as a fragment and refreshes the marked elements only', async () => {
    // Arrange
    const ui = page();

    // Act
    comeBack(ui.window);
    await settle();

    // Assert
    assert.equal(ui.sent.length, 1, 'focus and visibility on one return fetched twice');
    assert.equal(ui.sent[0].url, 'https://app.test/admin/emails/show/7');
    assert.equal(ui.sent[0].init.headers['X-Pf-Fragment'], '1');
    assert.equal(ui.$('#tab').getAttribute('aria-label'), 'Tracking · 1 clicked');
    assert.equal(ui.$('#panel .clicks').textContent, '1');
    // The open tab stays open, and what is not marked is not touched.
    assert.equal(ui.$('#tab').checked, true);
    assert.equal(ui.$('#other').textContent, 'Message');
});

test('a page with nothing marked fetches nothing', async () => {
    // Arrange
    const ui = page({ marked: false });

    // Act
    comeBack(ui.window);
    await settle();

    // Assert
    assert.equal(ui.sent.length, 0);
});

test('a failed fetch leaves the page as it is', async () => {
    // Arrange
    const ui = page({ answer: () => Promise.resolve({ ok: false, status: 500, text: () => Promise.resolve('') }) });

    // Act
    comeBack(ui.window);
    await settle();

    // Assert
    assert.equal(ui.sent.length, 1);
    assert.equal(ui.$('#tab').getAttribute('aria-label'), 'Tracking · 0 clicked');
    assert.equal(ui.$('#panel .clicks').textContent, '0');
});
