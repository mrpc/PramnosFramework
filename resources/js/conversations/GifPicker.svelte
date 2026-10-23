<script>
    /**
     * GIFs to send: the trending ones, or those a search finds — asked through this server, which
     * holds the provider's key. Picking one hands it to the composer, which sends it.
     */
    import { t } from './t.js';
    import { gifProvider } from './extras.js';

    let { api, onpick, onclose, onerror = () => {} } = $props();

    let query = $state('');
    let found = $state([]);
    let busy = $state(true);
    let box = $state(null);

    $effect(() => {
        box?.focus();
    });

    $effect(() => {
        const q = query;
        busy = true;
        const timer = setTimeout(() => {
            api.get('gifs', q.trim() ? { q: q.trim() } : {})
                .then((r) => { found = r.gifs; })
                .catch((err) => onerror(err.message))
                .finally(() => { busy = false; });
        }, 300);

        return () => clearTimeout(timer);
    });

    /** Escape closes it. */
    function onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            onclose?.();
        }
    }
</script>

<!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
<!-- svelte-ignore a11y_interactive_supports_focus -->
<div class="pf-conv-pop pf-conv-gifs" role="dialog" aria-label={t('Find a GIF')} onkeydown={onKeydown}>
    <input bind:this={box} type="search" class="pf-conv-input" bind:value={query} placeholder={t('Find a GIF')} aria-label={t('Find a GIF')} />
    <div class="pf-conv-gif-grid" aria-busy={busy}>
        {#each found as gif (gif.id + gif.url)}
            <button type="button" class="pf-conv-gif" title={gif.title} onclick={() => onpick?.(gif)}>
                <img src={gif.preview.url} alt={gif.title || 'GIF'} width={gif.preview.width || null} height={gif.preview.height || null} loading="lazy" />
            </button>
        {:else}
            {#if !busy}<p class="pf-conv-side-empty">{t('No GIFs found.')}</p>{/if}
        {/each}
    </div>
    <p class="pf-conv-hint">{t('Powered by %s', gifProvider() === 'klipy' ? 'KLIPY' : 'GIPHY')}</p>
</div>
