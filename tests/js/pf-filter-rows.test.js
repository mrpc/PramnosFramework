/**
 * `data-pf-filter-rows` in `pf-utils.js`: a search field over a table's rows.
 *
 * What has to hold: typing hides the rows that do not hold every word typed, matched on the row's
 * `data-pf-row-text` where it has one and on its text where not, ignoring case; clearing the field
 * shows them all again; a field naming no table does nothing.
 *
 * Run:
 *   node --test tests/js/pf-filter-rows.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

/** A table of three rows and a search field over it. */
function page(target = '#list') {
    const { window, document } = createDom();
    const table = document.createElement('table');
    table.setAttribute('id', 'list');
    const body = document.createElement('tbody');
    const rows = [['Professionals without a photo', 'professionals without a photo reminder'], ['Parents who paid', null], ['VIP', null]].map(([text, key]) => {
        const row = document.createElement('tr');
        row.textContent = text;
        if (key !== null) { row.setAttribute('data-pf-row-text', key); }
        body.appendChild(row);
        return row;
    });
    table.appendChild(body);
    document.body.appendChild(table);
    const field = document.createElement('input');
    field.setAttribute('data-pf-filter-rows', target);
    document.body.appendChild(field);
    loadScript(SOURCE, window);
    const search = (value) => {
        field.value = value;
        field.dispatchEvent(new window.Event('input', { bubbles: true }));
        return rows.map((r) => !r.hidden);
    };

    return { search };
}

test('typing keeps the rows holding every word, matched on their search text or their text', () => {
    // Arrange
    const { search } = page();

    // Act
    const reminder = search('REMINDER');
    const two = search('paid parents');
    const none = search('nobody');
    const all = search('');

    // Assert
    assert.deepEqual(reminder, [true, false, false], 'found by the row\'s search text, whatever the case');
    assert.deepEqual(two, [false, true, false], 'every word, in any order');
    assert.deepEqual(none, [false, false, false]);
    assert.deepEqual(all, [true, true, true], 'an empty field shows them all');
});

test('a field naming no table does nothing', () => {
    // Arrange
    const { search } = page('#missing');

    // Act
    const shown = search('vip');

    // Assert
    assert.deepEqual(shown, [true, true, true]);
});
