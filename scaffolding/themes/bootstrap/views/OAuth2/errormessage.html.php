<?php
/**
 * OAuth2 error message page (Bootstrap theme).
 *
 * Variables:
 *   $this->error — Error description string
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm border-danger">
                <div class="card-body p-4 text-center">
                    <?php $this->insert('../partials/auth_brand'); ?>
                    <h1 class="h4 text-danger mb-3"><?php echo htmlspecialchars(t('Authorization Error'), ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p class="mb-4"><?php echo htmlspecialchars($this->error ?? t('An unknown error occurred.')); ?></p>
                    <a href="javascript:history.back()" class="btn btn-outline-secondary">&larr; <?php echo htmlspecialchars(t('Go Back'), ENT_QUOTES, 'UTF-8'); ?></a>
                </div>
            </div>
        </div>
    </div>
</div>
