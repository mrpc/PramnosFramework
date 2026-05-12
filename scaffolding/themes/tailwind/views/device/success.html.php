<?php
/**
 * Device authorization success page (Tailwind theme).
 *
 * Variables:
 *   $this->deviceAuth — array{user_code, scope} — approved device auth record
 */
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8 text-center">
        <div class="text-5xl text-green-500 mb-4">&#10003;</div>
        <h1 class="text-xl font-semibold mb-2">Device Authorized!</h1>
        <p class="text-gray-600 mb-4">Your device has been successfully authorized.</p>
        <div class="bg-gray-50 rounded-lg p-4 text-left text-sm mb-4 space-y-1">
            <div><strong>Device Code:</strong> <?php echo htmlspecialchars($this->deviceAuth['user_code'] ?? ''); ?></div>
            <div><strong>Scopes:</strong> <?php echo htmlspecialchars($this->deviceAuth['scope'] ?? ''); ?></div>
            <div><strong>Authorized:</strong> <?php echo date('Y-m-d H:i:s'); ?></div>
        </div>
        <p class="text-gray-500 text-xs">You may now close this window and return to your device.</p>
    </div>
</div>
