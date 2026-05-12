<?php
/**
 * Forgot password form (Bootstrap theme).
 *
 * Variables:
 *   $this->title — Optional page title override
 *   $this->error — Optional error string
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3"><?php echo htmlspecialchars($this->title ?? 'Forgot Password'); ?></h1>

                    <?php if (!empty($this->error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
                    <?php endif; ?>
                    <?php if ($this->hasErrors()): ?>
                        <div class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
                    <?php endif; ?>
                    <?php if ($this->hasMessages()): ?>
                        <div class="alert alert-info"><?php echo $this->_printMessages(); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="<?php echo sURL; ?>Home/forgotpasswordsubmit">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" name="email" id="email" class="form-control" required autocomplete="email">
                            <div class="form-text">We will send a password reset link to this address.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Send Reset Link</button>
                    </form>
                    <div class="text-center mt-3">
                        <a href="<?php echo sURL; ?>Home/login" class="small">&larr; Back to login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
