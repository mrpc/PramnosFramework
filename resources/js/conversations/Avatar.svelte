<script>
    /**
     * A person's face: their picture where the application gives one (`conversations.avatars`),
     * else — and when the picture fails to load — their initials on a colour of their own; and
     * whether they are here.
     */
    import { initials, hue } from './logic.js';

    let { id = 0, name = '', online = null, small = false, src = null } = $props();

    let failed = $state(false);
    // A new picture gets its own try.
    $effect(() => { src; failed = false; });
</script>

<span class="pf-conv-avatar" class:pf-conv-avatar-sm={small} data-person={id > 0 ? id : null} style="--pf-conv-hue: {hue(id)}" aria-hidden="true">
    {#if src && !failed}<img class="pf-conv-avatar-img" {src} alt="" loading="lazy" onerror={() => (failed = true)} />{:else}{initials(name)}{/if}
    {#if online !== null}<span class="pf-conv-presence" class:pf-conv-online={online}></span>{/if}
</span>
