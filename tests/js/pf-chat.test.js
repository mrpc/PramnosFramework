/**
 * The conversation page's script, `scaffolding/assets/js/pf-chat.js`, driven end to end.
 *
 * What has to hold: asking sends the form without leaving the page, shows the question and a
 * thinking line at once, follows the queued question, and swaps the answered page in without a
 * reload; files are picked, dropped or pasted within the server's limits and shown as chips; a
 * refusal is said in place and gives the question back; Enter sends and Shift+Enter does not.
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
        className: '',
        value: '',
        disabled: false,
        clicked: 0,
        get textContent() { return this._text ?? this.children.map((c) => c.textContent).join(''); },
        set textContent(value) { this._text = String(value); this.children = []; },
        getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        appendChild(child) { child.parentNode = this; this.children.push(child); this._text = undefined; return child; },
        removeChild(child) { this.children.splice(this.children.indexOf(child), 1); child.parentNode = null; return child; },
        addEventListener(type, handler) { (this.listeners[type] ||= []).push(handler); },
        click() { this.clicked++; this.dispatch('click'); },
        dispatch(type, event = {}) {
            const e = { preventDefault() { this.prevented = true; }, ...event };
            (this.listeners[type] || []).forEach((h) => h(e));
            return e;
        },
        querySelector(selector) { return find(this, selector); },
        querySelectorAll(selector) { return findAll(this, selector); },
        focus() { this.focused = true; },
    };

    return el;
}

/** The descendant matching `[attr]`, `tag`, or `button[type="submit"]`. */
function find(root, selector) {
    for (const child of root.children) {
        const attr = selector.match(/^\[([\w-]+)\]$/);
        const typed = selector.match(/^(\w+)\[type="(\w+)"\]$/);
        const hit = attr ? attr[1] in child.attributes
            : typed ? child.tagName === typed[1].toUpperCase() && child.attributes.type === typed[2]
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

/** Every descendant carrying `[attr]`. */
function findAll(root, selector) {
    const attr = selector.match(/^\[([\w-]+)\]$/)[1];

    return root.children.flatMap((child) => (attr in child.attributes ? [child] : []).concat(findAll(child, selector)));
}

/** A file as the browser hands it over. */
const file = (name, size = 10, type = 'text/plain') => ({ name, size, type });

/**
 * The page, the script run over it, and a fetch answering from a queue of bodies — JSON, or a
 * page's HTML for the swap.
 *
 * @param {{waiting?: string, answers: Array<object|string|Error>, attributes?: object, parser?: boolean, now?: () => number}} options
 */
function run({ waiting, answers, attributes = {}, parser = true, now = () => 0, examples = [] }) {
    const page = element('div', { 'data-pf-chat': '', 'data-asked': '/chat/asked/', ...attributes });
    examples.forEach((text) => page.appendChild(element('button', { type: 'button', 'data-pf-chat-suggestion': text })));
    const messages = page.appendChild(element('div', { 'data-pf-chat-messages': '' }));
    if (waiting) {
        messages.appendChild(element('p', { 'data-pf-chat-waiting': waiting }));
    }
    const form = page.appendChild(element('form', { 'data-pf-chat-form': '' }));
    form.action = '/chat/ask';
    form.submitted = 0;
    form.submit = () => { form.submitted++; };
    form.requestSubmit = () => form.dispatch('submit');
    const box = form.appendChild(element('textarea'));
    const picker = form.appendChild(element('input', { 'data-pf-chat-files': '' }));
    const attach = form.appendChild(element('button', { 'data-pf-chat-attach': '' }));
    const chips = form.appendChild(element('span', { 'data-pf-chat-chips': '' }));
    const button = form.appendChild(element('button', { type: 'submit' }));

    const requests = [];
    const timers = [];
    const intervals = [];
    const sent = [];
    const replaced = [];
    const window = {
        location: { href: '/chat' },
        setTimeout: (fn) => timers.push(fn),
        setInterval: (fn) => { intervals.push(fn); return intervals.length; },
        clearInterval: () => {},
        history: { replaceState: (s, t, url) => replaced.push(url) },
    };
    if (parser) {
        window.DOMParser = function DOMParser() {
            this.parseFromString = (html) => ({ querySelector: () => (html.includes('data-pf-chat') ? { innerHTML: html } : null) });
        };
    }
    const queue = [...answers];
    const context = {
        window,
        Date: { now },
        Math,
        JSON,
        document: { querySelector: (s) => (s === '[data-pf-chat]' ? page : null), createElement: (t) => element(t) },
        FormData: function FormData(f) { this.form = f; this.append = (k, v, n) => { sent.push(n); }; this.delete = () => {}; },
        fetch: (url, init = {}) => {
            requests.push({ url, method: init.method || 'GET', body: init.body });
            const next = queue.shift();
            if (next instanceof Error) {
                return Promise.reject(next);
            }
            return Promise.resolve({ json: () => Promise.resolve(next), text: () => Promise.resolve(next) });
        },
    };
    vm.runInNewContext(SOURCE, context);

    const suggestions = page.querySelectorAll('[data-pf-chat-suggestion]');

    return { page, messages, form, box, picker, attach, chips, button, requests, timers, intervals, window, sent, replaced, suggestions };
}

/** Let every pending promise settle: a few turns, for a fetch that leads to another. */
const settle = async () => {
    for (let i = 0; i < 8; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
};

test('asking shows the question, follows it, and swaps the answered page in', async () => {
    // Arrange
    const ui = run({ answers: [{ status: 202, data: { ask_id: 5 } }, { data: { status: 'waiting' } }, { data: { status: 'answered', conversation_id: 9 } }, '<div data-pf-chat>answered</div>'] });
    ui.box.value = 'How did it go?';

    // Act
    const submitted = ui.form.dispatch('submit');
    await settle();
    const shown = ui.messages.children[0].textContent;
    // Still waiting: followed again a moment later
    ui.timers.shift()();
    await settle();

    // Assert
    assert.equal(submitted.prevented, true, 'the page is not left');
    assert.deepEqual(ui.requests.map((r) => [r.method, r.url]), [['POST', '/chat/ask'], ['GET', '/chat/asked/5'], ['GET', '/chat/asked/5'], ['GET', '/chat?c=9']]);
    assert.equal(ui.page.innerHTML, '<div data-pf-chat>answered</div>', 'the answered page, swapped in');
    assert.deepEqual(ui.replaced, ['/chat?c=9'], 'the address follows the conversation');
    assert.equal(ui.window.location.href, '/chat', 'without a reload');
    assert.equal(ui.box.value, '', 'the question box is cleared');
    assert.equal(shown, 'How did it go?', 'the question shows at once');
});

test('a page at its own address opens the conversation there; without a parser, by loading it', async () => {
    // Arrange — the analyst: its page is not /chat, and its follow address is not /chat/asked
    const ui = run({
        parser: false,
        attributes: { 'data-page': '/admin/Ai', 'data-asked': '/admin/Ai/asked/' },
        answers: [{ status: 202, data: { ask_id: 4 } }, { data: { status: 'answered', conversation_id: 6 } }],
    });
    ui.box.value = 'How many accounts?';

    // Act
    ui.form.dispatch('submit');
    await settle();

    // Assert
    assert.equal(ui.requests[1].url, '/admin/Ai/asked/4');
    assert.equal(ui.window.location.href, '/admin/Ai?c=6');
});

test('a page that cannot be read is opened the ordinary way', async () => {
    // Arrange / Act — answered in the request, and the page has no conversation in it
    const ui = run({ answers: [{ status: 200, data: { conversation_id: 3, message: {} } }, '<p>signed out</p>'] });
    ui.box.value = 'Hi';
    ui.form.dispatch('submit');
    await settle();

    // Assert
    assert.equal(ui.window.location.href, '/chat?c=3');
});

test('a refusal is said in place and gives the question and files back', async () => {
    // Arrange
    const refused = run({ answers: [{ status: 403, error: 'Agree first.' }] });
    refused.box.value = 'Hi';
    refused.picker.files = [file('brief.md')];
    refused.picker.dispatch('change');

    // Act
    refused.form.dispatch('submit');
    await settle();

    // Assert
    const line = refused.messages.children.at(-1);
    assert.equal(line.textContent, 'Agree first.');
    assert.equal(line.getAttribute('role'), 'alert');
    assert.equal(refused.button.disabled, false);
    assert.equal(refused.box.value, 'Hi', 'a retry is one press');
    assert.equal(refused.chips.children.length, 1, 'the file is held again');
    assert.equal(refused.messages.children.length, 1, 'the question shown at once is taken back');

    // Arrange / Act — failed in the worker, said in the page's own words
    const failed = run({ waiting: '7', attributes: { 'data-say-failed': 'Δεν απάντησε.' }, answers: [{ data: { status: 'failed', says: '' } }] });
    await settle();
    assert.equal(failed.messages.children[0].textContent, 'Δεν απάντησε.');
});

test('a network failure posts the form; a lost question is said; nothing asked sends nothing', async () => {
    // Arrange / Act
    const offline = run({ answers: [new Error('offline')] });
    offline.box.value = 'Hi';
    offline.form.dispatch('submit');
    await settle();
    const lost = run({ waiting: '2', answers: [new Error('offline'), { error: 'There is no such question.' }] });
    await settle();
    lost.timers.shift()();
    await settle();
    const empty = run({ answers: [] });
    empty.form.dispatch('submit');

    // Assert
    assert.equal(offline.form.submitted, 1);
    assert.equal(lost.messages.children[0].textContent, 'There is no such question.');
    assert.equal(empty.requests.length, 0, 'an empty box and no files ask nothing');
});

test('files are picked, dropped or pasted, held as chips, and sent with the question', async () => {
    // Arrange
    const ui = run({ answers: [{ status: 202, data: { ask_id: 1 } }] });

    // Act — 📎 opens the picker; picked, dropped, and a pasted picture with no name, twice
    ui.attach.click();
    ui.picker.files = [file('brief.md')];
    ui.picker.dispatch('change');
    const dropped = ui.form.dispatch('drop', { dataTransfer: { files: [file('notes.txt')] } });
    ui.form.dispatch('dragover');
    const pasted = ui.box.dispatch('paste', { clipboardData: { files: [file('image', 5, 'image/png')] } });
    ui.box.dispatch('paste', { clipboardData: { files: [file('image', 5, 'image/png')] } });
    const words = ui.box.dispatch('paste', { clipboardData: { files: [] } });
    // ✕ on the second chip lets it go
    ui.chips.children[1].children[0].click();
    ui.form.dispatch('submit');
    await settle();

    // Assert
    assert.equal(ui.picker.clicked, 1);
    assert.equal(dropped.prevented, true);
    assert.equal(pasted.prevented, true);
    assert.equal(words.prevented, undefined, 'pasted text is the box\'s');
    assert.deepEqual(ui.sent, ['brief.md', 'pasted.png', 'pasted-2.png'], 'every file held, renamed apart');
    assert.equal(ui.chips.children.length, 0, 'sent, so no longer held');
    assert.match(ui.messages.children[0].textContent, /📎 brief\.md, pasted\.png, pasted-2\.png/);
});

test('a file the server cannot read, too many, or too large is refused', () => {
    // Arrange
    const ui = run({ answers: [], attributes: { 'data-say-too-many': 'Too many.' } });

    // Act / Assert — not a kind it reads
    ui.picker.files = [file('report.pdf')];
    ui.picker.dispatch('change');
    assert.match(ui.messages.children.at(-1).textContent, /Attach a picture/);

    // Larger than 5 MB together
    ui.picker.files = [file('a.txt', 4000000), file('b.txt', 2000000)];
    ui.picker.dispatch('change');
    assert.match(ui.messages.children.at(-1).textContent, /too large/);

    // More than ten
    ui.picker.files = Array.from({ length: 11 }, (_, i) => file(`n${i}.txt`));
    ui.picker.dispatch('change');
    assert.equal(ui.messages.children.at(-1).textContent, 'Too many.');
    assert.equal(ui.chips.children.length, 10);
});

test('while waiting: thinking at once, the application\'s lines after a few seconds, a promise after a minute', async () => {
    // Arrange
    let clock = 0;
    const ui = run({ now: () => clock, attributes: { 'data-waiting-lines': '["Counting the clicks…"]', 'data-say-long': 'Still going.' }, answers: [{ status: 202, data: { ask_id: 3 } }, { data: { status: 'waiting' } }] });
    ui.box.value = 'Hi';

    // Act
    ui.form.dispatch('submit');
    const line = ui.messages.children.at(-1);
    const first = line.textContent;
    clock = 9000;
    ui.intervals[0]();
    const later = line.textContent;
    clock = 61000;
    ui.intervals[0]();

    // Assert
    assert.equal(first, 'Thinking…');
    assert.equal(later, 'Counting the clicks…');
    assert.equal(line.textContent, 'Still going.');
});

test('Enter sends, Shift+Enter is a new line', () => {
    // Arrange
    const ui = run({ answers: [{ status: 202, data: { ask_id: 1 } }] });
    ui.box.value = 'Hi';

    // Act
    const newLine = ui.box.dispatch('keydown', { key: 'Enter', shiftKey: true });
    const sent = ui.box.dispatch('keydown', { key: 'Enter', shiftKey: false });

    // Assert
    assert.equal(newLine.prevented, undefined);
    assert.equal(sent.prevented, true);
    assert.equal(ui.requests.length, 1);
});

test('an example question fills the box, ready to send or to change first', () => {
    // Arrange
    const ui = run({ answers: [], examples: ['How many signed up this week?', 'What failed in the queue?'] });

    // Act
    ui.suggestions[1].click();

    // Assert
    assert.equal(ui.box.value, 'What failed in the queue?');
    assert.equal(ui.box.focused, true, 'the cursor is in the box');
    assert.equal(ui.requests.length, 0, 'nothing is sent until asked');
});

test('a page without the conversation does nothing', () => {
    // Act / Assert
    assert.doesNotThrow(() => vm.runInNewContext(SOURCE, { window: {}, document: { querySelector: () => null } }));
});
