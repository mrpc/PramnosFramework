/**
 * A DOM small enough to run a page script against under `node --test`.
 *
 * Enough of the real thing for the scripts an application writes for its pages: elements with
 * attributes, classes, `dataset`, children and listeners; events that bubble; `querySelector` for
 * the selectors such scripts use; a document with `head`, `body` and `getElementById`; a window
 * with `location` and storage. Not a browser: no layout, no parsing of assigned `innerHTML` beyond
 * keeping the string, no CSS.
 *
 * Selectors: `tag`, `#id`, `.class`, `[attr]`, `[attr="v"]` (also `^=`, `$=`, `*=`), compounds of
 * those (`button.primary[data-x]`), descendants (`nav a`), children (`ul > li`), and comma lists.
 */
'use strict';

/** A selector part — one compound — as a test over one element. */
function compound(text) {
    const parts = text.match(/(^[\w-]+)|(#[\w-]+)|(\.[\w-]+)|(\[[^\]]+\])/g) || [];
    if (parts.join('') !== text) {
        throw new Error(`pramnos-testing: selector "${text}" is not supported`);
    }

    return (el) => parts.every((part) => {
        if (part[0] === '#') {
            return el.getAttribute('id') === part.slice(1);
        }
        if (part[0] === '.') {
            return el.classList.contains(part.slice(1));
        }
        if (part[0] === '[') {
            const m = part.match(/^\[([\w:-]+)(?:([\^$*]?)=["']?([^"']*)["']?)?\]$/);
            if (!m) {
                throw new Error(`pramnos-testing: attribute selector "${part}" is not supported`);
            }
            const [, name, op, value] = m;
            const actual = el.getAttribute(name);
            if (actual === null) {
                return false;
            }
            if (value === undefined) {
                return true;
            }
            return op === '^' ? actual.startsWith(value)
                : op === '$' ? actual.endsWith(value)
                    : op === '*' ? actual.includes(value)
                        : actual === value;
        }
        return el.tagName === part.toUpperCase();
    });
}

/** Whether `el` matches one selector of a comma list, read right to left. */
function matchesOne(el, selector) {
    const tokens = selector.trim().replace(/\s*>\s*/g, ' > ').split(/\s+/);
    let test = compound(tokens.pop());
    if (!test(el)) {
        return false;
    }
    let current = el;
    while (tokens.length) {
        const token = tokens.pop();
        if (token === '>') {
            test = compound(tokens.pop());
            current = current.parentNode;
            if (!current || !current.tagName || !test(current)) {
                return false;
            }
            continue;
        }
        test = compound(token);
        do {
            current = current.parentNode;
        } while (current && current.tagName && !test(current));
        if (!current || !current.tagName) {
            return false;
        }
    }
    return true;
}

function matches(el, selector) {
    return selector.split(',').some((one) => matchesOne(el, one));
}

function descendants(root) {
    return root.children.flatMap((child) => [child, ...descendants(child)]);
}

/** An event, as `new Event(type, { bubbles })` makes one. */
class Event {
    constructor(type, init = {}) {
        this.type = type;
        this.bubbles = Boolean(init.bubbles);
        this.cancelable = init.cancelable !== false;
        this.defaultPrevented = false;
        this.target = null;
        this.currentTarget = null;
        this._stopped = false;
        Object.assign(this, init.detail !== undefined ? { detail: init.detail } : {});
    }

    preventDefault() { this.defaultPrevented = true; }

    stopPropagation() { this._stopped = true; }
}

class CustomEvent extends Event {}

/** Listeners, for anything that receives events. */
function eventTarget(target) {
    const listeners = {};
    target.addEventListener = (type, handler) => { (listeners[type] ||= []).push(handler); };
    target.removeEventListener = (type, handler) => {
        listeners[type] = (listeners[type] || []).filter((one) => one !== handler);
    };
    target._fire = (event) => {
        event.currentTarget = target;
        (listeners[event.type] || []).slice().forEach((handler) => handler.call(target, event));
    };
    return target;
}

/**
 * One element.
 *
 * @param {object} document  The document it belongs to
 * @param {string} tag       Its tag name
 * @param {object} attributes Its attributes
 */
function createElement(document, tag, attributes = {}) {
    const attrs = {};
    const el = eventTarget({
        tagName: String(tag).toUpperCase(),
        nodeType: 1,
        ownerDocument: document,
        children: [],
        parentNode: null,
        style: {},
        hidden: false,
        disabled: false,
        checked: false,
        value: '',
        innerHTML: '',
        _text: '',

        get id() { return this.getAttribute('id') || ''; },
        set id(value) { this.setAttribute('id', value); },
        get className() { return this.getAttribute('class') || ''; },
        set className(value) { this.setAttribute('class', value); },
        get textContent() { return this._text + this.children.map((c) => c.textContent).join(''); },
        set textContent(value) { this._text = String(value); this.children = []; },
        get firstElementChild() { return this.children[0] || null; },
        get lastElementChild() { return this.children[this.children.length - 1] || null; },
        get parentElement() { return this.parentNode && this.parentNode.tagName ? this.parentNode : null; },

        getAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null; },
        setAttribute(name, value) {
            attrs[name] = String(value);
            if (name === 'value') {
                this.value = String(value);
            }
        },
        hasAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name); },
        removeAttribute(name) { delete attrs[name]; },
        get attributes() { return Object.keys(attrs).map((name) => ({ name, value: attrs[name] })); },

        appendChild(child) {
            if (child.parentNode) {
                child.parentNode.removeChild(child);
            }
            child.parentNode = this;
            this.children.push(child);
            return child;
        },
        append(...nodes) { nodes.forEach((node) => this.appendChild(node)); },
        insertBefore(child, before) {
            if (child.parentNode) {
                child.parentNode.removeChild(child);
            }
            const at = before ? this.children.indexOf(before) : -1;
            child.parentNode = this;
            if (at < 0) {
                this.children.push(child);
            } else {
                this.children.splice(at, 0, child);
            }
            return child;
        },
        removeChild(child) {
            this.children = this.children.filter((one) => one !== child);
            child.parentNode = null;
            return child;
        },
        remove() {
            if (this.parentNode) {
                this.parentNode.removeChild(this);
            }
        },
        cloneNode(deep = false) {
            const copy = createElement(document, tag, { ...attrs });
            copy._text = this._text;
            copy.value = this.value;
            copy.innerHTML = this.innerHTML;
            if (deep) {
                this.children.forEach((child) => copy.appendChild(child.cloneNode(true)));
            }
            return copy;
        },

        matches(selector) { return matches(this, selector); },
        closest(selector) {
            for (let up = this; up && up.tagName; up = up.parentNode) {
                if (matches(up, selector)) {
                    return up;
                }
            }
            return null;
        },
        querySelectorAll(selector) { return descendants(this).filter((one) => matches(one, selector)); },
        querySelector(selector) { return this.querySelectorAll(selector)[0] || null; },

        // Up through the ancestors, the document and the window — where a page script that
        // delegates (`document.addEventListener('click', …)`) is listening.
        dispatchEvent(event) {
            event.target = event.target || this;
            let at = this;
            while (at && !event._stopped) {
                at._fire(event);
                if (!event.bubbles) {
                    break;
                }
                at = at.parentNode || (at === document ? document.defaultView : null);
            }
            return !event.defaultPrevented;
        },
        click() { return this.dispatchEvent(new Event('click', { bubbles: true })); },
        focus() { document.activeElement = this; },
        blur() { if (document.activeElement === this) { document.activeElement = null; } },
    });

    el.classList = {
        list() { return (el.getAttribute('class') || '').split(/\s+/).filter(Boolean); },
        contains(name) { return this.list().includes(name); },
        add(...names) { el.setAttribute('class', [...new Set([...this.list(), ...names])].join(' ')); },
        remove(...names) { el.setAttribute('class', this.list().filter((n) => !names.includes(n)).join(' ')); },
        toggle(name, force) {
            const on = force === undefined ? !this.contains(name) : Boolean(force);
            if (on) { this.add(name); } else { this.remove(name); }
            return on;
        },
    };

    el.dataset = new Proxy({}, {
        get(_, key) {
            return typeof key === 'string' ? el.getAttribute('data-' + key.replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())) ?? undefined : undefined;
        },
        set(_, key, value) {
            el.setAttribute('data-' + String(key).replace(/[A-Z]/g, (c) => '-' + c.toLowerCase()), value);
            return true;
        },
        has(_, key) { return el.hasAttribute('data-' + String(key).replace(/[A-Z]/g, (c) => '-' + c.toLowerCase())); },
    });

    Object.entries(attributes).forEach(([name, value]) => el.setAttribute(name, value));

    return el;
}

