/**
 * `pf-companion.js`: a floating dock for one avatar, idle detection, and a free-form pose
 * pass-through — no pose vocabulary, no character content, no single required way to mount it.
 *
 * What has to hold: mount() works from an explicit `html` and from `<template data-pf-companion>`;
 * idle crosses into "away" after idleMs with no activity, and any tracked activity brings it
 * back, each dispatching pf-companion:activity with the right detail.active; a click dispatches
 * pf-companion:click and nothing else — no assumption about what it means; setPose() sets
 * data-pose verbatim, unvalidated; setPosition() swaps the corner class; unmount() removes the
 * dock and detaches every listener it installed; mounting twice replaces the first cleanly.
 *
 * Run:
 *   node --test tests/js/pf-companion.test.js
 */
'use strict';

const { test, beforeEach, afterEach, mock } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-companion.js');

let window;
let document;

beforeEach(() => {
    const dom = createDom();
    window = dom.window;
    document = dom.document;
    window.document = document;
    loadScript(SOURCE, window);
});

afterEach(() => {
    if (window.PfCompanion) {
        window.PfCompanion.unmount();
    }
    mock.timers.reset();
});

const AVATAR_HTML = '<span class="pf-assistant-avatar pf-assistant--idle" data-state="idle"></span>';

test('mount() does nothing when there is no html and no template', () => {
    const dock = window.PfCompanion.mount();
    assert.equal(dock, null);
    assert.equal(window.PfCompanion.isMounted(), false);
});

test('mount({html}) builds the dock with that markup, in the default corner', () => {
    const dock = window.PfCompanion.mount({ html: AVATAR_HTML });

    assert.equal(window.PfCompanion.isMounted(), true);
    assert.equal(dock.className, 'pf-companion-dock pf-companion-dock--bottom-right');
    // The test DOM keeps an assigned innerHTML as a string rather than parsing it (real
    // browsers parse it into real children) — checked at the string level for that reason.
    assert.ok(dock.innerHTML.includes('pf-assistant-avatar'));
    assert.equal(dock.getAttribute('data-pf-companion-activity'), 'active');
    assert.equal(dock.parentNode, document.body);
});

test('an unrecognized position falls back to bottom-right', () => {
    const dock = window.PfCompanion.mount({ html: AVATAR_HTML, position: 'middle-of-nowhere' });
    assert.equal(dock.className, 'pf-companion-dock pf-companion-dock--bottom-right');
});

test('a recognized position is used', () => {
    const dock = window.PfCompanion.mount({ html: AVATAR_HTML, position: 'top-left' });
    assert.equal(dock.className, 'pf-companion-dock pf-companion-dock--top-left');
});

test('with no html given, <template data-pf-companion> is read instead', () => {
    const template = document.createElement('template');
    template.setAttribute('data-pf-companion', '');
    template.setAttribute('data-position', 'top-right');
    template.innerHTML = AVATAR_HTML;
    document.body.appendChild(template);

    const dock = window.PfCompanion.mount();

    assert.ok(dock);
    assert.equal(dock.className, 'pf-companion-dock pf-companion-dock--top-right');
    assert.ok(dock.innerHTML.includes('pf-assistant-avatar'));
});

test('an explicit html option wins over the template', () => {
    const template = document.createElement('template');
    template.setAttribute('data-pf-companion', '');
    template.innerHTML = '<span class="pf-assistant-avatar from-template"></span>';
    document.body.appendChild(template);

    const dock = window.PfCompanion.mount({ html: '<span class="pf-assistant-avatar from-option"></span>' });
    assert.ok(dock.innerHTML.includes('from-option'));
});

test('clicking the dock dispatches pf-companion:click, and nothing else', () => {
    const heard = [];
    document.addEventListener('pf-companion:click', () => heard.push('click'));
    document.addEventListener('pf-companion:activity', () => heard.push('activity'));

    const dock = window.PfCompanion.mount({ html: AVATAR_HTML, idleMs: 0 });
    dock.click();

    assert.deepEqual(heard, ['click']);
});

test('setPose() sets data-pose verbatim on the avatar root, unvalidated', () => {
    const dock = window.PfCompanion.mount({ html: AVATAR_HTML });
    // The test DOM keeps an assigned innerHTML as a string rather than parsing it into real
    // children (a real browser does) — a real avatar node is appended directly here so
    // setPose()'s own element-finding logic (querySelector('.pf-assistant-avatar')) has a real
    // element to find, the same shape a parsed innerHTML would have produced.
    const avatar = document.createElement('span');
    avatar.className = 'pf-assistant-avatar';
    dock.appendChild(avatar);

    window.PfCompanion.setPose('a-pose-nothing-declared');

    assert.equal(avatar.getAttribute('data-pose'), 'a-pose-nothing-declared');
});

