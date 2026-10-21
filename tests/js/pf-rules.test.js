/**
 * `pf-rules.js`: a rule's editor.
 *
 * What has to hold: the stored definition draws as its trigger, steps and limits; every change
 * writes the definition back as JSON; a step is added from the menu of kinds, named after its
 * place and joined after the last one that ends; removing a step ends whatever led to it; each kind
 * has its own fields; a branch on conditions holds an audience builder whose changes become its
 * filter; events are picked from the searchable list; sending hours are kept only when both ends
 * are set on the 24-hour clock; limits are added per channel; each folded card says what it holds.
 *
 * Run:
 *   node --test tests/js/pf-rules.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SOURCE   = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-rules.js');
const AUDIENCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-audience.js');

const CATALOG = {
    actions: [
        { key: 'email', label: 'Send an email', channel: 'email', params: [{ name: 'template', label: 'Template', type: 'text', options: [] }] },
        { key: 'site_message', label: 'Show on the site', channel: 'site', params: [
            { name: 'kind', label: 'Kind', type: 'select', options: [{ value: 'banner', label: 'Banner' }, { value: 'card', label: 'Card' }] },
            { name: 'body', label: 'Message', type: 'textarea', options: [] }] },
        { key: 'group_add', label: 'Put in a group', channel: null, params: [{ name: 'group', label: 'Group', type: 'group', options: [] }] },
        { key: 'attribute', label: 'Set', channel: null, params: [{ name: 'attribute', label: 'Attribute', type: 'attribute', options: [] }, { name: 'n', label: 'N', type: 'number', options: [] }] },
    ],
    attributes: [{ key: 'language', label: 'Language', type: 'text', group: '', operators: ['is'], values: [], breakdown: true }],
    operators: { is: 'is' },
    segments: [{ id: 4, name: 'Dormant', members: 3 }],
    groups: [{ id: 7, name: 'Press' }],
    consent: [{ id: 3, name: 'Newsletter', channel: 'email' }],
    events: [{ key: 'payment.made', label: 'Paid', group: '' }],
};

let current = null;

/** A page with an editor holding this definition. */
function page(definition = null, withAudience = true) {
    const { window, document } = createDom();
    current = window;
    const root = document.createElement('div');
    root.setAttribute('data-pf-rule-editor', '');
    root.setAttribute('data-catalog', JSON.stringify(CATALOG));
    root.setAttribute('data-words', '{}');
    root.setAttribute('data-classes', JSON.stringify({ select: 'select-x', input: 'input-x', button: 'button-x' }));
    const hidden = document.createElement('input');
    hidden.setAttribute('data-pf-rule-state', '');
    hidden.value = definition === null ? '' : JSON.stringify(definition);
    root.appendChild(hidden);
    for (const part of ['when', 'steps', 'until']) {
        const box = document.createElement('div');
        box.setAttribute('data-pf-rule-' + part, '');
        root.appendChild(box);
    }
    for (const card of ['when', 'who', 'what', 'until']) {
        const line = document.createElement('span');
        line.setAttribute('data-pf-rule-summary', card);
        root.appendChild(line);
    }
    document.body.appendChild(root);
    if (withAudience) { loadScript(AUDIENCE, window); }
    loadScript(SOURCE, window);

    return { window, document, root, state: () => JSON.parse(hidden.value) };
}

function choose(node, value) {
    node.value = String(value);
    node.dispatchEvent(new current.Event('change'));
}

/** Pick an event in the searchable list, or type it where there is no list. */
function pickEvent(node, key) {
    if (node.classList.contains('pf-combo-button')) {
        node.click();
        node.parentNode.querySelector('[data-value="' + key + '"].pf-combo-option').click();
        return;
    }
    type(node, key);
}

/** Add a step of this kind from the menu under the steps. */
function add(root, kind) {
    root.querySelector('[data-pf-rule-add]').click();
    root.querySelector('[data-pf-rule-kind="' + kind + '"]').click();
}

function type(node, value) {
    node.value = String(value);
    node.dispatchEvent(new current.Event('input'));
}

test('an empty editor starts a rule on an event, taking a person in once', () => {
    // Arrange
    const { state, root } = page();

    // Act
    const definition = state();

    // Assert
    assert.deepEqual(definition, { trigger: { type: 'event', event: '' }, entry: 'first', steps: {}, caps: {} });
    assert.equal(root.querySelector('[data-pf-rule-summary="what"]').textContent, 'No steps yet');
});

