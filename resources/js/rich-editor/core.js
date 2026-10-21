/**
 * The rich editor's core: TipTap with a toolbar, a link panel and a picture panel, in plain DOM.
 *
 * One core, two ways in. Server-rendered pages get it bundled with TipTap as
 * `assets/js/pf-rich-editor.js` (see page.js), turning `<textarea data-pf-editor="rich">` into an
 * editor; a SPA imports it (the Svelte wrapper) with TipTap from its own dependencies.
 *
 * What a site adds of its own arrives as a **plugin**, never as a change here:
 *
 *   {
 *     name: 'mentions',
 *     extensions: (tiptap) => [ ...TipTap extensions... ],   // nodes, marks, keymaps, decorations
 *     buttons: [{ name, label, title, run(editor, ui), active?(editor) }],
 *     setup(ui) { ... return teardown?; },                    // panels, listeners
 *   }
 *
 * `tiptap` is the toolkit the editor runs on (Editor, Extension, Node, Mark, Plugin, PluginKey,
 * Decoration, DecorationSet, …), so a plugin written as a plain script needs no build of its own.
 */
import { Editor, Extension, Mark, Node, mergeAttributes } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { NodeSelection, Plugin, PluginKey, TextSelection } from '@tiptap/pm/state';
import { Fragment, Slice } from '@tiptap/pm/model';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import { Embeds, KeptSelection, LinkTitles, MoveBlocks, Picture, embedFor, embedProviders, moveBlock } from './extensions.js';

/** What a plugin builds with. */
export const tiptap = {
    Editor, Extension, Mark, Node, mergeAttributes, Plugin, PluginKey, NodeSelection, TextSelection,
    Fragment, Slice, Decoration, DecorationSet,
};

export { embedFor, embedProviders, moveBlock };

/** The words of the editor's own controls, in English; `options.labels` replaces any of them. */
export const LABELS = {
    formatting: 'Formatting',
    bold: 'Bold',
    italic: 'Italic',
    heading: 'Heading',
    subheading: 'Subheading',
    bulletList: 'Bulleted list',
    orderedList: 'Numbered list',
    quote: 'Quote',
    link: 'Link',
    picture: 'Picture',
    pictureFile: 'Upload a picture',
    pictureUrl: 'Picture by address',
    pictureAddress: 'Picture address',
    moveUp: 'Move the block up (Alt+↑)',
    moveDown: 'Move the block down (Alt+↓)',
    undo: 'Undo',
    redo: 'Redo',
    address: 'Address',
    open: 'Open ↗',
    removeLink: 'Remove link',
    findLink: 'Find a page on the site — its title or address',
    altText: 'Alt text',
    altHint: 'What the picture shows, for who cannot see it',
    caption: 'Caption',
    captionHint: 'Shown under the picture',
    body: 'Text',
    block: 'Block',
    paragraph: 'Paragraph',
};

/** The built-in buttons, in toolbar order. */
function builtinButtons(l, ui) {
    const toggle = (name, label, title, command, active) => ({ name, label, title, run: (e) => e.chain().focus()[command]().run(), active });
    const buttons = [
        toggle('bold', 'B', l.bold, 'toggleBold', (e) => e.isActive('bold')),
        toggle('italic', 'I', l.italic, 'toggleItalic', (e) => e.isActive('italic')),
        { name: 'h2', label: 'H2', title: l.heading, run: (e) => e.chain().focus().toggleHeading({ level: 2 }).run(), active: (e) => e.isActive('heading', { level: 2 }) },
        { name: 'h3', label: 'H3', title: l.subheading, run: (e) => e.chain().focus().toggleHeading({ level: 3 }).run(), active: (e) => e.isActive('heading', { level: 3 }) },
        toggle('bulletList', '• ' + l.bulletList.split(' ').pop(), l.bulletList, 'toggleBulletList', (e) => e.isActive('bulletList')),
        toggle('orderedList', '1.', l.orderedList, 'toggleOrderedList', (e) => e.isActive('orderedList')),
        toggle('blockquote', '“ ”', l.quote, 'toggleBlockquote', (e) => e.isActive('blockquote')),
        { name: 'link', label: l.link, title: l.link, run: () => ui.openLinkPanel(), active: (e) => e.isActive('link') },
    ];
    if (ui.canUpload) {
        buttons.push({ name: 'pictureFile', label: l.picture, title: l.pictureFile, run: () => ui.pickFile() });
    }
    buttons.push(
        { name: 'pictureUrl', label: l.picture + ' URL', title: l.pictureUrl, run: (e) => {
            const src = window.prompt(l.pictureAddress, 'https://');
            if (src) { e.chain().focus().insertContent({ type: 'picture', attrs: { src } }).run(); }
        } },
        { name: 'moveUp', label: '▲', title: l.moveUp, run: (e) => { moveBlock(e, -1); e.commands.focus(); } },
        { name: 'moveDown', label: '▼', title: l.moveDown, run: (e) => { moveBlock(e, 1); e.commands.focus(); } },
    );

    return buttons;
}

