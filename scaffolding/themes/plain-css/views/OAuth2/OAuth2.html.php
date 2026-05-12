<?php
/**
 * OAuth2 default/fallback page (plain-CSS theme).
 *
 * Variables:
 *   $this->header — Page heading string
 */
?>
<div class="page-section">
    <h2><?php echo htmlspecialchars($this->header ?? 'OAuth2'); ?></h2>
    <div class="alert alert-info">
        <strong>OAuth2 Server</strong> — This is the default OAuth2 view. A specific template was not found for the requested action.
    </div>
    <a href="<?php echo sURL; ?>Dashboard" class="btn">Dashboard</a>
    <a href="<?php echo sURL; ?>" class="btn" style="margin-left:8px">Home</a>
</div>
