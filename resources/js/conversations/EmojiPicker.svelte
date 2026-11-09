<script>
    /**
     * A small, fixed set of emoji, in groups — deliberately not the full table: what a team reaches
     * for fits without scrolling, and a missing one is a one-line addition. No library, no CDN.
     * The team's own come first, as their pictures, picked as `:name:`.
     *
     * With an `anchor` (a reaction's button in a message list) it floats over the page beside it —
     * below, or above where there is no room — so the list neither clips it nor scrolls; scrolling
     * closes it. A click outside closes any picker; a control marked `data-pf-emoji-toggle` is its
     * own button, which opens and closes it itself.
     */
    import { t } from './t.js';

    let { onpick, onclose, custom = {}, anchor = null } = $props();

    const GROUPS = [
        { label: 'Emoji: reactions', emoji: ['👍', '👎', '❤️', '🔥', '👏', '🙏', '💯', '✅', '👀', '🎉'] },
        { label: 'Emoji: faces', emoji: ['🙂', '😀', '😂', '😅', '😍', '😉', '😎', '🤔', '😮', '😢'] },
        { label: 'Emoji: work', emoji: ['☕', '📌', '📎', '📞', '📅', '💡', '⚠️', '🚀', '🐛', '💶'] },
    ];

    const ours = $derived(Object.keys(custom).sort());

    let panel = $state(null);
    let place = $state('');

    $effect(() => {
        panel?.querySelector('button')?.focus();
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

    /** Escape closes it. */
    function onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            onclose?.();
        }
    }
</script>

<!-- The first emoji is focused when it opens, which is what makes the dialog's Escape reachable. -->
<!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
<!-- svelte-ignore a11y_interactive_supports_focus -->
<div class="pf-conv-pop pf-conv-emoji" role="dialog" aria-label={t('Insert an emoji')} bind:this={panel} onkeydown={onKeydown} style={place || (anchor ? 'position:fixed;visibility:hidden' : '')}>
    {#if ours.length}
        <p class="pf-conv-pop-label">{t('Emoji: ours')}</p>
        <div class="pf-conv-emoji-row">
            {#each ours as name (name)}
                <button type="button" class="pf-conv-emoji-btn" onclick={() => onpick?.(':' + name + ':')} aria-label={':' + name + ':'} title={':' + name + ':'}>
                    <img class="pf-conv-custom-emoji" src={custom[name]} alt=""></button>
            {/each}
        </div>
    {/if}
    {#each GROUPS as group (group.label)}
        <p class="pf-conv-pop-label">{t(group.label)}</p>
        <div class="pf-conv-emoji-row">
            {#each group.emoji as emoji (emoji)}
                <button type="button" class="pf-conv-emoji-btn" onclick={() => onpick?.(emoji)} aria-label={emoji}>{emoji}</button>
            {/each}
        </div>
    {/each}
</div>
