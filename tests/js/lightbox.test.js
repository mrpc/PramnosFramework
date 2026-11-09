/**
 * The picture viewer of the conversations (`resources/js/conversations/lightbox.js`): the zoom's
 * arithmetic — fitted, zoomed around a point, panned without a gap, toggled — and the viewer
 * opening over the page, closing with ×, Escape and a click outside, and leaving a Ctrl click to
 * the browser.
 *
 * Run:
 *   node --test tests/js/lightbox.test.js
 */
'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { createDom, Event } = require('../../resources/js/pramnos-testing');

const load = () => import(pathToFileURL(path.join(__dirname, '..', '..', 'resources', 'js', 'conversations', 'lightbox.js')).href);

/** A 2000×1000 picture in a 1000×800 view: fitted at half its size. */
const natural = { w: 2000, h: 1000 };
const view = { w: 1000, h: 800 };

/** A picture is fitted, never above its own size; a small one stays 1:1. */
test('a picture is fitted to the view, never enlarged', async () => {
    // Arrange
    const { fitScale } = await load();

    // Act
    const large = fitScale(natural, view);
    const small = fitScale({ w: 200, h: 100 }, view);

    // Assert
    assert.equal(large, 0.5);
    assert.equal(small, 1);
});

/**
 * Zooming keeps the point under the pointer where it is; it stops at fitted and at 800%; a
 * picture smaller than the view along an axis is centred on it, and a larger one shows no gap.
 */
test('zooming keeps the point under the pointer, within fitted and 800%', async () => {
    // Arrange
    const { zoomAt, clampPan, MAX_SCALE } = await load();
    const fitted = clampPan({ scale: 0.5, x: 0, y: 0 }, natural, view);
    const point = { x: 600, y: 400 };

    // Act
    const zoomed = zoomAt(fitted, 1, point, natural, view);
    const tooFar = zoomAt(fitted, 50, point, natural, view);
    const tooNear = zoomAt(zoomed, 0.01, point, natural, view);
    const dragged = clampPan({ scale: 1, x: 500, y: -5000 }, natural, view);

    // Assert
    assert.deepEqual(fitted, { scale: 0.5, x: 0, y: 150 }, 'centred top to bottom');
    const before = { x: (point.x - fitted.x) / fitted.scale, y: (point.y - fitted.y) / fitted.scale };
    const after = { x: (point.x - zoomed.x) / zoomed.scale, y: (point.y - zoomed.y) / zoomed.scale };
    assert.deepEqual(after, before, 'the same point of the picture under the pointer');
    assert.equal(tooFar.scale, MAX_SCALE);
    assert.equal(tooNear.scale, 0.5, 'never below fitted');
    assert.deepEqual(dragged, { scale: 1, x: 0, y: -200 }, 'no gap left or below');
});

/** A double click toggles fitted ↔ 1:1, or ↔ 200% for a picture that fits at its own size. */
test('a double click toggles between fitted and 1:1, or 200% for a small picture', async () => {
    // Arrange
    const { toggleScale } = await load();

    // Act
    const fromFit = toggleScale({ scale: 0.5 }, natural, view);
    const back = toggleScale({ scale: 1 }, natural, view);
    const small = toggleScale({ scale: 1 }, { w: 200, h: 100 }, view);

    // Assert
    assert.equal(fromFit, 1);
    assert.equal(back, 0.5);
    assert.equal(small, 2);
});

/**
 * The viewer: a click on a message's picture opens it over the page with its name, Download and
 * New tab pointing at the file and the picture shown from `src` where given; × closes it, Escape
 * closes it, a click on the backdrop closes it; a Ctrl click is the browser's.
 */
test('the viewer opens on a click, and closes with ×, Escape or the backdrop', async () => {
    // Arrange
    const { window, document } = createDom({ url: 'https://app.test/' });
    globalThis.document = document;
    const { installLightbox, openImage } = await load();
    const list = document.createElement('div');
    const link = document.createElement('a');
    link.className = 'pf-conv-thumb';
    link.setAttribute('href', 'https://app.test/f/7');
    const thumb = document.createElement('img');
    thumb.setAttribute('alt', 'plan.png');
    link.appendChild(thumb);
    list.appendChild(link);
    document.body.appendChild(list);
    const stop = installLightbox(list, { download: 'Λήψη' });
    const click = (target, extra = {}) => {
        const event = new Event('click', { bubbles: true, cancelable: true });
        Object.assign(event, { button: 0, ctrlKey: false, metaKey: false, shiftKey: false }, extra);
        target.dispatchEvent(event);

        return event;
    };

    try {
        // Act
        const ctrl = click(list.querySelector('img'), { ctrlKey: true });
        const afterCtrl = document.querySelector('.pf-conv-lightbox');
        click(list.querySelector('img'));
        const box = document.querySelector('.pf-conv-lightbox');
        const name = box?.querySelector('.pf-conv-lightbox-name')?.textContent;
        const links = [...(box?.querySelectorAll('a') || [])].map((a) => [a.textContent, a.getAttribute('href')]);
        click(box.querySelector('.pf-conv-lightbox-close'));
        const afterClose = document.querySelector('.pf-conv-lightbox');
        openImage('https://app.test/f/8', 'b.png', { src: 'blob:app/1' });
        const shown = document.querySelector('.pf-conv-lightbox img')?.getAttribute('src');
        const key = new Event('keydown', { bubbles: true });
        key.key = 'Escape';
        document.dispatchEvent(key);
        const afterEscape = document.querySelector('.pf-conv-lightbox');
        const again = openImage('https://app.test/f/9', 'c.png');
        click(again);
        const afterBackdrop = document.querySelector('.pf-conv-lightbox');

        // Assert
        assert.equal(ctrl.defaultPrevented, false, 'a Ctrl click is the browser\'s');
        assert.equal(afterCtrl, null);
        assert.ok(box, 'opened');
        assert.equal(name, 'plan.png');
        assert.deepEqual(links, [['Λήψη', 'https://app.test/f/7'], ['Open in a new tab', 'https://app.test/f/7']]);
        assert.equal(afterClose, null, '×');
        assert.equal(shown, 'blob:app/1', 'a picture already in hand');
        assert.equal(afterEscape, null, 'Escape');
        assert.equal(afterBackdrop, null, 'a click outside the picture');
    } finally {
        stop();
        delete globalThis.document;
        window.close?.();
    }
});
