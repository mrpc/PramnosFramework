<script>
    /**
     * Starting a conversation — a channel (open or closed) or a message to people: one person is a
     * direct conversation, two or more a group — and browsing the open channels to join one.
     */
    import Avatar from './Avatar.svelte';
    import { t } from './t.js';
    import { lastSignIn } from './logic.js';
    import { canAsk, assistant } from './extras.js';

    let { api, me, mode = 'new', browse = [], ondone, onclose, onerror = () => {} } = $props();

    // Where it opens; the reader moves between the tabs from there.
    // svelte-ignore state_referenced_locally
    let tab = $state(mode === 'browse' ? 'browse' : 'people');
    let name = $state('');
    let topic = $state('');
    let closed = $state(false);
    let query = $state('');
    let found = $state([]);
    let chosen = $state([]);
    let busy = $state(false);
    let inactive = $state(false);      // inactive people offered too
    let dialog = $state(null);

    $effect(() => {
        dialog?.showModal?.();
    });

    $effect(() => {
        const q = query;
        const withInactive = inactive;
        if (tab !== 'people') {
            return;
        }
        const timer = setTimeout(() => {
            api.get('people', withInactive ? { q, inactive: 1 } : { q }).then((r) => (found = r.people)).catch((err) => onerror(err.message));
        }, 200);

        return () => clearTimeout(timer);
    });

    // Ask, offered first to whoever may ask it, while what is typed could be its name.
    const askOffered = $derived(canAsk() && chosen.length === 0 && 'ask'.startsWith(query.trim().toLowerCase()));

    /** One's conversation with Ask, made the first time. */
    async function withAsk() {
        busy = true;
        try {
            ondone((await api.post('withask')).id);
        } catch (err) {
            onerror(err.message);
        } finally {
            busy = false;
        }
    }

    function choose(person) {
        // Oneself alone is one's own dm, one's notes: never one of a group.
        if (person.id === me || chosen.some((p) => p.id === me)) {
            chosen = chosen.some((p) => p.id === person.id) ? [] : [person];
            return;
        }
        chosen = chosen.some((p) => p.id === person.id) ? chosen.filter((p) => p.id !== person.id) : [...chosen, person].slice(0, 8);
    }

    async function create(event) {
        event.preventDefault();
        busy = true;
        try {
            const answer = tab === 'channel'
                ? await api.post('create', { kind: closed ? 'private' : 'channel', name, topic })
                : await api.post('create', { kind: chosen.length === 1 ? 'dm' : 'group', members: chosen.map((p) => p.id) });
            ondone(answer.id);
        } catch (err) {
            onerror(err.message);
        } finally {
            busy = false;
        }
    }

    async function join(id) {
        try {
            await api.post('join/' + id);
            ondone(id);
        } catch (err) {
            onerror(err.message);
        }
    }
</script>

<dialog class="pf-conv-dialog" bind:this={dialog} onclose={onclose} aria-label={t('New conversation')}>
    <form method="dialog" class="pf-conv-dialog-body" onsubmit={create}>
        <header class="pf-conv-dialog-head">
            <div class="pf-conv-tabs" role="tablist">
                <button type="button" role="tab" aria-selected={tab === 'people'} class:pf-conv-active={tab === 'people'} onclick={() => (tab = 'people')}>{t('Message people')}</button>
                <button type="button" role="tab" aria-selected={tab === 'channel'} class:pf-conv-active={tab === 'channel'} onclick={() => (tab = 'channel')}>{t('New channel')}</button>
                <button type="button" role="tab" aria-selected={tab === 'browse'} class:pf-conv-active={tab === 'browse'} onclick={() => (tab = 'browse')}>{t('Browse channels')}</button>
            </div>
            <button type="button" class="pf-conv-icon-btn" aria-label={t('Close')} onclick={() => dialog.close()}>×</button>
        </header>

        {#if tab === 'people'}
            <input type="search" class="pf-conv-input" bind:value={query} placeholder={t('Find people by name')} aria-label={t('Find people by name')} />
            {#if chosen.length}
                <div class="pf-conv-chips">
                    {#each chosen as person (person.id)}
                        <span class="pf-conv-chip">{person.name}<button type="button" aria-label={t('Remove')} onclick={() => choose(person)}>×</button></span>
                    {/each}
                </div>
            {/if}
            <ul class="pf-conv-pick">
                {#if askOffered}
                    <li>
                        <button type="button" disabled={busy} onclick={withAsk}>
                            <Avatar id={0} name={assistant().name} src={assistant().avatar} html={assistant().html} small /> <span>{assistant().name}</span>
                            <span class="pf-conv-muted-text">{t('Bot · every message a question to the analyst')}</span>
                        </button>
                    </li>
                {/if}
                {#each found as person (person.id)}
                    <li>
                        <button type="button" class:pf-conv-active={chosen.some((p) => p.id === person.id)} onclick={() => choose(person)}>
                            <Avatar id={person.id} name={person.name} small /> <span class:pf-conv-inactive={person.inactive != null}>{person.id === me ? person.name + ' ' + t('(you)') : person.name}</span>
                            {#if person.inactive != null}<span class="pf-conv-muted-text">{lastSignIn(person.inactive) ? t('Inactive: last signed in %s', lastSignIn(person.inactive)) : t('Inactive: never signed in')}</span>{/if}
                        </button>
                    </li>
                {:else}
                    <li class="pf-conv-side-empty">{t('Nobody by that name.')}</li>
                {/each}
            </ul>
            <label class="pf-conv-hint"><input type="checkbox" bind:checked={inactive} /> {t('Show inactive people too')}</label>
            <p class="pf-conv-hint">{chosen.length > 1 ? t('A group conversation of %s people and you.', chosen.length) : t('One person: a direct conversation; more: a group.')}</p>
            <footer class="pf-conv-dialog-foot">
                <button type="submit" class="pf-conv-btn pf-conv-btn-primary" disabled={busy || chosen.length === 0}>{t('Start conversation')}</button>
            </footer>
        {:else if tab === 'channel'}
            <label class="pf-conv-field">{t('Channel name')}
                <input class="pf-conv-input" bind:value={name} required maxlength="80" placeholder={t('e.g. sales')} />
            </label>
            <label class="pf-conv-field">{t('What it is for')}
                <input class="pf-conv-input" bind:value={topic} maxlength="250" />
            </label>
            <label class="pf-conv-check"><input type="checkbox" bind:checked={closed} /> {t('Closed: only the people its members add')}</label>
            <footer class="pf-conv-dialog-foot">
                <button type="submit" class="pf-conv-btn pf-conv-btn-primary" disabled={busy || name.trim() === ''}>{t('Create channel')}</button>
            </footer>
        {:else}
            <ul class="pf-conv-browse-list">
                {#each browse as c (c.id)}
                    <li class="pf-conv-browse">
                        <span><strong>#{c.slug}</strong> {#if c.topic}<span class="pf-conv-muted-text">— {c.topic}</span>{/if}
                            <span class="pf-conv-muted-text">· {t('%s members', c.memberCount)}</span></span>
                        {#if c.joined}
                            <button type="button" class="pf-conv-btn" onclick={() => ondone(c.id)}>{t('Open channel')}</button>
                        {:else}
                            <button type="button" class="pf-conv-btn pf-conv-btn-primary" onclick={() => join(c.id)}>{t('Join channel')}</button>
                        {/if}
                    </li>
                {:else}
                    <li class="pf-conv-side-empty">{t('There are no open channels yet.')}</li>
                {/each}
            </ul>
        {/if}
    </form>
</dialog>
