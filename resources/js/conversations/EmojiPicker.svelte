<script>
    /**
     * The emoji picker: a search at the top — by a shortcode's name or an emoji's English name, Enter
     * taking the first — and tabs: the person's frequent ones (kept in this browser), eight
     * categories, and the team's own, as their pictures, picked as `:name:`. The data and the search
     * are `emoji-data.js`, which an application's own chat can import as well.
     *
     * Props: `onpick(emoji)`, `onclose()`, `custom` (name → picture; `false` leaves the team's own
     * out, for a status), `shortcodes` (name → emoji, for the search), and `anchor`: with it the
     * picker floats over the page beside that element — below, or above where there is no room — so
     * a list neither clips it nor scrolls, and scrolling closes it. A click outside closes any
     * picker; a control marked `data-pf-emoji-toggle` is its own button. The arrow keys move between
     * emoji, Escape closes.
     */
    import { t } from './t.js';
    import { CATEGORIES, searchEmoji, frequentEmoji, rememberEmoji } from './emoji-data.js';

    let { onpick, onclose, custom = {}, shortcodes = {}, anchor = null } = $props();

    /** Emoji a row holds: what the arrow keys move up and down by. */
    const COLUMNS = 8;

    const ours = $derived(custom ? Object.keys(custom).sort() : []);
    let tab = $state('frequent');
    let query = $state('');
    let recent = $state(frequentEmoji());
    let panel = $state(null);
    let search = $state(null);
    let place = $state('');

    /** What the picker shows: the search's finds, or the tab's emoji, as `{emoji, src?, name}`. */
    const shown = $derived(query.trim() !== ''
        ? searchEmoji(query, shortcodes, custom || {})
        : tab === 'frequent' ? recent.map((emoji) => (/^:[a-z0-9_+-]+:$/.test(emoji) && custom && custom[emoji.slice(1, -1)]
            ? { emoji, src: custom[emoji.slice(1, -1)], name: emoji } : { emoji, name: emoji })).filter((e) => !/^:/.test(e.emoji) || e.src)
            : tab === 'ours' ? ours.map((name) => ({ emoji: ':' + name + ':', src: custom[name], name: ':' + name + ':' }))
                : (CATEGORIES.find((c) => c.key === tab)?.emoji || []).map(([emoji, name]) => ({ emoji, name })));

    $effect(() => {
        search?.focus();
    });

    // Floating beside its anchor: below it, or above where the window has no room below.
    $effect(() => {
        if (!anchor || !panel) {
            return;
        }
        const at = anchor.getBoundingClientRect();
        const height = panel.offsetHeight;
        const top = at.bottom + height + 8 > window.innerHeight ? Math.max(8, at.top - height - 4) : at.bottom + 4;
        const left = Math.max(8, Math.min(at.left, window.innerWidth - panel.offsetWidth - 8));
        place = `position:fixed;top:${top}px;left:${left}px;z-index:1000`;
    });

    // A click outside closes it; so does scrolling, for one floating beside a list.
    $effect(() => {
        const outside = (event) => {
            if (panel && !panel.contains(event.target) && !anchor?.contains(event.target) && !event.target.closest?.('[data-pf-emoji-toggle]')) {
                onclose?.();
            }
        };
        const scrolled = (event) => {
            if (anchor && panel && !panel.contains(event.target)) {
                onclose?.();
            }
        };
        document.addEventListener('pointerdown', outside, true);
        window.addEventListener('scroll', scrolled, true);

        return () => {
            document.removeEventListener('pointerdown', outside, true);
            window.removeEventListener('scroll', scrolled, true);
        };
    });

    /** Pick one: remembered among the frequent, then handed on. */
    function pick(emoji) {
        recent = rememberEmoji(emoji);
        onpick?.(emoji);
    }

    /** Escape closes; in the search, Enter takes the first and the down arrow goes to the emoji. */
    function onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            onclose?.();

            return;
        }
        const buttons = [...(panel?.querySelectorAll('.pf-conv-emoji-grid button') || [])];
        if (event.target === search) {
            if (event.key === 'Enter' && shown.length) {
                event.preventDefault();
                pick(shown[0].emoji);
            } else if (event.key === 'ArrowDown' && buttons.length) {
                event.preventDefault();
                buttons[0].focus();
            }

            return;
        }
        const at = buttons.indexOf(event.target);
        const step = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: COLUMNS, ArrowUp: -COLUMNS }[event.key];
        if (at < 0 || step === undefined) {
            return;
        }
        event.preventDefault();
        const next = at + step;
        if (next < 0) {
            search?.focus();
        } else {
            buttons[Math.min(next, buttons.length - 1)]?.focus();
        }
    }
</script>

<!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
<!-- svelte-ignore a11y_interactive_supports_focus -->
<div class="pf-conv-pop pf-conv-emoji" role="dialog" aria-label={t('Insert an emoji')} bind:this={panel} onkeydown={onKeydown}
    style={place || (anchor ? 'position:fixed;visibility:hidden' : '')}>
    <input class="pf-conv-emoji-search" type="search" bind:this={search} bind:value={query} placeholder={t('Search emoji')} aria-label={t('Search emoji')}>
    {#if query.trim() === ''}
        <div class="pf-conv-emoji-tabs" role="tablist">
            <button type="button" role="tab" aria-selected={tab === 'frequent'} title={t('Emoji: frequent')} aria-label={t('Emoji: frequent')} onclick={() => (tab = 'frequent')}>🕘</button>
            {#each CATEGORIES as category (category.key)}
                <button type="button" role="tab" aria-selected={tab === category.key} title={t(category.label)} aria-label={t(category.label)} onclick={() => (tab = category.key)}>{category.icon}</button>
            {/each}
            {#if ours.length}
                <button type="button" role="tab" aria-selected={tab === 'ours'} title={t('Emoji: ours')} aria-label={t('Emoji: ours')} onclick={() => (tab = 'ours')}>
                    <img class="pf-conv-custom-emoji" src={custom[ours[0]]} alt=""></button>
            {/if}
        </div>
    {/if}
    <div class="pf-conv-emoji-grid">
        {#each shown as item (item.emoji)}
            <button type="button" class="pf-conv-emoji-btn" onclick={() => pick(item.emoji)} aria-label={item.name} title={item.name}>
                {#if item.src}<img class="pf-conv-custom-emoji" src={item.src} alt="">{:else}{item.emoji}{/if}</button>
        {:else}
            <p class="pf-muted">{t('No emoji found')}</p>
        {/each}
    </div>
</div>
