/**
 * `pf-audience.js`: the audience builder and picker.
 *
 * What has to hold: the state in the hidden field draws as conditions; every change writes the
 * JSON back, leaving out a condition still missing its value; the count is asked with the page's
 * token and drawn, and an older answer arriving late does not overwrite a newer one; operators
 * and values follow the attribute's type; the picker keeps segments and groups in and out and a
 * consent list, and shows what each step took out.
 *
 * Run:
 *   node --test tests/js/pf-audience.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, stubFetch } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-audience.js');

/** The window of the page under test, whose Event the script's elements take. */
let current = null;

/** Let the debounce and the fetch promises run. */
const settle = () => new Promise((done) => setTimeout(done, 15));

const CATALOG = {
    attributes: [
        { key: 'usertype', label: 'Account type', type: 'list', group: 'Account', operators: ['is', 'is_not', 'one_of', 'not_one_of', 'empty', 'not_empty'],
            values: [{ value: 1, label: 'Parent' }, { value: 2, label: 'Professional' }], breakdown: true },
        { key: 'language', label: 'Language', type: 'text', group: 'Account', operators: ['is', 'contains', 'empty'], values: [], breakdown: true },
        { key: 'lastlogin', label: 'Last sign-in', type: 'date', group: 'Account', operators: ['after', 'between', 'more_than_days_ago', 'empty'], values: [], breakdown: false },
        { key: 'interests', label: 'Interests', type: 'many', group: '', operators: ['any_of', 'none_of'], values: [], breakdown: true },
    ],
    operators: { is: 'is', is_not: 'is not', one_of: 'is one of', empty: 'is empty', contains: 'contains', after: 'is after',
        between: 'is between', more_than_days_ago: 'more than … days ago', in: 'is in segment', not_in: 'is not in segment', any_of: 'has any of' },
    segments: [{ id: 4, name: 'Dormant', members: 40 }],
    groups: [{ id: 7, name: 'Press' }],
    consent: [{ id: 3, name: 'Newsletter', channel: 'email' }],
};

/** A page with one builder of this mode and state, and the API stubbed. */
function page({ mode = 'filter', state = null, answer = { count: 1234, sample: [{ id: 5, name: 'Anna', url: '/admin/Users/view/5' }] } } = {}) {
    const { window, document } = createDom({ meta: { csrf: 't0ken' } });
    const root = document.createElement('div');
    root.setAttribute('data-pf-audience', mode);
    root.setAttribute('data-api', '/admin/Audience');
    root.setAttribute('data-sample', '3');
    root.setAttribute('data-delay', '0');
    root.setAttribute('data-breakdown', '');
    root.setAttribute('data-catalog', JSON.stringify(CATALOG));
    root.setAttribute('data-words', JSON.stringify({ picked: '%s picked', excluded: '−%s excepted', withoutConsent: '−%s without consent', and: 'and', or: 'or' }));
    const hidden = document.createElement('input');
    hidden.setAttribute('data-pf-audience-state', '');
    hidden.value = state === null ? '' : JSON.stringify(state);
    root.appendChild(hidden);
    const area = document.createElement('div');
    area.setAttribute('data-pf-audience-area', '');
    root.appendChild(area);
    const live = document.createElement('aside');
    live.setAttribute('data-pf-audience-live', '');
    root.appendChild(live);
    document.body.appendChild(root);
    window.fetch = stubFetch({ 'POST /admin/Audience/preview': answer });
    current = window;
    const changes = [];
    root.addEventListener('pf:audience-change', (event) => changes.push(event.detail));

    loadScript(SOURCE, window);

    return { window, document, root, hidden, live, changes, sent: () => window.fetch.calls.map((c) => new URLSearchParams(c.body)) };
}

/** Choose a value in a select and tell the script. */
function choose(select, value) {
    select.value = String(value);
    select.dispatchEvent(new current.Event('change'));
}

test('the stored filter draws as conditions and is asked about with the page token', async () => {
    // Arrange
    const state = { match: 'all', conditions: [{ attribute: 'usertype', op: 'is', value: 2 }] };

    // Act
    const { root, sent, window, live } = page({ state });
    await settle();

    // Assert
    const rows = root.querySelectorAll('[data-pf-audience-condition]');
    assert.equal(rows.length, 1);
    assert.equal(rows[0].querySelector('[data-pf-audience-attribute]').value, 'usertype');
    assert.equal(rows[0].querySelector('[data-pf-audience-op]').value, 'is');
    assert.equal(rows[0].querySelector('[data-pf-audience-value]').value, '2');
    assert.equal(window.fetch.calls[0].headers['X-CSRF-Token'], 't0ken');
    assert.deepEqual(JSON.parse(sent()[0].get('filter')), state);
    assert.equal(sent()[0].get('sample'), '3');
    assert.match(live.querySelector('[data-pf-audience-count]').textContent, /1.?234/);
    assert.equal(live.querySelector('[data-pf-audience-sample] a').getAttribute('href'), '/admin/Users/view/5');
});

