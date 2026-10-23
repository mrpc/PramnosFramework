/**
 * `pfAssistant` in `pf-utils.js`: the analyst's living picture (Assist\AvatarSets).
 *
 * What has to hold: a state is set on every `.pf-assistant-avatar` — `data-state` and its
 * `pf-assistant--{state}` class, the old class gone — and told as `pf-assistant:state`; only the
 * nine states are taken; a state that ends by itself goes back to idle after the set's duration;
 * an avatar the server drew offline stays so; a scheduled task's result makes it notify.
 *
 * Run:
 *   node --test tests/js/pf-assistant.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

/** A page with two pictures of the analyst — one drawn offline by the server — and the script. */
function page() {
    const { window, document } = createDom();
    const picture = (state, extra = {}) => {
        const el = document.createElement('span');
        el.className = 'pf-assistant-avatar pf-assistant--' + state;
        el.setAttribute('data-state', state);
        el.setAttribute('data-durations', JSON.stringify({ success: 20, greeting: 20, notify: 20 }));
        Object.entries(extra).forEach(([k, v]) => el.setAttribute(k, v));
        document.body.appendChild(el);
        return el;
    };
    const one = picture('idle');
    const away = picture('offline', { 'data-offline': '' });
    const heard = [];
    document.addEventListener('pf-assistant:state', (event) => heard.push(event.detail.state));
    loadScript(SOURCE, window);

    return { window, document, one, away, heard };
}

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

test('a state is set on the pictures and told; an unknown one is not taken', () => {
    // Arrange
    const ui = page();

    // Act
    ui.window.pfAssistant.state('thinking');
    ui.window.pfAssistant.state('dancing');

    // Assert
    assert.equal(ui.one.getAttribute('data-state'), 'thinking');
    assert.ok(ui.one.classList.contains('pf-assistant--thinking'));
    assert.ok(!ui.one.classList.contains('pf-assistant--idle'), 'the old state\'s class goes');
    assert.deepEqual(ui.heard, ['thinking'], 'told once, and not for a state that is not one');
    assert.equal(ui.away.getAttribute('data-state'), 'offline', 'drawn offline by the server: stays so');
    assert.deepEqual(Array.from(ui.window.pfAssistant.states), ['idle', 'listening', 'thinking', 'talking', 'success', 'error', 'offline', 'greeting', 'notify']);
});

test('a state that ends by itself goes back to idle after its duration', async () => {
    // Arrange
    const ui = page();

    // Act
    ui.window.pfAssistant.state('success');
    const at = ui.one.getAttribute('data-state');
    await wait(40);

    // Assert
    assert.equal(at, 'success');
    assert.equal(ui.one.getAttribute('data-state'), 'idle');
});

test('a scheduled task\'s result makes it notify; another notification does not', () => {
    // Arrange
    const ui = page();
    const tell = (type) => ui.document.dispatchEvent(new ui.window.CustomEvent('pf:notification', { detail: { type } }));

    // Act
    tell('Pramnos\\Notifications\\Welcome');
    const before = ui.one.getAttribute('data-state');
    tell('Pramnos\\Llm\\Chat\\RoutineReady');

    // Assert
    assert.equal(before, 'idle');
    assert.equal(ui.one.getAttribute('data-state'), 'notify');
});