/**
 * Make an editor in `host`.
 *
 * @param {HTMLElement} host The element the editor is drawn into
 * @param {object} [options]
 * @param {string}   [options.content]  The HTML to start from
 * @param {Function} [options.onChange] `(html) => void`, on every change
 * @param {Function} [options.upload]   `(File) => Promise<string|null>` — a picture's address once stored
 * @param {Function} [options.findLinks] `(text) => Promise<{title, url}[]>` — pages to link to
 * @param {boolean}  [options.readonly]
 * @param {Array}    [options.plugins]  Plugins, as above
 * @param {object|false} [options.embeds] `{ providers, preview }` for the embed previews; false for none
 * @param {object}   [options.labels]   Replacements for LABELS
 * @param {HTMLElement} [options.header] What sits in the text's column above the text, under the
 *                                    toolbar — a title field, as a page of an article has one. Moved
 *                                    into the editor, and out again with destroy()
 * @param {boolean}  [options.blockToolbar] A small toolbar floating over the block at the cursor —
 *                                    move it, make it a paragraph or a heading, bold, italic, link;
 *                                    a plugin's button with `block: true` is on it too. Off by default
 * @returns {{ editor: Editor, element: HTMLElement, getHTML: Function, setHTML: Function, setReadonly: Function, destroy: Function }}
 */
