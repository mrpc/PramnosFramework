/**
 * The cookie banner, `scaffolding/assets/js/pf-consent.js`, driven end to end.
 *
 * The one script serves every theme and every app style, so this is where the
 * behaviour a visitor sees is pinned: the banner appears until a choice is made,
 * refusing is one click like accepting, gated scripts run only when allowed, a
 * new policy version asks again, and a withdrawal reloads the page so a script
 * that already ran stops.
 *
 * A PHP test can assert the tag is emitted; only this can assert that clicking
 * "Reject all" leaves the analytics script unrun.
 *
 * Run:
 *   node --test tests/js/cookie-consent.test.js
 *
 * Zero npm dependencies: node:test, node:assert, node:vm, node:fs, node:child_process.
 */
'use strict';

const { test, describe } = require('node:test');
const assert             = require('node:assert/strict');
const vm                 = require('node:vm');
const fs                 = require('node:fs');
const path               = require('node:path');
const { execFileSync }   = require('node:child_process');

const ROOT   = path.join(__dirname, '..', '..');
const SOURCE = fs.readFileSync(path.join(ROOT, 'scaffolding/assets/js/pf-consent.js'), 'utf8');

/** `tag[a][b="c"]` — the only selector shapes the script uses. */
function matches(el, selector) {
    const m = selector.match(/^([a-z]*)((?:\[[^\]]+\])*)$/i);
    if (!m) {
        throw new Error('Selector not supported by the test DOM: ' + selector);
    }
    if (m[1] && el.tagName !== m[1].toUpperCase()) {
        return false;
    }
    const attrs = m[2].match(/\[[^\]]+\]/g) || [];
    return attrs.every((part) => {
        const [, name, value] = part.match(/^\[([\w-]+)(?:="([^"]*)")?\]$/);
        const actual = name in el.attrs ? el.attrs[name] : el[name];
        return value === undefined ? actual !== undefined && actual !== null : String(actual) === value;
    });
}

/** A document just big enough for the script: elements, a cookie jar, events. */
function makeDom({ cookie = '', csrf = null } = {}) {
    const listeners = {};
    const cookies   = {};
    if (cookie) {
        cookies.pf_consent = cookie;
    }

    class Element {
        constructor(tag) {
            this.tagName = tag.toUpperCase();
            this.attrs = {};
            this.children = [];
            this.parentNode = null;
            this.handlers = {};
            this.textContent = '';
            this.open = false;
        }
        get attributes() {
            return Object.keys(this.attrs).map((name) => ({ name, value: this.attrs[name] }));
        }
        setAttribute(name, value) { this.attrs[name] = String(value); }
        getAttribute(name) { return name in this.attrs ? this.attrs[name] : null; }
        appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
        replaceChild(next, old) {
            this.children[this.children.indexOf(old)] = next;
            next.parentNode = this;
            old.parentNode = null;
        }
        remove() {
            if (this.parentNode) {
                this.parentNode.children = this.parentNode.children.filter((c) => c !== this);
                this.parentNode = null;
            }
        }
        addEventListener(type, fn) { (this.handlers[type] = this.handlers[type] || []).push(fn); }
        click() { (this.handlers.click || []).forEach((fn) => fn({ target: this, preventDefault() {} })); }
        closest(selector) {
            for (let node = this; node; node = node.parentNode) {
                if (node instanceof Element && matches(node, selector)) {
                    return node;
                }
            }
            return null;
        }
        all() { return this.children.flatMap((c) => [c, ...c.all()]); }
        querySelectorAll(selector) { return this.all().filter((el) => matches(el, selector)); }
        querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
        showModal() { this.open = true; }
        close() { this.open = false; }
        get text() { return [this.textContent, ...this.children.map((c) => c.text)].join(' '); }
    }

    const html = new Element('html');
    const head = html.appendChild(new Element('head'));
    const body = html.appendChild(new Element('body'));
    if (csrf) {
        const meta = head.appendChild(new Element('meta'));
        meta.setAttribute('name', 'csrf');
        meta.setAttribute('content', csrf);
    }

    const document = {
        readyState: 'complete',
        head,
        body,
        adoptedStyleSheets: [],
        currentScript: null,
        events: [],
        createElement: (tag) => new Element(tag),
        querySelector: (s) => html.querySelector(s),
        querySelectorAll: (s) => html.querySelectorAll(s),
        addEventListener: (type, fn) => { (listeners[type] = listeners[type] || []).push(fn); },
        dispatchEvent(event) { this.events.push(event); },
        get cookie() {
            return Object.keys(cookies).map((k) => k + '=' + cookies[k]).join('; ');
        },
        set cookie(line) {
            this.lastCookieLine = line;
            const [pair] = line.split(';');
            const at = pair.indexOf('=');
            cookies[pair.slice(0, at)] = pair.slice(at + 1);
        },
    };

    /** Deliver a click the way a browser does: to the element, then to the document. */
    function click(el) {
        el.click();
        (listeners.click || []).forEach((fn) => fn({ target: el, preventDefault() {} }));
    }

    return { document, html, head, body, cookies, click, Element };
}

