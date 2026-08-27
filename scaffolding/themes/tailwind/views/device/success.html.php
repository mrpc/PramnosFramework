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
        <div class="text-5xl text-success mb-4">&#10003;</div>
        <h1 class="text-xl font-semibold mb-2">Device Authorized!</h1>
        <p class="text-base-content/80 mb-4">Your device has been successfully authorized.</p>
        <div class="bg-base-200 rounded-lg p-4 text-left text-sm mb-4 space-y-1">
            <div><strong>Device Code:</strong> <?php echo htmlspecialchars($this->deviceAuth['user_code'] ?? ''); ?></div>
            <div><strong>Scopes:</strong> <?php echo htmlspecialchars($this->deviceAuth['scope'] ?? ''); ?></div>
            <div><strong>Authorized:</strong> <?php echo date('Y-m-d H:i:s'); ?></div>
        </div>
        <p class="text-base-content/70 text-xs">You may now close this window and return to your device.</p>
    </div>
</div>
