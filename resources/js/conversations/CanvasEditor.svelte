<script>
    /**
     * A conversation's canvas: read by everyone who reads the conversation, edited by one member at
     * a time. "Edit" takes the lock (`lockcanvas`) and keeps it with a heartbeat while editing;
     * somebody else sees "X is editing" and reads. "Save" writes a version, "Done" saves and lets the
     * lock go. The versions are listed; one is shown and can be restored.
     *
     * The text is edited with the framework's rich editor — the page prints
     * `MessageEditor::scripts(MessageEditor::RICH)`, its styles, words and script —
     * and a plugin of its own: a checklist, and mentions of people (`@`, or the button) as
     * `<span data-type="mention" data-id>`. Pictures dropped or chosen are the conversation's files.
     *
     * Props: `api` (the conversations' client), `canvasId`, `conversationId`, `me`, `onclose`, and
     * `ondelete` (after it was deleted; `onclose` without it). Its author, and whoever manages the
     * conversation, see "Delete", asked in the page.
     * Call `reload()` when the conversation's channel says `canvas.changed` for this canvas.
     */
    import { tick } from 'svelte';
    import { t } from './t.js';

    let { api, canvasId, conversationId, me, onclose = null, ondelete = null } = $props();

    let canvas = $state(null);
    let editing = $state(false);
    let editor = $state('');            // who else is editing, by name
    let title = $state('');
    let error = $state('');
    let saving = $state(false);
    let versions = $state(null);
    let shown = $state(null);           // a version being looked at
    let deleting = $state(false);       // asked in the page: delete it?
    let host = $state(null);
    let instance = null;
    let beat = null;

    /** Read it again: after a save elsewhere, or when the lock changed hands. */
    export async function reload() {
        try {
            const answer = await api.get('canvas/' + canvasId);
            canvas = answer.canvas;
            if (!editing) {
                title = canvas.title;
            }
            editor = canvas.lockedBy && canvas.lockedBy !== me ? (canvas.editorName || '') : '';
        } catch (err) {
            error = err.message;
        }
    }

    $effect(() => {
        reload();
        return () => stop();
    });

    /** The buttons' signs: symbols, not words, so nothing to translate. */
    const CHECK = '☑';
    const AT = '@';

    /** A person mentioned: an inline node the server reads by its `data-id`. */
    function mentionPlugin() {
        return {
            name: 'canvas',
            extensions: (tiptap) => [
                tiptap.TaskList,
                tiptap.TaskItem.configure({ nested: true }),
                tiptap.Node.create({
                    name: 'mention',
                    group: 'inline',
                    inline: true,
                    atom: true,
                    selectable: false,
                    addAttributes: () => ({ id: { default: null }, label: { default: '' } }),
                    parseHTML: () => [{ tag: 'span[data-type="mention"]', getAttrs: (el) => ({ id: el.getAttribute('data-id'), label: el.getAttribute('data-label') || el.textContent.replace(/^@/, '') }) }],
                    renderHTML: ({ node }) => ['span', { 'data-type': 'mention', 'data-id': node.attrs.id, 'data-label': node.attrs.label, class: 'pf-canvas-mention' }, '@' + node.attrs.label],
                }),
            ],
            buttons: [
                { name: 'checklist', label: CHECK, title: t('Checklist'), run: (e) => e.chain().focus().toggleTaskList().run(), active: (e) => e.isActive('taskList') },
                { name: 'mention', label: AT, title: t('Mention someone'), run: (e, ui) => mention(e, ui) },
            ],
        };
    }

    /**
     * A people picker in a panel of the editor's own, under its toolbar — never the browser's
     * prompt(), which a desktop application does not have: a name typed, the people found, one
     * chosen goes into the text. Escape, or the button again, closes it.
     */
    let picker = null;
    function mention(e, ui) {
        if (picker) {
            picker.remove();
            picker = null;
            return;
        }
        const box = document.createElement('div');
        box.className = 'pf-rich-panel pf-canvas-picker';
        box.setAttribute('role', 'group');
        box.setAttribute('aria-label', t('Mention someone'));
        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'pf-rich-input';
        search.placeholder = t('Mention someone');
        search.setAttribute('aria-label', t('Mention someone'));
        const found = document.createElement('ul');
        found.className = 'pf-rich-found';
        const close = () => { box.remove(); picker = null; e.commands.focus(); };
        let typing = null;
        let asked = 0;
        search.addEventListener('input', () => {
            clearTimeout(typing);
            typing = setTimeout(async () => {
                const mine = ++asked;
                const q = search.value.trim();
                const people = q ? ((await api.get('people', { q }).catch(() => ({}))).people || []) : [];
                if (mine !== asked) {
                    return;
                }
                found.textContent = '';
                people.slice(0, 8).forEach((person) => {
                    const li = document.createElement('li');
                    const pick = document.createElement('button');
                    pick.type = 'button';
                    pick.textContent = person.name;
                    pick.addEventListener('click', () => {
                        close();
                        e.chain().focus().insertContent([{ type: 'mention', attrs: { id: String(person.id), label: person.name } }, { type: 'text', text: ' ' }]).run();
                    });
                    li.append(pick);
                    found.append(li);
                });
            }, 250);
        });
        search.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                found.querySelector('button')?.click();
            }
        });
        box.append(search, found);
        picker = ui.addPanel(box);
        search.focus();
    }

    /** Take the lock and open the editor; or say who has it. */
    async function edit() {
        error = '';
        if (!window.pfRichEditor) {
            error = t('The editor is not loaded on this page.');
            return;
        }
        const answer = await api.post('lockcanvas/' + canvasId);
        if (!answer.locked) {
            editor = answer.editorName || '';
            return;
        }
        await reload();
        editing = true;
        shown = null;
        // After the host is drawn.
        await tick();
        instance = window.pfRichEditor.create(host, {
            content: canvas.body,
            plugins: [mentionPlugin()],
            upload: async (file) => {
                try {
                    const kept = await api.upload('upload/' + conversationId, file);
                    return api.fileUrl(kept.id);
                } catch {
                    return null;
                }
            },
        });
        beat = setInterval(() => api.post('lockcanvas/' + canvasId).catch(() => {}), 1000 * 20);
    }

    /** Save a version; with `done`, close the editor and let the lock go. */
    async function save(done) {
        saving = true;
        error = '';
        try {
            await api.post('savecanvas/' + canvasId, { title, body: instance.getHTML() });
            if (done) {
                await api.post('unlockcanvas/' + canvasId);
                stop();
            }
            await reload();
        } catch (err) {
            error = err.message;
        } finally {
            saving = false;
        }
    }

    /** Close the editor without saving, the lock let go. */
    async function cancel() {
        stop();
        await api.post('unlockcanvas/' + canvasId).catch(() => {});
        await reload();
    }

    function stop() {
        picker?.remove();
        picker = null;
        clearInterval(beat);
        beat = null;
        instance?.destroy();
        instance = null;
        editing = false;
    }

    /** Delete it, asked in the page first; then whoever opened it is told. */
    async function destroy() {
        deleting = false;
        try {
            await api.post('deletecanvas/' + canvasId);
            stop();
            canvas = null;
            (ondelete || onclose)?.();
        } catch (err) {
            error = err.message;
        }
    }

    async function listVersions() {
        versions = versions ? null : (await api.get('canvasversions/' + canvasId)).versions;
    }

    async function show(version) {
        shown = (await api.get('canvas/' + canvasId, { version })).version;
    }

    async function restore(version) {
        try {
            await api.post('restorecanvas/' + canvasId, { version });
            shown = null;
            versions = null;
            await reload();
        } catch (err) {
            error = err.message;
        }
    }
