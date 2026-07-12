<?php
/**
 * Two-Factor Authentication overview page.
 *
 * Variables:
 *   $this->user   — User object (userid, username, email)
 *   $this->status — array {enabled: bool, setup: bool, backup_codes_remaining: int}
 */
?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">

        <h2 class="mb-4">Two-Factor Authentication</h2>

        <?php if (!empty($_GET['error'])): ?>
            <div class="alert alert-danger">
                <?php
                $messages = [
                    'already_enabled'  => 'Two-factor authentication is already enabled.',
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
                $messages = [
                    'disabled' => 'Two-factor authentication has been disabled.',
                ];
                echo htmlspecialchars($messages[$_GET['success']] ?? 'Done.');
                ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <?php if ($this->status['enabled']): ?>
                        <span class="badge bg-success fs-6 me-3">Enabled</span>
                    <?php else: ?>
                        <span class="badge bg-secondary fs-6 me-3">Disabled</span>
                    <?php endif; ?>
                    <h5 class="card-title mb-0">
                        <?php echo $this->status['enabled'] ? 'Your account is protected' : 'Add extra security'; ?>
                    </h5>
                </div>

                <p class="card-text text-muted">
                    Two-factor authentication adds a second layer of security to your account.
                    After entering your password you will be asked for a code from your authenticator app.
                </p>

                <?php if ($this->status['enabled']): ?>
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="border rounded p-3 text-center">
                                <div class="fs-3 fw-bold text-primary"><?php echo (int) $this->status['backup_codes_remaining']; ?></div>
                                <div class="small text-muted">Backup codes remaining</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?php echo sURL; ?>TwoFactorAuth/backup" class="btn btn-outline-primary">
                            Manage Backup Codes
                        </a>

                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#disableModal">
                            Disable 2FA
                        </button>
                    </div>
                <?php else: ?>
                    <a href="<?php echo sURL; ?>TwoFactorAuth/setup" class="btn btn-primary">
                        Enable Two-Factor Authentication
                    </a>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php if ($this->status['enabled']): ?>
<!-- Disable 2FA confirmation modal -->
<div class="modal fade" id="disableModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Disable Two-Factor Authentication</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/disable">
                <div class="modal-body">
                    <p>Enter your account password to confirm disabling 2FA.</p>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="confirm_password"
                               name="confirm_password" required autocomplete="current-password">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Disable 2FA</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
