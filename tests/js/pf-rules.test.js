/**
 * `pf-rules.js`: a rule's editor.
 *
 * What has to hold: the stored definition draws as its trigger, steps and limits; every change
 * writes the definition back as JSON; a new step is an action named after its place, removing a
 * step ends whatever led to it; each step type has its own fields, and changing the type keeps
 * where it led; a branch on conditions holds an audience builder whose changes become its filter;
 * sending hours are kept only when both ends are set; frequency caps are added per channel.
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
    document.body.appendChild(root);
    if (withAudience) { loadScript(AUDIENCE, window); }
    loadScript(SOURCE, window);

    return { window, document, root, state: () => JSON.parse(hidden.value) };
}

function choose(node, value) {
    node.value = String(value);
    node.dispatchEvent(new current.Event('change'));
}

function type(node, value) {
    node.value = String(value);
    node.dispatchEvent(new current.Event('input'));
}

test('an empty editor starts a rule on an event, taking a person in once', () => {
    // Arrange
    const { state } = page();

    // Act
    const definition = state();

    // Assert
    assert.deepEqual(definition, { trigger: { type: 'event', event: '' }, entry: 'first', steps: {}, caps: {} });
});

test('the trigger changes kind and keeps its own fields', () => {
    // Arrange
    const { root, state } = page();

    // Act
    type(root.querySelector('[data-pf-rule-event]'), 'payment.made');
    const event = state().trigger;
    choose(root.querySelector('[data-pf-rule-trigger]'), 'segment_entered');
    choose(root.querySelector('[data-pf-rule-segment]'), 4);
    const segment = state().trigger;
    choose(root.querySelector('[data-pf-rule-trigger]'), 'schedule');
    type(root.querySelector('[data-pf-rule-cron]'), '0 10 * * 1');
    choose(root.querySelector('[data-pf-rule-entry]'), 'every');

    // Assert
    assert.deepEqual(event, { type: 'event', event: 'payment.made' });
    assert.deepEqual(segment, { type: 'segment_entered', segment: 4 });
    assert.deepEqual(state().trigger, { type: 'schedule', cron: '0 10 * * 1' });
    assert.equal(state().entry, 'every');
});

test('steps are added as actions, wired to each other, and given their parameters', () => {
    // Arrange
    const { root, state } = page();

    // Act
    root.querySelector('[data-pf-rule-add]').click();
    root.querySelector('[data-pf-rule-add]').click();
    const first = () => root.querySelector('[data-pf-rule-step="s1"]');
    type(first().querySelector('[data-pf-rule-param="template"]'), 'welcome');
    choose(first().querySelector('[data-pf-rule-next="next"]'), 's2');
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
});

test('each type has its fields, and changing type keeps where the step led', () => {
    // Arrange
    const { root, state } = page({ trigger: { type: 'once' }, start: 'a', steps: {
        a: { type: 'action', action: 'group_add', params: {}, next: 'b' }, b: { type: 'action', action: 'email', params: {}, next: null } } });
    const step = () => root.querySelector('[data-pf-rule-step="a"]');

    // Act
    choose(step().querySelector('[data-pf-rule-param="group"]'), 7);
    const group = state().steps.a.params;
    choose(step().querySelector('[data-pf-rule-type]'), 'wait');
    type(step().querySelector('[data-pf-rule-amount]'), 3);
    const wait = state().steps.a;
    choose(step().querySelector('[data-pf-rule-unit]'), 3600);
    const hours = state().steps.a.seconds;
    choose(step().querySelector('[data-pf-rule-type]'), 'wait_event');
    type(step().querySelector('[data-pf-rule-param="event"]'), 'payment.made');
    choose(step().querySelector('[data-pf-rule-next="timeout"]'), 'b');
    const waitEvent = state().steps.a;
    choose(step().querySelector('[data-pf-rule-type]'), 'split');
    step().querySelector('[data-pf-rule-add-variant]').click();

    // Assert
    assert.deepEqual(group, { group: 7 });
    assert.deepEqual(wait, { type: 'wait', seconds: 3 * 86400, next: 'b' });
    assert.equal(hours, 3 * 3600);
    assert.deepEqual(waitEvent, { type: 'wait_event', event: 'payment.made', seconds: 86400, next: 'b', timeout: 'b' });
    assert.deepEqual(state().steps.a.variants.map((v) => [v.key, v.next]), [['A', 'b'], ['B', null], ['C', null]]);
});

test('a branch on conditions holds an audience builder; on a message it names an action step', () => {
    // Arrange
    const { root, state } = page({ trigger: { type: 'once' }, start: 'm', steps: {
        m: { type: 'action', action: 'email', params: {}, next: 'b' }, b: { type: 'branch', filter: { match: 'all', conditions: [] }, yes: null, no: null } } });
    const branch = () => root.querySelector('[data-pf-rule-step="b"]');

    // Act
    branch().querySelector('[data-pf-audience-add]').click();
    choose(branch().querySelector('[data-pf-audience-attribute]'), 'language');
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

test('until: a goal, a consent list, sending hours only with both ends, a cap per channel', () => {
    // Arrange
    const { root, state } = page({ trigger: { type: 'once' }, steps: {} });

    // Act
    type(root.querySelector('[data-pf-rule-goal]'), 'payment.made');
    type(root.querySelector('[data-pf-rule-goal-days]'), 10);
    choose(root.querySelector('[data-pf-rule-consent]'), 3);
    type(root.querySelector('[data-pf-rule-from]'), '09:00');
    const half = state().hours;
    type(root.querySelector('[data-pf-rule-to]'), '21:00');
    choose(root.querySelector('[data-pf-rule-add-cap]'), 'email');
    const cap = root.querySelector('[data-pf-rule-cap="email"] input');
    type(cap, 2);
    const capped = JSON.parse(JSON.stringify(state().caps));
    root.querySelector('[data-pf-rule-cap="email"] .pf-rule-remove').click();
    type(root.querySelector('[data-pf-rule-goal]'), '');

    // Assert
    assert.equal(half, null, 'one end of the window is no window');
    assert.deepEqual(state().hours, { from: '09:00', to: '21:00' });
    assert.equal(state().consent, 3);
    assert.deepEqual(capped, { email: { max: 2, days: 1 } });
    assert.deepEqual(state().caps, {});
    assert.equal(state().goal, null);
});

test('the controls wear the theme\'s classes', () => {
    // Arrange
    const { root } = page({ trigger: { type: 'event', event: 'x' }, steps: { a: { type: 'action', action: 'email', params: {}, next: null } } });

    // Act
    const trigger = root.querySelector('[data-pf-rule-trigger]');
    const event = root.querySelector('[data-pf-rule-event]');
    const add = root.querySelector('[data-pf-rule-add]');

    // Assert
    assert.ok(trigger.classList.contains('select-x'));
    assert.ok(event.classList.contains('input-x'));
    assert.ok(add.classList.contains('button-x'));
    assert.ok(root.querySelector('.pf-rule-label'), 'labels of their own, to line up');
});
