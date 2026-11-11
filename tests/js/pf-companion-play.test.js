/**
 * `pf-companion-play.js`: the companion leaves the dock and plays.
 *
 * What has to hold: while she is out the avatar is moved, not copied — the dock is left empty and
 * marked away — and she comes back into the same place; a run sets a gait and faces where she goes,
 * and the gait is gone when she arrives; the laser takes clicks for itself and ends on Esc, after
 * 30 s without a click, or on stop(); the yarn puts a ball on the page and a belly-up pose while
 * she plays, and takes both away; a new game ends the old; a question asked brings her home; the
 * eyes follow the pointer within ±1.35 and stop when told.
 *
 * Run (bounded: a mistake here once ran the machine out of memory):
 *   timeout 60 node --max-old-space-size=512 --test --test-force-exit tests/js/pf-companion-play.test.js
 */
'use strict';

const { test, beforeEach, afterEach, mock } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const { createDom, loadScript, CustomEvent } = require('../../resources/js/pramnos-testing');

const JS = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js');

let window;
let document;
let avatar;
let dock;

/** Let promises settle between timer ticks. */
const settle = () => new Promise((done) => setImmediate(done));

/**
 * Move the clock a little at a time, letting what each step released run — a run that ends starts
 * the next one from a promise, after the step that ended it.
 */
async function tick(ms) {
    for (let done = 0; done < ms; done += 100) {
        mock.timers.tick(Math.min(100, ms - done));
        await settle();
        await settle();
    }
}

/** No test may hang the run: each gives up after this, and the file runs with --test-force-exit. */
const LIMIT = { timeout: 5000 };

beforeEach(() => {
    mock.timers.enable({ apis: ['setTimeout'] });
    const dom = createDom();
    window = dom.window;
    document = dom.document;
    window.document = document;
    window.innerWidth = 1200;
    window.innerHeight = 800;
    window.CustomEvent = CustomEvent;
    loadScript(path.join(JS, 'pf-companion.js'), window);
    loadScript(path.join(JS, 'pf-companion-play.js'), window);
    // The test DOM keeps innerHTML as a string: the avatar is put in the dock by hand.
    dock = window.PfCompanion.mount({ html: '<span></span>', idleMs: 0 });
    avatar = document.createElement('span');
    avatar.className = 'pf-assistant-avatar';
    avatar.setAttribute('data-state', 'idle');
    dock.appendChild(avatar);
});

afterEach(() => {
    window.PfCompanionPlay.detach();
    window.PfCompanion.unmount();
    mock.timers.reset();
});

test('zoomies: out of the dock, not copied; a gait and a facing on the way; home again after', LIMIT, async () => {
    // Act
    window.PfCompanionPlay.zoomies();

    // Assert — out: the avatar is in the runner, the dock empty and marked away
    assert.equal(window.PfCompanionPlay.isOut(), true);
    assert.equal(avatar.parentNode.className, 'pf-companion-runner');
    assert.equal(dock.hasAttribute('data-pf-companion-away'), true);
    assert.equal(dock.querySelector('.pf-assistant-avatar'), null, 'never two of her');
    assert.equal(avatar.getAttribute('data-gait'), 'zoomies');

    // Act — across the page, and home
    await tick(60000);

    // Assert
    assert.equal(window.PfCompanionPlay.isOut(), false);
    assert.equal(avatar.parentNode, dock, 'back where she was');
    assert.equal(dock.hasAttribute('data-pf-companion-away'), false);
    assert.equal(avatar.hasAttribute('data-gait'), false);
    assert.equal(avatar.hasAttribute('data-facing'), false);
    assert.equal(window.PfCompanionPlay.playing(), null);
});

