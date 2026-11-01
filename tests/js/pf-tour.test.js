/**
 * `pf-tour.js`: a spotlight product tour — dims the page, cuts a glowing window around one
 * element per step, shows a card with the step's own text. No content of its own: an
 * application supplies its steps.
 *
 * What has to hold: steps render their own title/desc/avatar; stepping (next/back/dots/Escape/
 * backdrop) works and finish() calls onFinish exactly once; a step whose selector matches
 * nothing still shows a card, centered, rather than crashing; the optional "🔊 Listen" button
 * only appears when a voice controller was given, and toggles it.
 *
 * Run:
 *   node --test tests/js/pf-tour.test.js
 */
'use strict';

const { test, beforeEach, afterEach } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-tour.js');

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
    if (window.PfTour) {
        window.PfTour.stop();
    }
});

const steps = () => [
    { title: 'Welcome', desc: 'This is your dashboard.', selector: 'main' },
    { title: 'Search', desc: 'Press Ctrl+K.', selector: '.pf-ui-search' },
    { title: 'Done', desc: 'That is everything.' },
];

test('start() does nothing for an empty or missing steps list', () => {
    window.PfTour.start([]);
    assert.equal(document.body.children.length, 0);

    window.PfTour.start(undefined);
    assert.equal(document.body.children.length, 0);
});

test('start() renders the first step, with the right total in the badge', () => {
    window.PfTour.start(steps());

    const root = document.body.children[0];
    assert.equal(root.className, 'pf-tour');
    const title = root.querySelector('.pf-tour-title');
    const desc = root.querySelector('.pf-tour-desc');
    const badge = root.querySelector('.pf-tour-badge');

    assert.equal(title.textContent, 'Welcome');
    assert.equal(desc.textContent, 'This is your dashboard.');
    assert.equal(badge.textContent, 'Step 1 of 3');
    assert.equal(root.querySelectorAll('.pf-tour-dot').length, 3);
});

test('an avatarHtml step shows the avatar slot; one without it stays empty', () => {
    window.PfTour.start([
        { title: 'With avatar', desc: '', avatarHtml: '<svg></svg>' },
        { title: 'Without', desc: '' },
    ]);

    const root = document.body.children[0];
    assert.equal(root.querySelector('.pf-tour-avatar').style.display, '');

    root.querySelector('.pf-tour-next').click();
    assert.equal(root.querySelector('.pf-tour-avatar').style.display, 'none');
});

test('Next steps forward; the last step\'s button finishes the tour', () => {
    let finished = false;
    window.PfTour.start(steps(), { onFinish: () => { finished = true; } });

    const root = document.body.children[0];
    const next = root.querySelector('.pf-tour-next');

    next.click();
    assert.equal(root.querySelector('.pf-tour-title').textContent, 'Search');

    next.click();
    assert.equal(root.querySelector('.pf-tour-title').textContent, 'Done');
    assert.equal(next.textContent, 'Finish');

    next.click();
    assert.equal(finished, true);
    assert.equal(document.body.children.length, 0, 'the tour\'s DOM is removed');
});

test('Back steps backward, and is hidden on the first step', () => {
    window.PfTour.start(steps());
    const root = document.body.children[0];

    assert.equal(root.querySelector('.pf-tour-back').style.display, 'none');

    root.querySelector('.pf-tour-next').click();
    assert.equal(root.querySelector('.pf-tour-back').style.display, '');

    root.querySelector('.pf-tour-back').click();
    assert.equal(root.querySelector('.pf-tour-title').textContent, 'Welcome');
});

test('a dot jumps straight to its step', () => {
    window.PfTour.start(steps());
    const root = document.body.children[0];

    root.querySelectorAll('.pf-tour-dot')[2].click();
    assert.equal(root.querySelector('.pf-tour-title').textContent, 'Done');
    assert.ok(root.querySelectorAll('.pf-tour-dot')[2].className.includes('pf-tour-dot-active'));
});

test('the backdrop and Escape both finish the tour, calling onFinish once', () => {
    let calls = 0;
    window.PfTour.start(steps(), { onFinish: () => { calls++; } });
    document.body.children[0].querySelector('.pf-tour-backdrop').click();
    assert.equal(calls, 1);

    window.PfTour.start(steps(), { onFinish: () => { calls++; } });
    const escape = new window.Event('keydown');
    escape.key = 'Escape';
    document.dispatchEvent(escape);
    assert.equal(calls, 2);
});

test('stop() tears the tour down without calling onFinish', () => {
    let called = false;
    window.PfTour.start(steps(), { onFinish: () => { called = true; } });
    window.PfTour.stop();

    assert.equal(called, false);
    assert.equal(document.body.children.length, 0);
});

test('starting a second tour replaces the first without calling its onFinish', () => {
    let firstFinished = false;
    window.PfTour.start(steps(), { onFinish: () => { firstFinished = true; } });
    window.PfTour.start([{ title: 'Second tour', desc: '' }]);

    assert.equal(firstFinished, false);
    assert.equal(document.body.children.length, 1, 'only one tour\'s DOM at a time');
    assert.equal(document.body.children[0].querySelector('.pf-tour-title').textContent, 'Second tour');
});

test('the 🔊 button is absent without a voice controller, present with one', () => {
    window.PfTour.start(steps());
    assert.equal(document.body.children[0].querySelector('.pf-tour-speak').style.display, 'none');
    window.PfTour.stop();

    const voice = { speaking: false, speak() {}, stopSpeech() {} };
    window.PfTour.start(steps(), { voice });
    assert.equal(document.body.children[0].querySelector('.pf-tour-speak').style.display, '');
});

test('the 🔊 button speaks the current step through the given voice controller', () => {
    const calls = [];
    const voice = {
        speaking: false,
        speak(id, text, handlers) { calls.push([id, text]); handlers.onStart(); },
        stopSpeech() {},
    };
    window.PfTour.start(steps(), { voice });

    const speak = document.body.children[0].querySelector('.pf-tour-speak');
    speak.click();

    assert.equal(calls.length, 1);
    assert.equal(calls[0][1], 'Welcome. This is your dashboard.');
    assert.equal(speak.getAttribute('aria-pressed'), 'true');
});

test('a step whose selector matches nothing still renders, centered, without crashing', () => {
    window.PfTour.start([{ title: 'No target', desc: '', selector: '.does-not-exist' }]);
    const root = document.body.children[0];

    assert.equal(root.querySelector('.pf-tour-title').textContent, 'No target');
    assert.equal(root.querySelector('.pf-tour-glow').style.display, 'none');
    assert.equal(root.querySelector('.pf-tour-card').style.left, '50%');
});

test('a step whose selector matches an element positions the glow around it', () => {
    const main = document.createElement('main');
    main.getBoundingClientRect = () => ({ top: 100, left: 50, width: 200, height: 80 });
    document.body.appendChild(main);

    window.PfTour.start([{ title: 'Target', desc: '', selector: 'main' }]);
    const root = document.body.children[document.body.children.length - 1];

    const glow = root.querySelector('.pf-tour-glow');
    assert.equal(glow.style.display, 'block');
    assert.equal(glow.style.top, '92px');
    assert.equal(glow.style.left, '42px');
});