/** Load the script into a fresh DOM, configured as `CookieConsent::tag()` would. */
function load({ config = {}, configUrl = null, cookie = '', csrf = null, fetchImpl = null, gated = [] } = {}) {
    const dom = makeDom({ cookie, csrf });
    const tag = dom.head.appendChild(new dom.Element('script'));
    tag.setAttribute('src', '/assets/js/pf-consent.js');
    if (configUrl !== null) {
        tag.setAttribute('data-config-url', configUrl);
    } else {
        tag.setAttribute('data-config', JSON.stringify(Object.assign({
            version: '1',
            days: 180,
            policyUrl: '/cookies',
            recordUrl: '/cookieconsent/record',
            categories: [
                { name: 'necessary', label: 'Strictly necessary', description: '' },
                { name: 'analytics', label: 'Analytics', description: '' },
                { name: 'marketing', label: 'Marketing', description: '' },
            ],
            text: { acceptAll: 'Accept all', rejectAll: 'Reject all', customise: 'Customise', save: 'Save choices', settings: 'Cookie settings' },
        }, config)));
    }
    dom.document.currentScript = tag;

    gated.forEach(([category, src]) => {
        const placeholder = dom.body.appendChild(new dom.Element('script'));
        placeholder.setAttribute('type', 'text/plain');
        placeholder.setAttribute('data-consent', category);
        placeholder.setAttribute('src', src);
    });

    const requests = [];
    const reloads  = [];
    const context = {
        document: dom.document,
        window: {},
        location: { protocol: 'https:', reload: () => reloads.push(true) },
        CSSStyleSheet: class { replaceSync(css) { this.css = css; } },
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init.detail; } },
        fetch: fetchImpl || ((url, options) => { requests.push({ url, options }); return Promise.resolve({ json: () => ({}) }); }),
        console,
        Date,
        Math,
        JSON,
        Object,
        String,
        encodeURIComponent,
        decodeURIComponent,
    };
    vm.runInNewContext(SOURCE, context);

    return { ...dom, api: context.window.PramnosConsent, window: context.window, requests, reloads };
}

/** The button whose label is `text`. */
function buttonNamed(dom, text) {
    const found = dom.html.querySelectorAll('button').find((b) => b.textContent === text);
    assert.ok(found, 'no button labelled ' + text);
    return found;
}

function bannerOf(dom) {
    return dom.html.querySelectorAll('section').find((el) => /pf-consent-banner/.test(el.className)) || null;
}

