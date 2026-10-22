<?php
/**
 * The conversations' basic page: the reader's conversations, one open with its messages, and a box
 * to write in — drawn by `pf-conversations-basic.js` from the JSON API. A reference and a smoke test,
 * dressed by the theme's own `pf-*` classes and the few `pf-conv-basic-*` rules in components.css.
 *
 * Variables:
 *   $this->props — `Client::basic()`: where the JSON is, who is reading, their channel, the words
 */
$e     = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$props = $e((string) json_encode($this->props ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
?>
<div class="pf-conv-basic" data-pf-conversations-basic data-props="<?php echo $props; ?>">
    <nav class="pf-card pf-conv-basic-side" aria-label="<?php echo $e(t('Conversations')); ?>">
        <h1 class="pf-card-head"><?php echo $e(t('Conversations')); ?></h1>
        <ul class="pf-conv-basic-list" data-pf-list></ul>
    </nav>
    <section class="pf-card pf-conv-basic-main">
        <h2 class="pf-card-head" data-pf-title><?php echo $e(t('Loading the conversations…')); ?></h2>
        <p class="pf-conv-basic-earlier"><button type="button" class="pf-btn pf-btn-sm" data-pf-earlier hidden><?php echo $e(t('Earlier messages')); ?></button></p>
        <div class="pf-conv-basic-messages" aria-live="polite" data-pf-messages>
        </div>
        <p class="pf-conv-basic-error" role="alert" data-pf-error hidden></p>
        <form class="pf-conv-basic-composer" data-pf-composer hidden>
            <textarea class="pf-input" rows="2" aria-label="<?php echo $e(t('Write a message')); ?>" placeholder="<?php echo $e(t('Write a message')); ?>"></textarea>
            <button type="submit" class="pf-btn pf-btn-primary"><?php echo $e(t('Send the message')); ?></button>
        </form>
    </section>
</div>
<script src="<?php echo $e(assetUrl('assets/js/pf-conversations-basic.js')); ?>" defer></script>
