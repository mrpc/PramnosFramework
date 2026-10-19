<?php
/**
 * OAuth2 default/fallback page (Bootstrap theme).
 *
 * Variables:
 *   $this->header — Page heading string
 */
?>
<div class="container py-5">
    <h2><?php echo htmlspecialchars($this->header ?? t('OAuth2')); ?></h2>
    <div role="status" class="alert alert-info">
        <?php echo sprintf(htmlspecialchars(t('%s — This is the default OAuth2 view. A specific template was not found for the requested action.'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars(t('OAuth2 Server'), ENT_QUOTES, 'UTF-8') . '</strong>'); ?>
    </div>
    <a href="<?php echo sURL . rawurlencode((string) ($this->routeBase ?? 'Account')); ?>" class="btn btn-primary me-2"><?php echo htmlspecialchars(t('Your account'), ENT_QUOTES, 'UTF-8'); ?></a>
    <a href="<?php echo sURL; ?>" class="btn btn-secondary"><?php echo htmlspecialchars(t('Home'), ENT_QUOTES, 'UTF-8'); ?></a>
</div>
