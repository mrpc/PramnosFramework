<?php
/**
 * Device authorization success page (plain-CSS theme).
 *
 * Variables:
 *   $this->deviceAuth — array{user_code, scope} — approved device auth record
 */
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px;text-align:center">
        <div class="card-body" style="padding:28px">
            <div style="font-size:3rem;color:#27ae60;margin-bottom:12px">&#10003;</div>
            <h2 style="margin:0 0 8px;font-size:1.3rem">Device Authorized!</h2>
            <p style="color:#555;margin-bottom:16px">Your device has been successfully authorized.</p>
            <div style="background:#f5f5f5;border-radius:6px;padding:14px;text-align:left;font-size:13px;margin-bottom:14px">
                <div style="margin-bottom:4px"><strong>Device Code:</strong> <?php echo htmlspecialchars($this->deviceAuth['user_code'] ?? ''); ?></div>
                <div style="margin-bottom:4px"><strong>Scopes:</strong> <?php echo htmlspecialchars($this->deviceAuth['scope'] ?? ''); ?></div>
                <div><strong>Authorized:</strong> <?php echo date('Y-m-d H:i:s'); ?></div>
            </div>
            <p style="color:#888;font-size:12px">You may now close this window and return to your device.</p>
        </div>
    </div>
</div>
