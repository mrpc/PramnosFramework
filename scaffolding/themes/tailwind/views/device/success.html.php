<?php
/**
 * Device authorization success page (Tailwind theme).
 *
 * Variables:
 *   $this->deviceAuth — array{user_code, scope} — approved device auth record
 */
?>
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm text-center">
        <?php $this->insert('../partials/auth_brand'); ?>
        <div class="text-5xl text-success mb-4">&#10003;</div>
        <h1 class="text-xl font-semibold mb-2"><?php echo htmlspecialchars(t('Device Authorized!'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="text-base-content/80 mb-4"><?php echo htmlspecialchars(t('Your device has been successfully authorized.'), ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="bg-base-200 rounded-lg p-4 text-left text-sm mb-4 space-y-1">
            <div><strong><?php echo htmlspecialchars(t('Device Code:'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars($this->deviceAuth['user_code'] ?? ''); ?></div>
            <div><strong><?php echo htmlspecialchars(t('Scopes:'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars($this->deviceAuth['scope'] ?? ''); ?></div>
            <div><strong><?php echo htmlspecialchars(t('Authorized:'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo localDateTime(time()); ?></div>
        </div>
        <p class="text-base-content/70 text-xs"><?php echo htmlspecialchars(t('You may now close this window and return to your device.'), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
</div>