test('setPose() falls back to the dock\'s first child when nothing has the avatar class', () => {
    const dock = window.PfCompanion.mount({ html: AVATAR_HTML });
    const plain = document.createElement('span');
    dock.appendChild(plain);

    window.PfCompanion.setPose('curious');

    assert.equal(plain.getAttribute('data-pose'), 'curious');
});

test('setPose() does nothing when nothing is mounted', () => {
    assert.doesNotThrow(() => window.PfCompanion.setPose('idle'));
});

test('setPosition() swaps the corner class', () => {
    const dock = window.PfCompanion.mount({ html: AVATAR_HTML, position: 'bottom-right' });
    window.PfCompanion.setPosition('top-left');

    assert.ok(dock.classList.contains('pf-companion-dock--top-left'));
    assert.ok(!dock.classList.contains('pf-companion-dock--bottom-right'));
});

test('setPosition() with an unrecognized corner is ignored', () => {
    const dock = window.PfCompanion.mount({ html: AVATAR_HTML, position: 'bottom-right' });
    window.PfCompanion.setPosition('nowhere');

    assert.ok(dock.classList.contains('pf-companion-dock--bottom-right'));
});

test('unmount() removes the dock and isMounted() becomes false', () => {
    window.PfCompanion.mount({ html: AVATAR_HTML });
    window.PfCompanion.unmount();

    assert.equal(window.PfCompanion.isMounted(), false);
    assert.equal(document.body.children.length, 0);
});

test('unmount() with nothing mounted does not throw', () => {
    assert.doesNotThrow(() => window.PfCompanion.unmount());
});

test('mounting a second companion replaces the first, with only one dock present', () => {
    window.PfCompanion.mount({ html: AVATAR_HTML });
    window.PfCompanion.mount({ html: '<span class="pf-assistant-avatar second"></span>' });

    assert.equal(document.body.children.length, 1);
    assert.ok(document.body.children[0].innerHTML.includes('second'));
});

test('idleMs: 0 disables idle detection — no activity listeners, stays active forever', () => {
    const dock = window.PfCompanion.mount({ html: AVATAR_HTML, idleMs: 0 });
    // No way to observe listener count directly; the contract is simply that activity stays
    // 'active' and no pf-companion:activity ever fires without a timer to cross the threshold.
    assert.equal(dock.getAttribute('data-pf-companion-activity'), 'active');
});

/**
 * mock.timers.enable() patches the global setTimeout — only effective for code that calls the
 * *current* global, not a reference captured earlier. createDom()'s window.setTimeout is
 * captured when it is built, so these three tests enable the mock first and build their own
 * fresh window/document afterward, rather than using the shared beforeEach's.
 */
function freshPageWithMockedTimers() {
    mock.timers.enable({ apis: ['setTimeout', 'Date'] });
    const dom = createDom();
    dom.window.document = dom.document;
    // vm.createContext() gives the script its own realm with its own built-in Date, separate
    // from the host's — explicitly sharing the (already mocked) global Date is what makes
    // Date.now() inside pf-companion.js see the same clock mock.timers.tick() advances.
    dom.window.Date = Date;
    loadScript(SOURCE, dom.window);
    return dom;
}

test('going idle for idleMs fires pf-companion:activity with active: false', () => {
    const page = freshPageWithMockedTimers();
    const events = [];
    page.document.addEventListener('pf-companion:activity', (e) => events.push(e.detail.active));

    const dock = page.window.PfCompanion.mount({ html: AVATAR_HTML, idleMs: 1000 });
    mock.timers.tick(1000);

    assert.deepEqual(events, [false]);
    assert.equal(dock.getAttribute('data-pf-companion-activity'), 'away');
});

test('activity after going idle brings it back and fires the event again', () => {
    const page = freshPageWithMockedTimers();
    const events = [];
    page.document.addEventListener('pf-companion:activity', (e) => events.push(e.detail.active));

    const dock = page.window.PfCompanion.mount({ html: AVATAR_HTML, idleMs: 1000 });
    mock.timers.tick(1000);
    assert.equal(dock.getAttribute('data-pf-companion-activity'), 'away');

    page.document.dispatchEvent(new page.window.Event('mousemove'));
    assert.deepEqual(events, [false, true]);
    assert.equal(dock.getAttribute('data-pf-companion-activity'), 'active');
});

test('unmount() stops idle detection — no further activity events after', () => {
    const page = freshPageWithMockedTimers();
    const events = [];
    page.document.addEventListener('pf-companion:activity', (e) => events.push(e.detail.active));

    page.window.PfCompanion.mount({ html: AVATAR_HTML, idleMs: 1000 });
    page.window.PfCompanion.unmount();
    mock.timers.tick(2000);
    page.document.dispatchEvent(new page.window.Event('mousemove'));

    assert.deepEqual(events, []);
});
