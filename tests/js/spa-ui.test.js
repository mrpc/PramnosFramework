/**
 * `lib/ui.js`: the classes the SPA's shared components draw with, per UI system.
 *
 * What has to hold: every part a component asks for exists in every system — a part missing from
 * one draws nothing there, silently — the system is the page's (`window.__PRAMNOS__.ui`), and an
 * unknown one falls back rather than drawing nothing.
 *
 * Run:
 *   node --test tests/js/spa-ui.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const fs       = require('node:fs');
const os       = require('node:os');
const path     = require('node:path');
const { pathToFileURL } = require('node:url');

const TEMPLATES = path.join(__dirname, '..', '..', 'scaffolding', 'templates');

/** The module as a project receives it. */
async function load() {
    const dir  = fs.mkdtempSync(path.join(os.tmpdir(), 'pf-ui-'));
    const file = path.join(dir, 'ui.mjs');
    fs.copyFileSync(path.join(TEMPLATES, 'spa-ui.js.stub'), file);
    try {
        return await import(pathToFileURL(file).href);
    } finally {
        fs.rmSync(dir, { recursive: true, force: true });
    }
}

test('every part exists in every system', async () => {
    // Arrange
    const { MAPS } = await load();
    const parts = Object.keys(MAPS.tailwind);

    // Assert
    assert.ok(parts.length > 40, 'the sweep found the parts');
    assert.deepEqual(Object.keys(MAPS).sort(), ['bootstrap', 'plain-css', 'tailwind']);
    for (const [system, map] of Object.entries(MAPS)) {
        assert.deepEqual(Object.keys(map).sort(), [...parts].sort(), `${system} has every part, and no other`);
    }
});

test('every part the components ask for is in the map', async () => {
    // Arrange
    const { MAPS } = await load();
    const asked = new Set();
    for (const stub of ['spa-datatable', 'spa-pagination', 'spa-confirm-dialog', 'spa-field', 'spa-omnibox', 'spa-help-panel']) {
        const source = fs.readFileSync(path.join(TEMPLATES, stub + '.svelte.stub'), 'utf8');
        for (const m of source.matchAll(/ui\(([^)]*)\)/g)) {
            for (const name of m[1].matchAll(/'([\w.-]+)'/g)) {
                asked.add(name[1]);
            }
        }
    }

    // Assert
    assert.ok(asked.size > 30, 'the sweep found the calls');
    for (const part of asked) {
        assert.ok(part in MAPS.tailwind, `"${part}" is asked for and is not in the map`);
    }
});

test('the system is the page\'s, and an unknown one is tailwind', async () => {
    // Arrange
    const { ui, system } = await load();

    // Act / Assert
    delete globalThis.window;
    assert.equal(system(), 'tailwind', 'no window: a test, a build');
    globalThis.window = { __PRAMNOS__: { ui: 'bootstrap' } };
    assert.equal(system(), 'bootstrap');
    assert.equal(ui('btn', 'btn.sm'), 'btn btn-secondary btn btn-secondary btn-sm');
    assert.equal(ui('nonexistent'), '', 'a part nobody defined is nothing');
    globalThis.window = { __PRAMNOS__: { ui: 'plain-css' } };
    assert.equal(ui('mobile'), 'pf-ui-mobile pf-ui-stack');
    globalThis.window = { __PRAMNOS__: { ui: 'bootstrap5' } };
    assert.equal(system(), 'tailwind');
    assert.equal(ui('card'), 'card bg-base-100 border border-base-content/10');
    delete globalThis.window;
});

test('every pf-* hook a map uses is styled by that system\'s stylesheet', async () => {
    // Arrange
    const { MAPS } = await load();
    let checked = 0;

    for (const system of ['bootstrap', 'plain-css']) {
        const css = fs.readFileSync(path.join(__dirname, '..', '..', 'scaffolding', 'themes', system, 'style.css'), 'utf8');
        const hooks = new Set(Object.values(MAPS[system]).flatMap((classes) => classes.split(/\s+/)).filter((c) => c.startsWith('pf-')));

        // Assert
        for (const hook of hooks) {
            checked++;
            assert.match(css, new RegExp('\\.' + hook.replace(/[-]/g, '\\-') + '[\\s,{:.]'), `${system}: .${hook} has no rule in its style.css`);
        }
    }
    assert.ok(checked > 40, 'the sweep found the hooks');
});
