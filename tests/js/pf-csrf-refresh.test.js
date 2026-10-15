/**
 * A page open a while takes the session's current CSRF token — `pf-utils.js` and
 * `<meta name="csrf-refresh">`.
 *
 * What has to hold: a page with a token ten minutes old asks for the current one when the tab
 * comes back into view, and before a form carrying one is sent, and every `_csrf_token` field and
 * `<meta name="csrf">` takes the answer; the form is then sent once, with the new token, and no
 * other handler sees the first attempt; a younger page asks nothing; a failure sends the form as
 * it was; and without the meta tag nothing is asked.
 *
 * Run:
 *   node --test tests/js/pf-csrf-refresh.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, stubFetch, Event } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

/**
 * A page with a form carrying the token, pf-utils.js loaded, and a clock the test moves.
 *
 * @param {object}  answer  What `/Csrf` answers: a body, or `{ status, body }`
 * @param {boolean} withMeta Whether the head names the address
 */
const OLD_NAME = 'a'.repeat(64);
const NEW_NAME = 'b'.repeat(64);

function page(answer = { token: 'new', form: { name: NEW_NAME, value: 'fp-new', value_ip: 'fp-ip-new' } }, withMeta = true) {
    const { window, document } = createDom({ url: 'https://app.test/settings', meta: withMeta ? { 'csrf-refresh': 'https://app.test/Csrf', csrf: 'old' } : { csrf: 'old' } });
    const clock = { now: 1_000_000 };
    window.Date = { now: () => clock.now };
    window.fetch = stubFetch({ 'GET /Csrf': answer });

    const form = document.createElement('form');
    const field = document.createElement('input');
    field.setAttribute('name', '_csrf_token');
    field.value = 'old';
    form.appendChild(field);
    // The form token, as Session::getTokenField() draws it — and one pinned to the address
    const formToken = document.createElement('input');
    formToken.setAttribute('type', 'hidden');
    formToken.setAttribute('name', OLD_NAME);
    formToken.value = 'fp-old';
    form.appendChild(formToken);
    const pinned = document.createElement('input');
    pinned.setAttribute('type', 'hidden');
    pinned.setAttribute('name', OLD_NAME);
    pinned.value = 'fp-ip-new';
    document.body.appendChild(pinned);
    document.body.appendChild(form);

    // What the browser's requestSubmit does: a submit event, from the form.
    form.requestSubmit = () => form.dispatchEvent(new Event('submit', { bubbles: true }));
    loadScript(SOURCE, window);
    // Registered after pf-utils, so it runs after its capturing handler — as a page's own script,
    // or the browser's sending, sees the submit. `later` is every submit it saw; `sent` the ones
    // nobody held back.
    const later = [];
    const sent = [];
    document.addEventListener('submit', (event) => {
        later.push(field.value);
        if (!event.defaultPrevented) { sent.push(field.value); }
    });

    /** Let the fetch and the promises after it settle. */
    const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

    return { window, document, form, field, formToken, pinned, clock, sent, later, settle };
}

/**
 * Ten minutes on, a form is held, the token asked for, put in the field and the meta, and the
 * form sent once with it — the first attempt seen by no other handler.
 */
test('a stale form takes the current token and is sent once', async () => {
    // Arrange
    const { window, document, form, field, formToken, pinned, clock, sent, later, settle } = page();
    clock.now += 10 * 60 * 1000;

    // Act
    form.dispatchEvent(new Event('submit', { bubbles: true }));
    const heldFrom = later.slice();
    await settle();

    // Assert
    assert.deepEqual(heldFrom, [], 'no other handler saw the attempt with the old token');
    assert.deepEqual(sent, ['new'], 'sent once, with the new token');
    assert.equal(field.value, 'new');
    assert.equal(document.querySelector('meta[name="csrf"]').getAttribute('content'), 'new');
    assert.deepEqual([formToken.getAttribute('name'), formToken.value], [NEW_NAME, 'fp-new'], 'the form token, renamed and revalued');
    assert.deepEqual([pinned.getAttribute('name'), pinned.value], [NEW_NAME, 'fp-ip-new'], 'a pinned one stays pinned');
    assert.equal(window.fetch.calls.length, 1);
});

/** A form carrying only the form token is held and refreshed too. */
test('a form with only the form token is refreshed', async () => {
    // Arrange
    const { form, field, formToken, clock, settle } = page();
    form.children.splice(form.children.indexOf(field), 1);
    field.parentNode = null;
    clock.now += 10 * 60 * 1000;

    // Act
    form.dispatchEvent(new Event('submit', { bubbles: true }));
    await settle();

    // Assert
    assert.equal(formToken.getAttribute('name'), NEW_NAME);
});

/** A younger page sends its forms as they are, and asks nothing. */
test('a fresh page asks nothing', async () => {
    // Arrange
    const { window, form, sent, settle } = page();

    // Act
    form.dispatchEvent(new Event('submit', { bubbles: true }));
    await settle();

    // Assert
    assert.deepEqual(sent, ['old']);
    assert.equal(window.fetch.calls.length, 0);
});

/** Coming back into view after ten minutes, the page takes the token without a form being sent. */
test('coming back into view refreshes a stale page', async () => {
    // Arrange
    const { window, document, field, clock, settle } = page();
    document.visibilityState = 'visible';

    // Act — once too early, once late enough
    document.dispatchEvent(new Event('visibilitychange'));
    await settle();
    const early = field.value;
    clock.now += 11 * 60 * 1000;
    document.dispatchEvent(new Event('visibilitychange'));
    await settle();

    // Assert
    assert.equal(early, 'old');
    assert.equal(field.value, 'new');
    assert.equal(window.fetch.calls.length, 1);
    assert.equal(typeof window.pfRefreshCsrf, 'function');
});

/** A site that cannot answer leaves the token as it was, and the form is still sent. */
test('a failed refresh sends the form as it was', async () => {
    // Arrange
    const { form, field, clock, sent, settle } = page({ status: 500, body: {} });
    clock.now += 10 * 60 * 1000;

    // Act
    form.dispatchEvent(new Event('submit', { bubbles: true }));
    await settle();

    // Assert
    assert.deepEqual(sent, ['old']);
    assert.equal(field.value, 'old');
});

/** Without the meta tag nothing is asked, however old the page. */
test('without the meta tag nothing is asked', async () => {
    // Arrange
    const { window, form, clock, sent, settle } = page(undefined, false);
    clock.now += 60 * 60 * 1000;

    // Act
    form.dispatchEvent(new Event('submit', { bubbles: true }));
    await settle();

    // Assert
    assert.deepEqual(sent, ['old']);
    assert.equal(window.fetch.calls.length, 0);
    assert.equal(window.pfRefreshCsrf, undefined);
});