/** Storage over a Map, as `localStorage` behaves. */
function storage() {
    const map = new Map();
    return {
        getItem: (key) => (map.has(String(key)) ? map.get(String(key)) : null),
        setItem: (key, value) => { map.set(String(key), String(value)); },
        removeItem: (key) => { map.delete(String(key)); },
        clear: () => { map.clear(); },
        key: (i) => [...map.keys()][i] ?? null,
        get length() { return map.size; },
    };
}

/**
 * A window with its document.
 *
 * @param {object} options
 * @param {string} options.url        The page's address
 * @param {object} options.meta       `<meta name>` → content, in the head (`csrf` for pfApiHeaders)
 * @param {string} options.readyState The document's readiness
 * @returns {{window: object, document: object}}
 */
function createDom({ url = 'https://app.test/', meta = {}, readyState = 'complete' } = {}) {
    const document = eventTarget({ readyState, cookie: '', title: '', activeElement: null });
    const html = createElement(document, 'html');
    html.parentNode = document;
    document.documentElement = html;
    document.head = html.appendChild(createElement(document, 'head'));
    document.body = html.appendChild(createElement(document, 'body'));
    Object.entries(meta).forEach(([name, content]) => {
        const tag = createElement(document, 'meta', { name, content });
        tag.content = content;
        document.head.appendChild(tag);
    });
    document.createElement = (tag) => createElement(document, tag);
    document.createTextNode = (text) => ({ nodeType: 3, textContent: String(text), parentNode: null, children: [], cloneNode() { return { ...this }; } });
    document.querySelectorAll = (selector) => html.querySelectorAll(selector);
    document.querySelector = (selector) => html.querySelector(selector);
    document.getElementById = (id) => html.querySelector('#' + id);
    document.dispatchEvent = (event) => {
        event.target = event.target || document;
        document._fire(event);
        if (event.bubbles && !event._stopped) {
            document.defaultView._fire(event);
        }
        return !event.defaultPrevented;
    };

    const address = new URL(url);
    const window = eventTarget({
        document,
        location: {
            get href() { return address.href; },
            set href(value) { address.href = new URL(value, address).href; window.navigatedTo = address.href; },
            get pathname() { return address.pathname; },
            get search() { return address.search; },
            get hash() { return address.hash; },
            get origin() { return address.origin; },
            assign(value) { this.href = value; },
            replace(value) { this.href = value; },
            reload() { window.reloaded = (window.reloaded || 0) + 1; },
        },
        history: {
            entries: [],
            pushState(state, title, to) { this.entries.push(String(to)); address.href = new URL(to, address).href; },
            replaceState(state, title, to) { address.href = new URL(to, address).href; },
            back() {},
        },
        localStorage: storage(),
        sessionStorage: storage(),
        navigator: { userAgent: 'pramnos-testing', language: 'en' },
        Event,
        CustomEvent,
        confirm: () => true,
        alert: () => {},
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
        URL,
        URLSearchParams,
        console,
        JSON,
        Promise,
    });
    window.window = window;
    window.self = window;
    window.dispatchEvent = (event) => {
        event.target = event.target || window;
        window._fire(event);
        return !event.defaultPrevented;
    };
    document.defaultView = window;

    return { window, document };
}

module.exports = { createDom, createElement, Event, CustomEvent, matches };
