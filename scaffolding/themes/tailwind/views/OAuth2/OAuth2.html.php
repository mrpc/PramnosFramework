<?php
/**
 * OAuth2 default/fallback page (Tailwind theme).
 *
 * Variables:
 *   $this->header — Page heading string
 */
?>
<div class="container mx-auto py-10 px-4">
    <h2 class="text-2xl font-semibold mb-4"><?php echo htmlspecialchars($this->header ?? t('OAuth2'), ENT_QUOTES, 'UTF-8'); ?></h2>
    <div role="status" class="alert alert-info mb-6">
        <strong><?php echo htmlspecialchars(t('OAuth2 Server'), ENT_QUOTES, 'UTF-8'); ?></strong> — <?php echo htmlspecialchars(t('This is the default OAuth2 view. A specific template was not found for the requested action.'), ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <a href="<?php echo adminUrl('Dashboard'); ?>" class="btn btn-primary mr-2"><?php echo htmlspecialchars(t('Dashboard'), ENT_QUOTES, 'UTF-8'); ?></a>
    <a href="<?php echo sURL; ?>" class="btn btn-neutral"><?php echo htmlspecialchars(t('Home'), ENT_QUOTES, 'UTF-8'); ?></a>
</div>
