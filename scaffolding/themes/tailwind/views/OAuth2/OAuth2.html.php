<?php
/**
 * OAuth2 default/fallback page (Tailwind theme).
 *
 * Variables:
 *   $this->header — Page heading string
 */
?>
<div class="container mx-auto py-10 px-4">
    <h2 class="text-2xl font-semibold mb-4"><?php echo htmlspecialchars($this->header ?? 'OAuth2'); ?></h2>
    <div role="status" class="alert alert-info mb-6">
        <strong>OAuth2 Server</strong> — This is the default OAuth2 view. A specific template was not found for the requested action.
    </div>
    <a href="<?php echo adminUrl('Dashboard'); ?>" class="btn btn-primary mr-2">Dashboard</a>
    <a href="<?php echo sURL; ?>" class="btn btn-neutral">Home</a>
</div>
