<?php
/**
 * Device authorization success page (Bootstrap theme).
 *
 * Variables:
 *   $this->deviceAuth — array{user_code, scope} — approved device auth record
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm text-center">
                <div class="card-body p-4">
                    <?php $this->insert('../partials/auth_brand'); ?>
                    <div class="fs-1 text-success mb-2">&#10003;</div>
                    <h1 class="h4 mb-3"><?php echo htmlspecialchars(t('Device Authorized!'), ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p class="text-muted mb-3"><?php echo htmlspecialchars(t('Your device has been successfully authorized.'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <div class="bg-light rounded p-3 text-start small mb-3">
                        <div><strong><?php echo htmlspecialchars(t('Device Code:'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars($this->deviceAuth['user_code'] ?? ''); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('Scopes:'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars($this->deviceAuth['scope'] ?? ''); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('Authorized:'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo localDateTime(time()); ?></div>
                    </div>
                    <p class="text-muted small"><?php echo htmlspecialchars(t('You may now close this window and return to your device.'), ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
