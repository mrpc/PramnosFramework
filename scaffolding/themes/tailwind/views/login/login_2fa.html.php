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
$offerPasskey = in_array('passkey', (array) ($this->methods ?? []), true);
?>
<div class="flex items-center justify-center min-h-screen bg-base-200 px-4">
    <div class="card bg-base-100 shadow-md p-8 w-full max-w-sm" style="--color-primary:<?php echo $primary; ?>">
        <h1 class="text-2xl font-semibold mb-1">Two-step verification</h1>
        <p class="text-sm text-base-content/70 mb-6">Enter the 6-digit code from your authenticator app.</p>

        <?php if ($errorText !== ''): ?>
            <div class="alert alert-error mb-4"><?php echo htmlspecialchars($errorText); ?></div>
        <?php endif; ?>

        <form method="POST" action="<?php echo $base; ?>/verify" class="space-y-4">
            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
            <?php if (!empty($this->returnUrl)): ?>
                <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
            <?php endif; ?>
            <div>
                <label for="code" class="block text-sm font-medium text-base-content mb-1">Verification Code</label>
                <input type="text" id="code" name="code" data-pf-otp
                       class="input w-full text-center text-2xl font-mono tracking-widest"
                       maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                       autocomplete="one-time-code" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary w-full">Verify &amp; Sign In</button>
        </form>

        <?php if ($offerPasskey): ?>
        <div class="text-center text-sm text-base-content/60 my-4">or</div>
        <button type="button" class="btn btn-neutral w-full"
                data-pf-passkey-stepup
                data-options-url="<?php echo $base; ?>/passkeyOptions"
                data-verify-url="<?php echo $base; ?>/passkeyVerify"
                data-redirect="<?php echo sURL; ?>"
                data-error="#passkey-error">Use a passkey</button>
        <p id="passkey-error" class="text-error text-sm mt-2 hidden"></p>
        <?php endif; ?>

        <details class="mt-4">
            <summary class="text-sm text-base-content/70 cursor-pointer">Use a backup code instead</summary>
            <form method="POST" action="<?php echo $base; ?>/verify" class="mt-3 space-y-2">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php if (!empty($this->returnUrl)): ?>
                    <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                <?php endif; ?>
                <input type="text" name="code"
                       class="input w-full uppercase tracking-widest"
                       maxlength="8" placeholder="XXXXXXXX">
                <button type="submit" class="btn btn-neutral btn-sm w-full">Use Backup Code</button>
            </form>
        </details>

        <p class="text-center text-sm mt-4">
            <a href="<?php echo $base; ?>/login" class="text-primary hover:underline">&larr; Back to login</a>
        </p>
    </div>
</div>
<script src="<?php echo sURL; ?>assets/js/pf-webauthn.js"></script>
<script src="<?php echo sURL; ?>assets/js/pf-auth.js"></script>
