<?php
/**
 * Built-in second-factor step-up (plain-CSS theme) — Account/LoginFlow flow.
 *
 * Variables (set by Pramnos\Auth\Controllers\Account::renderStepUp):
 *   $this->routeBase     — controller route base (form action prefix)
 *   $this->brand         — [name, logo, primary_color, footer]
 *   $this->error         — optional error key (mapped below)
 *   $this->returnUrl     — post-login redirect (hidden field)
 *   $this->pendingUserId — user awaiting the second factor (informational)
 *   $this->methods       — offered step-up methods (e.g. ['twofactor','passkey'])
 *
 * The password is NOT present here — LoginFlow holds the pending login
 * server-side. This form submits only the code to <routeBase>/verify.
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
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        <div class="card-header">
            <h2 style="margin:0;font-size:1.25rem">Two-step verification</h2>
            <p style="margin:4px 0 0;font-size:13px;color:#666">Enter the 6-digit code from your authenticator app.</p>
        </div>
        <div class="card-body" style="padding:24px">

            <?php if ($errorText !== ''): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($errorText); ?></div>
            <?php endif; ?>

            <form method="POST" action="<?php echo $base; ?>/verify">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <?php if (!empty($this->returnUrl)): ?>
                    <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                <?php endif; ?>
                <div style="margin-bottom:20px">
                    <label for="code" style="display:block;margin-bottom:4px;font-weight:500">Verification Code</label>
                    <input type="text" id="code" name="code"
                           style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-family:monospace;font-size:22px;text-align:center;letter-spacing:.15em"
                           maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                           autocomplete="one-time-code" required autofocus>
                </div>
                <button type="submit" class="btn" style="width:100%;background-color:<?php echo $primary; ?>;border-color:<?php echo $primary; ?>">Verify &amp; Sign In</button>
            </form>

            <?php if ($offerPasskey): ?>
            <div style="text-align:center;margin:16px 0;color:#888;font-size:13px">or</div>
            <button type="button" id="passkey-stepup" class="btn" style="width:100%;background-color:#374151;border-color:#374151">Use a passkey</button>
            <p id="passkey-error" style="color:#b91c1c;font-size:13px;margin-top:8px;display:none"></p>
            <?php endif; ?>

            <details style="margin-top:16px">
                <summary style="font-size:13px;color:#666;cursor:pointer">Use a backup code instead</summary>
                <form method="POST" action="<?php echo $base; ?>/verify" style="margin-top:8px">
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <?php if (!empty($this->returnUrl)): ?>
                        <input type="hidden" name="return" value="<?php echo htmlspecialchars((string) $this->returnUrl); ?>">
                    <?php endif; ?>
                    <input type="text" name="code"
                           style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;text-transform:uppercase;letter-spacing:.1em;font-size:15px;margin-bottom:8px"
                           maxlength="8" placeholder="XXXXXXXX">
                    <button type="submit" class="btn btn-sm" style="width:100%">Use Backup Code</button>
                </form>
            </details>

            <div style="text-align:center;margin-top:12px">
                <a href="<?php echo $base; ?>/login" style="font-size:13px">&larr; Back to login</a>
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
<?php if ($offerPasskey): ?>
<script>
(function () {
    var btn = document.getElementById('passkey-stepup');
    if (!btn) { return; }
    // Progressive enhancement: hide the button if WebAuthn / the glue is absent.
    if (!window.PramnosWebAuthn || !window.PramnosWebAuthn.supported()) { btn.style.display = 'none'; return; }
    var base = <?php echo json_encode($base); ?>;
    var home = <?php echo json_encode(sURL); ?>;
    btn.addEventListener('click', function () {
        btn.disabled = true;
        window.PramnosWebAuthn.authenticate(base + '/passkeyOptions', base + '/passkeyVerify')
            .then(function (r) { window.location = r.redirect || home; })
            .catch(function () {
                btn.disabled = false;
                var e = document.getElementById('passkey-error');
                if (e) { e.style.display = 'block'; e.textContent = 'Passkey sign-in failed. Use your code instead.'; }
            });
    });
})();
</script>
<?php endif; ?>
