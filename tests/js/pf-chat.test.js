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
        style: { setProperty(name, value) { this[name] = value; } },
        className: '',
        value: '',
        disabled: false,
        clicked: 0,
        get textContent() { return this._text ?? this.children.map((c) => c.textContent).join(''); },
        set textContent(value) { this._text = String(value); this.children = []; },
        getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        removeAttribute(name) { delete this.attributes[name]; },
        appendChild(child) { child.parentNode = this; this.children.push(child); this._text = undefined; return child; },
        insertBefore(child, before) { child.parentNode = this; this.children.splice(this.children.indexOf(before), 0, child); return child; },
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

/** The descendant matching `[attr]`, `tag`, or `tag[attr="value"]`. */
function find(root, selector) {
    for (const child of root.children) {
        const attr = selector.match(/^\[([\w-]+)\]$/);
        const typed = selector.match(/^(\w+)\[([\w-]+)="([\w-]+)"\]$/);
        const hit = attr ? attr[1] in child.attributes
            : typed ? child.tagName === typed[1].toUpperCase() && child.attributes[typed[2]] === typed[3]
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

/** Whether an element is `[attr]`, `.class` or `tag`. */
function simple(el, selector) {
    const attr = selector.match(/^\[([\w-]+)\]$/);
    if (attr) {
        return attr[1] in el.attributes;
    }
    if (selector.startsWith('.')) {
        return el.className.split(' ').includes(selector.slice(1));
    }

    return el.tagName === selector.toUpperCase();
}

/** Whether an element matches `a, b` or `ancestor descendant`, of simple selectors. */
function matches(el, selector) {
    return selector.split(',').some((one) => {
        const parts = one.trim().split(/\s+/);
        if (!simple(el, parts[parts.length - 1])) {
            return false;
        }
        let up = el.parentNode;
        for (let i = parts.length - 2; i >= 0; i--) {
            while (up && !simple(up, parts[i])) {
                up = up.parentNode;
            }
            if (!up) {
                return false;
            }
            up = up.parentNode;
        }

        return true;
    });
}

/** Every descendant matching, in document order. */
function findAll(root, selector) {
    return root.children.flatMap((child) => (matches(child, selector) ? [child] : []).concat(findAll(child, selector)));
}

/** A file as the browser hands it over. */
const file = (name, size = 10, type = 'text/plain') => ({ name, size, type });

/**
 * The page, the script run over it, and a fetch answering from a queue of bodies — JSON, or a
 * page's HTML for the swap.
 *
 * @param {{waiting?: string, answers: Array<object|string|Error>, attributes?: object, parser?: boolean, now?: () => number}} options
 */
function run({ waiting, answers, attributes = {}, parser = true, now = () => 0, examples = [], before = [], clipboard = null, pfChat = undefined, states = null, pageClass = '' }) {
    const page = element('div', { 'data-pf-chat': '', 'data-asked': '/chat/asked/', 'data-question-class': 'bubble', ...attributes });
    page.className = pageClass;
    examples.forEach((text) => page.appendChild(element('button', { type: 'button', 'data-pf-chat-suggestion': text })));
    before.forEach((one) => page.appendChild(one));
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
    const delays = [];
    const intervals = [];
    const sent = [];
    const replaced = [];
    const window = {
        PfChat: pfChat,
        navigator: clipboard ? { clipboard } : {},
        location: { href: '/chat' },
        setTimeout: (fn, ms) => { delays.push(ms); return timers.push(fn); },
        setInterval: (fn) => { intervals.push(fn); return intervals.length; },
        clearInterval: () => {},
        clearTimeout: () => {},
        history: { replaceState: (s, t, url) => replaced.push(url) },
    };
    if (states) {
        window.pfAssistant = { state: (state) => states.push(state) };
        window.clearTimeout = () => {};
    }
    if (parser) {
        window.DOMParser = function DOMParser() {
            this.parseFromString = (html) => ({ querySelector: () => (html.includes('data-pf-chat') ? { innerHTML: html } : null) });
        };
    }
    const queue = [...answers];
    const context = {
        window,
        Date: class extends Date { static now() { return now(); } },
        Math,
        JSON,
        document: { querySelector: (s) => (s === '[data-pf-chat]' ? page : null), createElement: (t) => element(t), createElementNS: (ns, t) => element(t) },
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

    return { page, messages, form, box, picker, attach, chips, button, requests, timers, delays, intervals, window, sent, replaced, suggestions };
}

/** Let every pending promise settle: a few turns, for a fetch that leads to another. */
const settle = async () => {
    for (let i = 0; i < 8; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
};

/**
 * While the worker writes the answer, the ask carries its text so far: it replaces the thinking
 * line as it grows, is looked at more often than a silent wait, and is marked busy so a screen
 * reader reads it once it is whole. Answered, the page swaps in as before.
 */
test('the answer shows while it is written, and is looked at more often', async () => {
    // Arrange — a page already following question 5
    const ui = run({ waiting: '5', answers: [{ data: { status: 'waiting', partial: 'The first' } }, { data: { status: 'waiting', partial: 'The first half' } }, { data: { status: 'answered', conversation_id: 9 } }, '<div data-pf-chat>answered</div>'] });
    await settle();
    const line = ui.messages.children[0];
    const first = line.textContent;

    // Act — the next look
    ui.timers.shift()();
    await settle();
    const grown = line.textContent;
    ui.timers.shift()();
    await settle();

    // Assert
    assert.equal(first, 'The first');
    assert.equal(grown, 'The first half', 'the same line grows; no second one');
    assert.equal(line.getAttribute('aria-busy'), 'true');
    assert.equal(line.getAttribute('data-pf-chat-waiting'), '5', 'still the line a failure would be said in');
    assert.deepEqual(ui.delays.slice(0, 2), [700, 700], 'looked at again sooner than the silent 2 seconds');
    assert.equal(ui.page.innerHTML, '<div data-pf-chat>answered</div>');
});

/** An answer's 👍 and 👎 as RatingView draws them, 👎 pressed or not. */
function rating(pressed = false) {
    const span = element('span', { class: 'pf-rating', 'data-pf-rating': '5' });
    const form = (value, isPressed) => {
        const one = span.appendChild(element('form', { action: '/chat/rate/5', 'data-pf-rating-value': String(value) }));
        one.action = '/chat/rate/5';
        one.submitted = 0;
        one.submit = () => { one.submitted++; };
        one.appendChild(element('input', { type: 'hidden', name: 'rating' })).value = isPressed ? '0' : String(value);
        one.appendChild(element('button', { type: 'submit', 'aria-pressed': isPressed ? 'true' : 'false' }));

        return one;
    };
    const good = form(1, false);
    const bad = form(-1, pressed);
    const note = bad.appendChild(element('input', { type: 'text', name: 'note' }));
    const value = (one) => one.querySelector('input[name="rating"]').value;
    const pressedOf = (one) => one.querySelector('button').getAttribute('aria-pressed');

    return { span, good, bad, note, value, pressedOf };
}

/**
 * 👎 asks what was wrong before it sends: the note is hidden until the first press, which shows
 * and focuses it instead of sending; the next press sends, the note with it. 👍 sends at once, and
 * so does a 👎 already pressed, which only takes the rating back. A press is sent in the
 * background, not by posting the page.
 *
 * A note always on view between the buttons read as belonging to either, and nobody writes a
 * reason before deciding the answer was bad.
 */
test('👎 shows its note on the first press, and sends with the next', async () => {
    // Arrange
    const fresh = rating();
    const taken = rating(true);
    const ui = run({ answers: [{ ok: true, rating: -1, message: 'Noted' }, { ok: true, rating: 1, message: 'Noted' }, { ok: true, rating: 0, message: 'Noted' }], before: [fresh.span, taken.span] });
    const hiddenAtFirst = fresh.note.hidden;
    const takenNoteAtFirst = taken.note.hidden;

    // Act
    const first = fresh.bad.dispatch('submit');
    const asked = ui.requests.length;
    const second = fresh.bad.dispatch('submit');
    await settle();
    const good = fresh.good.dispatch('submit');
    await settle();
    const takeBack = taken.bad.dispatch('submit');
    await settle();

    // Assert
    assert.equal(hiddenAtFirst, true, 'no note until 👎');
    assert.equal(first.prevented, true, 'the first press asks, it does not send');
    assert.equal(asked, 0);
    assert.equal(fresh.note.focused, true);
    assert.equal(second.prevented, true, 'the second sends — in the background, not by posting the page');
    assert.equal(good.prevented, true, '👍 sends at once');
    assert.notEqual(takenNoteAtFirst, true, 'a pressed 👎 keeps its note as drawn');
    assert.equal(taken.note.hidden, true, 'taken back, the note folds with the rest');
    assert.equal(takeBack.prevented, true, 'and takes the rating back at once');
    assert.deepEqual(ui.requests.map((r) => [r.url, r.method]), [['/chat/rate/5', 'POST'], ['/chat/rate/5', 'POST'], ['/chat/rate/5', 'POST']]);
    assert.equal(fresh.bad.submitted + fresh.good.submitted + taken.bad.submitted, 0, 'no page was posted');
});

/**
 * What the server kept redraws both buttons in place: the chosen one pressed, and its hidden value
 * 0 so the next press takes it back; the other unpressed with its own value; the note folded and
 * emptied; and the server's words beside them, as a copy says ✓.
 */
test('a rating changes both buttons in place and says so', async () => {
    // Arrange
    const r = rating();
    const ui = run({ answers: [{ ok: true, rating: -1, message: 'Thank you — noted.' }, { ok: true, rating: 1, message: 'Thank you — noted.' }], before: [r.span] });

    // Act — 👎 with a note
    r.bad.dispatch('submit');
    r.note.value = 'Wrong year';
    r.bad.dispatch('submit');
    await settle();
    const afterBad = [r.pressedOf(r.good), r.value(r.good), r.pressedOf(r.bad), r.value(r.bad), r.note.hidden, r.note.value];
    const said = r.span.querySelector('[data-pf-rating-said]');
    const saidText = said.textContent;

    // …then 👍, which replaces it
    r.good.dispatch('submit');
    await settle();

    // Assert
    assert.deepEqual(afterBad, ['false', '1', 'true', '0', true, ''], '👎 pressed; pressing it again takes it back');
    assert.equal(saidText, '✓ Thank you — noted.');
    assert.equal(said.getAttribute('role'), 'status', 'read out by a screen reader');
    assert.deepEqual([r.pressedOf(r.good), r.value(r.good), r.pressedOf(r.bad), r.value(r.bad)], ['true', '0', 'false', '-1']);
    assert.equal(ui.requests[0].body.form, r.bad, 'the form itself is what is sent, token and note with it');
});

/**
 * A refusal the server words is said beside the buttons, which stay as they were; anything that
 * is not an answer — a lost connection, a page instead of JSON — posts the form as it would have
 * without the script.
 */
test('a refusal is said, and anything else falls back to posting the form', async () => {
    // Arrange
    const refused = rating();
    const lost = rating();
    run({ answers: [{ ok: false, error: 'There is no such answer.' }, new Error('offline')], before: [refused.span, lost.span] });

    // Act
    refused.good.dispatch('submit');
    await settle();
    lost.good.dispatch('submit');
    await settle();

    // Assert
    assert.equal(refused.span.querySelector('[data-pf-rating-said]').textContent, 'There is no such answer.');
    assert.equal(refused.pressedOf(refused.good), 'false', 'nothing changed');
    assert.equal(refused.good.submitted, 0);
    assert.equal(lost.good.submitted, 1, 'posted the old way');
    assert.equal(lost.good.querySelector('button').disabled, false);
});

/** The page's `/` commands, as ChatLook::commands() writes them. */
const COMMANDS = JSON.stringify([
    { name: 'new', says: 'Start a new conversation', url: '/chat' },
    { name: 'scheduled', says: 'Your scheduled tasks', url: '/chat/scheduled' },
    { name: 'attach', says: 'Attach a document', action: 'attach' },
    { name: 'memory', says: 'What it remembers', action: 'memory' },
    { name: 'week', says: 'This week', text: 'How did this week go?' },
]);

/** Type into the box as a person does: the value, then the input event. */
const type = (ui, value) => { ui.box.value = value; ui.box.dispatch('input'); };

/** Press a key in the box; the event says whether something took it. */
const press = (ui, key) => ui.box.dispatch('keydown', { key });

/**
 * `/` at the start of the box opens a menu of the page's commands over the composer, narrowed as
 * the name is typed; ↑↓ move the choice round, Enter or Tab runs it, Escape closes it, and while
 * it is open Enter runs the command rather than sending "/sc" as a question. A command goes to
 * its page, opens the file picker, opens what it remembers, or puts its text in the box.
 *
 * This is how a composer offers its tools without a row of buttons — and a person pressing Enter
 * on a half-typed command must never have it sent to the model.
 */
test('/ opens the commands, narrowed as typed, run from the keyboard', () => {
    // Arrange
    const memory = element('details', { 'data-pf-chat-memory': '' });
    const ui = run({ answers: [], attributes: { 'data-commands': COMMANDS }, before: [memory] });
    const menu = ui.form.querySelector('[role]');

    // Act / Assert — everything on "/", narrowed on "/s"
    type(ui, '/');
    assert.equal(menu.hidden, false);
    assert.equal(menu.children.length, 5);
    assert.equal(ui.box.getAttribute('aria-expanded'), 'true');
    type(ui, '/s');
    assert.deepEqual(menu.children.map((li) => li.children[0].textContent), ['/scheduled']);

    // ↓ wraps round; Enter runs the chosen one, and is not a question sent
    type(ui, '/');
    press(ui, 'ArrowDown');
    press(ui, 'ArrowUp');
    press(ui, 'ArrowUp');
    assert.equal(ui.box.getAttribute('aria-activedescendant'), menu.attributes.id + '-4', 'up from the first is the last');
    const enter = press(ui, 'Enter');
    assert.equal(enter.prevented, true);
    assert.equal(ui.box.value, 'How did this week go?', 'its text, ready to send or change');
    assert.equal(menu.hidden, true);
    assert.equal(ui.requests.length, 0, 'nothing was sent');

    // Tab runs too; a page command goes there, attach opens the picker, memory opens the panel
    type(ui, '/sch');
    press(ui, 'Tab');
    assert.equal(ui.window.location.href, '/chat/scheduled');
    type(ui, '/at');
    press(ui, 'Enter');
    assert.equal(ui.picker.clicked, 1);
    type(ui, '/memory');
    menu.children[0].dispatch('mousedown');
    assert.equal(memory.open, true);

    // Escape closes; a word that is no command closes it; so does a space after one
    type(ui, '/n');
    press(ui, 'Escape');
    assert.equal(menu.hidden, true);
    assert.equal(press(ui, 'ArrowDown').prevented, undefined, 'closed, the keys are the box\'s own');
    type(ui, '/nothing');
    assert.equal(menu.hidden, true);
    type(ui, '/new please');
    assert.equal(menu.hidden, true);
});

/**
 * A page without commands has no menu, and `/` is just a character.
 */
test('without commands, / is only a character', () => {
    // Arrange
    const ui = run({ answers: [] });

    // Act
    type(ui, '/');

    // Assert
    assert.equal(ui.form.querySelector('[role]'), null);
});

/**
 * An empty conversation is a greeting over the composer. Asked, the greeting goes and the page is
 * no longer `pf-chat-empty`, so the composer moves under the conversation; refused, it is empty
 * again, as the question goes back to the box.
 */
test('the greeting goes with the first question, and comes back with a refused one', async () => {
    // Arrange
    const hero = element('div', { 'data-pf-chat-hero': '' });
    const ui = run({ pageClass: 'pf-chat pf-chat-empty px-4', before: [hero], answers: [{ status: 403, error: 'Agree first.' }] });
    ui.box.value = 'Hi';

    // Act
    ui.form.dispatch('submit');
    const during = ui.page.className;
    const heroGone = hero.parentNode === null;
    await settle();

    // Assert
    assert.equal(heroGone, true);
    assert.equal(during, 'pf-chat px-4');
    assert.equal(ui.page.className, 'pf-chat px-4 pf-chat-empty');
});

test('asking shows the question, follows it, and swaps the answered page in', async () => {
    // Arrange
    const ui = run({ answers: [{ status: 202, data: { ask_id: 5 } }, { data: { status: 'waiting' } }, { data: { status: 'answered', conversation_id: 9 } }, '<div data-pf-chat>answered</div>'] });
    ui.box.value = 'How did it go?';

    // Act
    const submitted = ui.form.dispatch('submit');
    await settle();
    const shown = ui.messages.children[0].children[1].textContent;
    const said = ui.messages.children[0].children[0].textContent;
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
    assert.match(said, /^You · today \d{1,2}:\d\d/, 'as the page draws one: you, and now');
    assert.equal(ui.messages.children[0].children[1].className, 'bubble', 'in the theme\'s bubble');
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

test('a message\'s time is the reader\'s: today with the time, another day with the date', () => {
    // Arrange
    const now = Date.parse('2026-10-13T20:00:00Z');
    const today = element('time', { 'data-pf-chat-time': '', datetime: '2026-10-13T19:50:00Z' });
    const before = element('time', { 'data-pf-chat-time': '', datetime: '2026-09-01T08:00:00Z' });
    const broken = element('time', { 'data-pf-chat-time': '', datetime: 'never' });
    broken.textContent = '1 Sep 2026 08:00 UTC';

    // Act
    run({ answers: [], now: () => now, before: [today, before, broken] });

    // Assert
    assert.match(today.textContent, /^today \d{1,2}:\d\d/);
    assert.match(before.textContent, /2026/, 'another day carries its date');
    assert.equal(broken.textContent, '1 Sep 2026 08:00 UTC', 'what is not a time keeps the server\'s words');
});

test('copy puts the answer\'s Markdown on the clipboard, and says so', async () => {
    // Arrange
    const copied = [];
    const copy = element('button', { 'data-pf-chat-copy': '**Total**: 12' });
    copy.textContent = 'Copy';
    const ui = run({ answers: [], before: [copy], clipboard: { writeText: (text) => { copied.push(text); return Promise.resolve(); } } });

    // Act
    copy.click();
    await settle();
    const said = copy.textContent;
    ui.timers.shift()();

    // Assert
    assert.deepEqual(copied, ['**Total**: 12'], 'the Markdown, not the HTML');
    assert.equal(said, '✓ Copied');
    assert.equal(copy.textContent, 'Copy', 'and back after a moment');
});

test('a table in an answer is copied as tab-separated rows, which a spreadsheet pastes as cells', async () => {
    // Arrange
    const copied = [];
    const answer = element('div');
    answer.className = 'pf-chat-answer';
    const wrapper = answer.appendChild(element('div'));
    const table = wrapper.appendChild(element('table'));
    [['Name', 'Town'], ['Μαρία', 'Athens\nnorth'], ['Nikos', 'Patras']].forEach((cells, at) => {
        const row = table.appendChild(element('tr'));
        cells.forEach((text) => { row.appendChild(element(at === 0 ? 'th' : 'td')).textContent = text; });
    });
    const ui = run({ answers: [], before: [answer], clipboard: { writeText: (text) => { copied.push(text); return Promise.resolve(); } } });
    const button = answer.children[0];

    // Act
    button.click();
    await settle();

    // Assert
    assert.equal(button.className, 'pf-chat-copy-table', 'a button over the table');
    assert.equal(answer.children[1], wrapper, 'placed before the table\'s own scroll');
    assert.deepEqual(copied, ['Name\tTown\nΜαρία\tAthens north\nNikos\tPatras'], 'a line break in a cell does not split the row');
    assert.equal(ui.timers.length, 1, 'and says so for a moment');
});

test('a question asked in place falls back on the theme\'s inline style', async () => {
    // Arrange
    const ui = run({ answers: [{ status: 202, data: { ask_id: 5 } }], attributes: { 'data-question-style': 'background-color:rgba(128,128,128,.12);border-radius:1rem' } });
    ui.box.value = 'Hi';

    // Act
    ui.form.dispatch('submit');
    await settle();
    const bubble = ui.messages.children[0].children[1];

    // Assert
    assert.equal(bubble.style['background-color'], 'rgba(128,128,128,.12)');
    assert.equal(bubble.style['border-radius'], '1rem');
});

/** A chart's holder, as Markdown::toHtml() draws one, with the code inside it. */
function chartOf(spec) {
    const holder = element('div', { 'data-pf-chart': typeof spec === 'string' ? spec : JSON.stringify(spec) });
    holder.appendChild(element('pre')).textContent = 'the code';

    return holder;
}

/** Every descendant with this tag name. */
const all = (root, tag) => findAll(root, tag);

test('a chart is drawn as SVG over its code: bars, a line, a pie, with a key and the figures in tooltips', () => {
    // Arrange
    const bars = chartOf({ type: 'bar', title: 'Sign-ups', labels: ['Jan', 'Feb', 'Mar'], series: [{ name: '2025', data: [3, 5, 2] }, { name: '2026', data: [4, 1, 6] }] });
    const line = chartOf({ type: 'area', stacked: true, labels: ['Q1', 'Q2'], series: [{ name: 'A', data: [1, 2] }, { name: 'B', data: [3, 4] }] });
    const round = chartOf({ type: 'doughnut', labels: ['Yes', 'No', 'None'], series: [{ data: [70, 30, 0] }] });
    const whole = chartOf({ type: 'pie', labels: ['All'], series: [{ data: [5] }] });
    const sideways = chartOf({ type: 'hbar', labels: ['A'], series: [{ name: 'x', data: [-2] }], x: 'count', y: 'kind' });

    // Act
    run({ answers: [], before: [bars, line, round, whole, sideways] });

    // Assert
    assert.equal(all(bars, 'pre').length, 0, 'the code is replaced');
    assert.equal(all(bars, 'rect').length, 6, 'one bar per label and series');
    assert.match(all(bars, 'title')[0].textContent, /^Jan · 2025: 3$/, 'each shape says its figure');
    assert.equal(bars.children[0].textContent, 'Sign-ups', 'the title over it');
    assert.equal(bars.children[bars.children.length - 1].children.length, 2, 'a key, one entry per series');
    assert.equal(all(line, 'polygon').length, 2, 'two stacked areas');
    assert.equal(all(line, 'circle').length, 4, 'a point per label and series');
    assert.equal(all(round, 'path').length, 2, 'an empty slice draws nothing');
    assert.match(all(round, 'title')[0].textContent, /Yes: 70 \(70%\)/);
    assert.equal(all(whole, 'circle').length, 1, 'a whole pie is a circle: an arc to itself draws nothing');
    assert.equal(all(sideways, 'rect').length, 1);
});

test('a chart that is not one stays as its code; the application\'s own library draws instead', () => {
    // Arrange
    const broken = chartOf('{not json');
    const empty = chartOf({ type: 'bar', labels: [], series: [] });
    const theirs = chartOf({ type: 'line', labels: ['a'], series: [{ data: [1] }] });
    const drawn = [];

    // Act
    run({ answers: [], before: [broken, empty, theirs], pfChat: { chart: (el, spec) => { drawn.push(spec.type); return true; } } });

    // Assert
    assert.equal(all(broken, 'pre').length, 1, 'not JSON: the code stays');
    assert.equal(all(empty, 'pre').length, 1, 'no series: the code stays');
    assert.deepEqual(drawn, ['line'], 'handed to the application\'s library');
    assert.equal(all(theirs, 'svg').length, 0, 'and not drawn twice');
});

/**
 * A category's name under a bar is cut to the room its bar has, so long names side by side do not
 * run into each other, and the whole name is in its tooltip; a short one is left whole.
 */
test('long category names under bars are cut to their room, whole in a tooltip', () => {
    // Arrange
    const names = ['Φύλαξη παιδιών', 'Φροντίδα ηλικιωμένων και ατόμων με αναπηρία', 'Κατοικίδια', 'Καθαριότητα σπιτιού', 'Ιδιαίτερα μαθήματα', 'Ψυχική υγεία', 'Άλλο', 'Και άλλο'];
    const bars = chartOf({ type: 'bar', labels: names, series: [{ name: 'x', data: names.map((n, i) => i + 1) }] });

    // Act
    run({ answers: [], before: [bars] });
    const shown = all(bars, 'text').map((t) => t.textContent).filter((t) => names.some((n) => n === t || (t.endsWith('…') && n.startsWith(t.slice(0, -1)))));
    const cut = all(bars, 'g').map((g) => g.children.find((c) => c.tagName === 'TITLE')?.textContent);

    // Assert
    assert.equal(shown.length, 8, 'every name under its bar');
    assert.ok(shown.every((t) => t.length <= 11), 'eight bars in 640 units: each name within its share — ' + shown.join(' / '));
    assert.ok(shown.includes('Φροντίδα η…'), 'a long one cut, marked');
    assert.ok(cut.includes('Φροντίδα ηλικιωμένων και ατόμων με αναπηρία'), 'whole in its tooltip');
    assert.ok(shown.includes('Άλλο'), 'a short one whole');
});

/**
 * A page that places answers itself — the conversations — has their charts drawn on demand:
 * each once, however often it asks, and what is not a chart left as code.
 */
test('drawCharts draws the charts under an element, each once', () => {
    // Arrange
    const ui = run({ answers: [] });
    const message = element('div');
    const bars = message.appendChild(chartOf({ type: 'bar', labels: ['a', 'b'], series: [{ name: 'x', data: [1, 2] }] }));
    const broken = message.appendChild(chartOf('{not json'));

    // Act
    vm.runInNewContext('window.PfChat.drawCharts(message); window.PfChat.drawCharts(message)', { window: ui.window, message });

    // Assert
    assert.equal(all(bars, 'svg').length, 1, 'drawn, and not twice');
    assert.equal(all(broken, 'pre').length, 1, 'not a chart: its code stays');
});

test('the examples go the moment a question is sent, and come back with a refused one', async () => {
    // Arrange
    const sent = run({ answers: [{ status: 202, data: { ask_id: 1 } }], examples: ['One?', 'Two?'] });
    const refused = run({ answers: [{ status: 403, error: 'No.' }], examples: ['One?'] });
    sent.box.value = 'Hi';
    refused.box.value = 'Hi';

    // Act
    sent.box.dispatch('keydown', { key: 'Enter', shiftKey: false });
    const atOnce = sent.page.style.display;
    refused.form.dispatch('submit');
    await settle();

    // Assert
    assert.equal(atOnce, 'none', 'hidden on Enter, before any answer — by display, which an inline display:flex cannot win over');
    assert.equal(refused.page.style.display, '', 'the question is back in the box, and so are they');
});

test('a conversation is run once however often it is asked: no button or listener twice', async () => {
    // Arrange — a table in an answer, which gets one copy button per run
    const answer = element('div');
    answer.className = 'pf-chat-answer';
    answer.appendChild(element('div')).appendChild(element('table'));
    const ui = run({ answers: [{ status: 202, data: { ask_id: 1 } }], before: [answer] });

    // Act — what the side panel does after this script's own start found the same element
    vm.runInNewContext('window.PfChat.init(page)', { window: ui.window, page: ui.page });
    ui.box.value = 'Hi';
    ui.form.dispatch('submit');
    await settle();

    // Assert
    assert.equal(answer.children.filter((c) => c.className === 'pf-chat-copy-table').length, 1, 'one copy button');
    assert.equal(ui.requests.filter((r) => r.method === 'POST').length, 1, 'the question sent once');
});

test('a page without the conversation does nothing', () => {
    // Act / Assert
    assert.doesNotThrow(() => vm.runInNewContext(SOURCE, { window: {}, document: { querySelector: () => null } }));
});

test('the analyst\'s picture follows the question: listening, thinking, talking, then pleased — or troubled', async () => {
    // Arrange
    const states = [];
    const ui = run({ states, answers: [{ status: 202, data: { ask_id: 5 } }, { data: { status: 'answered', conversation_id: 9 } }, '<div data-pf-chat>answered</div>'] });
    const failing = [];
    const bad = run({ states: failing, answers: [{ status: 202, data: { ask_id: 6 } }, { data: { status: 'failed', says: 'No.' } }] });

    // Act
    ui.box.value = 'How did it go?';
    ui.box.dispatch('input');
    ui.form.dispatch('submit');
    await settle();
    ui.timers.splice(0).forEach((fn) => fn());
    await settle();
    bad.box.value = 'Again?';
    bad.form.dispatch('submit');
    await settle();

    // Assert
    assert.deepEqual(states.slice(0, 3), ['listening', 'thinking', 'talking'], 'writing, asked, answered');
    assert.ok(states.includes('success'), 'and pleased a moment later');
    assert.deepEqual(failing, ['thinking', 'error'], 'a failure troubles it');
});

