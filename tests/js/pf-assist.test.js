/**
 * The administration's side panel, `scaffolding/assets/js/pf-assist.js`, driven end to end.
 *
 * What has to hold: the button opens the panel from its template and closes it again, Esc too;
 * a tab shows its pane; Ask is the analyst's page fetched, its conversation taken out and run by
 * pf-chat.js, with the page it was opened on added to the question form and the list and
 * settings hidden; the conversation, the tab and whether it is open survive to the next page;
 * a new conversation starts empty; a page that cannot be fetched says so.
 *
 * Run:
 *   node --test tests/js/pf-assist.test.js
 *
 * Zero npm dependencies: node:test, node:assert, node:vm, node:fs.
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const vm       = require('node:vm');
const fs       = require('node:fs');
const path     = require('node:path');

const SOURCE = fs.readFileSync(path.join(__dirname, '..', '..', 'scaffolding/assets/js/pf-assist.js'), 'utf8');

/** A small element: attributes, children, listeners and the selectors the script uses. */
function element(tag, attributes = {}) {
    const el = {
        tagName: tag.toUpperCase(),
        attributes: { ...attributes },
        children: [],
        listeners: {},
        parentNode: null,
        style: {},
        hidden: false,
        value: '',
        _text: '',
        get textContent() { return this._text; },
        set textContent(value) { this._text = String(value); this.children = []; },
        get firstElementChild() { return this.children[0] || null; },
        getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        removeAttribute(name) { delete this.attributes[name]; },
        appendChild(child) {
            // As the DOM does: an element appended again moves, it is not copied.
            if (child.parentNode) {
                child.parentNode.children = child.parentNode.children.filter((one) => one !== child);
            }
            child.parentNode = this;
            this.children.push(child);
            return child;
        },
        addEventListener(type, handler) { (this.listeners[type] ||= []).push(handler); },
        dispatch(type, event = {}) {
            const e = { preventDefault() { this.prevented = true; }, ...event };
            (this.listeners[type] || []).forEach((h) => h(e));
            return e;
        },
        click() { this.dispatch('click'); },
        focus() { this.focused = true; },
        closest(selector) {
            for (let up = this; up; up = up.parentNode) {
                if (matches(up, selector)) {
                    return up;
                }
            }
            return null;
        },
        querySelector(selector) { return all(this, selector)[0] || null; },
        querySelectorAll(selector) { return all(this, selector); },
        cloneNode() { return clone(this); },
    };

    return el;
}

function clone(el) {
    const copy = element(el.tagName.toLowerCase(), el.attributes);
    copy._text = el._text;
    copy.value = el.value;
    el.children.forEach((child) => copy.appendChild(clone(child)));

    return copy;
}