test('the trigger changes kind and keeps its own fields; the event is picked from the list', () => {
    // Arrange
    const { root, state } = page();

    // Act
    pickEvent(root.querySelector('[data-pf-rule-event]'), 'payment.made');
    const event = state().trigger;
    const said = root.querySelector('[data-pf-rule-summary="when"]').textContent;
    choose(root.querySelector('[data-pf-rule-trigger]'), 'segment_entered');
    choose(root.querySelector('[data-pf-rule-segment]'), 4);
    const segment = state().trigger;
    choose(root.querySelector('[data-pf-rule-trigger]'), 'schedule');
    type(root.querySelector('[data-pf-rule-cron]'), '0 10 * * 1');
    choose(root.querySelector('[data-pf-rule-entry]'), 'every');

    // Assert
    assert.deepEqual(event, { type: 'event', event: 'payment.made' });
    assert.equal(said, 'When: Paid · the first time only', 'the folded card says it by its label');
    assert.deepEqual(segment, { type: 'segment_entered', segment: 4 });
    assert.deepEqual(state().trigger, { type: 'schedule', cron: '0 10 * * 1' });
    assert.equal(state().entry, 'every');
});

test('without the list the event is typed', () => {
    // Arrange
    const { root, state } = page(null, false);

    // Act
    const field = root.querySelector('[data-pf-rule-event]');
    type(field, 'payment.made');

    // Assert
    assert.ok(field.classList.contains('input-x'), 'a field in the theme\'s classes');
    assert.equal(state().trigger.event, 'payment.made');
});

test('steps are added from the menu, joined after the last one, and given their parameters', () => {
    // Arrange
    const { root, state } = page();

    // Act
    add(root, 'message');
    add(root, 'message');
    const first = () => root.querySelector('[data-pf-rule-step="s1"]');
    type(first().querySelector('[data-pf-rule-param="template"]'), 'welcome');
    const second = () => root.querySelector('[data-pf-rule-step="s2"]');
    choose(second().querySelector('[data-pf-rule-action]'), 'site_message');
    choose(second().querySelector('[data-pf-rule-param="kind"]'), 'card');
    const area = second().querySelector('textarea');
    area.value = 'Hi {firstname}';
    area.dispatchEvent(new current.Event('input'));

    // Assert
    assert.equal(state().start, 's1');
    assert.deepEqual(state().steps, {
        s1: { type: 'action', action: 'email', params: { template: 'welcome' }, next: 's2' },
        s2: { type: 'action', action: 'site_message', params: { kind: 'card', body: 'Hi {firstname}' }, next: null },
    });
    assert.match(root.querySelector('[data-pf-rule-summary="what"]').textContent, /^2 steps · s1 · Send an email → s2 · Show on the site$/);
});

test('the menu offers a message, an action, waits, a branch and a split, each with its fields', () => {
    // Arrange
    const { root, state } = page({ trigger: { type: 'once' }, steps: {} });

    // Act
    const kinds = (() => { root.querySelector('[data-pf-rule-add]').click(); return root.querySelectorAll('[data-pf-rule-kind]').map((b) => b.getAttribute('data-pf-rule-kind')); })();
    root.querySelector('[data-pf-rule-kind="action"]').click();
    choose(root.querySelector('[data-pf-rule-step="s1"] [data-pf-rule-param="group"]'), 7);
    add(root, 'wait');
    type(root.querySelector('[data-pf-rule-step="s2"] [data-pf-rule-amount]'), 3);
    choose(root.querySelector('[data-pf-rule-step="s2"] [data-pf-rule-unit]'), 3600);
    add(root, 'wait_event');
    pickEvent(root.querySelector('[data-pf-rule-step="s3"] [data-pf-rule-param="event"]'), 'payment.made');
    choose(root.querySelector('[data-pf-rule-step="s3"] [data-pf-rule-next="timeout"]'), 's1');
    add(root, 'split');
    root.querySelector('[data-pf-rule-step="s4"] [data-pf-rule-add-variant]').click();

    // Assert
    assert.deepEqual(kinds, ['message', 'action', 'wait', 'wait_event', 'branch', 'split']);
    assert.deepEqual(state().steps.s1, { type: 'action', action: 'group_add', params: { group: 7 }, next: 's2' }, 'an action is the first without a channel');
    assert.deepEqual(state().steps.s2, { type: 'wait', seconds: 3 * 3600, next: 's3' });
    assert.deepEqual(state().steps.s3, { type: 'wait_event', event: 'payment.made', seconds: 86400, next: 's4', timeout: 's1' });
    assert.deepEqual(state().steps.s4.variants.map((v) => [v.key, v.next]), [['A', null], ['B', null], ['C', null]], 'a split leads nowhere until told');
    assert.equal(root.querySelector('[data-pf-rule-step="s4"] [data-pf-rule-type]').getAttribute('data-pf-rule-type'), 'split');
});

