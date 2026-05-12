<?php
/**
 * Forgot password form (plain-CSS theme).
 *
 * Variables:
 *   $this->title — Optional page title override
 *   $this->error — Optional error string
 */
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        <div class="card-header"><h2 style="margin:0;font-size:1.25rem"><?php echo htmlspecialchars($this->title ?? 'Forgot Password'); ?></h2></div>
        <div class="card-body" style="padding:24px">

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
                <div style="margin-bottom:20px">
                    <label for="email" style="display:block;margin-bottom:4px;font-weight:500">Email Address</label>
                    <input type="email" name="email" id="email"
                           style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px"
                           required autocomplete="email">
                    <small style="color:#666">We will send a password reset link to this address.</small>
                </div>
                <button type="submit" class="btn" style="width:100%">Send Reset Link</button>
            </form>
            <div style="text-align:center;margin-top:12px">
                <a href="<?php echo sURL; ?>Home/login" style="font-size:13px">&larr; Back to login</a>
            </div>
        </div>
    </div>
</div>
