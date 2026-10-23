<script>
    /**
     * The conversations over every page of the administration: the faces of the recent direct and
     * group conversations stacked above the launcher, each with what is unread; the launcher opens
     * the list; a conversation opens in a small window at the foot of the page. A new direct message
     * opens its window by itself. The windows stay as they were from page to page (this browser's
     * storage, per reader). A face can be put away — until a newer message there — and the faces
     * turned off altogether.
     */
    import Avatar from './Avatar.svelte';
    import { assistant, isAnalyst } from './extras.js';
    import DockList from './DockList.svelte';
    import DockWindow from './DockWindow.svelte';
    import { t } from './t.js';
    import { dockLayout, sidebarPrefs, keepSidebarPrefs, withFormer } from './logic.js';

    let { api, props } = $props();

    // svelte-ignore state_referenced_locally
    const me = props.me.id;
    // svelte-ignore state_referenced_locally
    const KEY = 'pf-conv-dock:' + me;
    let conversations = $state([]);
    let browse = $state([]);
    let avatars = $state({});                  // id → the face the application gives
    let windows = $state(restore());           // [{ id, min }]
    let listOpen = $state(false);
    let online = $state({});
    let error = $state('');
    let width = $state(typeof window !== 'undefined' ? window.innerWidth : 1280);
    let known = null;                          // unread per conversation at the last reading
    let noFaces = $state(sidebarPrefs(me).noFaces);
    // svelte-ignore state_referenced_locally
    const FACES = 'pf-conv-faces:' + me;
    let hidden = $state(kept(FACES, {}));      // { id: the newest message when it was put away }
    let armed = $state(null);                  // the face a long press showed the × of

    const byId = $derived(Object.fromEntries(conversations.map((c) => [c.id, c])));
    const layout = $derived(dockLayout(conversations, windows, width, hidden));
    const heads = $derived(noFaces ? [] : layout.heads);
    const unread = $derived(conversations.reduce((n, c) => n + (c.muted ? 0 : c.unread), 0));
    const urgent = $derived(conversations.some((c) => c.mentions > 0 || (c.members !== null && c.unread > 0 && !c.muted)));

    /** The windows this reader had open, from this browser. */
    function restore() {
        try {
            const kept = JSON.parse(window.localStorage.getItem('pf-conv-dock:' + props.me.id) || '[]');

            return Array.isArray(kept) ? kept.filter((w) => Number.isInteger(w.id)).slice(0, 3) : [];
        } catch {
            return [];
        }
    }

    /** Something this browser kept for this reader, or `empty`. */
    function kept(key, empty) {
        try {
            return JSON.parse(window.localStorage.getItem(key) || 'null') ?? empty;
        } catch {
            return empty;
        }
    }

    /** Put a face away, until a newer message in its conversation. */
    function putAway(c) {
        armed = null;
        hidden = { ...hidden, [c.id]: c.lastMessageId || 0 };
        try {
            window.localStorage.setItem(FACES, JSON.stringify(hidden));
        } catch {
            /* not kept: back on the next page */
        }
    }

    /** Keep the windows as they are, for the next page. */
    $effect(() => {
        const keep = JSON.stringify(windows);
        try {
            window.localStorage.setItem(KEY, keep);
        } catch {
            /* private window, or storage refused: they simply do not follow */
        }
    });

    /** The list read again; a direct message that arrived since opens its window. */
    async function refresh() {
        try {
            const answer = await api.get('conversations');
            conversations = answer.conversations;
            browse = answer.browse || [];
            avatars = answer.avatars || {};
            // A person writing opens a window; a bot's notices wait in the list.
            const arrived = known === null ? [] : answer.conversations
                .filter((c) => c.members !== null && c.kind !== 'bot' && !c.muted && c.unread > (known[c.id] ?? 0));
            known = Object.fromEntries(answer.conversations.map((c) => [c.id, c.unread]));
            arrived.forEach((c) => open(c.id, false));
            // A window whose conversation the reader left is closed.
            windows = windows.filter((w) => answer.conversations.some((c) => c.id === w.id));
        } catch (err) {
            fail(err.message);
        }
    }

    /** Open a conversation's window — or bring it up; `focus` also expands it. */
    function open(id, focus = true) {
        listOpen = false;
        const there = windows.find((w) => w.id === id);
        if (there) {
            windows = windows.map((w) => (w.id === id && focus ? { ...w, min: false } : w));

            return;
        }
        windows = [...windows, { id, min: false }];
    }

    function close(id) {
        windows = windows.filter((w) => w.id !== id);
    }

    function minimise(id) {
        windows = windows.map((w) => (w.id === id ? { ...w, min: !w.min } : w));
    }

    function read(id) {
        conversations = conversations.map((c) => (c.id === id ? { ...c, unread: 0, mentions: 0 } : c));
        if (known) {
            known[id] = 0;
        }
    }

    function fail(message) {
        error = message;
        setTimeout(() => { if (error === message) { error = ''; } }, 6000);
    }

    /** The faces above the launcher shown or not, kept with the reader's other choices. */
    function setFaces(off) {
        noFaces = off;
        keepSidebarPrefs(me, { ...sidebarPrefs(me), noFaces: off });
    }

    $effect(() => {
        refresh();
        const resize = () => { width = window.innerWidth; };
        const keys = (event) => {
            if (event.key === 'Escape' && listOpen && !document.querySelector('.pf-conv-dialog[open]')) {
                listOpen = false;
            }
        };
        window.addEventListener('resize', resize);
        document.addEventListener('keydown', keys);
        document.addEventListener('pf:notifications:refresh', refresh);
        const cleanup = [() => window.removeEventListener('resize', resize), () => document.removeEventListener('keydown', keys),
            () => document.removeEventListener('pf:notifications:refresh', refresh)];
        const realtime = window.pfRealtime;
        if (realtime) {
            let timer = null;
            const soon = () => {
                clearTimeout(timer);
                timer = setTimeout(refresh, 500);
            };
            const here = ({ members }) => { online = Object.fromEntries(Object.keys(members || {}).map((id) => [id, true])); };
            // A message somewhere, or what the reader has read moved — on another page, or marked unread.
            const mine = realtime.channel(props.userChannel).listen('conversation.activity', soon).listen('conversation.read', soon)
                .listen('conversation.changed', soon);
            const room = realtime.channel('presence-chat').listen('pusher:subscription_succeeded', here)
                .listen('pusher:member_added', here).listen('pusher:member_removed', here);
            cleanup.push(() => {
                mine.stopListening?.('conversation.activity', soon);
                mine.stopListening?.('conversation.read', soon);
                mine.stopListening?.('conversation.changed', soon);
                ['pusher:subscription_succeeded', 'pusher:member_added', 'pusher:member_removed'].forEach((e) => room.stopListening?.(e, here));
            });
        } else {
            const timer = setInterval(() => { if (!document.hidden) { refresh(); } }, 30000);
            cleanup.push(() => clearInterval(timer));
        }

        return () => cleanup.forEach((fn) => fn());
    });
