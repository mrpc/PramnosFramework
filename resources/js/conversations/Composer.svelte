<script>
    /**
     * Writing a message: Enter sends, Shift+Enter is a new line; `@` suggests people (and `@all`,
     * `@online`), `:` suggests emoji; files are picked, dropped or pasted and uploaded at once; the
     * others are told the writer is typing, at most every few seconds. What is left unsent in a
     * conversation is kept in this browser and comes back — the sidebar's *Drafts & sent*.
     */
    import EmojiPicker from './EmojiPicker.svelte';
    import Avatar from './Avatar.svelte';
    import { t } from './t.js';
    import { mentionsToTokens, wordAt, suggestPeople, fold, size, commandIn, draftOf, keepDraft } from './logic.js';
    import { commands, canAsk, assistant } from './extras.js';

    let {
        api, conversationId, thread = null, members = [], me, emoji = {}, maxFileMb = 25, placeholder = '',
        disabled = false, typing = null, onsent = () => {}, onreply = () => {}, onerror = () => {},
    } = $props();

    // What was left unsent here comes back, from this browser; a thread's reply is not kept.
    // svelte-ignore state_referenced_locally
    let text = $state(thread === null ? draftOf(me, conversationId) : '');
    let box = $state(null);
    let files = $state([]);           // [{ key, name, size, id, busy }]
    let suggestions = $state([]);
    let chosen = $state(0);
    let current = null;               // the word being completed
    let picking = $state(false);
    let sending = $state(false);
    let dragging = $state(false);
    let picker = $state(null);
    let picked = [];
    let lastTyping = 0;

    $effect(() => {
        if (thread === null) {
            keepDraft(me, conversationId, text);
        }
    });

    const ready = $derived((text.trim() !== '' || files.some((f) => f.id)) && !files.some((f) => f.busy) && !sending);

    /** The box as tall as what is in it, up to a limit. */
    function fit() {
        if (!box) {
            return;
        }
        box.style.height = 'auto';
        box.style.height = Math.min(box.scrollHeight, 240) + 'px';
    }

    /** What the cursor is in the middle of: a name, an emoji, or nothing. */
    function suggest() {
        // A `/command` at the start, while its name is being typed.
        const typed = /^\/([a-z0-9_-]*)$/i.exec(text.slice(0, box.selectionStart));
        if (typed) {
            current = { sign: '/', word: typed[1], start: 0 };
            suggestions = commands().filter((c) => c.name.startsWith(typed[1].toLowerCase())).slice(0, 8)
                .map((c) => ({ command: c.name, label: `/${c.name}${c.usage ? ' ' + c.usage : ''}`, hint: c.description }));
            chosen = 0;
            return;
        }
        current = wordAt(text, box.selectionStart);
        if (!current) {
            suggestions = [];
            return;
        }
        if (current.sign === '@') {
            suggestions = suggestPeople(members, current.word, me).map((p) => ({ ...p, label: p.special ? '@' + p.name : p.name,
                hint: p.special ? (p.name === 'all' ? t('everybody here') : t('everybody here who is online')) : '' }));
            // The analyst, by its name or a word that calls it, offered as the name it goes by.
            if (canAsk() && assistant().mentions.some((word) => word.startsWith(fold(current.word)))) {
                const name = assistant().name;
                suggestions = [{ id: 0, name, special: true, label: `@${name}`, hint: t('the analyst answers in the thread') }, ...suggestions];
            }
        } else {
            const w = fold(current.word);
            suggestions = current.word.length < 2 ? [] : Object.entries(emoji).filter(([name]) => name.startsWith(w)).slice(0, 8)
                .map(([name, glyph]) => ({ emoji: glyph, label: glyph + ' :' + name + ':' }));
        }
        chosen = 0;
    }

    /** Put the chosen suggestion in place of the word. */
    function take(item) {
        const insert = item.command ? '/' + item.command + ' ' : item.emoji ? item.emoji + ' ' : '@' + item.name + ' ';
        if (!item.emoji && !item.special) {
            picked = picked.filter((p) => p.id !== item.id).concat([{ id: item.id, name: item.name }]);
        }
        const end = box.selectionStart;
        text = text.slice(0, current.start) + insert + text.slice(end);
        const at = current.start + insert.length;
        suggestions = [];
        queueMicrotask(() => {
            box.focus();
            box.setSelectionRange(at, at);
            fit();
        });
    }

    function onInput() {
        fit();
        suggest();
        const now = Date.now();
        if (typing && text.trim() !== '' && now - lastTyping > 3000) {
            lastTyping = now;
            typing(thread);
        }
    }

    function onKeydown(event) {
        if (suggestions.length) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                chosen = (chosen + (event.key === 'ArrowDown' ? 1 : suggestions.length - 1)) % suggestions.length;
                return;
            }
            if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                take(suggestions[chosen]);
                return;
            }
            if (event.key === 'Escape') {
                suggestions = [];
                return;
            }
        }
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            send();
        }
    }

    /** Upload files as they are added; each a chip until the message is sent. */
    function add(list) {
        for (const file of Array.from(list || [])) {
            const key = Math.random().toString(36).slice(2);
            if (file.size > maxFileMb * 1024 * 1024) {
                onerror(t('A file is at most %s MB.', maxFileMb));
                continue;
            }
            files = [...files, { key, name: file.name, size: file.size, id: null, busy: true }];
            api.upload('upload/' + conversationId, file).then((kept) => {
                files = files.map((f) => (f.key === key ? { ...f, id: kept.id, busy: false } : f));
            }).catch((err) => {
                files = files.filter((f) => f.key !== key);
                onerror(err.message);
            });
        }
    }

    function onPaste(event) {
        const pasted = Array.from(event.clipboardData?.files || []);
        if (pasted.length) {
            event.preventDefault();
            add(pasted);
        }
    }

    function onDrop(event) {
        event.preventDefault();
        dragging = false;
        add(event.dataTransfer?.files);
    }

    async function send() {
        if (!ready || disabled) {
            return;
        }
        sending = true;
        const body = mentionsToTokens(text.trim(), picked);
        // One of the application's commands: run, its answer for the writer alone.
        if (commandIn(body, commands()) && !files.some((f) => f.id)) {
            try {
                const answer = await api.post('command/' + conversationId, { text: body, thread });
                text = '';
                picked = [];
                queueMicrotask(fit);
                onreply(answer.reply);
            } catch (err) {
                onerror(err.message);
            } finally {
                sending = false;
                box?.focus();
            }
            return;
        }
        try {
            const answer = await api.post('post/' + conversationId, { body, thread, files: files.filter((f) => f.id).map((f) => f.id) });
            text = '';
            files = [];
            picked = [];
            queueMicrotask(fit);
            onsent(answer.message);
        } catch (err) {
            onerror(err.message);
        } finally {
            sending = false;
            box?.focus();
        }
    }

    /** An emoji from the picker, where the cursor is. */
    function insertEmoji(glyph) {
        picking = false;
        const at = box.selectionStart ?? text.length;
        text = text.slice(0, at) + glyph + text.slice(at);
        queueMicrotask(() => {
            box.focus();
            box.setSelectionRange(at + glyph.length, at + glyph.length);
        });
    }

    /** Focus the box when asked (a thread opening, a conversation chosen). */
    export function focus() {
        box?.focus();
    }
