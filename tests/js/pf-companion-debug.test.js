/**
 * `pf-companion-debug.js`: a generic preview panel for whatever `pf-companion.js` has mounted —
 * no pose list, no character content; it reads the mounted dock and knows about the 9
 * `pfAssistant` states only because those are the framework's own, unchanged.
 *
 * What has to hold: one button per pfAssistant state, each calling pfAssistant.state() with its
 * own name; the free-text box + Apply calls PfCompanion.setPose() with whatever was typed,
 * including a name nothing declared; quick-pick pose buttons appear only when the mounted
 * avatar carries data-poses, and not otherwise; the activity toggle flips
 * data-pf-companion-activity on the dock and dispatches pf-companion:activity; mounting twice
 * replaces the panel; unmount() removes it.
 *
 * Run:
 *   node --test tests/js/pf-companion-debug.test.js
 */
'use strict';

const { test, beforeEach, afterEach } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const UTILS_STUB = `
    window.pfAssistant = {
        states: ['idle', 'listening', 'thinking', 'talking', 'success', 'error', 'offline', 'greeting', 'notify'],
        state: function (name) { window.__statesCalled.push(name); },
    };
`;
const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-companion-debug.js');

let window;
let document;

beforeEach(() => {
    const dom = createDom();
    window = dom.window;
    document = dom.document;
    window.document = document;
    window.__statesCalled = [];
    loadScript(UTILS_STUB, window, { source: true });
    window.__poseCalls = [];
    window.PfCompanion = { setPose: (name) => window.__poseCalls.push(name) };
    loadScript(SOURCE, window);
});

afterEach(() => {
    if (window.PfCompanionDebug) {
        window.PfCompanionDebug.unmount();
    }
});

function mountDock(withPoses) {
    const dock = document.createElement('div');
    dock.className = 'pf-companion-dock pf-companion-dock--bottom-right';
    dock.setAttribute('data-pf-companion-activity', 'active');
    const avatar = document.createElement('span');
    avatar.className = 'pf-assistant-avatar';
    if (withPoses) {
        avatar.setAttribute('data-poses', withPoses);
    }
    dock.appendChild(avatar);
    document.body.appendChild(dock);
    return dock;
}

test('mount() renders one button per pfAssistant state, each calling state() with its own name', () => {
    window.PfCompanionDebug.mount();

    const buttons = document.querySelectorAll('.pf-companion-debug-state');
    assert.equal(buttons.length, 9);

    buttons[2].click(); // 'thinking'
    assert.deepEqual(window.__statesCalled, ['thinking']);
});

test('the free-text box + Apply calls setPose() with whatever was typed', () => {
    mountDock();
    window.PfCompanionDebug.mount();

    const input = document.querySelector('.pf-companion-debug-input');
    input.value = 'a-pose-nothing-declared';
    document.querySelector('.pf-companion-debug-apply').click();

    assert.deepEqual(window.__poseCalls, ['a-pose-nothing-declared']);
});

test('Apply does nothing when the box is empty', () => {
    window.PfCompanionDebug.mount();
    document.querySelector('.pf-companion-debug-apply').click();
    assert.deepEqual(window.__poseCalls, []);
});

test('quick-pick pose buttons appear only when the avatar declares data-poses', () => {
    mountDock('sitting, sleeping, walking');
    window.PfCompanionDebug.mount();

    const buttons = document.querySelectorAll('.pf-companion-debug-pose');
    assert.equal(buttons.length, 3);
    assert.equal(buttons[1].textContent, 'sleeping');

    buttons[1].click();
    assert.deepEqual(window.__poseCalls, ['sleeping']);
});

test('no quick-pick buttons, and no crash, when nothing is mounted at all', () => {
    window.PfCompanionDebug.mount();
    assert.equal(document.querySelectorAll('.pf-companion-debug-pose').length, 0);
});

test('no quick-pick buttons when the mounted avatar declares no poses', () => {
    mountDock();
    window.PfCompanionDebug.mount();
    assert.equal(document.querySelectorAll('.pf-companion-debug-pose').length, 0);
});

test('the activity toggle flips the dock\'s attribute and dispatches the event', () => {
    const dock = mountDock();
    window.PfCompanionDebug.mount();

    const events = [];
    document.addEventListener('pf-companion:activity', (e) => events.push(e.detail.active));

    document.querySelector('.pf-companion-debug-activity').click();
    assert.equal(dock.getAttribute('data-pf-companion-activity'), 'away');
    assert.deepEqual(events, [false]);

    document.querySelector('.pf-companion-debug-activity').click();
    assert.equal(dock.getAttribute('data-pf-companion-activity'), 'active');
    assert.deepEqual(events, [false, true]);
});

test('the activity toggle still dispatches the event when nothing is mounted', () => {
    window.PfCompanionDebug.mount();
    const events = [];
    document.addEventListener('pf-companion:activity', (e) => events.push(e.detail.active));

    // No dock to read a current state from, so there is nothing to "flip" — it announces
    // active first, same as a companion would when it first mounts.
    document.querySelector('.pf-companion-debug-activity').click();
    assert.deepEqual(events, [true]);
});

test('mounting a second panel replaces the first', () => {
    window.PfCompanionDebug.mount();
    window.PfCompanionDebug.mount();
    assert.equal(document.querySelectorAll('.pf-companion-debug-panel').length, 1);
});

test('unmount() removes the panel', () => {
    window.PfCompanionDebug.mount();
    window.PfCompanionDebug.unmount();
    assert.equal(document.querySelectorAll('.pf-companion-debug-panel').length, 0);
});

test('unmount() with nothing mounted does not throw', () => {
    assert.doesNotThrow(() => window.PfCompanionDebug.unmount());
});
