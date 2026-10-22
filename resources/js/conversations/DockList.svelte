<script>
    /**
     * The dock's list: the reader's conversations, newest first, what is unread in bold with its
     * count; a filter by name; a new conversation or a channel to join; files and space for whoever
     * manages them; the application's links; and whether the faces show above the launcher.
     */
    import Avatar from './Avatar.svelte';
    import NewConversation from './NewConversation.svelte';
    import { t } from './t.js';
    import { fold } from './logic.js';

    let { api, me, conversations = [], browse = [], online = {}, links = [], page = '', filesUrl = null, noFaces = false, onopen, onfaces, oncreated, onerror } = $props();

    let query = $state('');
    let dialog = $state(null);          // 'new' | 'browse' | null

    const shown = $derived(conversations.filter((c) => !c.archivedAt && fold(c.title || c.slug || '').includes(fold(query.trim().replace(/^[#@]/, ''))))
        .sort((a, b) => (b.lastMessageAt || 0) - (a.lastMessageAt || 0)));

    /** The other person of a direct conversation, for their face. */
    const other = (c) => (c.members || []).find((id) => id !== me) ?? me;
</script>

<div class="pf-conv-dock-list">
    <header class="pf-conv-dock-list-head">
        <h2>{t('Conversations')}</h2>
        <button type="button" class="pf-conv-icon-btn" title={t('New conversation')} aria-label={t('New conversation')} onclick={() => (dialog = 'new')}>✎</button>
    </header>
    <input class="pf-conv-input" type="search" bind:value={query} placeholder={t('Find a conversation')} aria-label={t('Find a conversation')} />
    <ul class="pf-conv-list pf-conv-dock-items">
        {#each shown as c (c.id)}
            <li>
                <button type="button" class="pf-conv-item" class:pf-conv-unread={c.unread > 0 && !c.muted} onclick={() => onopen(c.id)}>
                    {#if c.kind === 'dm'}
                        <Avatar id={other(c)} name={c.title} online={!!online[other(c)]} small />
                    {:else}
                        <span class="pf-conv-avatar pf-conv-avatar-sm pf-conv-avatar-group" aria-hidden="true">{c.kind === 'private' ? '🔒' : c.slug ? '#' : c.kind === 'bot' ? '⚙' : (c.members || []).length}</span>
                    {/if}
                    <span class="pf-conv-item-name">{c.title}</span>
                    {#if c.muted}<span class="pf-conv-muted-text" title={t('Muted')}>🔕</span>{/if}
                    {#if c.unread > 0 && !c.muted}<span class="pf-conv-badge">{c.unread}</span>{/if}
                </button>
            </li>
        {:else}
            <li class="pf-conv-muted-text">{query ? t('Nothing found.') : t('Start a conversation, or join a channel.')}</li>
        {/each}
    </ul>
    <footer class="pf-conv-dock-list-foot">
        <button type="button" class="pf-conv-side-link" onclick={() => (dialog = 'browse')}>+ {t('Browse channels')}</button>
        {#if filesUrl}<a class="pf-conv-side-link" href={filesUrl}>🗂 {t('Files and space')}</a>{/if}
        {#each links as link (link.url)}
            <a class="pf-conv-side-link" href={link.url}>{#if link.icon}<span aria-hidden="true">{link.icon}</span> {/if}{link.label}</a>
        {/each}
        {#if page}<a class="pf-conv-side-link" href={page}>↗ {t('Open the whole page')}</a>{/if}
        <label class="pf-conv-side-link"><input type="checkbox" checked={!noFaces} onchange={(e) => onfaces(!e.currentTarget.checked)} /> {t('Faces above the button')}</label>
    </footer>
</div>
{#if dialog}
    <NewConversation {api} {me} mode={dialog} {browse} ondone={(id) => { dialog = null; oncreated(id); }} onclose={() => (dialog = null)} {onerror} />
{/if}
