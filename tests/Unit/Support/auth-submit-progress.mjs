/**
 * Runs the shipped `pf-auth.js` under Node against a DOM small enough to submit a form in.
 *
 * The point is to exercise the real bytes a browser is served: a test that described what the
 * script *should* do would agree with itself. Same approach as `webauthn-conditional.mjs`, for the
 * same reason.
 *
 * What is actually under test is one distinction, and it is the whole reason this code is not three
 * lines. A submit listener that called `preventDefault()` may have done one of two opposite things:
 *
 *  - **refused** the submit — validation failed, the person stays on the page and has to fix the
 *    form. Disabling its button here leaves them with a form that cannot be submitted at all.
 *  - **held** the submit — the human-check proof is a moment from finishing and the form will go on
 *    its own. That hold is precisely when somebody presses the button a second time, because
 *    nothing on the page changed, so this is the case the indicator exists for.
 *
 * The script cannot tell them apart, so it skips both and the holder marks the form itself through
 * `window.PramnosAuth.markSubmitBusy`. Scenarios `validation-refused` and `held-then-busy` are the
 * two halves of that, and if they ever agree the design has quietly broken.
 *
 * Usage: node auth-submit-progress.mjs <script path> <scenario>
 * Prints one JSON object describing the state of the form and its button afterwards.
 */
import { readFileSync } from 'node:fs';

const [scriptPath, scenario] = process.argv.slice(2);

// ── a DOM with the handful of behaviours the script uses ─────────────────────

class ClassList {
    constructor() { this.names = []; }
    add(...names) { names.forEach((n) => { if (!this.names.includes(n)) { this.names.push(n); } }); }
    remove(...names) { this.names = this.names.filter((n) => !names.includes(n)); }
    contains(name) { return this.names.includes(name); }
}

class El {
    constructor(tagName, attributes = {}) {
        this.tagName = tagName.toUpperCase();
        this.attributes = { ...attributes };
        this.children = [];
        this.classList = new ClassList();
        this.style = {};
        this.disabled = false;
        this.textContent = '';
        this.value = '';
        this.listeners = {};
    }

    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
    }

    setAttribute(name, value) { this.attributes[name] = String(value); }

    addEventListener(type, handler) {
        (this.listeners[type] = this.listeners[type] || []).push(handler);
    }

    /** Depth-first, self included — enough for the flat forms these views render. */
    descendants() {
        return this.children.reduce((all, child) => all.concat([child], child.descendants()), []);
    }

    querySelectorAll(selector) {
        return matchAll(this.descendants(), selector);
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }

    /** Fires listeners in registration order, like a real element. */
    dispatch(type) {
        const event = { type, target: this, defaultPrevented: false };
        event.preventDefault = () => { event.defaultPrevented = true; };
        (this.listeners[type] || []).forEach((handler) => handler(event));
        return event;
    }
}

/**
 * The selector support is deliberately narrow: a comma-separated list of `tag`, `tag[attr]`,
 * `tag[attr="value"]`, `[attr]` and `tag:not([attr])`. Anything else matches nothing, which is the
 * right answer for the passkey and OTP selectors the same `init()` also runs.
 */
function matchAll(elements, selector) {
    const parts = selector.split(',').map((s) => s.trim()).filter(Boolean);

    return elements.filter((el) => parts.some((part) => matchOne(el, part)));
}

function matchOne(el, part) {
    const m = /^([a-zA-Z]*)(?::not\(\[([a-zA-Z-]+)\]\))?(?:\[([a-zA-Z-]+)(?:="([^"]*)")?\])?$/.exec(part);
    if (!m) { return false; }

    const [, tag, absent, attr, value] = m;

    if (tag && el.tagName !== tag.toUpperCase()) { return false; }
    if (absent && el.getAttribute(absent) !== null) { return false; }
    if (attr) {
        const actual = el.getAttribute(attr);
        if (actual === null) { return false; }
        if (value !== undefined && actual !== value) { return false; }
    }

    return true;
}

// ── the page ────────────────────────────────────────────────────────────────

const form = new El('form', scenario === 'unmarked-form' ? {} : { 'data-pf-progress': '' });

const button = new El('button', { type: 'submit' });
button.textContent = 'Sign in';
if (scenario === 'custom-label') {
    button.setAttribute('data-pf-busy-label', 'One moment');
}

const field = new El('input', { type: 'password', name: 'password' });
form.children.push(field, button);

const root = new El('html');
root.children.push(form);

const documentStub = {
    readyState: 'complete',
    querySelectorAll: (selector) => root.querySelectorAll(selector),
    querySelector: (selector) => root.querySelector(selector),
    addEventListener: () => {},
};

const windowStub = {
    document: documentStub,
    setTimeout,
    clearTimeout,
    location: { href: 'https://example.test/login' },
};
windowStub.window = windowStub;

// ── run the shipped script ──────────────────────────────────────────────────

const source = readFileSync(scriptPath, 'utf8');
const run = new Function('window', 'document', 'setTimeout', 'clearTimeout', 'fetch', source);
run(windowStub, documentStub, setTimeout, clearTimeout, () => Promise.reject(new Error('no network')));

// A listener registered *after* the script's own, the way a second script tag on the page would be.
if (scenario === 'validation-refused' || scenario === 'held-then-busy') {
    form.addEventListener('submit', (event) => {
        event.preventDefault();

        if (scenario === 'held-then-busy' && windowStub.PramnosAuth) {
            windowStub.PramnosAuth.markSubmitBusy(form);
        }
    });
}

form.dispatch('submit');

// The script defers its own work by a tick so every other listener has run first.
setTimeout(() => {
    process.stdout.write(JSON.stringify({
        exported: typeof (windowStub.PramnosAuth || {}).markSubmitBusy,
        formBusyAttribute: form.getAttribute('data-pf-busy'),
        ariaBusy: form.getAttribute('aria-busy'),
        buttonDisabled: button.disabled,
        buttonLabel: button.textContent,
        buttonClasses: button.classList.names,
    }) + '\n');
}, 5);
