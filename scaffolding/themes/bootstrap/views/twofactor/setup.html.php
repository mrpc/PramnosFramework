<?php
/**
 * Two-Factor Authentication setup page.
 *
 * Variables:
 *   $this->setupData — array {
 *       secret: string,
 *       qr_code_url: string,      — URL of QR code image (api.qrserver.com)
 *       manual_entry_key: string, — base32 secret for manual entry
 *       backup_codes: string[],   — plain-text codes shown once
 *   }
 *   $this->user — User object
 */
?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-7">

        <h2 class="mb-1">Set Up Two-Factor Authentication</h2>
        <p class="text-muted mb-4">Follow the steps below to secure your account.</p>

        <?php if (!empty($_GET['error'])): ?>
            <div class="alert alert-danger">
                <?php
                $messages = [
                    'invalid_code'  => 'The code was invalid or expired. Please try again.',
                    'code_required' => 'Please enter the 6-digit code from your authenticator app.',
                ];
                echo htmlspecialchars($messages[$_GET['error']] ?? 'An error occurred.');
                ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Scan QR code -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">Step 1 — Scan the QR code</div>
            <div class="card-body text-center">
                <img src="<?php echo htmlspecialchars($this->setupData['qr_code_url']); ?>"
                     alt="QR Code" width="200" height="200" class="mb-3 border rounded p-2">

                <p class="text-muted small mb-2">
                    Open your authenticator app (Google Authenticator, Authy, etc.) and scan this QR code.
                </p>

                <details class="mt-2">
                    <summary class="btn btn-link btn-sm p-0">Can't scan? Enter the key manually</summary>
                    <div class="mt-2">
                        <code class="user-select-all fs-6 d-block py-2 bg-light rounded border px-3">
                            <?php echo htmlspecialchars($this->setupData['manual_entry_key']); ?>
                        </code>
                        <p class="text-muted small mt-1">Type: Time-based (TOTP) &nbsp;|&nbsp; Digits: 6 &nbsp;|&nbsp; Interval: 30s</p>
                    </div>
                </details>
            </div>
        </div>

        <!-- Step 2: Save backup codes -->
        <?php if (!empty($this->setupData['backup_codes'])): ?>
        <div class="card mb-4 border-warning">
            <div class="card-header bg-warning-subtle fw-semibold">Step 2 — Save your backup codes</div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Store these codes in a safe place. Each code can be used once if you lose access to your authenticator app.
                    <strong>They will not be shown again.</strong>
                </p>
                <div class="row row-cols-2 g-2 mb-3">
                    <?php foreach ($this->setupData['backup_codes'] as $code): ?>
                        <div class="col">
                            <code class="d-block text-center border rounded py-1 bg-light">
                                <?php echo htmlspecialchars($code); ?>
                            </code>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Step 3: Verify -->
        <div class="card">
            <div class="card-header fw-semibold">Step 3 — Verify</div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Enter the 6-digit code shown in your authenticator app to confirm setup.
                </p>
                <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/setup">
                    <div class="mb-3">
                        <label for="verify_code" class="form-label">Authenticator code</label>
                        <input type="text" class="form-control form-control-lg"
                               id="verify_code" name="verify_code"
                               inputmode="numeric" pattern="\d{6}" maxlength="6"
                               placeholder="000000" required autofocus autocomplete="one-time-code">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Activate 2FA</button>
                        <a href="<?php echo sURL; ?>TwoFactorAuth" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>
