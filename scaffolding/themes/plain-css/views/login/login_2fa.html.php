<?php
/**
 * Two-factor authentication form (plain-CSS theme).
 *
 * Variables:
 *   $this->error        — Optional error string
 *   $this->username     — Username passed through (hidden)
 *   $this->tempPassword — Base64-encoded temporary password (hidden)
 *   $this->return       — Post-login redirect URL (hidden)
 */
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:60vh;padding:20px">
    <div class="card" style="width:100%;max-width:400px">
        <div class="card-header">
            <h2 style="margin:0;font-size:1.25rem">Two-Factor Authentication</h2>
            <p style="margin:4px 0 0;font-size:13px;color:#666">Enter the 6-digit code from your authenticator app.</p>
        </div>
        <div class="card-body" style="padding:24px">

            <?php if (!empty($this->error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
            <?php endif; ?>

            <form method="POST" action="<?php echo sURL; ?>Home/login">
                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                <input type="hidden" name="username" value="<?php echo htmlspecialchars($this->username ?? ''); ?>">
                <input type="hidden" name="password" value="<?php echo htmlspecialchars(base64_decode($this->tempPassword ?? '')); ?>">
                <?php if (!empty($this->return)): ?>
                    <input type="hidden" name="return" value="<?php echo htmlspecialchars($this->return); ?>">
                <?php endif; ?>
                <div style="margin-bottom:20px">
                    <label for="totp_code" style="display:block;margin-bottom:4px;font-weight:500">Verification Code</label>
                    <input type="text" id="totp_code" name="totp_code"
                           style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;font-family:monospace;font-size:22px;text-align:center;letter-spacing:.15em"
                           maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                           autocomplete="one-time-code" required autofocus>
                </div>
                <button type="submit" class="btn" style="width:100%">Verify &amp; Sign In</button>
            </form>

            <details style="margin-top:16px">
                <summary style="font-size:13px;color:#666;cursor:pointer">Use a backup code instead</summary>
                <form method="POST" action="<?php echo sURL; ?>Home/login" style="margin-top:8px">
                    <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                    <input type="hidden" name="username" value="<?php echo htmlspecialchars($this->username ?? ''); ?>">
                    <input type="hidden" name="password" value="<?php echo htmlspecialchars(base64_decode($this->tempPassword ?? '')); ?>">
                    <input type="text" name="totp_code"
                           style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box;text-transform:uppercase;letter-spacing:.1em;font-size:15px;margin-bottom:8px"
                           maxlength="8" placeholder="XXXXXXXX">
                    <button type="submit" class="btn btn-sm" style="width:100%">Use Backup Code</button>
                </form>
            </details>

            <div style="text-align:center;margin-top:12px">
                <a href="<?php echo sURL; ?>Home/login" style="font-size:13px">&larr; Back to login</a>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('totp_code').addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '');
    if (this.value.length === 6) { setTimeout(() => { if (this.value.length === 6) this.form.submit(); }, 100); }
});
</script>
