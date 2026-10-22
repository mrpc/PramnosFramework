<script>
    /**
     * One conversation in a small window at the foot of the page: its people and whether they are
     * here, the conversation itself, and in its header the whole page (↗), minimise and close.
     * Minimised, it is its header, with what is unread.
     */
    import Avatar from './Avatar.svelte';
    import Conversation from './Conversation.svelte';
    import { t } from './t.js';

    let { api, conversation, me, props, online = {}, minimised = false, onminimise, onclose, onread, onerror } = $props();

    const other = $derived((conversation.members || []).find((id) => id !== me) ?? null);
    const title = $derived(conversation.title || (conversation.slug ? '#' + conversation.slug : ''));
</script>

<section class="pf-conv-window" class:pf-conv-window-min={minimised} aria-label={title}>
    <header class="pf-conv-window-head">
        <button type="button" class="pf-conv-window-title" onclick={onminimise} aria-expanded={!minimised}>
            {#if conversation.kind === 'dm' && other !== null}
                <Avatar id={other} name={title} online={!!online[other]} small />
            {:else if conversation.kind === 'bot'}
                <span class="pf-conv-avatar pf-conv-avatar-sm pf-conv-avatar-system" aria-hidden="true">⚙</span>
            {:else}
                <span class="pf-conv-avatar pf-conv-avatar-sm pf-conv-avatar-group" aria-hidden="true">{conversation.slug ? '#' : (conversation.members || []).length}</span>
            {/if}
            <span class="pf-conv-window-name">{title}</span>
            {#if minimised && conversation.unread > 0}<span class="pf-conv-badge">{conversation.unread}</span>{/if}
        </button>
        <a class="pf-conv-window-btn" href={props.page + '#/c/' + conversation.id} title={t('Open the whole page')} aria-label={t('Open the whole page')}>↗</a>
        <button type="button" class="pf-conv-window-btn" title={minimised ? t('Open') : t('Minimise')} aria-label={minimised ? t('Open') : t('Minimise')} onclick={onminimise}>{minimised ? '▴' : '–'}</button>
        <button type="button" class="pf-conv-window-btn" title={t('Close')} aria-label={t('Close')} onclick={onclose}>✕</button>
    </header>
    {#if !minimised}
        <div class="pf-conv-window-body">
            <Conversation {api} id={conversation.id} {me} emoji={props.emoji} maxFileMb={props.maxFileMb} {online} compact
                onread={onread} {onerror} />
        </div>
    {/if}
</section>
