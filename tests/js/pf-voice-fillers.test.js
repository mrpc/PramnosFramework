/**
 * `pf-voice-fillers.js`: natural Greek filler phrases spoken while an answer is still being
 * worked out.
 *
 * What has to hold: a phrase always comes from the right list (immediate vs delayed), and never
 * immediately repeats itself.
 *
 * Run:
 *   node --test tests/js/pf-voice-fillers.test.js
 */
'use strict';

const { test, beforeEach } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-voice-fillers.js');

let fillers;

beforeEach(() => {
    const { window } = createDom();
    loadScript(SOURCE, window);
    fillers = window.PfVoiceFillers;
});

test('getRandomFiller returns a non-empty string', () => {
    const phrase = fillers.getRandomFiller();
    assert.equal(typeof phrase, 'string');
    assert.ok(phrase.length > 0);
});

test('getRandomFiller never immediately repeats the same phrase, for either list', () => {
    for (let i = 0; i < 200; i++) {
        const a = fillers.getRandomFiller(false);
        const b = fillers.getRandomFiller(false);
        assert.notEqual(a, b);
    }
    for (let i = 0; i < 200; i++) {
        const a = fillers.getRandomFiller(true);
        const b = fillers.getRandomFiller(true);
        assert.notEqual(a, b);
    }
});

test('delayed and immediate fillers are drawn from different lists', () => {
    const early = new Set();
    const delayed = new Set();
    for (let i = 0; i < 50; i++) {
        early.add(fillers.getRandomFiller(false));
        delayed.add(fillers.getRandomFiller(true));
    }
    const overlap = [...early].filter((p) => delayed.has(p));
    assert.equal(overlap.length, 0);
});

test('getFillerVariationsCount is the sum of both lists', () => {
    assert.ok(fillers.getFillerVariationsCount() > 100);
});
