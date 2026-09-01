<?php
/**
 * Two-Factor Authentication setup page (plain-CSS theme).
 *
 * Rendered by the TwoFactorAuth controller; account sidebar/breadcrumb point at
 * the Account controller via accountBase.
 *
 * Variables:
 *   $this->setupData — array { secret, qr_code_url, qr_code_data_uri,
 *                              manual_entry_key }
 *   $this->user — User object
 */
$this->accountBase = 'Account';
$this->activeNav   = 'twofactor_setup';
?>
<div class="page-section">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
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

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div>
            <div class="card">
                <div class="card-header"><strong>Step 1 — Scan the QR code</strong></div>
                <div class="card-body" style="text-align:center">
                    <?php
                    $qrSrc = $this->setupData['qr_code_data_uri'] ?? null;
                    if ($qrSrc === null) {
                        // Fallback: data URI not available (library missing) — use external API.
                        $qrSrc = htmlspecialchars($this->setupData['qr_code_url'] ?? '');
                    }
                    ?>
                    <img src="<?php echo $qrSrc; ?>"
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

            <!-- Backup codes are shown after verification, not before: the set
                 listed here used to be a different set from the one enrolment
                 stored. -->
            <div class="card" style="border-color:#f0ad4e;margin-top:16px">
                <div class="card-header" style="background:#fcf8e3"><strong>Step 2 — Your backup codes</strong></div>
                <div class="card-body">
                    <p style="font-size:.9em;color:#666">
                        You will be given ten one-time codes as soon as the code below is verified.
                        <strong>Save them then</strong> — they are shown once.
                    </p>
                </div>
            </div>

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
                                   placeholder="000000" required autofocus autocomplete="one-time-code" enterkeyhint="go"
                                   class="form-control" style="max-width:160px;font-size:1.4em;letter-spacing:.15em;text-align:center">
                        </div>
                        <button type="submit" class="btn btn-primary">Activate 2FA</button>
                        <a href="<?php echo sURL; ?>TwoFactorAuth" class="btn" style="margin-left:8px">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