describe('first visit', () => {
    /**
     * A visitor who has not chosen sees the banner, and nothing optional is
     * allowed yet — consent is opt-in, so silence is a refusal.
     */
    test('shows the banner and allows nothing optional', () => {
        // Arrange / Act
        const dom = load({ gated: [['analytics', '/ga.js']] });

        // Assert
        assert.ok(bannerOf(dom), 'the banner is in the document');
        assert.equal(dom.api.has('analytics'), false);
        assert.equal(dom.api.has('necessary'), true, 'strictly necessary never needs asking');
        // The gated script is still inert text.
        assert.equal(dom.html.querySelectorAll('script[type="text/plain"]').length, 1);
    });

    /**
     * "Accept all" writes every optional category into the cookie, releases the
     * gated scripts, announces the choice and takes the banner away.
     */
    test('accept all grants every category and releases gated scripts', () => {
        // Arrange
        const dom = load({ gated: [['analytics', '/ga.js'], ['marketing', '/ads.js']] });

        // Act
        dom.click(buttonNamed(dom, 'Accept all'));

        // Assert
        assert.equal(bannerOf(dom), null);
        assert.equal(dom.api.has('analytics'), true);
        assert.equal(dom.api.has('marketing'), true);
        const released = dom.html.querySelectorAll('script').filter((s) => s.getAttribute('type') === null && s.getAttribute('src') !== '/assets/js/pf-consent.js');
        assert.deepEqual(released.map((s) => s.getAttribute('src')).sort(), ['/ads.js', '/ga.js']);
        // The placeholder attribute does not survive onto the real script.
        assert.equal(released[0].getAttribute('data-consent'), null);
        assert.deepEqual(dom.document.events[0].detail.granted, ['analytics', 'marketing']);
        // The cookie is scoped to the whole site, lasts the configured time and is Secure on https.
        assert.match(dom.document.lastCookieLine, /Max-Age=15552000; Path=\/; SameSite=Lax; Secure$/);
    });

    /**
     * "Reject all" is one click, like accepting, and it is a real answer: the
     * cookie records it so the banner does not come back, and nothing runs.
     */
    test('reject all records a refusal and runs nothing', () => {
        // Arrange
        const dom = load({ gated: [['analytics', '/ga.js']] });

        // Act
        dom.click(buttonNamed(dom, 'Reject all'));

        // Assert
        assert.equal(bannerOf(dom), null);
        assert.equal(dom.api.has('analytics'), false);
        assert.match(decodeURIComponent(dom.cookies.pf_consent), /^v=1&c=&t=\d+$/);
        assert.equal(dom.html.querySelectorAll('script[type="text/plain"]').length, 1);
    });
});

describe('a returning visitor', () => {
    /**
     * A choice under the current policy version is honoured without asking,
     * and `onGrant()` runs straight away for a category already allowed.
     */
    test('keeps the stored choice and runs onGrant immediately', () => {
        // Arrange
        const cookie = encodeURIComponent('v=1&c=analytics&t=1700000000');
        const dom = load({ cookie });
        const ran = [];

        // Act
        dom.api.onGrant('analytics', () => ran.push('analytics'));
        dom.api.onGrant('marketing', () => ran.push('marketing'));

        // Assert
        assert.equal(bannerOf(dom), null, 'no banner for somebody who has answered');
        assert.deepEqual(ran, ['analytics'], 'marketing waits for a grant that has not come');
    });

    /**
     * A choice made under an older policy is not consent to the new one: the
     * banner asks again and nothing optional is allowed meanwhile.
     */
    test('a new policy version asks again', () => {
        // Arrange
        const cookie = encodeURIComponent('v=1&c=analytics.marketing&t=1700000000');

        // Act
        const dom = load({ cookie, config: { version: '2' } });

        // Assert
        assert.ok(bannerOf(dom));
        assert.equal(dom.api.has('analytics'), false);
    });

    /**
     * With no `data-consent-open` link on the page (a SPA), there has to be
     * some way back to the dialog — withdrawing must be as easy as agreeing.
     */
    test('a page without a settings link gets a reopen button', () => {
        // Arrange / Act
        const dom = load({ cookie: encodeURIComponent('v=1&c=&t=1') });

        // Assert
        assert.ok(dom.html.querySelector('button[data-consent-open]'));
    });

    /**
     * A theme footer's own link is used instead, and opens the dialog.
     */
    test('a footer link opens the dialog instead of a floating button', () => {
        // Arrange
        const setup = load({ cookie: encodeURIComponent('v=1&c=&t=1') });
        const link = setup.body.appendChild(new setup.Element('a'));
        link.setAttribute('data-consent-open', '');

        // Act
        setup.click(link);

        // Assert
        const dialog = setup.html.querySelector('dialog');
        assert.ok(dialog && dialog.open, 'the preferences dialog is open');
    });
});