export function createRichEditor(host, options = {}) {
    const l = Object.assign({}, LABELS, options.labels || {});
    const plugins = (options.plugins || []).filter(Boolean);
    const teardowns = [];

    const wrap = document.createElement('div');
    wrap.className = 'pf-rich';
    const bar = document.createElement('div');
    bar.className = 'pf-rich-toolbar';
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', l.formatting);
    // A press on the toolbar must not take the focus from the text, or the selection goes first.
    bar.addEventListener('mousedown', (event) => {
        if (!(event.target instanceof HTMLInputElement)) { event.preventDefault(); }
    });
    const panels = document.createElement('div');
    panels.className = 'pf-rich-panels';
    const area = document.createElement('div');
    area.className = 'pf-rich-area';
    wrap.append(bar, panels, area);
    host.append(wrap);

    const file = document.createElement('input');
    file.type = 'file';
    file.accept = 'image/*';
    file.hidden = true;
    wrap.append(file);

    const ui = {
        canUpload: typeof options.upload === 'function',
        openLinkPanel: () => { linkPanel.adding = true; drawLinkPanel(); },
        pickFile: () => file.click(),
        /** A panel of a plugin's own, under the toolbar. */
        addPanel(element) { panels.append(element); return element; },
        labels: l,
        wrapper: wrap,
        toolbar: bar,
        editor: null,
    };

    const extensions = [
        StarterKit.configure({ link: { openOnClick: false } }),
        Picture, LinkTitles, KeptSelection, MoveBlocks,
    ];
    if (options.embeds !== false) {
        extensions.push(Embeds(options.embeds || {}));
    }
    plugins.forEach((plugin) => {
        if (typeof plugin.extensions === 'function') { extensions.push(...plugin.extensions(tiptap)); }
    });

    const buttons = builtinButtons(l, ui);
    plugins.forEach((plugin) => (plugin.buttons || []).forEach((b) => buttons.push(b)));
    buttons.push({ name: 'undo', label: '↶', title: l.undo, run: (e) => e.chain().focus().undo().run(), spacer: true },
        { name: 'redo', label: '↷', title: l.redo, run: (e) => e.chain().focus().redo().run() });

    const drawn = buttons.map((spec) => {
        if (spec.spacer) {
            const gap = document.createElement('span');
            gap.className = 'pf-rich-spacer';
            bar.append(gap);
        }
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'pf-rich-button';
        button.dataset.button = spec.name;
        button.textContent = spec.label;
        button.title = spec.title || spec.label;
        button.setAttribute('aria-label', spec.title || spec.label);
        button.addEventListener('click', () => { if (editor.isEditable) { spec.run(editor, ui); } });
        bar.append(button);

        return { spec, button };
    });

    /** Pictures dropped or pasted, uploaded and put in — at `at`, else the cursor. */
    function uploadInto(event, data, at) {
        const files = Array.prototype.filter.call((data && data.files) || [], (f) => /^image\//.test(f.type));
        // Text beside a picture is what was meant: Word pastes a picture of the selection too.
        if (!ui.canUpload || !files.length || (data.getData && data.getData('text/plain'))) {
            return false;
        }
        event.preventDefault();
        Promise.all(files.map((f) => options.upload(f))).then((srcs) => {
            const content = srcs.filter(Boolean).map((src) => ({ type: 'picture', attrs: { src } }));
            if (content.length) {
                const chain = editor.chain().focus();
                (at == null ? chain.insertContent(content) : chain.insertContentAt(at, content)).run();
            }
        });

        return true;
    }

    const editor = new Editor({
        element: area,
        extensions,
        content: options.content || '',
        editable: !options.readonly,
        editorProps: {
            attributes: { class: 'pf-rich-text', 'aria-label': l.body, role: 'textbox', 'aria-multiline': 'true' },
            handleDrop: (view, event, slice, moved) => !moved && uploadInto(event, event.dataTransfer,
                view.posAtCoords({ left: event.clientX, top: event.clientY })?.pos),
            handlePaste: (view, event) => uploadInto(event, event.clipboardData),
            handleDOMEvents: {
                // A picture is chosen by clicking it, wherever on it the click lands.
                click: (view, event) => {
                    const figure = event.target instanceof Element ? event.target.closest('figure') : null;
                    if (figure && view.dom.contains(figure)) {
                        view.dispatch(view.state.tr.setSelection(NodeSelection.create(view.state.doc, view.posAtDOM(figure, 0))));
                    }

                    return false;
                },
            },
        },
        onUpdate: ({ editor: e }) => { if (options.onChange) { options.onChange(e.getHTML()); } },
        onTransaction: ({ transaction }) => {
            drawn.forEach(({ spec, button }) => {
                const on = spec.active ? !!spec.active(editor) : false;
                button.classList.toggle('is-active', on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            linkPanel.href = editor.getAttributes('link').href || '';
            linkPanel.adding = linkPanel.adding && !(transaction.selectionSet && !linkPanel.href);
            drawLinkPanel();
            drawPicturePanel();
        },
    });
    ui.editor = editor;

    // The title, in the text's column: under the toolbar, above the text.
    const header = options.header || null;
    const headerHome = header ? { parent: header.parentNode, next: header.nextSibling } : null;
    if (header) {
        header.classList.add('pf-rich-header');
        area.insertBefore(header, editor.view.dom);
    }

    file.addEventListener('change', () => {
        const chosen = file.files && file.files[0];
        file.value = '';
        if (chosen && ui.canUpload) {
            options.upload(chosen).then((src) => {
                if (src) { editor.chain().focus().insertContent({ type: 'picture', attrs: { src } }).run(); }
            });
        }
    });

    // ── The link at the cursor: where it goes, to open, change or remove; or a page to point it at ──
    const linkPanel = { href: '', adding: false, element: null, shownFor: null };

    function linkTo(url, text) {
        url = String(url || '').trim();
        if (!url) { return; }
        const bare = !linkPanel.href && editor.state.selection.empty;
        const chain = editor.chain();
        (bare ? chain.insertContent({ type: 'text', text: text || url, marks: [{ type: 'link', attrs: { href: url } }] })
            : chain.extendMarkRange('link').setLink({ href: url })).run();
        linkPanel.adding = false;
        drawLinkPanel();
    }

    function drawLinkPanel() {
        const show = !!(linkPanel.href || linkPanel.adding) && editor.isEditable;
        const key = show ? linkPanel.href + '|' + linkPanel.adding : null;
        if (key === linkPanel.shownFor) { return; }
        linkPanel.shownFor = key;
        if (linkPanel.element) { linkPanel.element.remove(); linkPanel.element = null; }
        if (!show) { return; }

        const box = document.createElement('div');
        box.className = 'pf-rich-panel';
        box.setAttribute('role', 'group');
        box.setAttribute('aria-label', l.link);
        const label = document.createElement('label');
        label.textContent = l.address + ' ';
        const input = document.createElement('input');
        input.className = 'pf-rich-input';
        input.value = linkPanel.href;
        input.placeholder = 'https://';
        input.addEventListener('change', () => linkTo(input.value));
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') { event.preventDefault(); linkTo(input.value); }
        });
        label.append(input);
        box.append(label);
        if (linkPanel.href) {
            const open = document.createElement('a');
            open.href = linkPanel.href;
            open.target = '_blank';
            open.rel = 'noopener noreferrer';
            open.textContent = l.open;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'pf-rich-button';
            remove.textContent = l.removeLink;
            remove.addEventListener('click', () => {
                editor.chain().focus().extendMarkRange('link').unsetLink().run();
                linkPanel.adding = false;
            });
            box.append(open, remove);
        }
        if (typeof options.findLinks === 'function') {
            const search = document.createElement('input');
            search.type = 'search';
            search.className = 'pf-rich-input';
            search.placeholder = l.findLink;
            search.setAttribute('aria-label', l.findLink);
            const results = document.createElement('ul');
            results.className = 'pf-rich-found';
            let typing = null;
            let asked = 0;
            search.addEventListener('input', () => {
                window.clearTimeout(typing);
                typing = window.setTimeout(() => {
                    const mine = ++asked;
                    const text = search.value.trim();
                    (text ? Promise.resolve(options.findLinks(text)).catch(() => []) : Promise.resolve([])).then((found) => {
                        if (mine !== asked) { return; }
                        results.textContent = '';
                        (found || []).forEach((page) => {
                            const li = document.createElement('li');
                            const pick = document.createElement('button');
                            pick.type = 'button';
                            pick.textContent = page.title || page.url;
                            pick.title = page.url;
                            pick.addEventListener('click', () => linkTo(page.url, page.title || page.url));
                            li.append(pick);
                            results.append(li);
                        });
                    });
                }, 400);
            });
            box.append(search, results);
        }
        panels.append(box);
        linkPanel.element = box;
        if (linkPanel.adding && !linkPanel.href) { input.focus(); }
    }

    // ── The picture clicked: where it is, what it says to who cannot see it, and its caption ──
    let picturePanel = null;
    let pictureAt = null;

    function drawPicturePanel() {
        const node = editor.state.selection.node;
        const isPicture = node && node.type.name === 'picture' && editor.isEditable;
        const at = isPicture ? editor.state.selection.from : null;
        if (at === pictureAt) { return; }
        pictureAt = at;
        if (picturePanel) { picturePanel.remove(); picturePanel = null; }
        if (!isPicture) { return; }

        const box = document.createElement('div');
        box.className = 'pf-rich-panel';
        box.setAttribute('role', 'group');
        box.setAttribute('aria-label', l.picture);
        const where = document.createElement('a');
        where.href = node.attrs.src || '';
        where.target = '_blank';
        where.rel = 'noopener noreferrer';
        where.textContent = node.attrs.src || '';
        where.className = 'pf-rich-src';
        box.append(where);
        [['alt', l.altText, l.altHint], ['caption', l.caption, l.captionHint]].forEach(([field, name, hint]) => {
            const label = document.createElement('label');
            label.textContent = name + ' ';
            const input = document.createElement('input');
            input.className = 'pf-rich-input';
            input.value = node.attrs[field] || '';
            input.placeholder = hint;
            input.dataset.field = field;
            input.addEventListener('input', () => {
                editor.chain().updateAttributes('picture', { [field]: input.value }).run();
            });
            label.append(input);
            box.append(label);
        });
        panels.append(box);
        picturePanel = box;
    }

    // ── The block toolbar: over the block at the cursor, while the text has the focus ──
    let blockBar = null;
    if (options.blockToolbar) {
        area.classList.add('has-block-toolbar');
        blockBar = document.createElement('div');
        blockBar.className = 'pf-rich-block';
        blockBar.setAttribute('role', 'toolbar');
        blockBar.setAttribute('aria-label', l.block);
        blockBar.hidden = true;
        // Pressing a button must not take the focus from the text, or the bar would go first.
        blockBar.addEventListener('mousedown', (event) => event.preventDefault());
        const blockSpecs = [
            { name: 'moveUp', label: '▲', title: l.moveUp, run: (e) => moveBlock(e, -1) },
            { name: 'moveDown', label: '▼', title: l.moveDown, run: (e) => moveBlock(e, 1) },
            { gap: true },
            { name: 'paragraph', label: '¶', title: l.paragraph, run: (e) => e.chain().focus().setParagraph().run(), active: (e) => e.isActive('paragraph') },
            { name: 'h2', label: 'H2', title: l.heading, run: (e) => e.chain().focus().toggleHeading({ level: 2 }).run(), active: (e) => e.isActive('heading', { level: 2 }) },
            { name: 'h3', label: 'H3', title: l.subheading, run: (e) => e.chain().focus().toggleHeading({ level: 3 }).run(), active: (e) => e.isActive('heading', { level: 3 }) },
            { gap: true },
            { name: 'bold', label: 'B', title: l.bold, run: (e) => e.chain().focus().toggleBold().run(), active: (e) => e.isActive('bold') },
            { name: 'italic', label: 'I', title: l.italic, run: (e) => e.chain().focus().toggleItalic().run(), active: (e) => e.isActive('italic') },
            { name: 'link', label: l.link, title: l.link, run: () => ui.openLinkPanel(), active: (e) => e.isActive('link') },
        ].concat(buttons.filter((b) => b.block));
        blockSpecs.forEach((spec) => {
            if (spec.gap) {
                const gap = document.createElement('span');
                gap.className = 'pf-rich-block-gap';
                blockBar.append(gap);
                return;
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pf-rich-button';
            button.dataset.block = spec.name;
            button.textContent = spec.label;
            button.title = spec.title || spec.label;
            button.setAttribute('aria-label', l.block + ': ' + (spec.title || spec.label));
            button.addEventListener('click', () => { if (editor.isEditable) { spec.run(editor, ui); } });
            blockBar.append(button);
            drawn.push({ spec, button });
        });
        area.prepend(blockBar);

        let focused = false;
        const place = () => {
            const show = focused && editor.isEditable;
            blockBar.hidden = !show;
            if (!show) { return; }
            const { selection } = editor.state;
            const at = selection.$from.depth === 0 ? selection.from : selection.$from.before(1);
            const dom = editor.view.nodeDOM(at);
            const top = dom && typeof dom.getBoundingClientRect === 'function'
                ? dom.getBoundingClientRect().top - area.getBoundingClientRect().top : 0;
            blockBar.style.top = Math.max(0, top - blockBar.offsetHeight - 4) + 'px';
        };
        editor.on('transaction', place);
        editor.on('focus', () => { focused = true; place(); });
        editor.on('blur', () => { focused = false; place(); });
    }

    plugins.forEach((plugin) => {
        if (typeof plugin.setup === 'function') {
            const undo = plugin.setup(ui);
            if (typeof undo === 'function') { teardowns.push(undo); }
        }
    });

    function setReadonly(readonly) {
        // Without an update: an empty text must stay empty, not become <p></p>.
        editor.setEditable(!readonly, false);
        wrap.classList.toggle('is-readonly', !!readonly);
        drawn.forEach(({ button }) => { button.disabled = !!readonly; });
    }
    setReadonly(!!options.readonly);

    return {
        editor,
        element: wrap,
        getHTML: () => editor.getHTML(),
        setHTML: (html) => editor.commands.setContent(html || '', { emitUpdate: false }),
        setReadonly,
        destroy() {
            teardowns.forEach((undo) => undo());
            // The header back where it came from, for whoever owns it to remove.
            if (header && headerHome && headerHome.parent) {
                headerHome.parent.insertBefore(header, headerHome.next && headerHome.next.parentNode === headerHome.parent ? headerHome.next : null);
            }
            editor.destroy();
            wrap.remove();
        },
    };
}
