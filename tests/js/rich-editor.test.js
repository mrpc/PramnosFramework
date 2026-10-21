/**
 * pf-rich-editor.js — the rich editor a server-rendered page loads, tested as it ships: the built
 * file, in jsdom.
 *
 * What has to hold: a `textarea[data-pf-editor="rich"]` becomes an editor whose HTML the textarea
 * keeps; a picture keeps its caption through a round trip; Alt+↑/↓ move blocks; a site's plugin
 * adds its own extension and button; a site's embed provider is asked before the built-in ones,
 * and a field can leave embeds out; a picture pasted is uploaded with the page's CSRF token and put
 * in; and the shipped file is built from the sources as they are.
 *
 * Run:
 *   node --test tests/js/rich-editor.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');
const fs       = require('node:fs');
const { JSDOM } = require('jsdom');

const BUNDLE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-rich-editor.js');

const settle = async () => { for (let i = 0; i < 10; i++) { await new Promise((r) => setImmediate(r)); } };

/**
 * A page with one rich field, the attributes given, the HTML it starts from, and the bundle run
 * in it — after `before(window)`, where a plugin is registered as a site's script would.
 */
function page({ value = '', attrs = '', before = null } = {}) {
    const dom = new JSDOM('<!doctype html><html><head><meta name="csrf" content="t0ken"></head><body>'
        + '<form><textarea id="body" name="body" data-pf-editor="rich" ' + attrs + '></textarea></form></body></html>',
        { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://app.test/admin/Mail' });
    const { window } = dom;
    // ProseMirror asks the layout engine where things are; jsdom has none.
    window.document.elementFromPoint = () => null;
    window.Range.prototype.getClientRects = () => [];
    window.Range.prototype.getBoundingClientRect = () => ({ top: 0, left: 0, right: 0, bottom: 0, width: 0, height: 0 });
    window.HTMLElement.prototype.scrollIntoView = () => {};
    const field = window.document.getElementById('body');
    field.value = value;
    if (before) { before(window); }
    // Loaded after the page: the bundle enhances the fields it finds.
    Object.defineProperty(window.document, 'readyState', { value: 'complete', configurable: true });
    window.eval(fs.readFileSync(BUNDLE, 'utf8'));
    const instance = window.pfRichEditor.for(field);

    return { window, field, instance, editor: instance && instance.editor, bar: field.nextElementSibling.querySelector('.pf-rich-toolbar') };
}

test('a rich field becomes an editor whose HTML the textarea keeps', () => {
    // Arrange
    const ui = page({ value: '<p>Hello</p>' });

    // Act — bold the word, as the toolbar's B does
    ui.editor.commands.selectAll();
    ui.bar.querySelector('[data-button="bold"]').click();

    // Assert
    assert.ok(ui.instance, 'the field was not turned into an editor');
    assert.equal(ui.field.hidden, true, 'the textarea is still shown beside the editor');
    assert.equal(ui.field.value, '<p><strong>Hello</strong></p>', 'the form would post what the editor no longer shows');
    assert.equal(ui.bar.querySelector('[data-button="bold"]').getAttribute('aria-pressed'), 'true');
});

test('a picture keeps its alt text and caption through a round trip; a bare img is a picture', () => {
    // Arrange / Act
    const ui = page({ value: '<figure><img src="/a.jpg" alt="A cat"><figcaption>On the wall</figcaption></figure><p><img src="/b.png"></p>' });
    const html = ui.editor.getHTML();

    // Assert
    assert.match(html, /^<figure><img src="\/a\.jpg" alt="A cat"><figcaption>On the wall<\/figcaption><\/figure>/,
        'the caption came back as a paragraph, or was lost');
    assert.match(html, /<figure><img src="\/b\.png" alt=""><\/figure>/);
});

test('Alt+↓ moves the block at the cursor down, and the cursor goes with it', () => {
    // Arrange
    const ui = page({ value: '<p>one</p><p>two</p><p>three</p>' });
    ui.editor.commands.setTextSelection(2);

    // Act
    const moved = ui.window.pfRichEditor.moveBlock(ui.editor, 1);

    // Assert
    assert.equal(moved, true);
    assert.equal(ui.editor.getHTML(), '<p>two</p><p>one</p><p>three</p>');
    assert.equal(ui.editor.state.selection.$from.parent.textContent, 'one', 'the cursor stayed behind');
    assert.equal(ui.window.pfRichEditor.moveBlock(ui.editor, -5), false);
});

test('a site\'s plugin adds its own mark and its own button, with the toolkit the editor runs on', () => {
    // Arrange — an editor made before the plugin exists
    const ui = page({ value: '<p>Hello <mark>world</mark></p>' });

    // A plugin needs pfRichEditor, which the bundle defines: register it, then enhance a new field.
    const { window } = ui;
    window.pfRichEditor.use({
        name: 'highlight',
        extensions: (tiptap) => [tiptap.Mark.create({
            name: 'highlight',
            parseHTML: () => [{ tag: 'mark' }],
            renderHTML: () => ['mark', 0],
        })],
        buttons: [{ name: 'highlight', label: 'H!', title: 'Highlight', run: (e) => e.chain().focus().toggleMark('highlight').run(), active: (e) => e.isActive('highlight') }],
        setup: (api) => { api.wrapper.dataset.highlight = 'on'; },
    });
    const second = window.document.createElement('textarea');
    second.value = '<p>Hello <mark>world</mark></p>';
    window.document.body.append(second);

    // Act
    const made = window.pfRichEditor.enhance(second);

    // Assert
    assert.match(made.getHTML(), /<mark>world<\/mark>/, 'the plugin\'s mark was not part of the schema');
    assert.ok(made.element.querySelector('[data-button="highlight"]'), 'the plugin\'s button is not on the toolbar');
    assert.equal(made.element.dataset.highlight, 'on', 'the plugin\'s setup did not run');
    assert.doesNotMatch(ui.editor.getHTML(), /<mark>/, 'an editor made before the plugin took it anyway');
});

test('a site\'s embed provider is asked first, and a field can leave embeds out', () => {
    // Arrange
    const ui = page({ value: '<p>https://podcasts.example/ep/7</p>' });
    ui.window.pfRichEditor.embeds.add({ name: 'podcast', match: (url) => (/podcasts\.example\/ep\/(\d+)/.test(url) ? { src: 'https://podcasts.example/player/7', label: 'Podcast' } : null) });
    const second = ui.window.document.createElement('textarea');
    second.value = '<p>https://podcasts.example/ep/7</p>';
    ui.window.document.body.append(second);
    const without = ui.window.document.createElement('textarea');
    without.value = second.value;
    without.setAttribute('data-pf-rich-plugins', 'none');
    ui.window.document.body.append(without);

    // Act
    const shown = ui.window.pfRichEditor.enhance(second);
    const plain = ui.window.pfRichEditor.enhance(without);

    // Assert
    assert.equal(ui.window.pfRichEditor.embeds.for('https://podcasts.example/ep/7').provider, 'podcast');
    assert.equal(ui.window.pfRichEditor.embeds.for('https://youtu.be/dQw4w9WgXcQ').provider, 'youtube');
    assert.ok(shown.element.querySelector('.pf-rich-embed iframe[src="https://podcasts.example/player/7"]'), 'the site\'s embed is not previewed');
    assert.equal(plain.element.querySelector('.pf-rich-embed'), null, 'embeds left out still previewed');
    assert.equal(shown.getHTML(), '<p>https://podcasts.example/ep/7</p>', 'the preview leaked into the saved HTML');
});

test('a pasted picture is uploaded with the page\'s CSRF token and put in; text with it stays text', async () => {
    // Arrange
    const sent = [];
    const ui = page({ value: '<p>Text</p>', attrs: 'data-pf-rich-upload="/admin/Media/upload"', before: (window) => {
        window.fetch = (url, init) => {
            sent.push({ url, init });
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ url: '/media/cat.png' }) });
        };
    } });
    const picture = new ui.window.File(['x'], 'cat.png', { type: 'image/png' });
    const paste = (files, text) => {
        const event = new ui.window.Event('paste', { bubbles: true, cancelable: true });
        event.clipboardData = { files, getData: (type) => (type === 'text/plain' ? text : '') };
        ui.editor.view.dom.dispatchEvent(event);
    };

    // Act
    paste([picture], '');
    await settle();
    paste([picture], 'the words copied');
    await settle();

    // Assert
    assert.equal(sent.length, 1, 'a paste that carried text was uploaded as a picture');
    assert.equal(sent[0].url, '/admin/Media/upload');
    assert.equal(sent[0].init.headers['X-CSRF-Token'], 't0ken');
    assert.match(ui.field.value, /<figure><img src="\/media\/cat\.png" alt=""><\/figure>/);
});

test('plain() gives the bare textarea for a text-only channel, and back, keeping what was written', () => {
    // Arrange
    const ui = page({ value: '<p>Hello</p>' });

    // Act — to the textarea, edited there, and back to the editor
    ui.window.pfRichEditor.plain(ui.field, true);
    const shownField = !ui.field.hidden && ui.instance.element.hidden;
    ui.field.value = '<p>Hello again</p>';
    ui.window.pfRichEditor.plain(ui.field, false);

    // Assert
    assert.equal(shownField, true, 'the textarea was not given back');
    assert.equal(ui.field.hidden, true);
    assert.equal(ui.instance.getHTML(), '<p>Hello again</p>', 'what was typed in the textarea was lost');
});

test('the shipped file is built from the sources as they are', async () => {
    // Arrange
    const { sourceStamp } = await import('../../resources/js/rich-editor/build.mjs');

    // Act
    const banner = fs.readFileSync(BUNDLE, 'utf8').split('\n', 1)[0];

    // Assert
    assert.ok(banner.includes(sourceStamp()), 'the sources changed and pf-rich-editor.js was not rebuilt: npm run build:editor');
});
