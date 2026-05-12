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
                    <div class="fs-1 text-success mb-2">&#10003;</div>
                    <h1 class="h4 mb-3">Device Authorized!</h1>
                    <p class="text-muted mb-3">Your device has been successfully authorized.</p>
                    <div class="bg-light rounded p-3 text-start small mb-3">
                        <div><strong>Device Code:</strong> <?php echo htmlspecialchars($this->deviceAuth['user_code'] ?? ''); ?></div>
                        <div><strong>Scopes:</strong> <?php echo htmlspecialchars($this->deviceAuth['scope'] ?? ''); ?></div>
                        <div><strong>Authorized:</strong> <?php echo date('Y-m-d H:i:s'); ?></div>
                    </div>
                    <p class="text-muted small">You may now close this window and return to your device.</p>
                </div>
            </div>
        </div>
    </div>
</div>