describe('the preferences dialog', () => {
    /**
     * Customising saves exactly the ticked categories; strictly necessary is
     * shown but cannot be unticked.
     */
    test('saves only the ticked categories', () => {
        // Arrange
        const dom = load();
        dom.click(buttonNamed(dom, 'Customise'));
        const dialog = dom.html.querySelector('dialog');
        const necessary = dialog.querySelector('input[name="necessary"]');
        dialog.querySelector('input[name="marketing"]').checked = true;

        // Act
        dom.click(buttonNamed(dom, 'Save choices'));

        // Assert
        assert.equal(necessary.disabled, true, 'necessary cannot be switched off');
        assert.equal(dom.api.has('marketing'), true);
        assert.equal(dom.api.has('analytics'), false);
        assert.equal(dialog.open, false);
    });

    /**
     * A script that has already run cannot be unloaded, so taking a category
     * back reloads the page — the only way the withdrawal takes effect now.
     */
    test('withdrawing a category reloads the page', () => {
        // Arrange
        const dom = load({ cookie: encodeURIComponent('v=1&c=analytics&t=1') });
        dom.api.open();
        // The dialog opens with the stored choice ticked; the visitor unticks it.
        dom.html.querySelector('input[name="analytics"]').checked = false;

        // Act
        dom.click(buttonNamed(dom, 'Save choices'));

        // Assert
        assert.equal(dom.reloads.length, 1);
        assert.equal(dom.api.has('analytics'), false);
    });
});

describe('the consent trail', () => {
    /**
     * A signed-in page carries the CSRF meta; the choice is posted with it so the
     * server can append it to authserver.user_consents.
     */
    test('posts the choice when the page is signed in', () => {
        // Arrange
        const dom = load({ csrf: 'tok123' });

        // Act
        dom.click(buttonNamed(dom, 'Accept all'));

        // Assert
        assert.equal(dom.requests.length, 1);
        assert.equal(dom.requests[0].url, '/cookieconsent/record');
        assert.equal(dom.requests[0].options.headers['X-CSRF-Token'], 'tok123');
        assert.deepEqual(JSON.parse(dom.requests[0].options.body), { granted: ['analytics', 'marketing'] });
    });

    /**
     * An anonymous visitor has no trail to add to and no token to send; the
     * cookie is their record, so nothing is posted.
     */
    test('posts nothing for an anonymous visitor', () => {
        // Arrange
        const dom = load();

        // Act
        dom.click(buttonNamed(dom, 'Reject all'));

        // Assert
        assert.equal(dom.requests.length, 0);
    });
});

describe('configuration', () => {
    /**
     * A SPA shell cannot read settings, so it points at `/cookieconsent`; a site
     * that turned the banner off answers `enabled: false` and nothing is drawn.
     */
    test('a remote configuration that says disabled draws nothing', async () => {
        // Arrange
        const fetched = [];

        // Act
        const dom = load({
            configUrl: '/cookieconsent',
            fetchImpl: (url) => { fetched.push(url); return Promise.resolve({ json: () => ({ enabled: false }) }); },
        });
        await new Promise((resolve) => setImmediate(resolve));

        // Assert
        assert.deepEqual(fetched, ['/cookieconsent']);
        assert.equal(bannerOf(dom), null);
        assert.equal(dom.html.querySelector('button'), null, 'no reopen button either');
    });

    /**
     * An enabled remote configuration draws the banner once it arrives.
     */
    test('a remote configuration that is enabled draws the banner', async () => {
        // Arrange
        const remote = {
            version: '1', days: 180, recordUrl: '', policyUrl: '',
            categories: [{ name: 'necessary', label: 'N', description: '' }, { name: 'analytics', label: 'A', description: '' }],
            text: { title: 'Cookies', acceptAll: 'Accept all', rejectAll: 'Reject all', customise: 'Customise' },
        };

        // Act
        const dom = load({ configUrl: '/cookieconsent', fetchImpl: () => Promise.resolve({ json: () => remote }) });
        await new Promise((resolve) => setImmediate(resolve));

        // Assert
        assert.ok(bannerOf(dom));
    });
});

