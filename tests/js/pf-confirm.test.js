/**
 * `data-confirm` in `pf-utils.js`: asked once, for the action — not at every click.
 *
 * On a link or a button it asks at the click. On a <form> it asks when the form is sent, once:
 * matched by `closest()` at every click, it asked at a click on a field, a box or a label inside
 * the form, and a Cancel there prevented the click — the box would not tick. A button with its
 * own `data-confirm` in such a form has asked already, so the form does not ask again.
 *
 * Run:
 *   node --test tests/js/pf-confirm.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

/** A form, `data-confirm` on it, with a checkbox and a submit button; confirm() answers `answer`. */
function page({ answer = true, buttonAsks = false } = {}) {
    const { window, document } = createDom();
    const asked = [];
    window.confirm = (message) => { asked.push(message); return answer; };
    const form = document.createElement('form');
    form.setAttribute('data-confirm', 'Move the invoice?');
    const box = document.createElement('input');
    box.setAttribute('type', 'checkbox');
    const button = document.createElement('button');
    button.setAttribute('type', 'submit');
    if (buttonAsks) { button.setAttribute('data-confirm', 'Really?'); }
    form.appendChild(box);
    form.appendChild(button);
    document.body.appendChild(form);
    loadScript(SOURCE, window);

    /** The form sent, as the browser fires it; whether it went. */
    const submit = (submitter) => {
        const event = new window.Event('submit', { bubbles: true, cancelable: true });
        event.submitter = submitter;
        form.dispatchEvent(event);
        return !event.defaultPrevented;
    };

    return { window, form, box, button, asked, submit };
}

/** A click, as the browser dispatches it; whether it was let through. */
function click(el, window) {
    const event = new window.Event('click', { bubbles: true, cancelable: true });
    el.dispatchEvent(event);
    return !event.defaultPrevented;
}

test('a click on a field inside a confirming form asks nothing and goes through', () => {
    // Arrange
    const ui = page({ answer: false });

    // Act
    const through = click(ui.box, ui.window);

    // Assert
    assert.deepEqual(ui.asked, [], 'a click on the checkbox asked');
    assert.equal(through, true, 'the checkbox could not be ticked');
});

test('sending the form asks once; Cancel keeps it from going', () => {
    // Arrange
    const ui = page({ answer: false });

    // Act — the button's click, then the submit it causes
    click(ui.button, ui.window);
    const sent = ui.submit(ui.button);

    // Assert
    assert.deepEqual(ui.asked, ['Move the invoice?'], 'asked more than once, or not at all');
    assert.equal(sent, false, 'a cancelled form went');
});

test('OK lets the form go', () => {
    // Arrange
    const ui = page({ answer: true });

    // Act
    const sent = ui.submit(ui.button);

    // Assert
    assert.equal(sent, true);
    assert.equal(ui.asked.length, 1);
});

test('a button with its own data-confirm asks at its click, and the form does not ask again', () => {
    // Arrange
    const ui = page({ answer: true, buttonAsks: true });

    // Act
    click(ui.button, ui.window);
    const sent = ui.submit(ui.button);

    // Assert
    assert.deepEqual(ui.asked, ['Really?']);
    assert.equal(sent, true);
});