/** `[attr]`, `[attr="v"]`, `tag`, `tag[name="v"]`, and comma lists of them. */
function matches(el, selector) {
    return selector.split(',').some((one) => {
        const m = one.trim().match(/^(\w*)(?:\[([\w-]+)(?:(\$?)="([^"]*)")?\])?$/);
        if (!m) {
            return false;
        }
        const [, tag, attr, ends, value] = m;
        const has = attr in el.attributes;
        return (!tag || el.tagName === tag.toUpperCase())
            && (!attr || (has && (value === undefined || (ends ? String(el.attributes[attr]).endsWith(value) : el.attributes[attr] === value))));
    });
}

function all(root, selector) {
    return root.children.flatMap((child) => (matches(child, selector) ? [child] : []).concat(all(child, selector)));
}

/** The panel the server draws, inside its template, with Ask and help. */
function template(withHelp = true) {
    const tpl = element('template', { 'data-pf-assist': '' });
    const aside = element('aside', { 'data-page': JSON.stringify({ screen: 'users', kind: 'user', id: '5' }), 'data-ask': '/admin/Ai', 'data-chat-script': '/assets/js/pf-chat.js', 'data-say-failed': 'Ask could not be opened here.' });
    aside.appendChild(element('button', { 'data-pf-assist-new': '' }));
    aside.appendChild(element('button', { 'data-pf-assist-close': '' }));
    if (withHelp) {
        aside.appendChild(element('button', { 'data-pf-assist-tab': 'ask' }));
        aside.appendChild(element('button', { 'data-pf-assist-tab': 'help' }));
    }
    aside.appendChild(element('section', { 'data-pf-assist-pane': 'ask' }));
    if (withHelp) {
        aside.appendChild(element('section', { 'data-pf-assist-pane': 'help' }));
    }
    tpl.content = element('fragment');
    tpl.content.appendChild(aside);

    return tpl;
}

/** The analyst's page as the parser returns its conversation: a list, the messages, a form. */
function askPage(conversationId) {
    const chat = element('div', { 'data-pf-chat': '' });
    const layout = chat.appendChild(element('div'));
    layout.appendChild(element('nav'));
    const main = layout.appendChild(element('main'));
    main.appendChild(element('h1'));
    main.appendChild(element('p')).appendChild(element('a', { href: '/admin/Ai/settings' }));
    main.appendChild(element('div', { 'data-pf-chat-messages': '' })).appendChild(element('nav'));
    const form = main.appendChild(element('form', { 'data-pf-chat-form': '' }));
    form.appendChild(element('input', { name: 'conversation_id' })).value = String(conversationId);
    layout.appendChild(element('aside'));

    return chat;
}

/**
 * The page, the script run over it, and a fetch answering from a queue: a page's conversation,
 * or an Error.
 */
function run({ answers = [], stored = {}, withHelp = true, chatLoaded = false } = {}) {
    const opener = element('a', { 'data-pf-assist-open': '' });
    const tpl = template(withHelp);
    const body = element('body');
    const head = element('head');
    const docListeners = {};
    const storage = { 'pf-assist': JSON.stringify(stored) };
    const requests = [];
    const inits = [];
    const queue = [...answers];
    const window = {
        sessionStorage: {
            getItem: (k) => (k in storage ? storage[k] : null),
            setItem: (k, v) => { storage[k] = v; },
        },
        DOMParser: function DOMParser() {
            this.parseFromString = (html) => ({ querySelector: () => html });
        },
    };
    if (chatLoaded) {
        window.PfChat = { init: (el) => inits.push(el) };
    }
    const document = {
        body,
        head,
        querySelector: (s) => (s === '[data-pf-assist-open]' ? opener : s === 'template[data-pf-assist]' ? tpl : null),
        createElement: (t) => element(t),
        importNode: (node) => node,
        addEventListener: (type, h) => { (docListeners[type] ||= []).push(h); },
    };
    head.appendChild = (script) => {
        // The script arrives: pf-chat.js defines PfChat.
        window.PfChat = { init: (el) => inits.push(el) };
        script.onload();
        return script;
    };
    const context = {
        window,
        document,
        JSON,
        Object,
        Number,
        fetch: (url) => {
            requests.push(url);
            const next = queue.shift();
            return next instanceof Error ? Promise.reject(next) : Promise.resolve({ text: () => Promise.resolve(next) });
        },
    };
    vm.runInNewContext(SOURCE, context);

    const panel = () => body.children[0];
    const key = (k) => docListeners.keydown.forEach((h) => h({ key: k }));
    const state = () => JSON.parse(storage['pf-assist'] || '{}');

    return { opener, body, panel, requests, inits, key, state };
}

const settle = async () => {
    for (let i = 0; i < 6; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
};

test('the button opens the panel beside the page and loads Ask into it; again, or Esc, closes it', async () => {
    // Arrange
    const ui = run({ answers: [askPage(0)] });

    // Act
    const opened = ui.opener.dispatch('click');
    await settle();
    const shown = ui.panel().style.display;
    ui.opener.dispatch('click');
    const closed = ui.panel().style.display;
    ui.opener.dispatch('click');
    ui.key('Escape');

    // Assert
    assert.equal(opened.prevented, true, 'the link is not followed');
    assert.equal(shown, 'flex');
    assert.equal(closed, 'none');
    assert.equal(ui.panel().style.display, 'none', 'Esc closes it');
    assert.equal(ui.opener.focused, true, 'focus goes back to the button');
    assert.deepEqual(ui.requests, ['/admin/Ai'], 'Ask fetched once, not on every opening');
    assert.equal(ui.body.children.length, 1, 'one panel');
    assert.equal(ui.state().open, false);
});

test('Ask is the analyst\'s conversation, run by pf-chat.js, with the page sent and the list hidden', async () => {
    // Arrange
    const chat = askPage(9);
    const ui = run({ answers: [chat], stored: { c: 9 } });

    // Act
    ui.opener.dispatch('click');
    await settle();
    ui.panel().dispatch('pf-chat:ready', { target: chat });
    const form = chat.querySelector('[data-pf-chat-form]');

    // Assert
    assert.deepEqual(ui.requests, ['/admin/Ai?c=9'], 'the conversation this tab was in');
    assert.equal(chat.getAttribute('data-pf-chat-embedded'), '', 'the address bar is the page\'s');
    assert.deepEqual(ui.inits, [chat], 'pf-chat.js loaded, and run over it');
    assert.equal(form.querySelector('input[name="page[screen]"]').value, 'users');
    assert.equal(form.querySelector('input[name="page[id]"]').value, '5', 'the record this page shows');
    assert.deepEqual(chat.querySelectorAll('nav, aside, h1').map((part) => [part.tagName, part.style.display === 'none']),
        [['NAV', true], ['H1', true], ['NAV', false], ['ASIDE', true]], 'the list and the settings are Ask\'s page\'s; a nav inside an answer stays');
    assert.equal(chat.querySelector('p').style.display, 'none', 'the line with the link to Ask\'s settings');
    assert.equal(ui.state().c, 9);

    // Drawn again after an answer: the fields are not added twice
    ui.panel().dispatch('pf-chat:ready', { target: chat });
    assert.equal(form.querySelectorAll('input[name="page[screen]"]').length, 1);
});

test('the tabs: help shows its pane and is remembered; a new conversation starts empty', async () => {
    // Arrange
    const ui = run({ answers: [askPage(4), askPage(0)], stored: { c: 4 }, chatLoaded: true });

    // Act
    ui.opener.dispatch('click');
    await settle();
    ui.panel().querySelector('[data-pf-assist-tab="help"]').click();
    const help = ui.panel().querySelector('[data-pf-assist-pane="help"]').hidden;
    const ask = ui.panel().querySelector('[data-pf-assist-pane="ask"]').hidden;
    const tab = ui.state().tab;
    const weight = ui.panel().querySelector('[data-pf-assist-tab="help"]').style.fontWeight;
    ui.panel().querySelector('[data-pf-assist-new]').click();
    await settle();

    // Assert
    assert.equal(help, false);
    assert.equal(ask, true);
    assert.equal(tab, 'help');
    assert.equal(weight, '600', 'the tab shown says so');
    assert.deepEqual(ui.requests, ['/admin/Ai?c=4', '/admin/Ai'], 'a new one, without the old conversation');
    assert.equal(ui.state().c, 0);
    assert.equal(ui.panel().querySelector('[data-pf-assist-tab="ask"]').getAttribute('aria-selected'), 'true', 'back on Ask');
});

test('open on the last page: open on this one, on the tab it was on', async () => {
    // Act
    const ui = run({ stored: { open: true, tab: 'help' } });

    // Assert
    assert.equal(ui.panel().style.display, 'flex');
    assert.equal(ui.panel().querySelector('[data-pf-assist-pane="help"]').hidden, false);
    assert.deepEqual(ui.requests, [], 'Ask is not fetched until it is shown');
});

test('a page that cannot be fetched, or has no conversation, says so and tries again next time', async () => {
    // Arrange
    const ui = run({ answers: [new Error('offline'), null, askPage(0)], withHelp: false, chatLoaded: true });

    // Act
    ui.opener.dispatch('click');
    await settle();
    const first = ui.panel().querySelector('[data-pf-assist-pane="ask"]').textContent;
    ui.opener.dispatch('click');
    ui.opener.dispatch('click');
    await settle();
    const second = ui.panel().querySelector('[data-pf-assist-pane="ask"]').textContent;
    ui.opener.dispatch('click');
    ui.opener.dispatch('click');
    await settle();

    // Assert
    assert.equal(first, 'Ask could not be opened here.');
    assert.equal(second, 'Ask could not be opened here.', 'a page without a conversation');
    assert.equal(ui.requests.length, 3, 'tried again each time it was shown');
    assert.equal(ui.inits.length, 1);
});

test('a page without the panel does nothing', () => {
    // Act / Assert
    assert.doesNotThrow(() => vm.runInNewContext(SOURCE, { window: {}, document: { querySelector: () => null } }));
});
