<?php
/**
 * Device authorization denied page (plain-CSS theme).
 */
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px;text-align:center">
        <?php $this->insert('../partials/auth_brand'); ?>
        <div class="card-body" style="padding:28px">
            <div style="font-size:3rem;color:#c0392b;margin-bottom:12px">&#10060;</div>
            <h2 style="margin:0 0 12px;font-size:1.3rem"><?php echo htmlspecialchars(t('Authorization Denied'), ENT_QUOTES, 'UTF-8'); ?></h2>
            <p style="color:#555"><?php echo htmlspecialchars(t('The device authorization request was denied. You may now close this window.'), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>
</div>
