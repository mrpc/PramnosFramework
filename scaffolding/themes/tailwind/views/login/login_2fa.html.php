<?php
/**
 * Two-factor authentication form (Tailwind theme).
 *
 * Variables:
 *   $this->error        — Optional error string
 *   $this->username     — Username passed through (hidden)
 *   $this->tempPassword — Base64-encoded temporary password (hidden)
 *   $this->return       — Post-login redirect URL (hidden)
 */
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8">
        <h1 class="text-2xl font-semibold mb-1">Two-Factor Authentication</h1>
        <p class="text-sm text-gray-500 mb-6">Enter the 6-digit code from your authenticator app.</p>

        <?php if (!empty($this->error)): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded p-3 mb-4"><?php echo htmlspecialchars($this->error); ?></div>
        <?php endif; ?>

        <form method="POST" action="<?php echo sURL; ?>Home/login" class="space-y-4">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <input type="hidden" name="username" value="<?php echo htmlspecialchars($this->username ?? ''); ?>">
            <input type="hidden" name="password" value="<?php echo htmlspecialchars(base64_decode($this->tempPassword ?? '')); ?>">
            <?php if (!empty($this->return)): ?>
                <input type="hidden" name="return" value="<?php echo htmlspecialchars($this->return); ?>">
            <?php endif; ?>
            <div>
                <label for="totp_code" class="block text-sm font-medium text-gray-700 mb-1">Verification Code</label>
                <input type="text" id="totp_code" name="totp_code"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-center text-2xl font-mono tracking-widest focus:outline-none focus:ring-2 focus:ring-blue-500"
                       maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                       autocomplete="one-time-code" required autofocus>
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition-colors">Verify &amp; Sign In</button>
        </form>

        <details class="mt-4">
            <summary class="text-sm text-gray-500 cursor-pointer">Use a backup code instead</summary>
            <form method="POST" action="<?php echo sURL; ?>Home/login" class="mt-3 space-y-2">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="username" value="<?php echo htmlspecialchars($this->username ?? ''); ?>">
                <input type="hidden" name="password" value="<?php echo htmlspecialchars(base64_decode($this->tempPassword ?? '')); ?>">
                <input type="text" name="totp_code"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-gray-400"
                       maxlength="8" placeholder="XXXXXXXX">
                <button type="submit" class="w-full bg-gray-600 hover:bg-gray-700 text-white text-sm py-2 px-4 rounded-md transition-colors">Use Backup Code</button>
            </form>
        </details>

        <p class="text-center text-sm mt-4">
            <a href="<?php echo sURL; ?>Home/login" class="text-blue-600 hover:underline">&larr; Back to login</a>
        </p>
    </div>
</div>
<script>
document.getElementById('totp_code').addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '');
    if (this.value.length === 6) { setTimeout(() => { if (this.value.length === 6) this.form.submit(); }, 100); }
});
</script>
