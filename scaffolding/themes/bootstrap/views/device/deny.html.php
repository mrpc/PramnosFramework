<?php
/**
 * Device authorization denied page (Bootstrap theme).
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm text-center">
                <div class="card-body p-4">
                    <?php $this->insert('../partials/auth_brand'); ?>
                    <div class="fs-1 text-danger mb-2">&#10060;</div>
                    <h1 class="h4 mb-3"><?php echo htmlspecialchars(t('Authorization Denied'), ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p class="text-muted"><?php echo htmlspecialchars(t('The device authorization request was denied. You may now close this window.'), ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
