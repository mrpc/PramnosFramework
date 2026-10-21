/**
 * The rich editor's own TipTap extensions — what a page of text needs beyond StarterKit.
 *
 * Generic on purpose: a picture with its caption, the selection kept while a panel has the focus,
 * blocks moved with the keyboard, a link's address on hover, and embeds whose providers are a
 * list anybody can add to. A site's own behaviour arrives as a plugin (see core.js), not here.
 */
import { Extension, Node } from '@tiptap/core';
import { NodeSelection, Plugin, TextSelection } from '@tiptap/pm/state';
import { Fragment } from '@tiptap/pm/model';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

/**
 * A picture, with its alternative text and its caption: `<figure><img><figcaption>`.
 *
 * TipTap's image knows no caption, so a caption written under a picture was read back as a
 * paragraph after it and saved as one. A picture without a caption is the same node with none;
 * a bare `<img>` is read as one too.
 */
export const Picture = Node.create({
    name: 'picture',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,
    addAttributes() {
        const img = (el) => (el.tagName === 'IMG' ? el : el.querySelector('img'));

        return {
            src: { default: null, parseHTML: (el) => img(el)?.getAttribute('src') ?? null },
            alt: { default: '', parseHTML: (el) => img(el)?.getAttribute('alt') ?? '' },
            caption: {
                default: '',
                parseHTML: (el) => (el.tagName === 'FIGURE' ? el.querySelector('figcaption')?.textContent.trim() ?? '' : ''),
            },
        };
    },
    parseHTML() {
        return [
            { tag: 'figure', getAttrs: (el) => (el.querySelector('img') ? null : false) },
            { tag: 'img[src]' },
        ];
    },
    renderHTML({ node }) {
        const { src, alt, caption } = node.attrs;

        return ['figure', {}, ['img', { src, alt }], ...(caption ? [['figcaption', {}, caption]] : [])];
    },
});

/** Where each link goes, on hover — a decoration, so the title is never saved into the text. */
export const LinkTitles = Extension.create({
    name: 'linkTitles',
    addProseMirrorPlugins() {
        return [new Plugin({
            props: {
                decorations(state) {
                    const titles = [];
                    state.doc.descendants((node, pos) => {
                        const mark = node.isText ? node.marks.find((m) => m.type.name === 'link') : null;
                        if (mark) {
                            titles.push(Decoration.inline(pos, pos + node.nodeSize, { title: mark.attrs.href }));
                        }
                    });

                    return DecorationSet.create(state.doc, titles);
                },
            },
        })];
    },
});

/**
 * The selected words stay marked while the focus is in a panel — the link's address, a search.
 * The browser draws no selection outside a focused editor, so without this the words seemed
 * gone, and there looked to be nothing to link.
 */
export const KeptSelection = Extension.create({
    name: 'keptSelection',
    addProseMirrorPlugins() {
        const kept = new Plugin({
            state: {
                init: () => false,
                apply: (tr, blurred) => (tr.getMeta('blur') ? true : tr.getMeta('focus') ? false : blurred),
            },
            props: {
                decorations(state) {
                    const { from, to, empty } = state.selection;

                    return kept.getState(state) && !empty
                        ? DecorationSet.create(state.doc, [Decoration.inline(from, to, { class: 'pf-rich-kept' })])
                        : null;
                },
            },
        });

        return [kept];
    },
});

/**
 * Move the block at the cursor — a paragraph, a heading, a list, a quote, a picture — one place
 * up (-1) or down (1). The cursor goes with it. False at either end.
 *
 * @param {import('@tiptap/core').Editor} editor
 * @param {number} dir
 * @returns {boolean}
 */
export function moveBlock(editor, dir) {
    const { state } = editor;
    const { doc, selection } = state;
    const index = selection.$from.index(0);
    const other = index + dir;

    if (other < 0 || other >= doc.childCount) {
        return false;
    }

    const block = doc.child(index);
    const next = doc.child(other);
    let start = 0;
    for (let i = 0; i < Math.min(index, other); i++) {
        start += doc.child(i).nodeSize;
    }

    const from = dir < 0 ? start + next.nodeSize : start;
    const to = dir < 0 ? start : start + next.nodeSize;
    const shift = to - from;
    const tr = state.tr.replaceWith(start, start + block.nodeSize + next.nodeSize,
        Fragment.fromArray(dir < 0 ? [block, next] : [next, block]));

    tr.setSelection(selection instanceof NodeSelection
        ? NodeSelection.create(tr.doc, selection.from + shift)
        : TextSelection.create(tr.doc, selection.from + shift, selection.to + shift));
    editor.view.dispatch(tr.scrollIntoView());

    return true;
}

