/**
 * `data-pf-people` in `pf-utils.js`: a field of names, comma-separated, offered the people of the
 * page's list (ShareView::people()) as a name is typed.
 *
 * What has to hold: nothing is offered on an empty field or right after a comma; the name after
 * the last comma is matched by username or name, without those already written, each offer
 * carrying the names before it; and nothing is asked of the server.
 *
 * Run:
 *   node --test tests/js/pf-people.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

/** A page with the list of people, a field that suggests from it, and the script. */
function page() {
    const { window, document } = createDom();
    const list = document.createElement('datalist');
    list.setAttribute('id', 'pf-people');
    [['maria', 'Maria Papa'], ['nikos', 'Nikos Mavros'], ['manos', 'Manos Lykos']].forEach(([value, label]) => {
        const option = document.createElement('option');
        option.setAttribute('value', value);
        option.textContent = label;
        list.appendChild(option);
    });
    document.body.appendChild(list);
    const field = document.createElement('input');
    field.setAttribute('data-pf-people', 'pf-people');
    document.body.appendChild(field);
    const requests = [];
    window.fetch = (...args) => { requests.push(args); return Promise.resolve({}); };
    loadScript(SOURCE, window);
    const type = (value) => {
        field.value = value;
        field.dispatchEvent(new window.Event('input', { bubbles: true }));

        const offered = document.getElementById('pf-people-offered');

        return offered ? offered.querySelectorAll('option').map((o) => o.getAttribute('value')) : [];
    };

    return { document, field, type, requests };
}

/**
 * Offered only once something is typed after the last comma; matched by username or name; the
 * names already written lead each offer and are not offered again.
 *
 * A list on an empty field is every colleague at once, and one after a comma re-offers the person
 * just written: neither helps somebody typing a name.
 */
test('offers the people matching the name after the last comma, once something is typed', () => {
    // Arrange
    const ui = page();

    // Act
    const empty = ui.type('');
    const byUsername = ui.type('man');
    const byName = ui.type('mavr');
    const afterComma = ui.type('maria, ');
    const next = ui.type('maria, ma');

    // Assert
    assert.deepEqual(empty, [], 'nothing on an empty field');
    assert.deepEqual(byUsername, ['manos'], 'by username');
    assert.deepEqual(byName, ['nikos'], 'by name');
    assert.deepEqual(afterComma, [], 'nothing right after a comma');
    assert.deepEqual(next, ['maria, nikos', 'maria, manos'], 'the written name leads, and is not offered again; Mavros holds "ma" too');
    assert.equal(ui.field.getAttribute('list'), 'pf-people-offered', 'the field shows the offered list, not the whole one');
    assert.equal(ui.requests.length, 0, 'nothing is asked of the server');
});

/**
 * A field naming a list the page does not have is left alone.
 */
test('a field without its list is left alone', () => {
    // Arrange
    const ui = page();
    ui.document.getElementById('pf-people').remove();
    ui.field.setAttribute('data-pf-people', 'pf-nobody');

    // Act
    ui.type('ma');

    // Assert
    assert.equal(ui.field.getAttribute('list'), null);
});
