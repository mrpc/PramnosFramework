<?php
/**
 * Set new password form (Bootstrap theme).
 *
 * Variables:
 *   $this->error      — Optional error string
 *   $this->user->userid — User ID for the reset token
 *   $this->user->reset  — Reset token
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Set New Password</h1>

                    <?php if (!empty($this->error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
                    <?php endif; ?>
                    <?php if ($this->hasErrors()): ?>
                        <div class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
                    <?php endif; ?>
                    <?php if ($this->hasMessages()): ?>
                        <div class="alert alert-info"><?php echo $this->_printMessages(); ?></div>
                    <?php endif; ?>
                    <div id="formError" class="alert alert-danger d-none"></div>

                    <form method="POST" action="<?php echo sURL; ?>Home/rpcsave" onsubmit="return validateNewPassword()">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="userid" value="<?php echo (int) $this->user->userid; ?>">
                        <input type="hidden" name="reset" value="<?php echo htmlspecialchars($this->user->reset ?? ''); ?>">
                        <div class="mb-3">
                            <label for="password" class="form-label">New Password</label>
                            <input type="password" name="password" id="password" class="form-control" required minlength="8"
                                   placeholder="At least 8 characters, a digit, and a symbol">
                        </div>
                        <div class="mb-3">
                            <label for="repassword" class="form-label">Confirm Password</label>
                            <input type="password" name="repassword" id="repassword" class="form-control" required
                                   placeholder="Repeat your new password">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Save New Password</button>
                    </form>
                    <div class="text-center mt-3">
                        <a href="<?php echo sURL; ?>Home/login" class="small">&larr; Back to login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
function validateNewPassword() {
    var pass = document.getElementById('password'), re = document.getElementById('repassword');
    var err = document.getElementById('formError');
    err.classList.add('d-none'); err.textContent = '';
    if (!/^(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(pass.value)) {
        err.textContent = 'Password must be at least 8 characters and contain a digit and a symbol.';
        err.classList.remove('d-none'); pass.focus(); return false;
    }
    if (pass.value !== re.value) {
        err.textContent = 'Passwords do not match.';
        err.classList.remove('d-none'); re.focus(); return false;
    }
    return true;
}
</script>
