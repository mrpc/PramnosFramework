---
use_cases:
  - Giving a form a rich-text editor with pictures, captions and links
  - Letting an editor upload pictures by dropping or pasting them into the text
  - Adding a site's own button, mark or block to the editor with a plugin
  - Previewing embeds of a provider of the site's own (a podcast, a map) in the editor
  - Turning the rich editor off for a text-only channel such as push
---

# Rich Text Editor

`pf-rich-editor.js` turns a `<textarea data-pf-editor="rich">` into a WYSIWYG editor built on
[TipTap](https://tiptap.dev). The textarea keeps the HTML, so the form posts what it would
without JavaScript. It ships with every other `assets/js/pf-*.js` — `init` and
`project:resync --js` copy it to the web root — and it is loaded only by a page that asks for it.

```php
// app/app.php — as the mass-message editor (see the Email guide)
'messaging' => ['editor' => 'rich', 'rich' => ['upload' => adminUrl('Media/upload'), 'links' => adminUrl('search')]],
```

```php
<textarea name="body" data-pf-editor="rich"><?php echo $e($html); ?></textarea>
<?php echo \Pramnos\Html\MessageEditor::scripts(\Pramnos\Html\MessageEditor::RICH); ?>
```

`MessageEditor::scripts('rich')` prints the editor's scoped stylesheet, the application's
defaults — the controls' words through `t()`, and the `upload` and `links` addresses from
`messaging.rich` — and the script.

## What it does

- **Text:** bold, italic, two heading levels, bulleted and numbered lists, quotes, undo and redo.
- **Links:** the link at the cursor shows its address, to open, change or remove. With a `links`
  address the panel searches the site for a page to point it at. The selected words stay marked
  while the panel has the focus.
- **Pictures** are `<figure><img alt><figcaption>`: clicking one shows its alternative text and
  caption to edit. A bare `<img>` is read as a picture too.
- **Uploading:** with an `upload` address, a picture dropped lands where it is dropped, one pasted
  at the cursor, and the toolbar's Picture chooses a file. It is POSTed as `file` with the page's
  CSRF token; the answer is `{"url": "…"}`. A paste that carries text as well stays text — Word
  pastes a picture of the selection beside it.
- **Blocks** move up and down with Alt+↑ / Alt+↓, or the toolbar's ▲ ▼.
- **Embeds:** an address alone in a paragraph — YouTube, Vimeo, or a provider of the site's own —
  is previewed under it. The address is what is saved; how it is embedded on the published page
  is the site's business.

## A field's own settings

| Attribute | |
| --- | --- |
| `data-pf-rich-upload="/admin/Media/upload"` | where this field's pictures go, instead of the default |
| `data-pf-rich-links="/admin/search"` | where its link panel searches |
| `data-pf-rich-plugins="embeds,mentions"` | only these plugins; every one registered without it. `embeds` is named like a plugin, so a field can leave previews out |
| `data-pf-rich-labels='{"bold":"Έντονα"}'` | the controls' words |
| `readonly` / `disabled` | the text shown, not editable |

```js
pfRichEditor.enhance(textarea)            // a field added after the page loaded
pfRichEditor.for(textarea)                // its editor: { editor, getHTML(), setHTML(), setReadonly(), destroy() }
pfRichEditor.plain(textarea, true)        // the bare textarea, for a text-only channel; false gives the editor back
pfRichEditor.create(element, options)     // an editor that is not a field
```

## Adding a site's own

Everything a site adds is a **plugin**, registered before the page has loaded — a plain script
after `pf-rich-editor.js`, no build:

```js
pfRichEditor.use({
    name: 'highlight',
    // TipTap extensions: marks, nodes, keymaps, decorations. `tiptap` is the toolkit the editor
    // runs on — Editor, Extension, Node, Mark, Plugin, PluginKey, Decoration, DecorationSet, …
    extensions: (tiptap) => [tiptap.Mark.create({
        name: 'highlight',
        parseHTML: () => [{ tag: 'mark' }],
        renderHTML: () => ['mark', 0],
    })],
    // Buttons, after the built-in ones.
    buttons: [{
        name: 'highlight', label: 'H!', title: 'Highlight',
        run: (editor, ui) => editor.chain().focus().toggleMark('highlight').run(),
        active: (editor) => editor.isActive('highlight'),
    }],
    // Anything else: a panel of its own (`ui.addPanel(element)`), listeners. Return a teardown.
    setup(ui) { /* ui.editor, ui.wrapper, ui.toolbar, ui.labels */ },
});
```

A plugin under a name already registered replaces it. Plugins apply to editors made after they are
registered.

An embed provider of the site's own is asked before the built-in ones:

```js
pfRichEditor.embeds.add({
    name: 'podcast',
    match(url) {
        const m = /^https:\/\/podcasts\.example\/ep\/(\d+)/.exec(url);
        return m ? { src: 'https://podcasts.example/player/' + m[1], label: 'Podcast' } : null;
    },
});
```

`match` answers `{ src, label, video }` for an address it embeds — `src` is drawn in a frame, a
provider without one is shown by name — or `null`.

## Building it

The sources are ES modules in `resources/js/rich-editor/`: `core.js` (the editor and its
toolbar, `createRichEditor()`), `extensions.js` (the picture, the kept selection, moving blocks,
embeds), `page.js` (the textarea wiring and `window.pfRichEditor`). `npm run build:editor` bundles
them with TipTap into `scaffolding/assets/js/pf-rich-editor.js`, whose first line records the
TipTap version and a hash of the sources; a test fails when the sources changed and the file was
not rebuilt.
