<script>
    /**
     * One message, drawn the same way everywhere — the conversation, a thread, search results: its
     * writer, its text (the server's HTML, escaped there), a system card, its files, its reactions,
     * its thread, and the actions on hover. One component, so the places cannot drift apart.
     */
    import Avatar from './Avatar.svelte';
    import { assistant } from './extras.js';
    import EmojiPicker from './EmojiPicker.svelte';
    import Card from './Card.svelte';
    import { cardsFor, drawable, charts } from './extras.js';
    import { t, tn, clock } from './t.js';
    import { size, tokensToText, mentionsToTokens, initials, playerUrl } from './logic.js';

    let {
        message, continued = false, me, names = {}, avatars = {}, api, emoji = {}, moderator = false, inThread = false,
        online = {}, onreply = null, onerror = () => {}, saved = false, onsave = null,
    } = $props();

    let picking = $state(false);
    let playing = $state('');          // the link whose video plays in place
    let refCards = $state({});

    // The application's cards for what the message refers to — `#user:48211` — read in batches.
    $effect(() => {
        const keys = drawable(message.refs);
        if (message.deletedAt === null && keys.length) {
            cardsFor(api, keys).then((cards) => { refCards = cards; });
        }
    });
    let editing = $state(false);
    let draft = $state('');
    let picked = [];

    const mine = $derived(message.authorType === 'user' && message.authorId === me);
    const deleted = $derived(message.deletedAt !== null);
    // A system message is signed by what sent it: the card's `from`, its source (`jira` → Jira), or "System".
    const writer = $derived(message.authorType === 'user' ? (message.author || '#' + message.authorId)
        : message.card?.from === 'Ask' ? assistant().name : message.card?.from || (message.source ? message.source.charAt(0).toUpperCase() + message.source.slice(1) : t('System message')));

    // "(edited)" inside the text's last paragraph, so it ends the line rather than starting one.
    const body = $derived(!message.editedAt ? message.html
        : message.html.replace(/(<\/p>|<\/li><\/[uo]l>|<\/blockquote>|<\/pre>)?\s*$/, (end) => '<span class="pf-conv-msg-edited">' + escape(t('(edited)')) + '</span>' + end));

    /** Text as HTML text. */
    function escape(text) {
        return String(text).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    }

    /** A shortcode reaction as its emoji. */
    const glyph = (e) => (/^:[\w+-]+:$/.test(e) ? (emoji[e.slice(1, -1)] || e) : e);

    /** Who reacted so, by name. */
    const who = (people) => people.map((id) => (id === me ? t('You') : names[id] || '#' + id)).join(', ');

    /** React, or take it back where the reader already did. */
    async function toggle(e) {
        picking = false;
        const on = !(message.reactions || []).some((r) => r.emoji === e && r.people.includes(me));
        try {
            await api.post('react/' + message.id, { emoji: e, on });
        } catch (err) {
            onerror(err.message);
        }
    }

    /** Take a link's card off one's own message. */
    async function hideCard(url) {
        try {
            await api.post('unfurl/' + message.id, { url });
        } catch (err) {
            onerror(err.message);
        }
    }

    async function pin() {
        try {
            await api.post('pin/' + message.id, { on: !message.pinned });
        } catch (err) {
            onerror(err.message);
        }
    }

    async function remove() {
        if (!window.confirm(t('Delete this message? It cannot be brought back.'))) {
            return;
        }
        try {
            await api.post('delete/' + message.id);
        } catch (err) {
            onerror(err.message);
        }
    }

    function startEdit() {
        const back = tokensToText(message.body, names);
        draft = back.text;
        picked = back.picked;
        editing = true;
    }

    async function save() {
        const body = mentionsToTokens(draft.trim(), picked);
        if (body === '' || body === message.body) {
            editing = false;
            return;
        }
        try {
            await api.post('edit/' + message.id, { body });
            editing = false;
        } catch (err) {
            onerror(err.message);
        }
    }

    /** Enter saves, Escape leaves it as it was. */
    function editKeys(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            save();
        } else if (event.key === 'Escape') {
            editing = false;
        }
    }

    /** Focus the editor and put the cursor at its end. */
    function focusEnd(node) {
        node.focus();
        node.setSelectionRange(node.value.length, node.value.length);
    }
</script>