test('a branch on conditions holds an audience builder; on a message it names an action step', () => {
    // Arrange
    const { root, state } = page({ trigger: { type: 'once' }, start: 'm', steps: {
        m: { type: 'action', action: 'email', params: {}, next: 'b' }, b: { type: 'branch', filter: { match: 'all', conditions: [] }, yes: null, no: null } } });
    const branch = () => root.querySelector('[data-pf-rule-step="b"]');

    // Act
    branch().querySelector('[data-pf-audience-add]').click();
    pickEvent(branch().querySelector('[data-pf-audience-attribute]'), 'language');
    type(branch().querySelector('[data-pf-audience-value]'), 'el');
    const filter = state().steps.b.filter;
    choose(branch().querySelector('[data-pf-rule-mode]'), 'message');
    choose(branch().querySelector('[data-pf-rule-message]'), 'm');
    choose(branch().querySelector('[data-pf-rule-status]'), 'clicked');
    choose(branch().querySelector('[data-pf-rule-next="yes"]'), 'm');

    // Assert
    assert.deepEqual(filter, { match: 'all', conditions: [{ attribute: 'language', op: 'is', value: 'el' }] });
    assert.deepEqual(state().steps.b, { type: 'branch', yes: 'm', no: null, message: { step: 'm', status: 'clicked' } });
});

test('removing a step ends whatever led to it, and moves the start', () => {
    // Arrange
    const { root, state } = page({ trigger: { type: 'once' }, start: 'a', steps: {
        a: { type: 'action', action: 'email', params: {}, next: 'b' },
        b: { type: 'split', variants: [{ key: 'A', share: 50, next: 'a' }, { key: 'B', share: 50, next: null }] } } });

    // Act
    root.querySelector('[data-pf-rule-step="a"] [data-pf-rule-remove]').click();

    // Assert
    assert.deepEqual(Object.keys(state().steps), ['b']);
    assert.equal(state().start, 'b');
    assert.equal(state().steps.b.variants[0].next, null);
});

test('until: a goal, a consent list, sending hours on the 24-hour clock with both ends, a limit per channel', () => {
    // Arrange
    const { root, state } = page({ trigger: { type: 'once' }, steps: {} });

    // Act
    pickEvent(root.querySelector('[data-pf-rule-goal]'), 'payment.made');
    type(root.querySelector('[data-pf-rule-goal-days]'), 10);
    choose(root.querySelector('[data-pf-rule-consent]'), 3);
    type(root.querySelector('[data-pf-rule-from]'), '9:0');
    const typing = state().hours;
    type(root.querySelector('[data-pf-rule-from]'), '09:00');
    const half = state().hours;
    type(root.querySelector('[data-pf-rule-to]'), '21:00');
    const said = root.querySelector('[data-pf-rule-summary="until"]').textContent;
    root.querySelector('[data-pf-rule-add-cap]').click();
    const cap = root.querySelector('[data-pf-rule-cap="email"] input');
    type(cap, 2);
    const capped = JSON.parse(JSON.stringify(state().caps));
    root.querySelector('[data-pf-rule-cap="email"] .pf-rule-remove').click();

    // Assert
    assert.equal(typing, undefined, 'half a time is not a time');
    assert.equal(half, null, 'one end of the window is no window');
    assert.deepEqual(state().hours, { from: '09:00', to: '21:00' });
    assert.equal(root.querySelector('[data-pf-rule-from]').getAttribute('placeholder'), '09:00');
    assert.equal(state().consent, 3);
    assert.deepEqual(state().goal, { event: 'payment.made', days: 10 });
    assert.equal(said, 'Goal: Paid within 10 days · 09:00–21:00');
    assert.deepEqual(capped, { email: { max: 2, days: 1 } });
    assert.deepEqual(state().caps, {});
});

test('the controls wear the theme\'s classes', () => {
    // Arrange
    const { root } = page({ trigger: { type: 'event', event: 'x' }, steps: { a: { type: 'split', variants: [{ key: 'A', share: 50, next: null }, { key: 'B', share: 50, next: null }] } } });

    // Act
    const trigger = root.querySelector('[data-pf-rule-trigger]');
    const more = root.querySelector('[data-pf-rule-add-variant]');

    // Assert
    assert.ok(trigger.classList.contains('select-x'));
    assert.ok(more.classList.contains('button-x'));
    assert.ok(root.querySelector('.pf-rule-sentence .pf-rule-word'), 'words between the fields, in sentences');
});
