<?php
/**
 * Built-in second-factor step-up (Tailwind theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderStepUp):
 *   $this->routeBase, $this->brand, $this->error, $this->returnUrl,
 *   $this->pendingUserId, $this->methods
 *
 * No password here — LoginFlow holds the pending login server-side. The form
 * submits only the code to <routeBase>/verify.
 */
$brand   = $this->brand ?? [];
$primary = htmlspecialchars((string) ($brand['primary_color'] ?? '#2563eb'), ENT_QUOTES);
$base    = sURL . rawurlencode((string) ($this->routeBase ?? 'Account'));

$errorMessages = [
    'invalid_token' => 'Your session expired. Please try again.',
    'missing_code'  => 'Please enter your verification code.',
    'invalid_code'  => 'Invalid or expired code. Please try again.',
];
$errorKey  = (string) ($this->error ?? '');
$errorText = $errorMessages[$errorKey] ?? $errorKey;
?>
<div class="flex items-center justify-center min-h-screen bg-gray-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-8">
        <h1 class="text-2xl font-semibold mb-1">Two-step verification</h1>
        <p class="text-sm text-gray-500 mb-6">Enter the 6-digit code from your authenticator app.</p>

        <?php if ($errorText !== ''): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 rounded-sm p-3 mb-4"><?php echo htmlspecialchars($errorText); ?></div>
        <?php endif; ?>

        <form method="POST" action="<?php echo $base; ?>/verify" class="space-y-4">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <?php if (!empty($this->returnUrl)): ?>
                <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
            <?php endif; ?>
            <div>
                <label for="code" class="block text-sm font-medium text-gray-700 mb-1">Verification Code</label>
                <input type="text" id="code" name="code"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-center text-2xl font-mono tracking-widest focus:outline-hidden focus:ring-2 focus:ring-blue-500"
                       maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                       autocomplete="one-time-code" required autofocus>
            </div>
            <button type="submit" class="w-full text-white font-medium py-2 px-4 rounded-md transition-colors" style="background-color:<?php echo $primary; ?>">Verify &amp; Sign In</button>
        </form>

        <details class="mt-4">
            <summary class="text-sm text-gray-500 cursor-pointer">Use a backup code instead</summary>
            <form method="POST" action="<?php echo $base; ?>/verify" class="mt-3 space-y-2">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php if (!empty($this->returnUrl)): ?>
                    <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                <?php endif; ?>
                <input type="text" name="code"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 uppercase tracking-widest focus:outline-hidden focus:ring-2 focus:ring-gray-400"
                       maxlength="8" placeholder="XXXXXXXX">
                <button type="submit" class="w-full bg-gray-600 hover:bg-gray-700 text-white text-sm py-2 px-4 rounded-md transition-colors">Use Backup Code</button>
            </form>
        </details>

        <p class="text-center text-sm mt-4">
            <a href="<?php echo $base; ?>/login" class="text-blue-600 hover:underline">&larr; Back to login</a>
        </p>
    </div>
</div>
<script>
document.getElementById('code').addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '');
    if (this.value.length === 6) { setTimeout(() => { if (this.value.length === 6) this.form.submit(); }, 100); }
});
</script>
