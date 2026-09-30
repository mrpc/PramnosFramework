/**
 * Runs the shipped `pf-auth.js` under Node against the two-step page's phone prompt and its
 * "Don't ask again on this device" box.
 *
 * The real bytes a browser is served, as in `auth-submit-progress.mjs`, on a DOM just large
 * enough for them — with the clock and the network in the harness's hands, so "twenty seconds
 * without a receipt" is a number rather than a wait.
 *
 * Usage: node auth-sign-in-approval.mjs <script path> <scenario>
 * Prints one JSON object describing the page afterwards.
 */
import { readFileSync } from 'node:fs';

const [scriptPath, scenario] = process.argv.slice(2);

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
        this.textContent = '';
        this.value = '';
        this.listeners = {};
        this.submitted = 0;
    }

    getAttribute(name) {
        return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
    }

    setAttribute(name, value) { this.attributes[name] = String(value); }
    removeAttribute(name) { delete this.attributes[name]; }
    addEventListener(type, handler) { (this.listeners[type] = this.listeners[type] || []).push(handler); }
    appendChild(child) { this.children.push(child); return child; }
    submit() { this.submitted++; }
    // A browser's requestSubmit() fires the submit event first; submit() does not.
    requestSubmit() { this.dispatch('submit'); this.submitted++; }
    descendants() { return this.children.reduce((all, child) => all.concat([child], child.descendants()), []); }
    querySelectorAll(selector) { return matchAll(this.descendants(), selector); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }

    dispatch(type) {
        const event = { type, target: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        (this.listeners[type] || []).forEach((handler) => handler(event));
        return event;
    }
}

function matchAll(elements, selector) {
    const parts = selector.split(',').map((s) => s.trim()).filter(Boolean);

    return elements.filter((el) => parts.some((part) => matchOne(el, part)));
}

function matchOne(el, part) {
    if (part.startsWith('#')) { return el.getAttribute('id') === part.slice(1); }

    const m = /^([a-zA-Z]*)(?:\[([a-zA-Z-]+)(?:="([^"]*)")?\])?$/.exec(part);
    if (!m) { return false; }

    const [, tag, attr, value] = m;
    if (tag && el.tagName !== tag.toUpperCase()) { return false; }
    if (attr) {
        const actual = el.getAttribute(attr);
        if (actual === null) { return false; }
        if (value !== undefined && actual !== value) { return false; }
    }

    return true;
}

// ── the page ────────────────────────────────────────────────────────────────

const root = new El('html');
const codeForm = new El('form', { method: 'POST' });
const trustBox = new El('input', { type: 'checkbox', 'data-pf-trust-device': '' });
trustBox.checked = scenario !== 'trust-unticked';
const passkey = new El('button', { 'data-pf-passkey-stepup': '', 'data-verify-url': '/Account/passkeyVerify' });
const others = new El('details', { id: 'pf-other-ways' });
others.open = false;
const push = new El('div', { 'data-pf-push-approval': '', 'data-status-url': '/Account/pushstatus', 'data-patience': '20' });
const state = new El('p', { 'data-pf-push-state': '' });
const finish = new El('form', { 'data-pf-push-finish': '' });
const resend = new El('form', { 'data-pf-push-resend': '' });
resend.classList.add('hidden');
push.children.push(state, finish, resend);
others.children.push(codeForm);
root.children.push(push, others, trustBox, passkey);

const documentStub = {
    readyState: 'complete',
    querySelectorAll: (selector) => root.querySelectorAll(selector),
    querySelector: (selector) => root.querySelector(selector),
    addEventListener: () => {},
    createElement: (tag) => new El(tag),
};

// The clock and the network, driven by the scenario.
let now = 1000;
const timers = [];
const fakeSetTimeout = (fn) => { timers.push(fn); return timers.length; };
const answers = {
    'push-approved': ['sent', 'delivered', 'approved'],
    'push-slow': ['sent', 'sent'],
    'push-denied': ['delivered', 'denied'],
    'push-expired': ['expired'],
    'trust-ticked': ['sent'],
    'trust-unticked': ['sent'],
}[scenario] || ['sent'];
let asked = 0;
const fetchStub = () => {
    const answer = answers[Math.min(asked, answers.length - 1)];
    asked++;

    return Promise.resolve({ json: () => Promise.resolve({ state: answer }) });
};

const windowStub = { document: documentStub, location: { href: 'https://example.test/Account/verify' } };
windowStub.window = windowStub;
globalThis.Date.now = () => now;

const source = readFileSync(scriptPath, 'utf8');
const run = new Function('window', 'document', 'setTimeout', 'clearTimeout', 'fetch', source);
run(windowStub, documentStub, fakeSetTimeout, () => {}, fetchStub);

const settle = () => new Promise((resolve) => setImmediate(resolve));

(async () => {
    // Let each poll answer, then move the clock past the patience before the next.
    for (let round = 0; round < 4; round++) {
        await settle();
        await settle();
        if (scenario === 'push-slow') { now += 25000; }
        const next = timers.shift();
        if (next) { next(); }
    }
    await settle();

    if (scenario.startsWith('trust')) {
        codeForm.dispatch('submit');
        trustBox.checked = !trustBox.checked;
        trustBox.dispatch('change');
    }

    // By property: a browser reflects `.name` into the attribute, this DOM does not.
    const trustField = codeForm.children.find((child) => child.name === 'trust_device') || null;

    process.stdout.write(JSON.stringify({
        state: state.textContent,
        finished: finish.submitted,
        othersOpen: others.open,
        resendHidden: resend.classList.contains('hidden') || resend.style.display === 'none',
        polls: asked,
        trustSubmitted: trustField ? trustField.value : null,
        finishTrust: (finish.children.find((child) => child.name === 'trust_device') || {}).value ?? null,
        passkeyUrl: passkey.getAttribute('data-verify-url'),
    }) + '\n');
})();
