/**
 * `pf-image.js`: a picture cropped and sized in the browser before it is sent.
 *
 * What has to hold: choosing a picture in a `data-pf-image` input opens a crop dialog held to the
 * input's ratio; its buttons turn, flip, zoom and reset the picture; "Use this picture" puts the
 * result — at the size asked for, never enlarged by a maximum — into the input in place of the
 * file chosen, shows it, and sends the form when asked; Cancel and Escape empty the input, so
 * nothing leaves uncropped; without Cropper.js, or with `data-crop="0"`, the picture is only
 * resized; and a file that is not a picture is left alone.
 *
 * Run:
 *   node --test tests/js/pf-image.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, Event } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-image.js');

/**
 * A page with a picture input, a preview, a form, a fake Cropper.js and fake canvases: every
 * canvas drawn records its size and fill, and every blob is the type it was asked for.
 */
function page({ attributes = {}, cropper = true } = {}) {
    const { window, document } = createDom({ url: 'https://app.test/Account/profile' });
    const form = document.createElement('form');
    let submitted = 0;
    form.requestSubmit = () => { submitted++; };
    const input = document.createElement('input');
    input.setAttribute('type', 'file');
    input.setAttribute('name', 'photo');
    input.setAttribute('data-pf-image', '');
    Object.entries(attributes).forEach(([name, value]) => input.setAttribute(name, value));
    input.form = form;
    form.appendChild(input);
    const preview = document.createElement('img');
    preview.setAttribute('id', 'preview');
    preview.hidden = true;
    document.body.append(form, preview);

    const canvases = [];
    const create = document.createElement;
    document.createElement = (tag) => {
        const element = create(tag);
        if (tag === 'canvas') {
            const drawn = { fill: null };
            element.getContext = () => ({
                fillRect() { drawn.fill = this.fillStyle; },
                drawImage(source, x, y, width, height) { drawn.from = source; drawn.width = width; drawn.height = height; },
            });
            element.toBlob = (done, type, quality) => done({ type, quality, size: 1 });
            element.drawn = drawn;
            canvases.push(element);
        }
        if (tag === 'img') {
            // A picture of 3000×2000 that loads as soon as it is given an address.
            Object.defineProperty(element, 'src', {
                set(value) { this._src = value; element.naturalWidth = 3000; element.naturalHeight = 2000; if (element.onload) { element.onload(); } },
                get() { return this._src; },
            });
        }
        if (tag === 'dialog') {
            element.showModal = () => { element.open = true; };
            element.close = () => { element.open = false; };
        }
        return element;
    };
    document.createElement.original = create;

    const croppers = [];
    if (cropper) {
        window.Cropper = class {
            constructor(image, options) { this.image = image; this.options = options; this.calls = []; this.flipped = 1; croppers.push(this); }
            rotate(degrees) { this.calls.push(['rotate', degrees]); }
            scaleX(value) { this.calls.push(['scaleX', value]); this.flipped = value; }
            getData() { return { scaleX: this.flipped }; }
            zoom(by) { this.calls.push(['zoom', by]); }
            reset() { this.calls.push(['reset']); }
            destroy() { this.destroyed = true; }
            getCroppedCanvas() { return { width: 1800, height: 1800, cropped: true }; }
        };
    }
    window.URL = { createObjectURL: (file) => 'blob:' + (file.name || 'x'), revokeObjectURL() {} };
    window.File = class { constructor(parts, name, options) { this.name = name; this.type = options.type; } };
    window.DataTransfer = class {
        constructor() { const list = []; this.items = { add: (file) => list.push(file) }; this.files = list; }
    };

    loadScript(SOURCE, window);

    const told = [];
    for (const name of ['pf:image-ready', 'pf:image-cancel']) {
        input.addEventListener(name, (event) => told.push([name, event.detail]));
    }
    /** Choose a file in the input, as a person would. */
    const choose = (file = { name: 'me.HEIC.png', type: 'image/png' }) => {
        input.files = [file];
        input.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const dialog = () => document.querySelector('dialog');
    const press = (action) => {
        const target = dialog().querySelector(`[data-pf-image-action="${action}"]`);
        dialog()._fire(Object.assign(new Event('click'), { target }));
    };

    return { window, document, input, preview, canvases, croppers, told, choose, dialog, press, submitted: () => submitted };
}

test('a picture opens the crop dialog, held to the ratio, with the words given', () => {
    // Arrange
    const { choose, croppers, dialog } = page({ attributes: { 'data-aspect': '16/9', 'data-round': '1', 'data-words': '{"Cancel":"Άκυρο"}' } });

    // Act
    choose();

    // Assert
    assert.equal(croppers.length, 1);
    assert.equal(croppers[0].options.aspectRatio, 16 / 9);
    assert.equal(croppers[0].options.viewMode, 1, 'the crop stays inside the picture');
    assert.equal(dialog().open, true);
    assert.equal(dialog().classList.contains('pf-image-round'), true, 'the round guide');
    assert.equal(dialog().querySelector('[data-pf-image-action="cancel"]').textContent, 'Άκυρο');
    assert.equal(dialog().querySelector('[data-pf-image-action="use"]').textContent, 'Use this picture', 'the rest untranslated');
});

test('the ratio is read as a number, a fraction or a ratio, and free otherwise', () => {
    // Arrange
    const { window } = page();

    // Act
    const read = ['1', '1.5', '4:3', '16 / 9', '', 'square', '0'].map(window.pfImage.ratio);

    // Assert
    assert.deepEqual(read.slice(0, 4), [1, 1.5, 4 / 3, 16 / 9]);
    assert.equal(read.slice(4).every(Number.isNaN), true, 'free: NaN is what Cropper.js takes for no ratio');
});

test('the buttons turn, flip back and forth, zoom and reset the picture', () => {
    // Arrange
    const { choose, croppers, press } = page();
    choose();

    // Act
    ['rotate-left', 'rotate-right', 'flip', 'flip', 'zoom-in', 'zoom-out', 'reset'].forEach(press);

    // Assert
    assert.deepEqual(croppers[0].calls, [['rotate', -90], ['rotate', 90], ['scaleX', -1], ['scaleX', 1], ['zoom', 0.1], ['zoom', -0.1], ['reset']]);
});

test('using the picture puts the result in the input, at most the size asked, shown and sent', () => {
    // Arrange
    const { choose, press, input, preview, canvases, croppers, told, dialog, submitted } = page({
        attributes: { 'data-aspect': '1', 'data-max-width': '512', 'data-max-height': '512', 'data-preview': '#preview', 'data-submit': '1' },
    });
    choose();

    // Act
    press('use');

    // Assert
    const drawn = canvases[0].drawn;
    assert.deepEqual([drawn.width, drawn.height], [512, 512], 'the 1800px crop brought down to the maximum');
    assert.equal(drawn.from.cropped, true, 'drawn from the crop');
    assert.equal(drawn.fill, '#fff', 'JPEG has no transparency: drawn on white');
    assert.equal(input.files.length, 1);
    assert.equal(input.files[0].name, 'me.HEIC.jpg', 'the extension follows the type');
    assert.equal(input.files[0].type, 'image/jpeg');
    assert.equal(preview.src, 'blob:me.HEIC.jpg');
    assert.equal(preview.hidden, false);
    assert.equal(told[0][0], 'pf:image-ready');
    assert.equal(submitted(), 1);
    assert.equal(croppers[0].destroyed, true);
    assert.equal(dialog(), null, 'the dialog is gone');
});

test('cancel, or Escape, empties the input and says so', () => {
    // Arrange
    const first = page();
    const second = page();
    let prevented = false;

    // Act
    first.choose();
    first.input.value = 'C:\\fakepath\\me.png';
    first.press('cancel');
    second.choose();
    second.dialog()._fire(Object.assign(new Event('cancel'), { preventDefault() { prevented = true; } }));

    // Assert
    assert.equal(first.input.value, '', 'nothing uncropped is sent');
    assert.deepEqual(first.told.map(([name]) => name), ['pf:image-cancel']);
    assert.equal(first.dialog(), null);
    assert.equal(prevented, true, 'the browser does not close it behind the script');
    assert.deepEqual(second.told.map(([name]) => name), ['pf:image-cancel']);
});

test('without Cropper.js, or with data-crop="0", the picture is only resized, in the type asked', () => {
    // Arrange
    const none = page({ cropper: false, attributes: { 'data-max-width': '1000' } });
    const off = page({ attributes: { 'data-crop': '0', 'data-width': '600', 'data-type': 'image/png' } });

    // Act
    none.choose();
    off.choose({ name: 'map.webp', type: 'image/webp' });

    // Assert
    assert.equal(none.dialog(), null);
    assert.deepEqual([none.canvases[0].drawn.width, none.canvases[0].drawn.height], [1000, 667], 'in proportion');
    assert.equal(off.croppers.length, 0, 'no dialog although Cropper.js is there');
    assert.deepEqual([off.canvases[0].drawn.width, off.canvases[0].drawn.height], [600, 400]);
    assert.equal(off.canvases[0].drawn.fill, null, 'PNG keeps its transparency');
    assert.equal(off.input.files[0].name, 'map.png');
    assert.equal(off.submitted(), 0, 'not sent unless asked');
});

test('the size: exact sides are kept, a maximum never enlarges, an unknown type is JPEG', () => {
    // Arrange
    const { window, choose, canvases, input } = page({ cropper: false, attributes: { 'data-type': 'image/gif' } });
    const size = window.pfImage.size;

    // Act
    choose();

    // Assert
    assert.deepEqual(JSON.parse(JSON.stringify(size(200, 100, { maxWidth: 1024, maxHeight: 1024 }))), { width: 200, height: 100 }, 'not enlarged');
    assert.deepEqual(JSON.parse(JSON.stringify(size(200, 100, { width: 400, height: 300 }))), { width: 400, height: 300 }, 'exactly, enlarged');
    assert.deepEqual(JSON.parse(JSON.stringify(size(3000, 2000, { height: 500, maxWidth: 600 }))), { width: 600, height: 400 });
    assert.deepEqual([canvases[0].drawn.width, canvases[0].drawn.height], [3000, 2000], 'no size asked: its own');
    assert.equal(input.files[0].type, 'image/jpeg');
});

test('a file that is not a picture, or no file, is left for the server', () => {
    // Arrange
    const { choose, input, dialog, canvases, told } = page();
    const original = { name: 'cv.pdf', type: 'application/pdf' };

    // Act
    choose(original);
    input.files = [];
    input.dispatchEvent(new Event('change', { bubbles: true }));

    // Assert
    assert.equal(dialog(), null);
    assert.equal(canvases.length, 0);
    assert.deepEqual(told, []);
});
