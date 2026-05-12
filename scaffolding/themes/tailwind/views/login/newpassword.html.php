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
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8">
        <h1 class="text-2xl font-semibold mb-6">Set New Password</h1>

        <?php if (!empty($this->error)): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded p-3 mb-4"><?php echo htmlspecialchars($this->error); ?></div>
        <?php endif; ?>
        <?php if ($this->hasErrors()): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded p-3 mb-4"><?php echo $this->_printErrors(); ?></div>
        <?php endif; ?>
        <?php if ($this->hasMessages()): ?>
            <div class="bg-blue-100 border border-blue-300 text-blue-800 rounded p-3 mb-4"><?php echo $this->_printMessages(); ?></div>
        <?php endif; ?>
        <div id="formError" class="bg-red-100 border border-red-300 text-red-800 rounded p-3 mb-4 hidden"></div>

        <form method="POST" action="<?php echo sURL; ?>Home/rpcsave" class="space-y-4" onsubmit="return validateNewPassword()">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <input type="hidden" name="userid" value="<?php echo (int) $this->user->userid; ?>">
            <input type="hidden" name="reset" value="<?php echo htmlspecialchars($this->user->reset ?? ''); ?>">
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                <input type="password" name="password" id="password"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                       required minlength="8" placeholder="At least 8 chars, digit and symbol">
            </div>
            <div>
                <label for="repassword" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                <input type="password" name="repassword" id="repassword"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                       required>
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition-colors">Save New Password</button>
        </form>
        <p class="text-center text-sm mt-4">
            <a href="<?php echo sURL; ?>Home/login" class="text-blue-600 hover:underline">&larr; Back to login</a>
        </p>
    </div>
</div>
<script>
function validateNewPassword() {
    var pass = document.getElementById('password'), re = document.getElementById('repassword');
    var err = document.getElementById('formError');
    err.classList.add('hidden'); err.textContent = '';
    if (!/^(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(pass.value)) {
        err.textContent = 'Password must be at least 8 characters and contain a digit and a symbol.';
        err.classList.remove('hidden'); pass.focus(); return false;
    }
    if (pass.value !== re.value) {
        err.textContent = 'Passwords do not match.';
        err.classList.remove('hidden'); re.focus(); return false;
    }
    return true;
}
</script>
