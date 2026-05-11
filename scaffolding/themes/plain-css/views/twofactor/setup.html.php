<?php
/**
 * Two-Factor Authentication setup page (plain-CSS theme).
 *
 * Variables:
 *   $this->setupData — array {
 *       secret: string,
 *       qr_code_url: string,
 *       manual_entry_key: string,
 *       backup_codes: string[],
 *   }
 *   $this->user — User object
 */
?>
<div class="page-section" style="max-width:600px;margin:0 auto">

    <h2>Set Up Two-Factor Authentication</h2>

    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-error">
            <?php
            $messages = [
                'invalid_code'  => 'The code was invalid or expired. Please try again.',
                'code_required' => 'Please enter the 6-digit code from your authenticator app.',
            ];
            echo htmlspecialchars($messages[$_GET['error']] ?? 'An error occurred.');
            ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><strong>Step 1 — Scan the QR code</strong></div>
        <div class="card-body" style="text-align:center">
            <img src="<?php echo htmlspecialchars($this->setupData['qr_code_url']); ?>"
                 alt="QR Code" width="200" height="200" style="border:1px solid #ddd;padding:8px;border-radius:4px">
            <p style="font-size:.9em;color:#666;margin-top:8px">
                Open your authenticator app (Google Authenticator, Authy, etc.) and scan this QR code.
            </p>
            <details style="text-align:left;margin-top:8px">
                <summary style="cursor:pointer;font-size:.85em;color:#555">Can't scan? Enter the key manually</summary>
                <div style="margin-top:8px;background:#f5f5f5;border-radius:4px;padding:10px">
                    <code style="word-break:break-all"><?php echo htmlspecialchars($this->setupData['manual_entry_key']); ?></code>
                    <p style="font-size:.8em;color:#888;margin:4px 0 0">Type: Time-based (TOTP) &nbsp;|&nbsp; Digits: 6 &nbsp;|&nbsp; Interval: 30s</p>
                </div>
            </details>
        </div>
    </div>

    <?php if (!empty($this->setupData['backup_codes'])): ?>
    <div class="card" style="border-color:#f0ad4e;margin-top:16px">
        <div class="card-header" style="background:#fcf8e3"><strong>Step 2 — Save your backup codes</strong></div>
        <div class="card-body">
            <p style="font-size:.9em;color:#666">
                Store these codes in a safe place. Each can be used once.
                <strong>They will not be shown again.</strong>
            </p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">
                <?php foreach ($this->setupData['backup_codes'] as $code): ?>
                    <code style="display:block;text-align:center;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;padding:6px">
                        <?php echo htmlspecialchars($code); ?>
                    </code>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card" style="margin-top:16px">
        <div class="card-header"><strong>Step 3 — Verify</strong></div>
        <div class="card-body">
            <p style="font-size:.9em;color:#666;margin-bottom:12px">
                Enter the 6-digit code shown in your authenticator app to confirm setup.
            </p>
            <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/setup">
                <div class="form-group">
                    <label for="verify_code">Authenticator code</label>
                    <input type="text" id="verify_code" name="verify_code"
                           inputmode="numeric" pattern="\d{6}" maxlength="6"
                           placeholder="000000" required autofocus autocomplete="one-time-code"
                           class="form-control" style="max-width:160px;font-size:1.4em;letter-spacing:.15em;text-align:center">
                </div>
                <button type="submit" class="btn btn-primary">Activate 2FA</button>
                <a href="<?php echo sURL; ?>TwoFactorAuth" class="btn" style="margin-left:8px">Cancel</a>
            </form>
        </div>
    </div>

</div>