test('a new condition is left out of the JSON until it has a value', async () => {
    // Arrange
    const { root, hidden, window, changes } = page();
    await settle();

    // Act
    root.querySelector('[data-pf-audience-add]').click();
    const what = root.querySelector('[data-pf-audience-attribute]');
    choose(what, 'language');
    const before = JSON.parse(hidden.value);
    const value = root.querySelector('[data-pf-audience-value]');
    value.value = 'el';
    value.dispatchEvent(new window.Event('input'));
    await settle();

    // Assert
    assert.deepEqual(before.conditions, [], 'language "is" with no value yet');
    assert.deepEqual(JSON.parse(hidden.value).conditions, [{ attribute: 'language', op: 'is', value: 'el' }]);
    assert.deepEqual(changes[changes.length - 1].conditions[0].value, 'el');
});

test('operators follow the type: no value for "is empty", ticked choices for "one of", days for a date', async () => {
    // Arrange
    const { root, hidden, window } = page({ state: { match: 'all', conditions: [{ attribute: 'usertype', op: 'is', value: 1 }, { attribute: 'lastlogin', op: 'more_than_days_ago', value: 30 }] } });
    await settle();
    const [first, second] = root.querySelectorAll('[data-pf-audience-condition]');

    // Act
    choose(first.querySelector('[data-pf-audience-op]'), 'one_of');
    const boxes = root.querySelectorAll('[data-pf-audience-choice]');
    boxes[1].checked = true;
    boxes[1].dispatchEvent(new window.Event('change'));
    const days = second.querySelector('.pf-audience-days input');
    const rows = root.querySelectorAll('[data-pf-audience-condition]');
    choose(rows[1].querySelector('[data-pf-audience-op]'), 'empty');

    // Assert
    assert.equal(boxes.length, 2, 'one box per labelled value');
    assert.equal(days.value, '30');
    assert.equal(root.querySelectorAll('[data-pf-audience-condition]')[1].querySelector('[data-pf-audience-value]'), null);
    assert.deepEqual(JSON.parse(hidden.value).conditions, [
        { attribute: 'usertype', op: 'one_of', value: [2] },
        { attribute: 'lastlogin', op: 'empty' },
    ]);
});

test('a range takes two values, and a list without labels is written with commas', async () => {
    // Arrange
    const { root, hidden, window } = page({ state: { match: 'all', conditions: [{ attribute: 'lastlogin', op: 'between' }, { attribute: 'interests', op: 'any_of' }] } });
    await settle();
    const [range, many] = root.querySelectorAll('[data-pf-audience-condition]');

    // Act
    const [from, to] = range.querySelectorAll('input');
    from.value = '2026-01-01';
    from.dispatchEvent(new window.Event('input'));
    const half = JSON.parse(hidden.value).conditions;
    to.value = '2026-01-31';
    to.dispatchEvent(new window.Event('change'));
    const list = many.querySelector('input');
    list.value = '3, 5,';
    list.dispatchEvent(new window.Event('input'));

    // Assert
    assert.deepEqual(half, [], 'half a range is not sent');
    assert.deepEqual(JSON.parse(hidden.value).conditions, [
        { attribute: 'lastlogin', op: 'between', value: ['2026-01-01', '2026-01-31'] },
        { attribute: 'interests', op: 'any_of', value: [3, 5] },
    ]);
});

test('a segment is a condition of its own, and a nested group joins with its own word', async () => {
    // Arrange
    const { root, hidden } = page();
    await settle();

    // Act
    root.querySelector('[data-pf-audience-add]').click();
    choose(root.querySelector('[data-pf-audience-attribute]'), '__segment');
    choose(root.querySelector('[data-pf-audience-condition] [data-pf-audience-value]'), 4);
    choose(root.querySelector('[data-pf-audience-op]'), 'not_in');
    root.querySelector('[data-pf-audience-add-group]').click();
    const nested = () => root.querySelector('.pf-audience-group[data-depth="1"]');   // redrawn on each change of shape
    choose(nested().querySelector('[data-pf-audience-attribute]'), 'usertype');
    choose(nested().querySelector('[data-pf-audience-value]'), 1);

    // Assert
    assert.deepEqual(JSON.parse(hidden.value), { match: 'all', conditions: [
        { segment: 4, op: 'not_in' },
        { match: 'any', conditions: [{ attribute: 'usertype', op: 'is', value: 1 }] },
    ] });
    assert.ok(root.querySelector('.pf-audience-join'), 'the word between two conditions');
});

