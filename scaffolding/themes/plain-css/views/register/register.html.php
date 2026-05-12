<?php
/**
 * User registration form (plain-CSS theme).
 *
 * Variables:
 *   $this->header   — Page heading
 *   $this->error    — Optional error string
 *   $this->formData — array of previously submitted field values (username, email)
 */
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:70vh;padding:20px">
    <div class="card" style="width:100%;max-width:460px">
        <div class="card-header"><h2 style="margin:0;font-size:1.25rem"><?php echo htmlspecialchars($this->header ?? 'Create Account'); ?></h2></div>
        <div class="card-body" style="padding:24px">

            <?php if (!empty($this->error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
            <?php endif; ?>
            <?php if ($this->hasErrors()): ?>
                <div class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
            <?php endif; ?>

            <form method="POST" action="<?php echo sURL; ?>Home/register">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php
                $inputStyle = 'width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px';
                $labelStyle = 'display:block;margin-bottom:4px;font-weight:500';
                ?>
                <div style="margin-bottom:14px">
                    <label for="username" style="<?php echo $labelStyle; ?>">Username</label>
                    <input type="text" name="username" id="username" style="<?php echo $inputStyle; ?>" required autocomplete="username"
                           value="<?php echo htmlspecialchars($this->formData['username'] ?? ''); ?>">
                </div>
                <div style="margin-bottom:14px">
                    <label for="email" style="<?php echo $labelStyle; ?>">Email Address</label>
                    <input type="email" name="email" id="email" style="<?php echo $inputStyle; ?>" required autocomplete="email"
                           value="<?php echo htmlspecialchars($this->formData['email'] ?? ''); ?>">
                </div>
                <div style="margin-bottom:14px">
                    <label for="password" style="<?php echo $labelStyle; ?>">Password</label>
                    <input type="password" name="password" id="password" style="<?php echo $inputStyle; ?>" required minlength="6" autocomplete="new-password">
                    <small style="color:#666">At least 6 characters.</small>
                </div>
                <div style="margin-bottom:20px">
                    <label for="confirm_password" style="<?php echo $labelStyle; ?>">Confirm Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" style="<?php echo $inputStyle; ?>" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn" style="width:100%;background:#27ae60">Create Account</button>
            </form>
            <div style="text-align:center;margin-top:12px">
                <a href="<?php echo sURL; ?>Home/login" style="font-size:13px">Already have an account? Sign in</a>
            </div>
        </div>
    </div>
</div>
