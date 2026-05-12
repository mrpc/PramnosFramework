<?php
/**
 * Generic login-flow message page (Bootstrap theme).
 *
 * Variables:
 *   $this->title   — Optional page title override
 *   $this->message — Optional plain-text message body
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3"><?php echo htmlspecialchars($this->title ?? 'Notice'); ?></h1>

                    <?php if ($this->hasErrors()): ?>
                        <div class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
                    <?php endif; ?>
                    <?php if ($this->hasMessages()): ?>
                        <div class="alert alert-info"><?php echo $this->_printMessages(); ?></div>
                    <?php endif; ?>

                    <?php if (!empty($this->message)): ?>
                        <p><?php echo htmlspecialchars($this->message); ?></p>
                    <?php else: ?>
                        <p class="text-muted">Your request has been submitted. If an account exists, you will receive instructions by email.</p>
                    <?php endif; ?>

                    <div class="text-center mt-4">
                        <a href="<?php echo sURL; ?>Home/login" class="btn btn-primary">Back to Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
