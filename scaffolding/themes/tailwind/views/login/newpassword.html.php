<?php
/**
 * Set new password form (Tailwind theme).
 *
 * Variables:
 *   $this->error      — Optional error string
 *   $this->user->userid — User ID for the reset token
 *   $this->user->reset  — Reset token
 */
?>
<div class="flex flex-col items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm">
        <?php $this->insert('../partials/auth_brand'); ?>
        <h1 class="text-2xl font-semibold mb-6"><?php echo htmlspecialchars(t('Set New Password'), ENT_QUOTES, 'UTF-8'); ?></h1>

        <?php if (!empty($this->error)): ?>
            <div role="alert" class="alert alert-error mb-4"><?php echo htmlspecialchars($this->error); ?></div>
        <?php endif; ?>
        <?php if ($this->hasErrors()): ?>
            <div role="alert" class="alert alert-error mb-4"><?php echo $this->_printErrors(); ?></div>
        <?php endif; ?>
        <?php if ($this->hasMessages()): ?>
            <div role="status" class="alert alert-info mb-4"><?php echo $this->_printMessages(); ?></div>
        <?php endif; ?>
        <div id="formError" role="alert" class="alert alert-error mb-4 hidden"></div>

        <form method="POST" action="<?php echo sURL; ?>Home/rpcsave" class="space-y-4" onsubmit="return validateNewPassword()">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <input type="hidden" name="userid" value="<?php echo (int) $this->user->userid; ?>">
            <input type="hidden" name="reset" value="<?php echo htmlspecialchars($this->user->reset ?? ''); ?>">
            <div>
                <label for="password" class="block text-sm font-medium text-base-content mb-1"><?php echo htmlspecialchars(t('New Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="password" name="password" id="password"
                       class="input w-full"
                       required minlength="8" placeholder="<?php echo htmlspecialchars(t('At least 8 chars, digit and symbol'), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo \Pramnos\Html\PasswordToggle::render(
                    'password', '', ''
                ); ?>
            </div>
            <div>
                <label for="repassword" class="block text-sm font-medium text-base-content mb-1"><?php echo htmlspecialchars(t('Confirm Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input type="password" name="repassword" id="repassword"
                       class="input w-full"
                       required>
                <?php echo \Pramnos\Html\PasswordToggle::render(
                    'repassword', '', ''
                ); ?>
            </div>
            <button type="submit" class="btn btn-primary w-full"><?php echo htmlspecialchars(t('Save New Password'), ENT_QUOTES, 'UTF-8'); ?></button>
        </form>
        <p class="text-center text-sm mt-4">
            <a href="<?php echo sURL; ?>login" class="text-primary hover:underline">&larr; <?php echo htmlspecialchars(t('Back to login'), ENT_QUOTES, 'UTF-8'); ?></a>
        </p>
    </div>
    <?php $this->insert('../partials/auth_home_link'); ?>
</div>
<script>
function validateNewPassword() {
    var pass = document.getElementById('password'), re = document.getElementById('repassword');
    var err = document.getElementById('formError');
    err.classList.add('hidden'); err.textContent = '';
    if (!/^(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(pass.value)) {
        err.textContent = <?php echo json_encode(t('Password must be at least 8 characters and contain a digit and a symbol.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        err.classList.remove('hidden'); pass.focus(); return false;
    }
    if (pass.value !== re.value) {
        err.textContent = <?php echo json_encode(t('Passwords do not match.'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        err.classList.remove('hidden'); re.focus(); return false;
    }
    return true;
}
</script>
