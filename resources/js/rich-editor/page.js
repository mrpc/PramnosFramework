/**
 * pf-rich-editor.js — the rich editor on a server-rendered page.
 *
 * Built from this file, with TipTap, into `scaffolding/assets/js/pf-rich-editor.js` by
 * `npm run build:editor`; that file reaches a project's web root with every other `pf-*.js`.
 *
 * Every `<textarea data-pf-editor="rich">` becomes an editor whose HTML the textarea keeps, so
 * the form posts what it would without JavaScript. The field says the rest:
 *
 *   data-pf-rich-upload="/admin/Media/upload"   pictures dropped, pasted or chosen are POSTed here
 *                                               as `file`; the answer is `{ "url": "…" }`
 *   data-pf-rich-links="/admin/search"          the link panel searches here (`?q=`); the answer
 *                                               is `[{title, url}]`, or `{results: […]}`
 *   data-pf-rich-plugins="embeds,mentions"      only these plugins; every one registered without it
 *   data-pf-rich-labels='{"bold":"Έντονα"}'     the controls' words
 *   data-pf-rich-block-toolbar="1"              a toolbar over the block at the cursor ("0": none)
 *
 * `window.pfRichEditorDefaults = { labels, upload, links, blockToolbar }`, set before this file, is what a field
 * without its own attribute takes — `MessageEditor::scripts('rich')` prints the application's.
 *
 * A site adds its own behaviour with a plugin, before the page has loaded:
 *
 *   pfRichEditor.use({ name: 'mentions', extensions: (tiptap) => [...], buttons: [...], setup(ui) {...} });
 *   pfRichEditor.embeds.add({ name: 'podcast', match: (url) => ... ? { src, label } : null });
 *
 *   pfRichEditor.enhance(textarea)   // a field added after the page loaded
 *   pfRichEditor.create(element, options)   // an editor that is not a field
 */
import { createRichEditor, embedFor, embedProviders, LABELS, moveBlock, tiptap } from './core.js';

const registered = [];
const editors = new WeakMap();

/** The page's CSRF token, for the upload — the same header pf-utils.js sends. */
function headers() {
    if (typeof window.pfApiHeaders === 'function') {
        return window.pfApiHeaders({ Accept: 'application/json' });
    }
    const meta = document.querySelector('meta[name="csrf"]');

    return meta && meta.content ? { Accept: 'application/json', 'X-CSRF-Token': meta.content } : { Accept: 'application/json' };
}

/** A picture POSTed to `url`; its address, or null when it was refused. */
function uploader(url) {
    return (file) => {
        const body = new FormData();
        body.append('file', file);

        return window.fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: headers() })
            .then((r) => (r.ok ? r.json() : null))
            .then((answer) => (answer && typeof answer.url === 'string' ? answer.url : null))
            .catch(() => null);
    };
}

/** Pages to link to, from `url?q=`. */
function linkFinder(url) {
    return (text) => window.fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(text),
        { credentials: 'same-origin', headers: headers() })
        .then((r) => (r.ok ? r.json() : []))
        .then((answer) => (Array.isArray(answer) ? answer : (answer && answer.results) || []));
}

/**
 * The plugins a field takes: the ones it names, else every one registered. `embeds` is built in
 * and named like a plugin, so a field can leave it out.
 */
function pluginsFor(field) {
    const named = (field.getAttribute('data-pf-rich-plugins') || '').split(',').map((s) => s.trim()).filter(Boolean);

    return named.length ? registered.filter((p) => named.indexOf(p.name) !== -1) : registered.slice();
}

/** Turn a textarea into an editor, once. */
function enhance(field) {
    if (!field || editors.has(field)) {
        return editors.get(field) || null;
    }
    // The application's defaults, as MessageEditor::scripts() prints them; a field's own win.
    const defaults = window.pfRichEditorDefaults || {};
    let labels = {};
    try { labels = JSON.parse(field.getAttribute('data-pf-rich-labels') || '{}'); } catch (e) { labels = {}; }
    labels = Object.assign({}, defaults.labels || {}, labels);
    const named = (field.getAttribute('data-pf-rich-plugins') || '').split(',').map((s) => s.trim());
    const host = document.createElement('div');
    field.insertAdjacentElement('afterend', host);
    field.hidden = true;

    const upload = field.getAttribute('data-pf-rich-upload') || defaults.upload || '';
    const links = field.getAttribute('data-pf-rich-links') || defaults.links || '';
    const instance = createRichEditor(host, {
        content: field.value,
        readonly: field.readOnly || field.disabled,
        upload: upload ? uploader(upload) : null,
        findLinks: links ? linkFinder(links) : null,
        plugins: pluginsFor(field),
        embeds: field.hasAttribute('data-pf-rich-plugins') && named.indexOf('embeds') === -1 ? false : {},
        labels,
        // The field's own say, else the application's.
        blockToolbar: field.hasAttribute('data-pf-rich-block-toolbar')
            ? field.getAttribute('data-pf-rich-block-toolbar') !== '0'
            : !!defaults.blockToolbar,
        onChange: (html) => {
            field.value = html;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        },
    });
    // The label that named the textarea names the text now.
    if (field.id) {
        const label = document.querySelector('label[for="' + field.id + '"]');
        if (label) {
            label.addEventListener('click', (event) => { event.preventDefault(); instance.editor.commands.focus(); });
        }
    }
    editors.set(field, instance);

    return instance;
}

function enhanceAll() {
    Array.prototype.forEach.call(document.querySelectorAll('textarea[data-pf-editor="rich"]'), enhance);
}

window.pfRichEditor = {
    create: createRichEditor,
    enhance,
    /** Add a plugin to every editor made from now on; one under the same name replaces it. */
    use(plugin) {
        if (!plugin || !plugin.name) { return; }
        const at = registered.findIndex((p) => p.name === plugin.name);
        if (at === -1) { registered.push(plugin); } else { registered[at] = plugin; }
    },
    plugins: () => registered.slice(),
    /** The editor a field became, if it did. */
    for: (field) => editors.get(field) || null,
    /**
     * The bare textarea for a field (`on`), or the editor again — for a channel that is text
     * only, as `PfEditor.plain()` does for the builtin one. What was typed goes across both ways.
     */
    plain(field, on) {
        const instance = editors.get(field);
        if (!instance) { return; }
        if (on) {
            field.value = instance.getHTML();
            instance.element.hidden = true;
            field.hidden = false;
        } else {
            instance.setHTML(field.value);
            field.hidden = true;
            instance.element.hidden = false;
        }
    },
    tiptap,
    moveBlock,
    labels: LABELS,
    embeds: {
        /** A provider of the site's own, asked before the built-in ones. */
        add(provider) {
            if (provider && typeof provider.match === 'function') { embedProviders.unshift(provider); }
        },
        providers: embedProviders,
        for: (text) => embedFor(text),
    },
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhanceAll);
} else {
    enhanceAll();
}
