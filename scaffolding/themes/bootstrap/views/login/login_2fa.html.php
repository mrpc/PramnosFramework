<?php
/**
 * Two-factor authentication form (Bootstrap theme).
 *
 * Variables:
 *   $this->error        — Optional error string
 *   $this->username     — Username passed through (hidden)
 *   $this->tempPassword — Base64-encoded temporary password (hidden)
 *   $this->return       — Post-login redirect URL (hidden)
 */
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Two-Factor Authentication</h1>
                    <p class="text-muted small mb-3">Enter the 6-digit code from your authenticator app.</p>

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
                        <div class="mb-3">
                            <label for="totp_code" class="form-label">Verification Code</label>
                            <input type="text" id="totp_code" name="totp_code" class="form-control text-center fs-4 font-monospace"
                                   maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                                   autocomplete="one-time-code" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Verify &amp; Sign In</button>
                    </form>

                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer">Use a backup code instead</summary>
                        <form method="POST" action="<?php echo sURL; ?>Home/login" class="mt-2">
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <input type="hidden" name="username" value="<?php echo htmlspecialchars($this->username ?? ''); ?>">
                            <input type="hidden" name="password" value="<?php echo htmlspecialchars(base64_decode($this->tempPassword ?? '')); ?>">
                            <div class="mb-2">
                                <input type="text" name="totp_code" class="form-control text-uppercase"
                                       maxlength="8" placeholder="XXXXXXXX" style="letter-spacing:.1em">
                            </div>
                            <button type="submit" class="btn btn-secondary w-100 btn-sm">Use Backup Code</button>
                        </form>
                    </details>

                    <p class="text-center small mt-3"><a href="<?php echo sURL; ?>Home/login">&larr; Back to login</a></p>
                </div>
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
