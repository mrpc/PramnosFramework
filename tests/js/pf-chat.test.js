/**
 * The conversation page's script, `scaffolding/assets/js/pf-chat.js`, driven end to end.
 *
 * What has to hold: asking sends the form without leaving the page and follows the queued
 * question until the conversation opens; a failure is said in place; a question already waiting
 * when the page was drawn is followed; Enter sends and Shift+Enter does not.
 *
 * Run:
 *   node --test tests/js/pf-chat.test.js
 *
 * Zero npm dependencies: node:test, node:assert, node:vm, node:fs.
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const vm       = require('node:vm');
const fs       = require('node:fs');
const path     = require('node:path');

const SOURCE = fs.readFileSync(path.join(__dirname, '..', '..', 'scaffolding/assets/js/pf-chat.js'), 'utf8');

/** A small element: attributes, children, listeners — only what the script touches. */
function element(tag, attributes = {}) {
    const el = {
        tagName: tag.toUpperCase(),
        attributes: { ...attributes },
        children: [],
        listeners: {},
        parentNode: null,
        style: {},
        textContent: '',
        value: '',
        disabled: false,
        getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        appendChild(child) { child.parentNode = this; this.children.push(child); return child; },
        insertBefore(child, before) {
            child.parentNode = this;
            this.children.splice(this.children.indexOf(before), 0, child);
            return child;
        },
        addEventListener(type, handler) { (this.listeners[type] ||= []).push(handler); },
        dispatch(type, event = {}) {
            const e = { preventDefault() { this.prevented = true; }, ...event };
            (this.listeners[type] || []).forEach((h) => h(e));
            return e;
        },
        querySelector(selector) { return find(this, selector); },
    };

    return el;
}

/** The descendant matching `[attr]`, `tag`, `.class` or `button[type="submit"]`. */
function find(root, selector) {
    for (const child of root.children) {
        const attr = selector.match(/^\[([\w-]+)\]$/);
        const typed = selector.match(/^(\w+)\[type="(\w+)"\]$/);
        const hit = attr ? attr[1] in child.attributes
            : typed ? child.tagName === typed[1].toUpperCase() && child.attributes.type === typed[2]
            : selector.startsWith('.') ? (child.attributes.class || '').split(' ').includes(selector.slice(1))
            : child.tagName === selector.toUpperCase();
        if (hit) {
            return child;
        }
        const deeper = find(child, selector);
        if (deeper) {
            return deeper;
        }
    }

    return null;
}

/**
 * The page, the script run over it, and a fetch answering from a queue of bodies.
 *
 * @param {{waiting?: string, answers: Array<object>}} options
 */
function run({ waiting, answers, attributes = {} }) {
    const page = element('div', { 'data-pf-chat': '', 'data-asked': '/chat/asked/', ...attributes });
    const messages = page.appendChild(element('div', { class: 'pf-chat-messages' }));
    if (waiting) {
        messages.appendChild(element('p', { 'data-pf-chat-waiting': waiting }));
    }
    const form = page.appendChild(element('form', { 'data-pf-chat-form': '' }));
    form.action = '/chat/ask';
    form.submitted = 0;
    form.submit = () => { form.submitted++; };
    form.requestSubmit = () => form.dispatch('submit');
    const box = form.appendChild(element('textarea'));
    const button = form.appendChild(element('button', { type: 'submit' }));

    const requests = [];
    const timers = [];
    const window = { location: { href: '/chat' }, setTimeout: (fn) => timers.push(fn) };
    const queue = [...answers];
    const context = {
        window,
        document: { querySelector: (s) => (s === '[data-pf-chat]' ? page : null), createElement: (t) => element(t) },
        FormData: function FormData(f) { this.form = f; },
        fetch: (url, init = {}) => {
            requests.push({ url, method: init.method || 'GET' });
            const next = queue.shift();
            return next instanceof Error ? Promise.reject(next) : Promise.resolve({ json: () => Promise.resolve(next) });
        },
    };
    vm.runInNewContext(SOURCE, context);

    return { page, messages, form, box, button, requests, timers, window };
}