test('removing a condition takes it out; the "all" or "any" of a group is kept', async () => {
    // Arrange
    const { root, hidden } = page({ state: { match: 'all', conditions: [{ attribute: 'language', op: 'empty' }, { attribute: 'usertype', op: 'is', value: 2 }] } });
    await settle();

    // Act
    root.querySelector('[data-pf-audience-remove]').click();
    choose(root.querySelector('.pf-audience-match'), 'any');

    // Assert
    assert.deepEqual(JSON.parse(hidden.value), { match: 'any', conditions: [{ attribute: 'usertype', op: 'is', value: 2 }] });
});

test('a breakdown is asked for and drawn; dates are not offered for one', async () => {
    // Arrange
    const { root, live, sent } = page({ answer: { count: 3, sample: [], breakdown: [{ value: 'el', label: 'Greek', count: 2 }, { value: null, label: '(empty)', count: 1 }] } });
    await settle();
    const by = live.querySelector('[data-pf-audience-by]');

    // Act
    choose(by, 'language');
    await settle();

    // Assert
    assert.deepEqual(by.querySelectorAll('option').map((o) => o.getAttribute('value')), ['', 'usertype', 'language', 'interests']);
    assert.equal(sent()[sent().length - 1].get('breakdown'), 'language');
    const items = live.querySelectorAll('[data-pf-audience-breakdown] li');
    assert.equal(items.length, 2);
    assert.match(items[0].textContent, /Greek 2/);
    assert.ok(root);
});

test('a refusal is shown as said, and an answer to an older question is dropped', async () => {
    // Arrange
    let call = 0;
    const answers = [
        () => new Promise((done) => setTimeout(() => done({ status: 200, body: { count: 111, sample: [] } }), 30)),
        () => ({ status: 422, body: { error: 'filter.0: no attribute x.' } }),
    ];
    const { root, live, window } = page({ answer: () => answers[Math.min(call++, 1)]() });

    // Act
    root.querySelector('[data-pf-audience-add]').click();
    choose(root.querySelector('[data-pf-audience-attribute]'), 'language');
    await new Promise((done) => setTimeout(done, 60));

    // Assert
    assert.equal(live.querySelector('[role="alert"]').textContent, 'filter.0: no attribute x.');
    assert.equal(live.querySelector('[data-pf-audience-count]'), null, 'the late 111 did not overwrite it');
    assert.ok(window);
});

test('the picker keeps segments and groups in and out and a consent list, and shows what each step took out', async () => {
    // Arrange
    const { root, hidden, live, sent } = page({ mode: 'picker', state: { include: [{ segment: 4 }] },
        answer: { included: 4416, excluded: 812, consent: 640, final: 2964, sample: [] } });
    await settle();

    // Act
    choose(root.querySelector('[data-pf-audience-pick="include"]'), 'g7');
    choose(root.querySelector('[data-pf-audience-pick="include"]'), 'g7');
    choose(root.querySelector('[data-pf-audience-pick="exclude"]'), 's4');
    choose(root.querySelector('[data-pf-audience-consent]'), 3);
    await settle();

    // Assert
    const state = JSON.parse(hidden.value);
    assert.deepEqual(state.include, [{ segment: 4 }, { group: 7 }], 'a pick twice is kept once');
    assert.deepEqual(state.exclude, [{ segment: 4 }]);
    assert.equal(state.consent, 3);
    assert.deepEqual(state.filter, { match: 'all', conditions: [] });
    assert.deepEqual(JSON.parse(sent()[sent().length - 1].get('picker')), state);
    assert.equal(root.querySelectorAll('[data-pf-audience-chip]').length, 3);
    assert.match(live.querySelector('[data-pf-audience-count]').textContent, /2.?964/);
    assert.match(live.querySelector('[data-pf-audience-steps]').textContent, /4.?416 picked · −812 excepted · −640 without consent/);
});

test('a chip is taken out with its button', async () => {
    // Arrange
    const { root, hidden } = page({ mode: 'picker', state: { include: [{ segment: 4 }, { group: 7 }], consent: 3 } });
    await settle();

    // Act
    root.querySelector('.pf-audience-chip-remove').click();

    // Assert
    assert.deepEqual(JSON.parse(hidden.value).include, [{ group: 7 }]);
    assert.equal(root.querySelector('[data-pf-audience-consent]').value, '3');
});
