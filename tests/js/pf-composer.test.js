/**
 * The message composer in `pf-utils.js` (`data-pf-composer`, markup from Html\MessageComposer).
 *
 * What has to hold, in a plain textarea, the builtin editor and the rich editor: a saved message fills the subject
 * and the body; a placeholder goes in at the cursor of whichever of the two had the focus last;
 * "Write with AI" sends the text and the placeholders and fills what comes back; the signature is
 * added when the form is sent, and only once.
 *
 * Run:
 *   node --test tests/js/pf-composer.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');
const fs       = require('node:fs');
const { JSDOM } = require('jsdom');

const UTILS = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');
const RICH  = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-rich-editor.js');
const BUILTIN = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-editor.js');

const settle = async () => { for (let i = 0; i < 10; i++) { await new Promise((r) => setImmediate(r)); } };

/**
 * A message form with the composer, the body in `mode` ('none' or 'rich'), a server answering
 * `routes`, and the prompt answering `prompted`.
 */
function page({ mode = 'none', routes = {}, prompted = 'Hello', signature = '<p>— Ann</p>' } = {}) {
    const words = JSON.stringify({ name: 'Name', instruction: 'Say' }).replace(/"/g, '&quot;');
    const dom = new JSDOM('<!doctype html><html><head><meta name="csrf" content="t0ken"></head><body>'
        + '<form data-pf-composer data-pf-composer-url="/admin/Composer" data-pf-composer-scope="user-message"'
        + ' data-pf-editor-fields=\'[{"token":"{firstname}","label":"First name"}]\'>'
        + '<div class="pf-composer-toolbar" data-pf-composer-words="' + words + '">'
        + '<select data-pf-composer-saved><option value="">Saved…</option></select>'
        + '<button type="button" data-pf-composer-save>Save</button>'
        + '<button type="button" data-pf-composer-forget hidden>Forget</button>'
        + '<span data-pf-composer-fields></span>'
        + '<button type="button" data-pf-composer-write>Write</button>'
        + '<label><input type="checkbox" data-pf-composer-signature data-signature="' + signature.replace(/"/g, '&quot;') + '"></label>'
        + '</div>'
        + '<input name="subject" data-pf-composer-subject>'
        + '<textarea id="body" name="message" data-pf-composer-body' + (mode !== 'none' ? ' data-pf-editor="' + mode + '"' : '') + '></textarea>'
        + '</form></body></html>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://app.test/admin/users/notify/5' });
    const { window } = dom;
    window.document.elementFromPoint = () => null;
    window.Range.prototype.getClientRects = () => [];
    window.Range.prototype.getBoundingClientRect = () => ({ top: 0, left: 0, right: 0, bottom: 0, width: 0, height: 0 });
    window.HTMLElement.prototype.scrollIntoView = () => {};
    const sent = [];
    window.fetch = (url, init = {}) => {
        sent.push({ url, init });
        const key = Object.keys(routes).find((k) => url.indexOf(k) !== -1);
        const answer = key ? routes[key] : {};
        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(typeof answer === 'function' ? answer(init) : answer) });
    };
    window.prompt = () => prompted;
    window.confirm = () => false;
    window.alert = () => {};
    Object.defineProperty(window.document, 'readyState', { value: 'complete', configurable: true });
    if (mode === 'rich') { window.eval(fs.readFileSync(RICH, 'utf8')); }
    if (mode === 'builtin') {
        window.document.execCommand = () => true;
        window.eval(fs.readFileSync(BUILTIN, 'utf8'));
        window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
    }
    window.eval(fs.readFileSync(UTILS, 'utf8'));
    const $ = (s) => window.document.querySelector(s);

    return { window, sent, $, form: $('form'), subject: $('[data-pf-composer-subject]'), body: $('#body') };
}

const SAVED = { '/saved': { canShare: false, messages: [{ id: 3, name: 'Welcome', subject: 'Hi {firstname}', body: '<p>Welcome, {firstname}</p>', shared: true, mine: false, canForget: false }] } };

