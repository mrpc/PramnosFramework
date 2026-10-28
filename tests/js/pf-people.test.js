/**
 * `data-pf-people` in `pf-utils.js`: a field of names, comma-separated, offered the people the
 * page's API answers for the name being typed (`GET <url>?q=`, SharePolicy::candidates()).
 *
 * What has to hold: nothing is asked under two characters, on an empty field or right after a
 * comma; one request after a pause, however many keys came before it; the one before it cancelled
 * when a new one leaves; an answer kept for its term; an answer to a term the field no longer
 * says is not shown; those already written are not offered, and the names before the last comma
 * lead each offer; without an address nothing is asked.
 *
 * Run:
 *   node --test tests/js/pf-people.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

/** The people the API knows. */
const PEOPLE = [
    { value: 'maria', label: 'Maria Papa' },
    { value: 'manos', label: 'Manos Lykos' },
];

/**
 * A page whose root carries the API's address and a field inside it; the script; a fetch that
 * records each request and answers when told; timers run by hand.
 */
function page({ url = '/admin/Ai/people' } = {}) {
    const { window, document } = createDom();
    const root = document.createElement('div');
    if (url) {
        root.setAttribute('data-pf-people-url', url);
    }
    const field = document.createElement('input');
    field.setAttribute('data-pf-people', '');
    root.appendChild(field);
    document.body.appendChild(root);

    const requests = [];
    const timers = [];
    window.setTimeout = (fn) => timers.push(fn);
    window.clearTimeout = () => { timers.length = 0; };
    window.AbortController = class { constructor() { this.signal = { aborted: false }; } abort() { this.signal.aborted = true; } };
    window.fetch = (address, init) => new Promise((resolve) => {
        const term = decodeURIComponent(address.split('q=')[1]);
        requests.push({ address, signal: init.signal, answer: () => resolve({ json: () => ({ data: { people: PEOPLE.filter((one) => one.value.includes(term)) } }) }) });
    });
    loadScript(SOURCE, window);

    const type = (value) => {
        field.value = value;
        field.dispatchEvent(new window.Event('input', { bubbles: true }));
    };
    const offered = () => {
        const list = document.getElementById('pf-people-offered');

        return list ? list.querySelectorAll('option').map((o) => o.getAttribute('value')) : [];
    };
    const settle = () => new Promise((resolve) => setImmediate(resolve));

    return { field, type, offered, requests, timers, settle };
}

/**
 * Keys typed in a row are one request, sent after the pause, for what the field says then; the
 * answer is offered; the same term again is answered from what was kept, without asking.
 *
 * "Don't send ten requests by the time I've typed a name."
 */
test('one request after the pause, its answer offered and kept', async () => {
    // Arrange
    const ui = page();

    // Act — m, ma, mar: under two characters nothing waits; then one pause
    ui.type('m');
    const afterOne = ui.timers.length;
    ui.type('ma');
    ui.type('mar');
    ui.timers.splice(0).forEach((fn) => fn());
    ui.requests[0].answer();
    await ui.settle();
    const first = ui.offered();
    ui.type('ma');
    const waitedForNew = ui.timers.length;
    ui.type('mar');

    // Assert
    assert.equal(afterOne, 0, 'one character asks nothing');
    assert.equal(ui.requests.length, 1, 'one request for three keys');
    assert.equal(ui.requests[0].address, '/admin/Ai/people?q=mar');
    assert.deepEqual(first, ['maria']);
    assert.equal(waitedForNew, 1, 'a term not asked yet waits for its pause');
    assert.equal(ui.timers.length, 0, 'a term already answered waits for nothing');
    assert.equal(ui.requests.length, 1, 'and asks nothing');
    assert.deepEqual(ui.offered(), ['maria'], 'answered from what was kept');
});

/**
 * A request still out when another leaves is cancelled; an answer that arrives for a term the
 * field no longer says is kept but not shown; the names written before the last comma lead each
 * offer and are not offered again; right after a comma, nothing is.
 */
test('an older answer is cancelled or not shown; written names lead and are not offered', async () => {
    // Arrange
    const ui = page();

    // Act
    ui.type('ma');
    ui.timers.splice(0).forEach((fn) => fn());
    ui.type('man');
    ui.timers.splice(0).forEach((fn) => fn());
    ui.requests[0].answer();
    await ui.settle();
    const stale = ui.offered();
    ui.requests[1].answer();
    await ui.settle();
    const current = ui.offered();
    ui.type('maria, ');
    const afterComma = ui.offered();
    ui.type('maria, ma');

    // Assert
    assert.equal(ui.requests[0].signal.aborted, true, 'the older request is cancelled');
    assert.deepEqual(stale, [], 'an answer to "ma" is not shown under "man"');
    assert.deepEqual(current, ['manos']);
    assert.deepEqual(afterComma, [], 'nothing right after a comma');
    assert.deepEqual(ui.offered(), ['maria, manos'], 'answered from "ma" kept; maria, written, not offered again');
});

/**
 * A page without the address — a person's own Ask, which offers nobody — asks nothing.
 */
test('without an address nothing is asked', () => {
    // Arrange
    const ui = page({ url: '' });

    // Act
    ui.type('mar');

    // Assert
    assert.equal(ui.timers.length, 0);
    assert.equal(ui.requests.length, 0);
});
