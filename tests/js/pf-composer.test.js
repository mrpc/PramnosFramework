/**
 * The message composer in `pf-utils.js` (`data-pf-composer`, markup from Html\MessageComposer).
 *
 * What has to hold, in a plain textarea, the builtin editor and the rich editor: a saved message
 * fills the subject and the body; a field from the dropdown goes in at the cursor of whichever of
 * the two had the focus last — its value where the form gives one, the token otherwise; "Write
 * with AI" and saving work in panels, never a browser dialog (a stubbed prompt throws); Undo puts
 * back what the AI replaced; the signature is added when the form is sent, and only once.
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
function page({ mode = 'none', routes = {}, signature = '<p>— Ann</p>', fields = [{ token: '{firstname}', label: 'First name' }] } = {}) {
    const words = JSON.stringify({ write: 'Write', improve: 'Improve', saved: 'Saved.' }).replace(/"/g, '&quot;');
    const dom = new JSDOM('<!doctype html><html><head><meta name="csrf" content="t0ken"></head><body>'
        + '<form data-pf-composer data-pf-composer-url="/admin/Composer" data-pf-composer-scope="user-message"'
        + ' data-pf-editor-fields=\'' + JSON.stringify(fields) + '\'>'
        + '<div class="pf-composer-toolbar" data-pf-composer-words="' + words + '">'
        + '<select data-pf-composer-saved hidden><option value="">Saved…</option></select>'
        + '<button type="button" data-pf-composer-save>Save</button>'
        + '<button type="button" data-pf-composer-forget hidden>Forget</button>'
        + '<details class="pf-composer-menu"><summary>Fields ▾</summary><span class="pf-composer-fields pf-composer-menu-list" data-pf-composer-fields></span></details>'
        + '<button type="button" data-pf-composer-write>Write with AI</button>'
        + '<label><input type="checkbox" data-pf-composer-signature data-signature="' + signature.replace(/"/g, '&quot;') + '"></label>'
        + '<span data-pf-composer-status></span>'
        + '<div class="pf-composer-panel" data-pf-composer-save-panel hidden><input data-pf-composer-name>'
        + '<label data-pf-composer-shared-label hidden><input type="checkbox" data-pf-composer-shared></label>'
        + '<button type="button" data-pf-composer-save-go>Save</button><button type="button" data-pf-composer-cancel>Cancel</button></div>'
        + '<div class="pf-composer-panel" data-pf-composer-write-panel hidden><textarea data-pf-composer-instruction></textarea>'
        + '<button type="button" data-pf-composer-write-go>Improve</button><button type="button" data-pf-composer-undo hidden>Undo</button>'
        + '<button type="button" data-pf-composer-cancel>Close</button></div>'
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
    // The composer asks in its own panels: a browser dialog anywhere fails the test.
    // jsdom reports a listener's exception instead of throwing it, so each one is also recorded.
    const dialogs = [];
    window.prompt = window.confirm = window.alert = (text) => { dialogs.push(text); throw new Error('a browser dialog was opened'); };
    Object.defineProperty(window.document, 'readyState', { value: 'complete', configurable: true });
    if (mode === 'rich') { window.eval(fs.readFileSync(RICH, 'utf8')); }
    if (mode === 'builtin') {
        window.document.execCommand = () => true;
        window.eval(fs.readFileSync(BUILTIN, 'utf8'));
        window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
    }
    window.eval(fs.readFileSync(UTILS, 'utf8'));
    const $ = (s) => window.document.querySelector(s);

    return { window, sent, dialogs, $, form: $('form'), subject: $('[data-pf-composer-subject]'), body: $('#body') };
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

test('a field goes in at the cursor of whichever field had the focus last — the token, never filled; the menu stays open', () => {
    // Arrange
    const ui = page();
    const menu = ui.$('details.pf-composer-menu');
    const button = ui.$('[data-pf-composer-fields] button');
    ui.subject.value = 'Hello !';
    ui.body.value = 'Dear ,';

    // Act — the subject, cursor before "!"; then the body, cursor before ","
    menu.open = true;
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
    // Several details go in one after another: the dropdown stays open until its toggle or Escape.
    assert.equal(menu.open, true, 'the dropdown closed after a choice');
    ui.window.document.dispatchEvent(new ui.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(menu.open, false, 'Escape did not close the dropdown');
});

test('a field with a value — a message to one person — inserts the value, not the token', () => {
    // Arrange
    const ui = page({ fields: [{ token: '{firstname}', label: 'First name', value: 'Cathy' }, { token: '{email}', label: 'Email' }] });
    const [first, email] = ui.$('[data-pf-composer-fields]').querySelectorAll('button');
    ui.body.value = '';
    ui.body.dispatchEvent(new ui.window.FocusEvent('focusin', { bubbles: true }));

    // Act
    first.click();
    email.click();

    // Assert — the value where given, the token where not
    assert.equal(ui.body.value, 'Cathy{email}');
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

test('Write with AI: a panel, the fields filled from the answer, and Undo puts the text back', async () => {
    // Arrange
    const ui = page({ routes: { '/write': { subject: 'Welcome, {firstname}', body: '<p>Glad you are here.</p>' } } });
    const panel = ui.$('[data-pf-composer-write-panel]');
    const go = ui.$('[data-pf-composer-write-go]');
    ui.subject.value = 'Hi';
    ui.body.value = '<p>Old</p>';

    // Act — open the panel, ask, wait, undo
    ui.$('[data-pf-composer-write]').click();
    ui.$('[data-pf-composer-instruction]').value = 'Welcome them';
    ui.$('[data-pf-composer-instruction]').dispatchEvent(new ui.window.Event('input'));
    const label = go.textContent;
    go.click();
    const busy = go.disabled;
    await settle();
    const call = ui.sent.find((s) => s.url.indexOf('/write') !== -1);
    const posted = new URLSearchParams(call.init.body);
    const written = [ui.subject.value, ui.body.value];
    ui.$('[data-pf-composer-undo]').click();

    // Assert
    assert.equal(panel.hidden, false, 'the panel did not open');
    assert.equal(label, 'Write', 'an instruction is written from; an empty one improves');
    assert.equal(busy, true, 'the button was not disabled while writing');
    assert.equal(go.disabled, false);
    assert.equal(call.init.headers['X-CSRF-Token'], 't0ken');
    assert.equal(posted.get('instruction'), 'Welcome them');
    assert.deepEqual(JSON.parse(posted.get('fields')), ['{firstname}']);
    assert.deepEqual(written, ['Welcome, {firstname}', '<p>Glad you are here.</p>']);
    assert.deepEqual([ui.subject.value, ui.body.value], ['Hi', '<p>Old</p>'], 'Undo did not restore the text');
    assert.equal(ui.$('[data-pf-composer-undo]').hidden, true);
    assert.deepEqual(ui.dialogs, [], 'a browser dialog was opened');
});

test('saving: a panel with a name and "for everybody" where allowed, posted to the form\'s scope', async () => {
    // Arrange
    const ui = page({ routes: { '/saved': { messages: [], canShare: true }, '/save': { saved: 9, shared: true } } });
    await settle();
    ui.subject.value = 'Due';
    ui.body.value = '<p>Pay</p>';

    // Act
    ui.$('[data-pf-composer-save]').click();
    const suggested = ui.$('[data-pf-composer-name]').value;
    ui.$('[data-pf-composer-name]').value = 'Reminder';
    ui.$('[data-pf-composer-shared]').checked = true;
    ui.$('[data-pf-composer-save-go]').click();
    await settle();
    const call = ui.sent.find((s) => s.url.endsWith('/save'));
    const posted = new URLSearchParams(call.init.body);

    // Assert
    assert.equal(suggested, 'Due', 'the name is not offered from the subject');
    assert.equal(ui.$('[data-pf-composer-shared-label]').hidden, false, 'sharing allowed but not offered');
    assert.deepEqual([posted.get('name'), posted.get('subject'), posted.get('body'), posted.get('scope'), posted.get('shared')],
        ['Reminder', 'Due', '<p>Pay</p>', 'user-message', '1']);
    assert.equal(ui.$('[data-pf-composer-save-panel]').hidden, true, 'the panel stays open after saving');
    assert.equal(ui.$('[data-pf-composer-status]').textContent, 'Saved.');
    assert.deepEqual(ui.dialogs, [], 'a browser dialog was opened');
});

test('saving without a name says so in the toolbar and posts nothing; forgetting asks by a second press', async () => {
    // Arrange
    const ui = page({ routes: { '/saved': { messages: [{ id: 4, name: 'Old', subject: 's', body: '', shared: false, mine: true, canForget: true }] }, '/forget': { forgotten: 4 } } });
    await settle();

    // Act
    ui.$('[data-pf-composer-save]').click();
    ui.$('[data-pf-composer-name]').value = '  ';
    ui.$('[data-pf-composer-save-go]').click();
    ui.$('[data-pf-composer-saved]').value = '4';
    ui.$('[data-pf-composer-saved]').dispatchEvent(new ui.window.Event('change'));
    const forget = ui.$('[data-pf-composer-forget]');
    forget.click();
    const afterOne = ui.sent.filter((s) => s.url.endsWith('/forget')).length;
    forget.click();
    await settle();

    // Assert
    assert.equal(ui.sent.filter((s) => s.url.endsWith('/save')).length, 0);
    assert.notEqual(ui.$('[data-pf-composer-status]').textContent, '');
    assert.equal(afterOne, 0, 'one press forgot it');
    assert.equal(ui.sent.filter((s) => s.url.endsWith('/forget')).length, 1);
    assert.deepEqual(ui.dialogs, [], 'a browser dialog was opened');
});

test('the saved messages\' list is hidden while there are none, and shown after the first save', async () => {
    // Arrange — nothing saved; the server lists the new one after the save
    let list = [];
    const ui = page({ routes: {
        '/saved': () => ({ messages: list, canShare: false }),
        '/save': () => { list = [{ id: 1, name: 'First', subject: 's', body: '', shared: false, mine: true, canForget: true }]; return { saved: 1, shared: false }; },
    } });
    await settle();
    const picker = ui.$('[data-pf-composer-saved]');
    const hiddenBefore = picker.hidden;

    // Act
    ui.$('[data-pf-composer-save]').click();
    ui.$('[data-pf-composer-name]').value = 'First';
    ui.$('[data-pf-composer-save-go]').click();
    await settle();

    // Assert
    assert.equal(hiddenBefore, true, 'an empty list was shown');
    assert.equal(ui.$('[data-pf-composer-save]').hidden, false, '"Save this message" must stay visible');
    assert.equal(picker.hidden, false, 'the list did not appear after the first save');
    assert.equal(picker.value, '1', 'the new message is not the one chosen');
});
