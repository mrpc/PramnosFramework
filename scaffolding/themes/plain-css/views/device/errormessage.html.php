<?php
/**
 * Device authorization error page (plain-CSS theme).
 *
 * Variables:
 *   $this->error    — Error message string
 *   $this->userCode — User code for retry link (optional)
 */
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px;text-align:center">
        <?php $this->insert('../partials/auth_brand'); ?>
        <div class="card-body" style="padding:28px">
            <div style="font-size:3rem;margin-bottom:12px">&#9888;&#65039;</div>
            <h2 style="margin:0 0 16px;font-size:1.3rem"><?php echo htmlspecialchars(t('Authorization Error'), ENT_QUOTES, 'UTF-8'); ?></h2>
            <div role="alert" class="alert alert-danger"><?php echo htmlspecialchars($this->error ?? t('An error occurred.'), ENT_QUOTES, 'UTF-8'); ?></div>
            <a href="?user_code=<?php echo urlencode($this->userCode ?? ''); ?>" class="btn" style="margin-top:8px">&larr; <?php echo htmlspecialchars(t('Try Again'), ENT_QUOTES, 'UTF-8'); ?></a>
        </div>
    </div>
</div>
