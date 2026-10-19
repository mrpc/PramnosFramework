<?php
/**
 * OAuth2 error message page (Tailwind theme).
 *
 * Variables:
 *   $this->error — Error description string
 */
?>
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm text-center">
        <?php $this->insert('../partials/auth_brand'); ?>
        <h1 class="text-2xl font-semibold text-error mb-4"><?php echo htmlspecialchars(t('Authorization Error'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-base-content mb-6"><?php echo htmlspecialchars($this->error ?? t('An unknown error occurred.'), ENT_QUOTES, 'UTF-8'); ?></p>
        <a href="javascript:history.back()" class="btn btn-outline inline-block">&larr; <?php echo htmlspecialchars(t('Go Back'), ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
</div>
