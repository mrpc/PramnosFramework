<?php
/**
 * Generic login-flow message page (plain-CSS theme).
 *
 * Variables:
 *   $this->title   — Optional page title override
 *   $this->message — Optional plain-text message body
 */
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        <div class="card-header"><h2 style="margin:0;font-size:1.25rem"><?php echo htmlspecialchars($this->title ?? 'Notice'); ?></h2></div>
        <div class="card-body" style="padding:24px">

            <?php if ($this->hasErrors()): ?>
                <div class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
            <?php endif; ?>
            <?php if ($this->hasMessages()): ?>
                <div class="alert alert-info"><?php echo $this->_printMessages(); ?></div>
            <?php endif; ?>

            <?php if (!empty($this->message)): ?>
                <p><?php echo htmlspecialchars($this->message); ?></p>
            <?php else: ?>
                <p style="color:#555">Your request has been submitted. If an account exists, you will receive instructions by email.</p>
            <?php endif; ?>

            <div style="text-align:center;margin-top:24px">
                <a href="<?php echo sURL; ?>Home/login" class="btn">Back to Login</a>
            </div>
        </div>
    </div>
</div>