test('laser: a click is hers, not the page\'s; she runs to it facing it; Esc ends it and she comes home', LIMIT, async () => {
    // Arrange
    const started = [];
    document.addEventListener('pf-companion-play:start', (e) => started.push(e.detail.game));
    window.PfCompanionPlay.laser();
    const prevented = [];
    const click = new window.Event('click');
    click.clientX = 600;
    click.clientY = 300;
    click.preventDefault = () => prevented.push('default');
    click.stopPropagation = () => prevented.push('propagation');

    // Act
    document.dispatchEvent(click);

    // Assert
    assert.deepEqual(started, ['laser']);
    assert.ok(document.querySelector('.pf-companion-laser'), 'the dot is on the page');
    assert.equal(document.documentElement.style.cursor, 'crosshair');
    assert.deepEqual(prevented, ['default', 'propagation'], 'not a click on the page');
    assert.equal(avatar.getAttribute('data-gait'), 'sprint');
    assert.equal(avatar.getAttribute('data-facing'), 'right', 'the point is to her right');

    // Act — Esc
    const esc = new window.Event('keydown');
    esc.key = 'Escape';
    document.dispatchEvent(esc);
    await tick(10000);

    // Assert
    assert.equal(window.PfCompanionPlay.playing(), null);
    assert.equal(document.querySelector('.pf-companion-laser'), null, 'the dot is gone');
    assert.equal(document.documentElement.style.cursor, '', 'the cursor as it was');
    assert.equal(avatar.parentNode, dock);
});

test('laser: thirty seconds without a click ends it', LIMIT, async () => {
    // Arrange
    window.PfCompanionPlay.laser();

    // Act
    await tick(29000);
    const before = window.PfCompanionPlay.playing();
    await tick(1500);

    // Assert
    assert.equal(before, 'laser');
    assert.equal(window.PfCompanionPlay.playing(), null);
});

test('yarn: a ball on the page, belly-up while she plays, both gone after; her own pose back', LIMIT, async () => {
    // Arrange
    avatar.setAttribute('data-pose', 'loaf');
    window.PfCompanionPlay.yarn();
    assert.ok(document.querySelector('.pf-companion-yarn'));

    // Act — she reaches it: a sprint of under two seconds, then 3.2 s of play
    await tick(2500);

    // Assert
    assert.equal(avatar.getAttribute('data-pose'), 'belly-up');

    // Act — she has played, and comes home
    await tick(10000);

    // Assert
    assert.equal(document.querySelector('.pf-companion-yarn'), null);
    assert.equal(avatar.getAttribute('data-pose'), 'loaf', 'her pose as it was');
    assert.equal(avatar.parentNode, dock);
});

test('a new game ends the one before; a question asked brings her home', LIMIT, async () => {
    // Arrange
    const stopped = [];
    document.addEventListener('pf-companion-play:stop', (e) => stopped.push(e.detail.game));
    window.PfCompanionPlay.laser();

    // Act
    window.PfCompanionPlay.zoomies();
    document.dispatchEvent(new CustomEvent('pf-assistant:state', { detail: { state: 'thinking' } }));
    await tick(10000);

    // Assert
    assert.deepEqual(stopped, ['laser', 'zoomies']);
    assert.equal(document.querySelector('.pf-companion-laser'), null);
    assert.equal(window.PfCompanionPlay.isOut(), false);
});

test('the eyes follow the pointer, within ±1.35, and stop when told', LIMIT, () => {
    // Arrange
    window.PfCompanionPlay.follow(true);
    const move = new window.Event('mousemove');
    move.clientX = 1000;
    move.clientY = 32;   // level with her eyes: the test DOM draws her 64 px at 0,0

    // Act
    document.dispatchEvent(move);

    // Assert — far to the right: the whole reach, along x
    assert.equal(avatar.style.getPropertyValue('--pf-look-x'), '1.35');
    assert.equal(avatar.style.getPropertyValue('--pf-look-y'), '0.00');

    // Act
    window.PfCompanionPlay.follow(false);

    // Assert
    assert.equal(avatar.style.getPropertyValue('--pf-look-x'), '');
});

test('attach: an element of the application\'s own, not the framework\'s dock; detach puts her back at once', LIMIT, () => {
    // Arrange — a component's own element, no PfCompanion dock in use
    window.PfCompanion.unmount();
    const own = document.createElement('div');
    document.body.appendChild(own);
    const pet = document.createElement('span');
    pet.className = 'pf-assistant-avatar';
    own.appendChild(pet);
    window.PfCompanionPlay.attach(own);

    // Act
    window.PfCompanionPlay.zoomies();
    const outside = pet.parentNode.className;
    window.PfCompanionPlay.detach();

    // Assert
    assert.equal(outside, 'pf-companion-runner', 'she left the component\'s element');
    assert.equal(pet.parentNode, own, 'back in her place, without the walk home');
    assert.equal(own.hasAttribute('data-pf-companion-away'), false);
    assert.equal(window.PfCompanionPlay.playing(), null);
    assert.equal(document.querySelector('.pf-companion-runner'), null, 'nothing left on the page');
});

