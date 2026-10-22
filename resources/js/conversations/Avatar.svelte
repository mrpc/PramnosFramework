<script>
    /** A person's face: their initials on a colour of their own, and whether they are here. */
    import { initials, hue } from './logic.js';

    let { id = 0, name = '', online = null, small = false } = $props();
</script>

<span class="pf-conv-avatar" class:pf-conv-avatar-sm={small} style="--pf-conv-hue: {hue(id)}" aria-hidden="true">
    {initials(name)}
    {#if online !== null}<span class="pf-conv-presence" class:pf-conv-online={online}></span>{/if}
</span>
