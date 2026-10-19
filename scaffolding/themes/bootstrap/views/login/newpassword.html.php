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
                    <?php $this->insert('../partials/auth_brand'); ?>
                    <h1 class="h4 mb-3"><?php echo htmlspecialchars(t('Set New Password'), ENT_QUOTES, 'UTF-8'); ?></h1>

                    <?php if (!empty($this->error)): ?>
                        <div role="alert" class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
                    <?php endif; ?>
                    <?php if ($this->hasErrors()): ?>
                        <div role="alert" class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
                    <?php endif; ?>
                    <?php if ($this->hasMessages()): ?>
                        <div role="status" class="alert alert-info"><?php echo $this->_printMessages(); ?></div>
                    <?php endif; ?>
                    <div id="formError" role="alert" class="alert alert-danger d-none"></div>

                    <form method="POST" action="<?php echo sURL; ?>Home/rpcsave" onsubmit="return validateNewPassword()">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <input type="hidden" name="userid" value="<?php echo (int) $this->user->userid; ?>">
                        <input type="hidden" name="reset" value="<?php echo htmlspecialchars($this->user->reset ?? ''); ?>">
                        <div class="mb-3">
                            <label for="password" class="form-label"><?php echo htmlspecialchars(t('New Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="password" name="password" id="password" class="form-control" required minlength="8"
                                   placeholder="<?php echo htmlspecialchars(t('At least 8 characters, a digit, and a symbol'), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'password', '', ''
                            ); ?>
                        </div>
                        <div class="mb-3">
                            <label for="repassword" class="form-label"><?php echo htmlspecialchars(t('Confirm Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="password" name="repassword" id="repassword" class="form-control" required
                                   placeholder="<?php echo htmlspecialchars(t('Repeat your new password'), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'repassword', '', ''
                            ); ?>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><?php echo htmlspecialchars(t('Save New Password'), ENT_QUOTES, 'UTF-8'); ?></button>
                    </form>
                    <div class="text-center mt-3">
                        <a href="<?php echo sURL; ?>login" class="small">&larr; <?php echo htmlspecialchars(t('Back to login'), ENT_QUOTES, 'UTF-8'); ?></a>
                    </div>
                </div>
            </div>
            <?php $this->insert('../partials/auth_home_link'); ?>
        </div>
    </div>
</div>
<script>
function validateNewPassword() {
    var pass = document.getElementById('password'), re = document.getElementById('repassword');
    var err = document.getElementById('formError');
    err.classList.add('d-none'); err.textContent = '';
    if (!/^(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(pass.value)) {
        err.textContent = <?php echo json_encode(t('Password must be at least 8 characters and contain a digit and a symbol.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        err.classList.remove('d-none'); pass.focus(); return false;
    }
    if (pass.value !== re.value) {
        err.textContent = <?php echo json_encode(t('Passwords do not match.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        err.classList.remove('d-none'); re.focus(); return false;
    }
    return true;
}
</script>