</script>

<!-- svelte-ignore a11y_no_static_element_interactions -->
<div class="pf-conv-composer" class:pf-conv-dragging={dragging}
    ondragover={(e) => { e.preventDefault(); dragging = true; }} ondragleave={() => (dragging = false)} ondrop={onDrop}>
    {#if suggestions.length}
        <ul class="pf-conv-pop pf-conv-suggest" role="listbox">
            {#each suggestions as item, i (item.label)}
                <li role="option" aria-selected={i === chosen}>
                    <button type="button" class:pf-conv-active={i === chosen} onmousedown={(e) => { e.preventDefault(); take(item); }}>
                        {#if item.command}<span class="pf-conv-suggest-command">{item.label}</span> <span class="pf-conv-suggest-hint">{item.hint}</span>
                        {:else if item.emoji}{item.label}{:else if item.special}<span class="pf-conv-avatar pf-conv-avatar-sm pf-conv-avatar-group" aria-hidden="true">@</span> {item.label} <span class="pf-conv-suggest-hint">{item.hint}</span>
                        {:else}<Avatar id={item.id} name={item.name} small /> {item.label}{/if}
                    </button>
                </li>
            {/each}
        </ul>
    {/if}
    {#if files.length}
        <div class="pf-conv-chips">
            {#each files as file (file.key)}
                <span class="pf-conv-chip" class:pf-conv-busy={file.busy}>
                    📎 {file.name} <span class="pf-conv-file-size">{file.busy ? t('uploading…') : size(file.size)}</span>
                    <button type="button" aria-label={t('Remove')} onclick={() => (files = files.filter((f) => f.key !== file.key))}>×</button>
                </span>
            {/each}
        </div>
    {/if}
    <div class="pf-conv-composer-row">
        <textarea bind:this={box} bind:value={text} class="pf-conv-input" rows="1" {placeholder} {disabled}
            aria-label={placeholder} oninput={onInput} onkeydown={onKeydown} onpaste={onPaste} onclick={suggest}></textarea>
        <div class="pf-conv-composer-tools">
            <input bind:this={picker} type="file" multiple hidden onchange={(e) => { add(e.currentTarget.files); e.currentTarget.value = ''; }} />
            <button type="button" class="pf-conv-icon-btn" title={t('Attach a file')} aria-label={t('Attach a file')} onclick={() => picker.click()} {disabled}>📎</button>
            <button type="button" class="pf-conv-icon-btn" title={t('Insert an emoji')} aria-label={t('Insert an emoji')} onclick={() => (picking = !picking)} {disabled}>☺</button>
            <button type="button" class="pf-conv-send" aria-label={t('Send')} title={t('Send')} disabled={!ready || disabled} onclick={send}>➤</button>
        </div>
    </div>
    {#if picking}
        <div class="pf-conv-pop-anchor pf-conv-pop-up"><EmojiPicker onpick={insertEmoji} onclose={() => (picking = false)} /></div>
    {/if}
    <p class="pf-conv-hint">{t('Enter to send · Shift+Enter for a new line · @ to mention')}</p>
</div>