/** Alt+↑ and Alt+↓ move the block at the cursor. */
export const MoveBlocks = Extension.create({
    name: 'moveBlocks',
    addKeyboardShortcuts() {
        return {
            'Alt-ArrowUp': () => moveBlock(this.editor, -1),
            'Alt-ArrowDown': () => moveBlock(this.editor, 1),
        };
    },
});

/**
 * The embed providers: each `{ name, match(url) }`, where `match` answers
 * `{ src, label, video }` for an address it embeds, or null. The first that answers wins, so a
 * site's own provider added before a built-in one replaces it for its addresses.
 */
export const embedProviders = [
    {
        name: 'youtube',
        match(url) {
            const m = /^https?:\/\/(?:www\.|m\.)?(?:youtube\.com\/(?:watch\?(?:.*&)?v=|shorts\/|embed\/)|youtu\.be\/)([\w-]{11})/.exec(url);

            return m ? { src: 'https://www.youtube-nocookie.com/embed/' + m[1], label: 'YouTube', video: true } : null;
        },
    },
    {
        name: 'vimeo',
        match(url) {
            const m = /^https?:\/\/(?:www\.)?vimeo\.com\/(\d+)/.exec(url);

            return m ? { src: 'https://player.vimeo.com/video/' + m[1], label: 'Vimeo', video: true } : null;
        },
    },
];

/**
 * The embed an address alone in a paragraph stands for, or null.
 *
 * @param {string} text
 * @param {Array<{name: string, match: Function}>} providers
 */
export function embedFor(text, providers = embedProviders) {
    const url = String(text || '').trim();
    if (!/^https?:\/\/\S+$/.test(url)) {
        return null;
    }
    for (const provider of providers) {
        const found = provider.match(url);
        if (found) {
            return Object.assign({ provider: provider.name, url }, found);
        }
    }

    return null;
}

/**
 * An address alone in a paragraph, previewed under it as the site will show it. What is kept and
 * sent is the address — the paragraph stays the address and stays editable; how it is embedded
 * on the published page is the site's own business.
 *
 * @param {{ providers?: Array, preview?: Function }} [options] `providers` replaces the list;
 *        `preview(embed)` draws the preview element instead of the iframe
 */
export function Embeds(options = {}) {
    const providers = () => options.providers || embedProviders;

    return Extension.create({
        name: 'embedPreviews',
        addProseMirrorPlugins() {
            return [new Plugin({
                props: {
                    decorations(state) {
                        const widgets = [];
                        state.doc.forEach((node, offset) => {
                            const embed = node.type.name === 'paragraph' ? embedFor(node.textContent, providers()) : null;
                            if (embed) {
                                widgets.push(Decoration.node(offset, offset + node.nodeSize, {
                                    class: 'pf-rich-embed-source', title: embed.label + ' — the address it is embedded from',
                                }));
                                widgets.push(Decoration.widget(offset + node.nodeSize,
                                    () => (options.preview ? options.preview(embed) : framePreview(embed)), { key: embed.url, side: -1 }));
                            }
                        });

                        return DecorationSet.create(state.doc, widgets);
                    },
                },
            })];
        },
    });
}

/** The default preview: the provider's player in a frame, or the provider's name. */
function framePreview(embed) {
    const box = document.createElement('div');
    box.className = 'pf-rich-embed' + (embed.video ? ' is-video' : '');
    box.contentEditable = 'false';
    box.dataset.embed = embed.provider;
    if (embed.src) {
        const frame = document.createElement('iframe');
        frame.src = embed.src;
        frame.title = embed.label;
        frame.loading = 'lazy';
        frame.allow = 'encrypted-media; picture-in-picture; fullscreen';
        frame.setAttribute('allowfullscreen', '');
        box.append(frame);
    } else {
        box.textContent = embed.label + ' — shown embedded on the site';
    }

    return box;
}