describe('Google Consent Mode v2', () => {
    /** The consent commands on the data layer, as plain arrays. */
    function commands(dom) {
        return (dom.window.dataLayer || [])
            .filter((entry) => !('event' in entry))
            .map((entry) => Array.prototype.slice.call(entry));
    }

    /**
     * Before any choice, the default is everything denied except security —
     * what a GTM container loaded after this script must start from.
     */
    test('pushes an all-denied default on first visit', () => {
        // Arrange / Act
        const dom = load();

        // Assert
        const [first, ...rest] = commands(dom);
        assert.equal(first[0], 'consent');
        assert.equal(first[1], 'default');
        assert.equal(first[2].analytics_storage, 'denied');
        assert.equal(first[2].ad_user_data, 'denied');
        assert.equal(first[2].security_storage, 'granted');
        assert.equal(rest.length, 0, 'no update until the visitor answers');
    });

    /**
     * A choice becomes a `consent update` with each category's signals, plus an
     * event a Tag Manager trigger can fire tags on.
     */
    test('a choice pushes an update and an event', () => {
        // Arrange
        const dom = load();
        dom.click(buttonNamed(dom, 'Customise'));
        dom.html.querySelector('input[name="analytics"]').checked = true;

        // Act
        dom.click(buttonNamed(dom, 'Save choices'));

        // Assert
        const update = commands(dom).find((c) => c[1] === 'update');
        assert.equal(update[2].analytics_storage, 'granted');
        assert.equal(update[2].ad_storage, 'denied', 'marketing was not ticked');
        const event = dom.window.dataLayer.find((entry) => entry.event === 'pramnos_consent');
        assert.deepEqual(event.pramnosConsent, ['analytics']);
    });

    /**
     * A returning visitor's stored choice is applied straight after the default,
     * so tags do not wait for a click that already happened on an earlier visit.
     */
    test('a stored choice is pushed as an update on load', () => {
        // Arrange / Act
        const dom = load({ cookie: encodeURIComponent('v=1&c=marketing&t=1') });

        // Assert
        const update = commands(dom).find((c) => c[1] === 'update');
        assert.equal(update[2].ad_personalization, 'granted');
        assert.equal(update[2].analytics_storage, 'denied');
    });
});

describe('findings from a real deployment', () => {
    /**
     * In a SPA the configuration is fetched. The all-denied default must already be on
     * the data layer while that fetch is outstanding — a Tag Manager loaded after the
     * script runs in that window — and even if it never answers.
     */
    test('the Consent Mode default is pushed before the configuration arrives', () => {
        // Arrange / Act — a fetch that never resolves.
        const dom = load({ configUrl: '/cookieconsent', fetchImpl: () => new Promise(() => {}) });

        // Assert
        const first = Array.prototype.slice.call(dom.window.dataLayer[0]);
        assert.deepEqual(first.slice(0, 2), ['consent', 'default']);
        assert.equal(first[2].ad_storage, 'denied');
    });

    /**
     * A cookie that is not valid percent-encoding is no choice, not an exception:
     * the default is still pushed and the banner asks again.
     */
    test('a malformed cookie asks again instead of throwing', () => {
        // Arrange / Act
        const dom = load({ cookie: '%E0%A4%A' });

        // Assert
        assert.ok(bannerOf(dom), 'the banner is shown');
        assert.equal(Array.prototype.slice.call(dom.window.dataLayer[0])[1], 'default');
    });

    /**
     * A modal dialog is centred by the browser's `margin: auto`, which Tailwind's
     * preflight (`* { margin: 0 }`) removes — so the dialog sets it itself, or it opens
     * in the top-left corner.
     */
    test('the dialog centres itself whatever the page reset', () => {
        // Arrange / Act
        const dom = load();
        const css = dom.document.adoptedStyleSheets.map((sheet) => sheet.css).join('');

        // Assert
        assert.match(css, /\.pf-consent-dialog\{margin:auto;/);
    });
});

describe('agreement with PHP', () => {
    /**
     * The cookie is written here and read by `CookieConsent::granted()`. If the
     * two disagreed about the format, PHP would see a refusal where the visitor
     * had agreed — so this runs the real PHP against what the script wrote.
     */
    test('CookieConsent::granted() reads what the script writes', () => {
        // Arrange
        const dom = load();
        dom.click(buttonNamed(dom, 'Accept all'));
        // PHP decodes a cookie value before $_COOKIE sees it; do the same.
        const value = decodeURIComponent(dom.cookies.pf_consent);

        // Act
        const php = 'require "vendor/autoload.php";'
            + ' echo json_encode(Pramnos\\Security\\CookieConsent::granted(["pf_consent" => $argv[1]]));';
        const out = execFileSync('php', ['-r', php, '--', value], { cwd: ROOT, encoding: 'utf8' });

        // Assert
        assert.deepEqual(JSON.parse(out), ['necessary', 'analytics', 'marketing']);
    });
});
