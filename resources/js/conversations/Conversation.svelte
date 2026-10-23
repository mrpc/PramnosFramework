<script>
    /**
     * One conversation, in a dock window: its messages with a line for each day and where the unread
     * start, older ones as the reader scrolls up, the composer, and a thread beside it. Live through
     * `private-conversation.{id}` where the page has a realtime connection; read again every few
     * seconds where it has none, and after a reconnect.
     */
    import { tick, untrack } from 'svelte';
    import MessageItem from './MessageItem.svelte';
    import Card from './Card.svelte';
    import Composer from './Composer.svelte';
    import { t, tn, dayLabel, longDate } from './t.js';
    import { upsert, replace, timeline, typingNow, TYPING_MS, lastSignIn } from './logic.js';
    import { assistant, analystState } from './extras.js';

    // `openThread`: a thread to open with it — from the sidebar's Threads or Activity.
    let { api, id, me, emoji = {}, maxFileMb = 25, online = {}, resync = 0, onread = () => {}, onerror = () => {}, compact = false,
        openThread: threadToOpen = null } = $props();

    let conversation = $state(null);
    let members = $state([]);
    let messages = $state([]);
    let pinned = $state([]);
    let saved = $state([]);
    let openedAt = $state(0);         // what the reader had read when it opened: where "New" goes
    let loading = $state(true);
    let more = $state(true);
    let list = $state(null);
    let composer = $state(null);
    let thread = $state(null);        // { root, replies }
    let typing = $state({});
    let now = $state(Date.now());
    let notes = $state([]);           // the answers of the reader's commands: theirs alone, this page only
    let readUpTo = 0;
    let stick = true;
    let unseen = $state(0);           // new messages that arrived while the reader was further up
    let inner = $state(null);

    const names = $derived(Object.fromEntries(members.map((m) => [m.id, m.name])));
    const avatars = $derived(Object.fromEntries(members.filter((m) => m.avatar).map((m) => [m.id, m.avatar])));
    const member = $derived(conversation?.member ?? null);
    const items = $derived(timeline(messages, openedAt, me));
    const typers = $derived(Object.values(typingNow(typing, now)).filter((w) => w.thread === null && !w.thinking).map((w) => w.name));
    // The analyst answering in the conversation itself; in a thread, it is that thread's "… is typing".
    const thinking = $derived(!!typingNow(typing, now).ask && (typingNow(typing, now).ask.thread ?? null) === null);
    // How long "thinking" shows without an answer: as long as the analyst takes at most.
    const ASK_MS = 180000;
    const threadTypers = $derived(thread ? Object.values(typingNow(typing, now)).filter((w) => w.thread === thread.root.id).map((w) => w.name) : []);
    const title = $derived(!conversation ? '' : conversation.slug ? conversation.slug : conversation.kind === 'bot' ? (conversation.displayName || conversation.name)
        : members.filter((m) => m.id !== me).map((m) => m.name).join(', ') || names[me] || '');
    // A deleted reply leaves no trace in its thread.
    const replies = $derived(thread ? thread.replies.filter((r) => r.deletedAt === null) : []);
    // An owner deletes what others wrote.
    const moderator = $derived(member?.role === 'owner');
    // The other person of a direct conversation, when they are inactive: a note above the composer.
    const asleep = $derived(conversation?.kind === 'dm' ? members.find((m) => m.id !== me && m.inactive != null) ?? null : null);

    /** Read the conversation: its newest messages, who is in it, what is pinned. */
    async function load(conversationId) {
        loading = true;
        thread = null;
        typing = {};
        try {
            const answer = await api.get('messages/' + conversationId);
            if (conversationId !== id) {
                return;
            }
            conversation = answer.conversation;
            members = answer.members;
            messages = answer.messages;
            pinned = answer.pinned;
            saved = answer.saved ?? [];
            openedAt = answer.conversation.member?.lastreadid ?? 0;
            readUpTo = openedAt;
            more = answer.messages.length >= 50;
            unseen = 0;
            await tick();
            const first = list?.querySelector('.pf-conv-new');
            // Where the unread start, when there are some; else the newest — and kept there while
            // what is below grows (cards, pictures).
            stick = !first;
            if (first) {
                first.scrollIntoView({ block: 'center' });
            } else {
                bottom();
            }
            read();
            if (threadToOpen) {
                openThread({ id: threadToOpen });
            } else {
                composer?.focus();
            }
        } catch (err) {
            onerror(err.message);
        } finally {
            loading = false;
        }
    }

    /** What came after the newest message the page has: after a reconnect, or polling. */
    async function catchUp() {
        const last = messages.length ? Math.max(...messages.map((m) => m.id), ...(thread ? thread.replies.map((m) => m.id) : [])) : 0;
        try {
            const answer = await api.get('messages/' + id, { after: last });
            answer.messages.forEach(arrive);
            members = answer.members;
        } catch {
            /* offline: the next try */
        }
    }

    /** Older messages as the reader reaches the top. */
    async function older() {
        if (!more || loading || !messages.length) {
            return;
        }
        loading = true;
        const height = list.scrollHeight;
        try {
            const answer = await api.get('messages/' + id, { before: messages[0].id });
            messages = [...answer.messages, ...messages];
            more = answer.messages.length >= 50;
            await tick();
            list.scrollTop += list.scrollHeight - height;
        } catch (err) {
            onerror(err.message);
        } finally {
            loading = false;
        }
    }

    function bottom() {
        if (list) {
            list.scrollTop = list.scrollHeight;
        }
    }

    function onScroll() {
        stick = list.scrollHeight - list.scrollTop - list.clientHeight < 80;
        if (stick) {
            unseen = 0;
        }
        if (list.scrollTop < 120) {
            older();
        }
        if (stick) {
            read();
        }
    }

    /** Tell the server what the reader has seen: the newest message, while the page is in view. */
    function read() {
        const newest = messages.length ? messages[messages.length - 1].id : 0;
        if (!member || newest <= readUpTo || document.hidden) {
            return;
        }
        readUpTo = newest;
        api.post('read/' + id, { message: newest }).then(() => onread(id)).catch(() => {});
    }

    /** A message from the server, new or changed, put where it belongs. */
    async function arrive(message) {
        if (message.conversationId !== id) {
            return;
        }
        if (message.threadRootId !== null) {
            if (thread && thread.root.id === message.threadRootId) {
                thread = { ...thread, replies: upsert(thread.replies, message) };
            }
            // Counted once, though it arrives twice — from the composer and from the channel.
            messages = messages.map((m) => (m.id === message.threadRootId && !m._counted?.includes(message.id)
                ? { ...m, replyCount: m.replyCount + 1, lastReplyAt: message.at, _counted: [...(m._counted || []), message.id] } : m));
        }
        // A reply also sent to the conversation is in both: its thread, and the conversation's own list.
        if (message.threadRootId === null || message.broadcast) {
            const isNew = !messages.some((m) => m.id === message.id);
            messages = upsert(messages, message);
            if (thread && thread.root.id === message.id) {
                thread = { ...thread, root: message };
            }
            if (isNew && message.authorType === 'user') {
                const { [message.authorId]: _gone, ...rest } = typing;
                typing = rest;
            }
            // In the conversation with Ask, a question sent is a question being answered, until the answer comes.
            if (isNew && conversation?.kind === 'bot' && conversation.name === 'Ask') {
                const { ask: _done, ...rest } = typing;
                typing = message.authorId === me ? { ...rest, ask: { name: assistant().name, thread: null, thinking: true, until: Date.now() + ASK_MS } } : rest;
                // The analyst's picture: thinking at the question, talking as its answer arrives.
                analystState(message.authorId === me ? 'thinking' : 'talking');
                if (message.authorId !== me) {
                    setTimeout(() => analystState('success'), 1200);
                }
            } else if (isNew && message.card?.from === 'Ask') {
                // An answer in a thread, to an @-call.
                analystState('talking');
                setTimeout(() => analystState('success'), 1200);
            }
            if (isNew && stick) {
                await tick();
                bottom();
                read();
            } else if (isNew && message.authorId !== me) {
                unseen++;
            }
        }
    }

    /** A message changed: edited, deleted, reacted to, pinned. */
    function changed(message, event) {
        messages = replace(messages, message);
        if (thread) {
            thread = { root: thread.root.id === message.id ? message : thread.root, replies: replace(thread.replies, message) };
        }
        if (event === 'message.pinned') {
            pinned = message.pinned ? [message.id, ...pinned.filter((p) => p !== message.id)] : pinned.filter((p) => p !== message.id);
        }
    }

    /** Keep a message for later, or let it go: the reader's own mark. */
    async function save(message, on) {
        try {
            await api.post('save/' + message.id, { on: on ? 1 : 0 });
            saved = on ? [message.id, ...saved] : saved.filter((s) => s !== message.id);
        } catch (err) {
            onerror(err.message);
        }
    }

    async function openThread(root) {
        try {
            const answer = await api.get('messages/' + id, { thread: root.id });
            thread = { root: answer.messages[0] ?? root, replies: answer.messages.slice(1) };
            saved = [...new Set([...saved, ...(answer.saved ?? [])])];
        } catch (err) {
            onerror(err.message);
        }
    }

    /** A command's answer, shown to the reader alone at the foot of the conversation. */
    async function note(reply) {
        if (!reply || (!reply.text && !reply.card)) {
            return;
        }
        notes = [...notes, { key: Date.now() + Math.random(), ...reply }];
        await tick();
        bottom();
    }

    /** Tell the others the reader is typing, here or in the open thread. */
    function whisper(inThread) {
        window.pfRealtime?.channel('private-conversation.' + id).whisper('typing', { id: me, name: names[me] || '', thread: inThread });
    }

    async function join() {
        try {
            await api.post('join/' + id);
            await load(id);
            onread(id);
        } catch (err) {
            onerror(err.message);
        }
    }

    // Open the conversation, and hear it, whenever it changes.
    $effect(() => {
        const conversationId = id;
        untrack(() => load(conversationId));
        const channel = window.pfRealtime?.channel('private-conversation.' + conversationId);
        if (!channel) {
            const timer = setInterval(() => untrack(catchUp), 8000);

            return () => clearInterval(timer);
        }
        const handlers = {
            'message.posted': (m) => arrive(m),
            'message.edited': (m) => changed(m, 'message.edited'),
            'message.deleted': (m) => changed(m, 'message.deleted'),
            'message.reacted': (m) => changed(m, 'message.reacted'),
            'message.pinned': (m) => changed(m, 'message.pinned'),
            // The analyst on an answer — told by the server, which a worker can do and a whisper cannot.
            'assistant.thinking': (p) => {
                const until = p && p.until ? p.until * 1000 : Date.now() + ASK_MS;
                typing = { ...typing, ask: { name: (p && p.name) || assistant().name, thread: p?.thread ?? null, thinking: true, until } };
                analystState('thinking');
            },
            'assistant.done': () => {
                const { ask: _done, ...rest } = typing;
                typing = rest;
            },
            'client-typing': (w) => {
                if (w && w.id !== me) {
                    typing = { ...typing, [w.id]: { name: w.name, thread: w.thread ?? null, until: Date.now() + TYPING_MS } };
                }
            },
        };
        Object.entries(handlers).forEach(([event, fn]) => channel.listen(event, fn));
        // A channel the server never confirms — its auth refused, the socket down — hears nothing:
        // until it is confirmed, the conversation is caught up as it is without realtime.
        const name = 'private-conversation.' + conversationId;
        const watchdog = setInterval(() => {
            const status = window.pfRealtime?.status?.();
            if (!status || !(status.subscribed || []).includes(name)) {
                untrack(catchUp);
            }
        }, 8000);

        return () => {
            clearInterval(watchdog);
            Object.entries(handlers).forEach(([event, fn]) => channel.stopListening?.(event, fn));
        };
    });

    // Whatever grows below — a card read, a picture loaded — keeps the newest in view while the
    // reader is at the foot.
    $effect(() => {
        if (!inner || typeof ResizeObserver === 'undefined') {
            return;
        }
        const watch = new ResizeObserver(() => { if (stick) { bottom(); } });
        watch.observe(inner);

        return () => watch.disconnect();
    });

    // A reconnect: what was missed.
    $effect(() => {
        if (resync > 0) {
            untrack(catchUp);
        }
    });

    // "is typing" fades without being said again; reading resumes when the tab comes back.
    $effect(() => {
        const timer = setInterval(() => (now = Date.now()), 1500);
        const visible = () => { if (!document.hidden && stick) { read(); } };
        document.addEventListener('visibilitychange', visible);

        return () => {
            clearInterval(timer);
            document.removeEventListener('visibilitychange', visible);
        };
    });