test('detach when her place is gone: the runner is removed, nothing orphaned on the page', LIMIT, () => {
    // Arrange — the component unmounts while she plays
    window.PfCompanion.unmount();
    const own = document.createElement('div');
    document.body.appendChild(own);
    const pet = document.createElement('span');
    pet.className = 'pf-assistant-avatar';
    own.appendChild(pet);
    window.PfCompanionPlay.attach(own);
    window.PfCompanionPlay.zoomies();
    document.body.removeChild(own);

    // Act
    window.PfCompanionPlay.detach();

    // Assert
    assert.equal(document.querySelector('.pf-companion-runner'), null);
    assert.equal(window.PfCompanionPlay.isOut(), false);
});

test('state: on the avatar without pfAssistant, and a question asked ends the game', LIMIT, async () => {
    // Arrange
    window.PfCompanionPlay.laser();

    // Act
    window.PfCompanionPlay.state('thinking');
    await tick(10000);

    // Assert
    assert.equal(avatar.getAttribute('data-state'), 'thinking');
    assert.equal(avatar.classList.contains('pf-assistant--thinking'), true);
    assert.equal(window.PfCompanionPlay.playing(), null, 'the question ended the game');
    assert.equal(avatar.parentNode, dock);
});

test('stopped in the middle of playing, she has her own pose back, not the game\'s', LIMIT, async () => {
    // Arrange
    avatar.setAttribute('data-pose', 'loaf');
    window.PfCompanionPlay.yarn();
    await tick(2500);
    const playing = avatar.getAttribute('data-pose');

    // Act
    window.PfCompanionPlay.stop();
    await tick(10000);

    // Assert
    assert.equal(playing, 'belly-up');
    assert.equal(avatar.getAttribute('data-pose'), 'loaf');
});

test('the yarn ball is drawn, and what plays outside the dock is put on the page\'s root', LIMIT, () => {
    // Act
    window.PfCompanionPlay.yarn();

    // Assert — a body with a transform would make position: fixed its own
    assert.equal(document.querySelector('.pf-companion-yarn').parentNode, document.documentElement);
    assert.equal(document.querySelector('.pf-companion-runner').parentNode, document.documentElement);
    assert.ok(document.querySelector('.pf-companion-yarn').innerHTML.includes('<svg'), 'drawn, not an emoji a system may lack');
});

test('laser: a click on a control is the page\'s; the badge, a right click and a double click stop it', LIMIT, async () => {
    // Arrange — a Stop button of the page's own
    const own = document.createElement('button');
    own.className = 'their-stop';
    own.closest = (selector) => (selector.includes('button') ? own : null);
    document.body.appendChild(own);
    const click = (target) => {
        const e = new window.Event('click');
        e.target = target;
        e.clientX = 600;
        e.clientY = 300;
        e.preventDefault = () => { e.prevented = true; };
        e.stopPropagation = () => {};
        document.dispatchEvent(e);

        return e;
    };

    // Act / Assert — the control's click goes through, the laser keeps playing
    window.PfCompanionPlay.laser();
    const onButton = click(own);
    assert.equal(onButton.prevented, undefined, 'not taken by the laser');
    assert.equal(avatar.hasAttribute('data-gait'), false, 'no pounce');
    const badge = document.querySelector('.pf-companion-laser-stop');
    assert.ok(badge, 'a badge to stop it');
    assert.match(badge.textContent, /Esc/);

    // The badge stops it
    badge.dispatchEvent(new window.Event('click'));
    assert.equal(window.PfCompanionPlay.playing(), null);
    assert.equal(document.querySelector('.pf-companion-laser-stop'), null, 'the badge goes with it');

    // A right click stops it
    window.PfCompanionPlay.laser();
    const right = new window.Event('contextmenu');
    right.preventDefault = () => {};
    document.dispatchEvent(right);
    assert.equal(window.PfCompanionPlay.playing(), null);

    // A double click stops it
    window.PfCompanionPlay.laser();
    document.dispatchEvent(new window.Event('dblclick'));
    assert.equal(window.PfCompanionPlay.playing(), null);
    await tick(10000);
});
