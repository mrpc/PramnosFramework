<script>
    /**
     * A small, fixed set of emoji, in groups — deliberately not the full table: what a team reaches
     * for fits without scrolling, and a missing one is a one-line addition. No library, no CDN.
     */
    import { t } from './t.js';

    let { onpick, onclose } = $props();

    const GROUPS = [
        { label: 'Emoji: reactions', emoji: ['👍', '👎', '❤️', '🔥', '👏', '🙏', '💯', '✅', '👀', '🎉'] },
        { label: 'Emoji: faces', emoji: ['🙂', '😀', '😂', '😅', '😍', '😉', '😎', '🤔', '😮', '😢'] },
        { label: 'Emoji: work', emoji: ['☕', '📌', '📎', '📞', '📅', '💡', '⚠️', '🚀', '🐛', '💶'] },
    ];

    let panel = $state(null);

    $effect(() => {
        panel?.querySelector('button')?.focus();
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
<div class="pf-conv-pop pf-conv-emoji" role="dialog" aria-label={t('Insert an emoji')} bind:this={panel} onkeydown={onKeydown}>
    {#each GROUPS as group (group.label)}
        <p class="pf-conv-pop-label">{t(group.label)}</p>
        <div class="pf-conv-emoji-row">
            {#each group.emoji as emoji (emoji)}
                <button type="button" class="pf-conv-emoji-btn" onclick={() => onpick?.(emoji)} aria-label={emoji}>{emoji}</button>
            {/each}
        </div>
    {/each}
</div>