</script>

<section class="pf-conv-main" class:pf-conv-compact={compact} aria-label={title}>

    <div class="pf-conv-body">
        <div class="pf-conv-scroll" bind:this={list} onscroll={onScroll}>
            <div class="pf-conv-scroll-inner" bind:this={inner}>
            {#if !more && messages.length && conversation}
                <!-- Only at the very top, once the oldest message is in: what this conversation is. -->
                <div class="pf-conv-start">
                    <p class="pf-conv-start-title">{conversation.slug ? (conversation.kind === 'private' ? '🔒 ' : '#') + conversation.slug : title}</p>
                    {#if conversation.topic}<p class="pf-conv-start-topic">{conversation.topic}</p>{/if}
                    <p class="pf-conv-muted-text">{conversation.createdBy && names[conversation.createdBy]
                        ? t('Started by %s on %s.', names[conversation.createdBy], longDate(conversation.createdAt))
                        : t('Started on %s.', longDate(conversation.createdAt))}</p>
                </div>
            {/if}
            {#if !loading && !messages.length}
                <div class="pf-conv-empty">
                    <p class="pf-conv-start-title">{t('Nothing here yet.')}</p>
                    <p class="pf-conv-muted-text">{t('Write the first message below.')}</p>
                </div>
            {/if}
            {#each items as item (item.key)}
                {#if item.type === 'day'}
                    <div class="pf-conv-day" role="separator"><span>{dayLabel(item.at)}</span></div>
                {:else if item.type === 'new'}
                    <div class="pf-conv-new" role="separator"><span>{t('New messages')}</span></div>
                {:else}
                    <MessageItem {avatars} message={item.message} continued={item.continued} {me} {names} {api} {emoji} {online} {moderator}
                        onreply={openThread} {onerror} saved={saved.includes(item.message.id)} onsave={save} />
                {/if}
            {/each}
            {#each notes as n (n.key)}
                <div class="pf-conv-note" role="status">
                    <div class="pf-conv-note-head">
                        <span>{t('Only you can see this.')}</span>
                        <button type="button" class="pf-conv-icon-btn" aria-label={t('Close')} onclick={() => (notes = notes.filter((x) => x.key !== n.key))}>×</button>
                    </div>
                    {#if n.text}<p class="pf-conv-note-text">{n.text}</p>{/if}
                    {#if n.card}<Card card={n.card} />{/if}
                </div>
            {/each}
            </div>
        </div>
        {#if unseen > 0}
            <button type="button" class="pf-conv-jump" onclick={() => { stick = true; unseen = 0; bottom(); read(); }}>
                ↓ {tn(unseen, '%s new message', '%s new messages')}
            </button>
        {/if}
        <p class="pf-conv-typing" aria-live="polite">{#if thinking}{t('%s is thinking…', assistant().name)}{:else if typers.length}{typers.length === 1 ? t('%s is typing…', typers[0]) : t('Several people are typing…')}{/if}</p>
        {#if member && asleep}
            <p class="pf-conv-inactive-note" role="note">{lastSignIn(asleep.inactive) ? t('Has not signed in since %s — they will probably not see this.', lastSignIn(asleep.inactive))
                : t('Has never signed in — they will probably not see this.')}</p>
        {/if}
        {#if member}
            <Composer bind:this={composer} {api} conversationId={id} members={members} {me} {emoji} {maxFileMb}
                placeholder={conversation?.slug ? t('Message #%s', conversation.slug) : t('Message %s', title)}
                disabled={!!conversation?.archivedAt} typing={whisper} onsent={arrive} onreply={note} {onerror} />
        {:else if conversation}
            <div class="pf-conv-join">
                <p>{t('You are reading #%s.', conversation.slug)}</p>
                <button type="button" class="pf-conv-btn pf-conv-btn-primary" onclick={join}>{t('Join to write')}</button>
            </div>
        {/if}
    </div>

    {#if thread}
        <aside class="pf-conv-thread" aria-label={t('Thread')}>
            <header class="pf-conv-thread-head">
                <h2>{t('Thread')}</h2>
                <button type="button" class="pf-conv-icon-btn" aria-label={t('Close')} onclick={() => (thread = null)}>×</button>
            </header>
            <div class="pf-conv-scroll">
                <MessageItem {avatars} message={thread.root} {me} {names} {api} {emoji} {online} {moderator} inThread {onerror}
                    saved={saved.includes(thread.root.id)} onsave={save} />
                <div class="pf-conv-day" role="separator"><span>{tn(replies.length, '%s reply', '%s replies')}</span></div>
                {#each replies as reply, i (reply.id)}
                    <MessageItem {avatars} message={reply} continued={i > 0 && replies[i - 1].authorId === reply.authorId && reply.at - replies[i - 1].at < 300}
                        {me} {names} {api} {emoji} {online} {moderator} inThread {onerror} saved={saved.includes(reply.id)} onsave={save} />
                {/each}
            </div>
            <p class="pf-conv-typing" aria-live="polite">{#if threadTypers.length}{t('%s is typing…', threadTypers[0])}{/if}</p>
            {#if member}
                <Composer {api} conversationId={id} thread={thread.root.id} members={members} {me} {emoji} {maxFileMb}
                    placeholder={t('Reply…')} typing={whisper} onsent={arrive} onreply={note} {onerror} />
            {/if}
        </aside>
    {/if}
</section>