for (const mode of ['none', 'builtin', 'rich']) {
    test('a saved message fills the subject and the body (' + mode + ')', async () => {
        // Arrange
        const ui = page({ mode, routes: SAVED });
        await settle();

        // Act
        ui.$('[data-pf-composer-saved]').value = '3';
        ui.$('[data-pf-composer-saved]').dispatchEvent(new ui.window.Event('change'));

        // Assert
        assert.equal(ui.$('[data-pf-composer-saved]').options.length, 2, 'the saved messages were not listed');
        assert.equal(ui.subject.value, 'Hi {firstname}');
        assert.match(ui.body.value, /<p>Welcome, \{firstname\}<\/p>/, 'the body the form posts was not filled');
    });
}

test('a placeholder goes in at the cursor of whichever field had the focus last, never filled', () => {
    // Arrange
    const ui = page();
    const button = ui.$('[data-pf-composer-fields] button');
    ui.subject.value = 'Hello !';
    ui.body.value = 'Dear ,';

    // Act — the subject, cursor before "!"; then the body, cursor before ","
    ui.subject.dispatchEvent(new ui.window.FocusEvent('focusin', { bubbles: true }));
    ui.subject.setSelectionRange(6, 6);
    button.click();
    ui.body.dispatchEvent(new ui.window.FocusEvent('focusin', { bubbles: true }));
    ui.body.setSelectionRange(5, 5);
    button.click();

    // Assert
    assert.equal(button.textContent, 'First name');
    assert.equal(ui.subject.value, 'Hello {firstname}!');
    assert.equal(ui.body.value, 'Dear {firstname},');
});

test('the signature is added when the form is sent — once', () => {
    // Arrange
    const ui = page();
    ui.body.value = '<p>Text</p>';
    ui.$('[data-pf-composer-signature]').checked = true;
    ui.form.addEventListener('submit', (e) => e.preventDefault());

    // Act — sent twice: a refused send and a second try
    ui.form.dispatchEvent(new ui.window.Event('submit', { cancelable: true }));
    ui.form.dispatchEvent(new ui.window.Event('submit', { cancelable: true }));

    // Assert
    assert.equal(ui.body.value, '<p>Text</p><p>— Ann</p>');
});

test('Write with AI sends the text and the placeholders, and fills what comes back', async () => {
    // Arrange
    const ui = page({ prompted: 'Welcome them', routes: { '/write': { subject: 'Welcome, {firstname}', body: '<p>Glad you are here.</p>' } } });
    ui.subject.value = 'Hi';

    // Act
    ui.$('[data-pf-composer-write]').click();
    await settle();
    const call = ui.sent.find((s) => s.url.indexOf('/write') !== -1);
    const posted = new URLSearchParams(call.init.body);

    // Assert
    assert.equal(call.init.headers['X-CSRF-Token'], 't0ken');
    assert.equal(posted.get('instruction'), 'Welcome them');
    assert.equal(posted.get('subject'), 'Hi');
    assert.deepEqual(JSON.parse(posted.get('fields')), ['{firstname}']);
    assert.equal(ui.subject.value, 'Welcome, {firstname}');
    assert.equal(ui.body.value, '<p>Glad you are here.</p>');
});

test('saving asks for a name and posts the message to the form\'s scope', async () => {
    // Arrange
    const ui = page({ prompted: 'Reminder', routes: { '/save': { saved: 9, shared: false }, '/saved': { messages: [] } } });
    ui.subject.value = 'Due';
    ui.body.value = '<p>Pay</p>';

    // Act
    ui.$('[data-pf-composer-save]').click();
    await settle();
    const call = ui.sent.find((s) => s.url.endsWith('/save'));
    const posted = new URLSearchParams(call.init.body);

    // Assert
    assert.deepEqual([posted.get('name'), posted.get('subject'), posted.get('body'), posted.get('scope'), posted.get('shared')],
        ['Reminder', 'Due', '<p>Pay</p>', 'user-message', '0']);
});