/** Let every pending promise settle: a few turns, for a fetch that leads to another. */
const settle = async () => {
    for (let i = 0; i < 5; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
};

test('asking sends the form, follows the question, and opens the conversation', async () => {
    // Arrange
    const ui = run({ answers: [{ status: 202, data: { ask_id: 5 } }, { data: { status: 'waiting' } }, { data: { status: 'answered', conversation_id: 9 } }] });
    ui.box.value = 'How did it go?';

    // Act
    const submitted = ui.form.dispatch('submit');
    await settle();
    // Still waiting: followed again a moment later
    ui.timers.shift()();
    await settle();

    // Assert
    assert.equal(submitted.prevented, true, 'the page is not left');
    assert.deepEqual(ui.requests.map((r) => [r.method, r.url]), [['POST', '/chat/ask'], ['GET', '/chat/asked/5'], ['GET', '/chat/asked/5']]);
    assert.equal(ui.window.location.href, '/chat?c=9');
    assert.equal(ui.box.value, '', 'the question box is cleared');
    assert.equal(ui.messages.children[0].textContent, 'How did it go?', 'the question shows at once');
});

test('a failure is said in place, from the refusal or the queued answer', async () => {
    // Arrange / Act — refused when asked
    const refused = run({ answers: [{ status: 403, error: 'Agree first.' }] });
    refused.form.dispatch('submit');
    await settle();

    // Assert
    const line = refused.messages.children.at(-1);
    assert.equal(line.textContent, 'Agree first.');
    assert.equal(line.getAttribute('role'), 'alert');
    assert.equal(refused.button.disabled, false);

    // Arrange / Act — failed in the worker
    const failed = run({ waiting: '7', answers: [{ data: { status: 'failed', says: 'Out of credit.' } }] });
    await settle();
    assert.equal(failed.messages.children[0].textContent, 'Out of credit.');
});

test('answered in the request, and a network failure falls back to posting', async () => {
    // Arrange / Act
    const direct = run({ answers: [{ status: 200, data: { conversation_id: 3, message: {} } }] });
    direct.form.dispatch('submit');
    await settle();
    const offline = run({ answers: [new Error('offline')] });
    offline.form.dispatch('submit');
    await settle();
    const lost = run({ waiting: '2', answers: [new Error('offline'), { error: 'There is no such question.' }] });
    await settle();
    lost.timers.shift()();
    await settle();

    // Assert
    assert.equal(direct.window.location.href, '/chat?c=3');
    assert.equal(offline.form.submitted, 1);
    assert.equal(lost.messages.children[0].textContent, 'There is no such question.');
});

test('a page at its own address opens the conversation there', async () => {
    // Arrange — the analyst: its page is not /chat, and its follow address is not /chat/asked
    const ui = run({
        attributes: { 'data-page': '/admin/Ai/analyst', 'data-asked': '/admin/Ai/analystAsked/' },
        answers: [{ status: 202, data: { ask_id: 4 } }, { data: { status: 'answered', conversation_id: 6 } }],
    });

    // Act
    ui.form.dispatch('submit');
    await settle();

    // Assert
    assert.equal(ui.requests[1].url, '/admin/Ai/analystAsked/4');
    assert.equal(ui.window.location.href, '/admin/Ai/analyst?c=6');
});

test('Enter sends, Shift+Enter is a new line', () => {
    // Arrange
    const ui = run({ answers: [{ status: 202, data: { ask_id: 1 } }] });

    // Act
    const newLine = ui.box.dispatch('keydown', { key: 'Enter', shiftKey: true });
    const sent = ui.box.dispatch('keydown', { key: 'Enter', shiftKey: false });

    // Assert
    assert.equal(newLine.prevented, undefined);
    assert.equal(sent.prevented, true);
    assert.equal(ui.requests.length, 1);
});

test('a page without the conversation does nothing', () => {
    // Act / Assert
    assert.doesNotThrow(() => vm.runInNewContext(SOURCE, { window: {}, document: { querySelector: () => null } }));
});