<article class="pf-conv-msg" class:pf-conv-msg-continued={continued} class:pf-conv-msg-pinned={message.pinned} data-message={message.id}>
    <div class="pf-conv-msg-gutter">
        {#if continued}
            <time class="pf-conv-msg-hover-time">{clock(message.at)}</time>
        {:else if message.authorType === 'user'}
            <Avatar id={message.authorId} name={writer} online={online[message.authorId] ?? null} src={avatars[message.authorId]} />
        {:else if message.card?.from === 'Ask' && assistant().html}
            <span class="pf-conv-avatar pf-conv-avatar-assistant" aria-hidden="true">{@html assistant().html}</span>
        {:else if message.card?.former}
            <span class="pf-conv-avatar pf-conv-avatar-former" title={t('Deleted profile')} aria-hidden="true">{initials(writer)}</span>
        {:else}
            <span class="pf-conv-avatar pf-conv-avatar-system" aria-hidden="true">⚙</span>
        {/if}
    </div>
    <div class="pf-conv-msg-main">
        {#if !continued}
            <header class="pf-conv-msg-head">
                <span class="pf-conv-msg-author" class:pf-conv-msg-former={message.card?.former} title={message.card?.former ? t('Deleted profile') : null}>{writer}</span>
                {#if message.card?.former}<span class="pf-conv-msg-badge">{t('Former member')}</span>{/if}
                <time class="pf-conv-msg-time">{clock(message.at)}</time>
                {#if message.pinned}<span class="pf-conv-msg-badge">📌 {t('Pinned message')}</span>{/if}
            </header>
        {/if}

        {#if message.rootPreview && !inThread}
            <button type="button" class="pf-conv-msg-root" onclick={() => onreply?.({ id: message.threadRootId })}>
                {t('Replied to a thread:')} <span>{message.rootPreview.author ? message.rootPreview.author + ': ' : ''}{message.rootPreview.text}</span>
            </button>
        {/if}
        {#if deleted}
            <p class="pf-conv-msg-deleted">{t('This message was deleted.')}</p>
        {:else if editing}
            <div class="pf-conv-edit">
                <textarea class="pf-conv-input" rows="2" bind:value={draft} onkeydown={editKeys} use:focusEnd></textarea>
                <div class="pf-conv-edit-actions">
                    <span class="pf-conv-hint">{t('Enter to save · Esc to cancel')}</span>
                    <button type="button" class="pf-conv-btn" onclick={() => (editing = false)}>{t('Cancel')}</button>
                    <button type="button" class="pf-conv-btn pf-conv-btn-primary" onclick={save}>{t('Save')}</button>
                </div>
            </div>
        {:else}
            {#if message.html}
                <!-- The server's HTML: everything the writer wrote is escaped there first (Conversations\Markdown). -->
                <div class="pf-conv-msg-body" use:charts={body}>{@html body}</div>
            {/if}
            {#if message.card && (message.card.title || message.card.text || message.card.image || (message.card.fields && Object.keys(message.card.fields).length))}
                <Card card={message.card} />
            {/if}
            {#each message.unfurls || [] as card (card.url)}
                <div class="pf-conv-unfurl">
                    {#if mine}<button type="button" class="pf-conv-unfurl-hide" aria-label={t('Remove preview')} title={t('Remove preview')} onclick={() => hideCard(card.url)}>×</button>{/if}
                    <div class="pf-conv-unfurl-site">{#if card.icon}<img src={card.icon} alt="" width="16" height="16" />{/if}{card.site}{#if card.author} · {card.author}{/if}</div>
                    {#if card.title}<a class="pf-conv-unfurl-title" href={card.url} target="_blank" rel="noopener noreferrer">{card.title}</a>{/if}
                    {#if card.description}<p class="pf-conv-unfurl-text">{card.description}</p>{/if}
                    {#if card.embed && playing === card.url && playerUrl(card.embed)}
                        <iframe class="pf-conv-unfurl-player" style="aspect-ratio: {card.embed.aspect}" src={playerUrl(card.embed)} title={card.title}
                            allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
                    {:else if card.image && card.embed && playerUrl(card.embed)}
                        <button type="button" class="pf-conv-unfurl-media pf-conv-unfurl-play" aria-label={t('Play')} onclick={() => (playing = card.url)}>
                            <img src={card.image.url} alt="" width={card.image.width} height={card.image.height} loading="lazy" /><span aria-hidden="true">▶</span>
                        </button>
                    {:else if card.image}
                        <a class="pf-conv-unfurl-media" href={card.url} target="_blank" rel="noopener noreferrer">
                            <img src={card.image.url} alt="" width={card.image.width} height={card.image.height} loading="lazy" />
                        </a>
                    {/if}
                </div>
            {/each}
            {#each Object.entries(refCards) as [key, card] (key)}
                {#if card}<Card {card} />{/if}
            {/each}
            {#if message.files?.length}
                <div class="pf-conv-files">
                    {#each message.files as file (file.id)}
                        {#if file.removedAt}
                            <span class="pf-conv-file pf-conv-file-removed">📎 {file.name} · {t('removed on %s', new Date(file.removedAt * 1000).toLocaleDateString())}</span>
                        {:else if file.thumbnail || String(file.mime || '').startsWith('image/')}
                            <!-- An image small enough has no thumbnail: it is shown itself. -->
                            <a class="pf-conv-thumb" href={api.fileUrl(file.id)} target="_blank" rel="noopener">
                                <img src={api.fileUrl(file.id, file.thumbnail)} alt={file.name} loading="lazy" />
                            </a>
                        {:else}
                            <a class="pf-conv-file" href={api.fileUrl(file.id)} target="_blank" rel="noopener">
                                <span class="pf-conv-file-icon" aria-hidden="true">📄</span>
                                <span class="pf-conv-file-name">{file.name}</span>
                                <span class="pf-conv-file-size">{size(file.size)}</span>
                            </a>
                        {/if}
                    {/each}
                </div>
            {/if}
        {/if}

        {#if !deleted && message.reactions?.length}
            <div class="pf-conv-reactions">
                {#each message.reactions as reaction (reaction.emoji)}
                    <button type="button" class="pf-conv-reaction" class:pf-conv-reaction-mine={reaction.people.includes(me)}
                        title={who(reaction.people)} onclick={() => toggle(reaction.emoji)}>
                        <span>{glyph(reaction.emoji)}</span><span class="pf-conv-reaction-count">{reaction.count}</span>
                    </button>
                {/each}
                <button type="button" class="pf-conv-reaction pf-conv-reaction-add" aria-label={t('Add a reaction')} onclick={() => (picking = !picking)}>☺+</button>
            </div>
        {/if}

        {#if !inThread && message.replyCount > 0}
            <button type="button" class="pf-conv-replies" onclick={() => onreply?.(message)}>
                <span class="pf-conv-replies-count">{tn(message.replyCount, '%s reply', '%s replies')}</span>
                {#if message.lastReplyAt}<span class="pf-conv-replies-last">{t('last at %s', clock(message.lastReplyAt))}</span>{/if}
            </button>
        {/if}
    </div>

    {#if !deleted && !editing}
        <div class="pf-conv-msg-actions" role="toolbar" aria-label={t('Message actions')}>
            <button type="button" title={t('Add a reaction')} aria-label={t('Add a reaction')} onclick={() => (picking = !picking)}>☺</button>
            {#if !inThread && message.threadRootId === null && onreply}
                <button type="button" title={t('Reply in thread')} aria-label={t('Reply in thread')} onclick={() => onreply(message)}>💬</button>
            {/if}
            {#if onsave}
                <button type="button" class:pf-conv-active={saved} aria-pressed={saved} title={saved ? t('Remove from saved') : t('Save for later')}
                    aria-label={saved ? t('Remove from saved') : t('Save for later')} onclick={() => onsave(message, !saved)}>🔖</button>
            {/if}
            <button type="button" title={message.pinned ? t('Unpin message') : t('Pin message')} aria-label={message.pinned ? t('Unpin message') : t('Pin message')} onclick={pin}>📌</button>
            {#if mine && message.body !== ''}
                <button type="button" title={t('Edit message')} aria-label={t('Edit message')} onclick={startEdit}>✎</button>
            {/if}
            {#if mine || moderator}
                <button type="button" class="pf-conv-danger" title={t('Delete message')} aria-label={t('Delete message')} onclick={remove}>🗑</button>
            {/if}
        </div>
    {/if}
    {#if picking}
        <div class="pf-conv-pop-anchor"><EmojiPicker onpick={toggle} onclose={() => (picking = false)} /></div>
    {/if}
</article>
