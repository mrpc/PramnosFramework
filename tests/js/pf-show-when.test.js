/**
 * `data-pf-show-when` in `pf-utils.js`: a field shown only while another field of its form says so.
 *
 * What has to hold: on load, each marked element shows only when the named field holds one of its
 * values; changing the field shows and hides them again; an element naming no field of its form is
 * left as it is.
 *
 * Run:
 *   node --test tests/js/pf-show-when.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

/** A form with a cadence select and three fields that depend on it. */
function page() {
    const { window, document } = createDom();
    const form = document.createElement('form');
    const select = document.createElement('select');
    select.setAttribute('name', 'cadence');
    ['daily', 'weekly', 'once'].forEach((value) => {
        const option = document.createElement('option');
        option.setAttribute('value', value);
        option.value = value;
        select.appendChild(option);
    });
    select.value = 'daily';
    form.appendChild(select);
    const fields = {};
    [['at', 'cadence:daily weekly'], ['weekday', 'cadence:weekly'], ['stray', 'nothing:here']].forEach(([id, when]) => {
        const label = document.createElement('label');
        label.setAttribute('data-pf-show-when', when);
        form.appendChild(label);
        fields[id] = label;
    });
    document.body.appendChild(form);
    loadScript(SOURCE, window);
    const shown = () => ['at', 'weekday', 'stray'].map((id) => !fields[id].hidden);
    const choose = (value) => {
        select.value = value;
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
        return shown();
    };

    return { shown, choose };
}

test('each field shows only while the cadence uses it, and follows a change', () => {
    // Arrange
    const { shown, choose } = page();

    // Act
    const onLoad = shown();
    const weekly = choose('weekly');
    const once   = choose('once');

    // Assert
    assert.deepEqual(onLoad, [true, false, true], 'daily: the time only; a field naming nothing is left alone');
    assert.deepEqual(weekly, [true, true, true]);
    assert.deepEqual(once, [false, false, true]);
});
