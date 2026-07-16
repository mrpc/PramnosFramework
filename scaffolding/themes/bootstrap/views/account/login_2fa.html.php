<?php
/**
 * Built-in second-factor step-up (Bootstrap theme) — Account/LoginFlow flow.
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
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Two-step verification</h1>
                    <p class="text-muted small mb-3">Enter the 6-digit code from your authenticator app.</p>

                    <?php if ($errorText !== ''): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="<?php echo $base; ?>/verify">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <?php if (!empty($this->returnUrl)): ?>
                            <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                        <?php endif; ?>
                        <div class="mb-3">
                            <label for="code" class="form-label">Verification Code</label>
                            <input type="text" id="code" name="code" class="form-control text-center fs-4 font-monospace"
                                   maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                                   autocomplete="one-time-code" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" style="background-color:<?php echo $primary; ?>;border-color:<?php echo $primary; ?>">Verify &amp; Sign In</button>
                    </form>

                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer">Use a backup code instead</summary>
                        <form method="POST" action="<?php echo $base; ?>/verify" class="mt-2">
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <?php if (!empty($this->returnUrl)): ?>
                                <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                            <?php endif; ?>
                            <div class="mb-2">
                                <input type="text" name="code" class="form-control text-uppercase"
                                       maxlength="8" placeholder="XXXXXXXX" style="letter-spacing:.1em">
                            </div>
                            <button type="submit" class="btn btn-secondary w-100 btn-sm">Use Backup Code</button>
                        </form>
                    </details>

                    <p class="text-center small mt-3"><a href="<?php echo $base; ?>/login">&larr; Back to login</a></p>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('code').addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '');
    if (this.value.length === 6) { setTimeout(() => { if (this.value.length === 6) this.form.submit(); }, 100); }
});
</script>
