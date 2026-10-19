<?php
/**
 * OAuth2 default/fallback page (plain-CSS theme).
 *
 * Variables:
 *   $this->header — Page heading string
 */
?>
<div class="page-section">
    <h2><?php echo htmlspecialchars($this->header ?? t('OAuth2'), ENT_QUOTES, 'UTF-8'); ?></h2>
    <div role="status" class="alert alert-info">
        <strong><?php echo htmlspecialchars(t('OAuth2 Server'), ENT_QUOTES, 'UTF-8'); ?></strong> — <?php echo htmlspecialchars(t('This is the default OAuth2 view. A specific template was not found for the requested action.'), ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <a href="<?php echo sURL . rawurlencode((string) ($this->routeBase ?? 'Account')); ?>" class="btn"><?php echo htmlspecialchars(t('Your account'), ENT_QUOTES, 'UTF-8'); ?></a>
    <a href="<?php echo sURL; ?>" class="btn" style="margin-left:8px"><?php echo htmlspecialchars(t('Home'), ENT_QUOTES, 'UTF-8'); ?></a>
</div>
