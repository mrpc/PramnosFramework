<script>
    /** A card: a system message's, a reference's, a command's answer — title linked, text, an image, fields, a tone. */
    import { t } from './t.js';

    let { card } = $props();
    // Ask's titles come in English, written by a worker that knows no reader — t('Open in Ask') —
    // and are put in the reader's language here.
    const title = $derived(card.from === 'Ask' && card.title ? t(card.title) : card.title);
</script>

<div class="pf-conv-card pf-conv-card-{card.color || 'neutral'}">
    {#if card.title}
        <p class="pf-conv-card-title">
            {#if card.url}<a href={card.url} target={card.url.startsWith('/') ? undefined : '_blank'} rel="noopener noreferrer">{title}</a>{:else}{title}{/if}
        </p>
    {/if}
    {#if card.text}<p class="pf-conv-card-text">{card.text}</p>{/if}
    {#if card.image}<img class="pf-conv-card-image" src={card.image} alt={title || ''} loading="lazy" />{/if}
    {#if card.fields && Object.keys(card.fields).length}
        <dl class="pf-conv-card-fields">
            {#each Object.entries(card.fields) as [label, value] (label)}
                <div><dt>{label}</dt><dd>{value}</dd></div>
            {/each}
        </dl>
    {/if}
</div>