</script>

<div class="pf-conv-dock-windows">
    {#each layout.windows as w (w.id)}
        {#if byId[w.id]}
            <DockWindow {api} conversation={byId[w.id]} {me} {props} {online} {avatars} minimised={w.min}
                onminimise={() => minimise(w.id)} onclose={() => close(w.id)} onread={read} onerror={fail} />
        {/if}
    {/each}
</div>

<div class="pf-conv-dock-heads">
    {#each heads as c (c.id)}
        {@const other = (c.members || []).find((id) => id !== me) ?? me}
        <span class="pf-conv-dock-face" class:pf-conv-dock-face-armed={armed === c.id}>
        <button type="button" class="pf-conv-dock-head" title={c.title} aria-label={c.title + (c.unread ? ' (' + c.unread + ')' : '')} onclick={() => open(c.id)}
            oncontextmenu={(e) => { e.preventDefault(); armed = armed === c.id ? null : c.id; }}>
            {#if c.kind === 'dm'}
                <Avatar id={other} name={c.title} former={withFormer(c, me)} online={withFormer(c, me) ? null : !!online[other]} src={c.avatar || avatars[other]} html={isAnalyst(c) ? assistant().html : null} />
            {:else if c.kind === 'bot'}
                <span class="pf-conv-avatar pf-conv-avatar-system" aria-hidden="true">⚙</span>
            {:else}
                <span class="pf-conv-avatar pf-conv-avatar-group" aria-hidden="true">{(c.members || []).length}</span>
            {/if}
            {#if c.unread > 0 && !c.muted}<span class="pf-conv-dock-count pf-conv-dock-mention">{c.unread}</span>{/if}
        </button>
        <button type="button" class="pf-conv-dock-face-close" title={t('Put away')} aria-label={t('Put away') + ': ' + c.title} onclick={() => putAway(c)}>×</button>
        </span>
    {/each}
    <button type="button" class="pf-conv-dock-button" aria-expanded={listOpen} aria-label={t('Conversations') + (unread ? ' (' + unread + ')' : '')}
        title={t('Conversations')} onclick={() => (listOpen = !listOpen)}>
        <span aria-hidden="true">💬</span>
        {#if unread > 0}<span class="pf-conv-dock-count" class:pf-conv-dock-mention={urgent}>{unread > 99 ? '99+' : unread}</span>{/if}
    </button>
</div>

{#if listOpen}
    <div class="pf-conv-dock-panel" role="dialog" aria-label={t('Conversations')}>
        <DockList {api} {me} {conversations} {browse} {online} {avatars} links={props.links || []} page={props.page || ''} filesUrl={props.files} {noFaces}
            onopen={(id) => open(id)} onfaces={setFaces} oncreated={async (id) => { await refresh(); open(id); }} onerror={fail} />
    </div>
{/if}
{#if error}
    <div class="pf-conv-toast pf-conv-dock-toast" role="alert">{error}</div>
{/if}