</script>

<section class="pf-canvas" aria-label={t('Canvas')}>
    {#if canvas}
        <header class="pf-canvas-head">
            {#if editing}
                <input class="pf-input pf-canvas-title" bind:value={title} maxlength="150" aria-label={t('Title')}>
                <button type="button" class="pf-conv-btn" disabled={saving} onclick={() => save(false)}>{t('Save')}</button>
                <button type="button" class="pf-conv-btn pf-conv-btn-primary" disabled={saving} onclick={() => save(true)}>{t('Done')}</button>
                <button type="button" class="pf-conv-btn" onclick={cancel}>{t('Cancel')}</button>
            {:else}
                <h2 class="pf-canvas-title">{canvas.title}</h2>
                {#if editor}
                    <span class="pf-canvas-editing">{t('%s is editing', editor)}</span>
                {:else}
                    <button type="button" class="pf-conv-btn" onclick={edit}>{t('Edit')}</button>
                {/if}
                <button type="button" class="pf-conv-btn" aria-expanded={!!versions} onclick={listVersions}>{t('Versions')}</button>
                {#if canvas.deletable}<button type="button" class="pf-conv-btn pf-conv-danger" onclick={() => (deleting = true)}>{t('Delete')}</button>{/if}
                {#if onclose}<button type="button" class="pf-conv-btn" onclick={onclose} aria-label={t('Close')}>✕</button>{/if}
            {/if}
        </header>
        {#if error}<p class="pf-conv-error" role="alert">{error}</p>{/if}
        {#if deleting}
            <div class="pf-conv-confirm" role="alertdialog" aria-label={t('Delete')}>
                <span>{t('Delete this canvas? Its versions are kept in the history.')}</span>
                <button type="button" class="pf-conv-btn pf-conv-danger" onclick={destroy}>{t('Delete')}</button>
                <button type="button" class="pf-conv-btn" onclick={() => (deleting = false)}>{t('Cancel')}</button>
            </div>
        {/if}
        {#if versions}
            <ol class="pf-canvas-versions">
                {#each versions as v (v.version)}
                    <li>
                        <button type="button" class="pf-conv-link" onclick={() => show(v.version)}>{t('Version %s', v.version)}</button>
                        <span class="pf-conv-muted">{new Date(v.at * 1000).toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' })}</span>
                        {#if v.version !== canvas.version}<button type="button" class="pf-conv-btn" onclick={() => restore(v.version)}>{t('Restore')}</button>{/if}
                    </li>
                {/each}
            </ol>
        {/if}
        {#if editing}
            <div class="pf-canvas-editor" bind:this={host}></div>
        {:else if shown}
            <p class="pf-conv-muted">{t('Version %s', shown.version)} · <button type="button" class="pf-conv-link" onclick={() => (shown = null)}>{t('Back to the latest')}</button></p>
            <!-- Cleaned by the server to what a canvas carries. -->
            <div class="pf-canvas-body">{@html shown.body}</div>
        {:else}
            <div class="pf-canvas-body">{@html canvas.body}</div>
        {/if}
    {:else if error}
        <p class="pf-conv-error" role="alert">{error}</p>
    {/if}
</section>
