/**
 * pf-editor.js, the builtin editor — where it is drawn.
 *
 * What has to hold: a textarea written inside its `<label>` (no `for`) gets its editor after the
 * label, never inside it — a browser sends every click in a label to its first labelable element,
 * which would be the toolbar's first button, so a click into the text to type toggled bold — and
 * a click on the label's words puts the cursor in the text. A textarea outside a label is drawn
 * where it was, as before.
 *
 * Run:
 *   node --test tests/js/pf-editor.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');
const fs       = require('node:fs');
const { JSDOM } = require('jsdom');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-editor.js');

/** A form with the field as `html`, and the script run over it. */
function page(html) {
    const dom = new JSDOM('<!doctype html><html><body><form>' + html + '</form></body></html>', { runScripts: 'outside-only' });
    dom.window.document.execCommand = () => true;
    dom.window.eval(fs.readFileSync(SOURCE, 'utf8'));
    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));

    return dom.window;
}

test('a field inside its label gets its editor after the label, and the label\'s click reaches the text', () => {
    // Arrange
    const window = page('<label id="l">Email body <textarea name="body" data-pf-editor="builtin">hi</textarea></label>');
    const label = window.document.getElementById('l');
    const editor = window.document.querySelector('.pf-editor');
    const area = editor.querySelector('.pf-editor-area');

    // Act — jsdom does not move the focus to a contenteditable, so the call is what is watched
    let focused = false;
    area.focus = () => { focused = true; };
    label.click();

    // Assert
    assert.equal(label.contains(editor), false, 'the toolbar is inside the label: a click on the text presses its first button');
    assert.equal(label.nextElementSibling, editor, 'the editor is not where the field was');
    assert.equal(focused, true, 'the label\'s click did not put the cursor in the text');
    assert.equal(area.getAttribute('aria-label'), 'Email body', 'the text is not named by the label');
});

test('a field outside a label is drawn where it was', () => {
    // Arrange / Act
    const window = page('<p id="p"><textarea name="body" data-pf-editor="builtin"></textarea></p>');

    // Assert
    assert.equal(window.document.getElementById('p').firstElementChild.className, 'pf-editor');
});
