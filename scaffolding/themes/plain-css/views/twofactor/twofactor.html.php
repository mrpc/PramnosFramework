<?php
/**
 * Two-Factor Authentication overview page (plain-CSS theme).
 *
 * Variables:
 *   $this->user   — User object (userid, username, email)
 *   $this->status — array {enabled: bool, setup: bool, backup_codes_remaining: int}
 */
?>
<div class="page-section" style="max-width:540px;margin:0 auto">

    <h2>Two-Factor Authentication</h2>

    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-error">
            <?php
            $messages = [
                'already_enabled'   => 'Two-factor authentication is already enabled.',
                'password_required' => 'Please enter your password to disable 2FA.',
                'invalid_password'  => 'Incorrect password. 2FA was not disabled.',
            ];
            echo htmlspecialchars($messages[$_GET['error']] ?? 'An error occurred.');
            ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['success'])): ?>
        <div class="alert alert-success">
            <?php
            $messages = ['disabled' => 'Two-factor authentication has been disabled.'];
            echo htmlspecialchars($messages[$_GET['success']] ?? 'Done.');
            ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <p>
                <strong>Status:</strong>
                <?php if ($this->status['enabled']): ?>
                    <span class="badge badge-success">Enabled</span>
                <?php else: ?>
                    <span class="badge badge-secondary">Disabled</span>
                <?php endif; ?>
            </p>

            <p>
                Two-factor authentication adds a second layer of security to your account.
                After entering your password you will be asked for a code from your authenticator app.
            </p>

            <?php if ($this->status['enabled']): ?>
                <p><strong><?php echo (int) $this->status['backup_codes_remaining']; ?></strong> backup codes remaining.</p>

                <p>
                    <a href="<?php echo sURL; ?>TwoFactorAuth/backup" class="btn">Manage Backup Codes</a>
                </p>

                <hr>
                <h4>Disable Two-Factor Authentication</h4>
                <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/disable">
                    <div class="form-group">
                        <label for="confirm_password">Confirm your password to disable 2FA:</label>
                        <input type="password" id="confirm_password" name="confirm_password"
                               required autocomplete="current-password" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-danger">Disable 2FA</button>
                </form>

            <?php else: ?>
                <a href="<?php echo sURL; ?>TwoFactorAuth/setup" class="btn btn-primary">
                    Enable Two-Factor Authentication
                </a>
            <?php endif; ?>
        </div>
    </div>

</div>
