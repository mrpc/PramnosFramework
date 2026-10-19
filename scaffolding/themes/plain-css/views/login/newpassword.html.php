<?php
/**
 * Set new password form (plain-CSS theme).
 *
 * Variables:
 *   $this->error      — Optional error string
 *   $this->user->userid — User ID for the reset token
 *   $this->user->reset  — Reset token
 */
?>
<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        <?php $this->insert('../partials/auth_brand'); ?>
        <div class="card-header"><h2 style="margin:0;font-size:1.25rem"><?php echo htmlspecialchars(t('Set New Password'), ENT_QUOTES, 'UTF-8'); ?></h2></div>
        <div class="card-body" style="padding:24px">

            <?php if (!empty($this->error)): ?>
                <div role="alert" class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
            <?php endif; ?>
            <?php if ($this->hasErrors()): ?>
                <div role="alert" class="alert alert-danger"><?php echo $this->_printErrors(); ?></div>
            <?php endif; ?>
            <?php if ($this->hasMessages()): ?>
                <div role="status" class="alert alert-info"><?php echo $this->_printMessages(); ?></div>
            <?php endif; ?>
            <div id="formError" role="alert" class="alert alert-danger" style="display:none"></div>

            <form method="POST" action="<?php echo sURL; ?>Home/rpcsave" onsubmit="return validateNewPassword()">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="userid" value="<?php echo (int) $this->user->userid; ?>">
                <input type="hidden" name="reset" value="<?php echo htmlspecialchars($this->user->reset ?? ''); ?>">
                <div style="margin-bottom:16px">
                    <label for="password" style="display:block;margin-bottom:4px;font-weight:500"><?php echo htmlspecialchars(t('New Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="password" name="password" id="password"
                           style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px"
                           required minlength="8" placeholder="<?php echo htmlspecialchars(t('At least 8 chars, digit and symbol'), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo \Pramnos\Html\PasswordToggle::render(
                        'password', '', ''
                    ); ?>
                </div>
                <div style="margin-bottom:20px">
                    <label for="repassword" style="display:block;margin-bottom:4px;font-weight:500"><?php echo htmlspecialchars(t('Confirm Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                    <input type="password" name="repassword" id="repassword"
                           style="width:100%;padding:8px 12px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-size:15px"
                           required>
                    <?php echo \Pramnos\Html\PasswordToggle::render(
                        'repassword', '', ''
                    ); ?>
                </div>
                <button type="submit" class="btn" style="width:100%"><?php echo htmlspecialchars(t('Save New Password'), ENT_QUOTES, 'UTF-8'); ?></button>
            </form>
            <div style="text-align:center;margin-top:12px">
                <a href="<?php echo sURL; ?>login" style="font-size:13px">&larr; <?php echo htmlspecialchars(t('Back to login'), ENT_QUOTES, 'UTF-8'); ?></a>
            </div>
        </div>
    </div>
    <?php $this->insert('../partials/auth_home_link'); ?>
</div>
<script>
function validateNewPassword() {
    var pass = document.getElementById('password'), re = document.getElementById('repassword');
    var err = document.getElementById('formError');
    err.style.display = 'none'; err.textContent = '';
    if (!/^(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(pass.value)) {
        err.textContent = <?php echo json_encode(t('Password must be at least 8 characters and contain a digit and a symbol.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        err.style.display = 'block'; pass.focus(); return false;
    }
    if (pass.value !== re.value) {
        err.textContent = <?php echo json_encode(t('Passwords do not match.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        err.style.display = 'block'; re.focus(); return false;
    }
    return true;
}
</script>
