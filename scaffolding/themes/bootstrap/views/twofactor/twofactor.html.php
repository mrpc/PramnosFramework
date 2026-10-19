<?php
/**
 * Two-Factor Authentication overview page (Bootstrap theme).
 *
 * Rendered by the TwoFactorAuth controller; the account sidebar/breadcrumb
 * point at the Account controller via accountBase.
 *
 * Variables:
 *   $this->user   — User object (userid, username, email)
 *   $this->status — array {enabled: bool, setup: bool, backup_codes_remaining: int}
 */
$this->accountBase = 'Account';
$this->activeNav   = 'twofactor';
?>
<div class="container py-4">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="mb-4"><?php echo htmlspecialchars(t('Two-Factor Authentication'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if (!empty($_GET['error'])): ?>
        <div role="alert" class="alert alert-danger">
            <?php
            $messages = [
                'already_enabled'  => t('Two-factor authentication is already enabled.'),
                'password_required' => t('Please enter your password to disable 2FA.'),
                'invalid_password'  => t('Incorrect password. 2FA was not disabled.'),
            ];
            echo htmlspecialchars($messages[$_GET['error']] ?? t('An error occurred.'));
            ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['success'])): ?>
        <div role="status" class="alert alert-success">
            <?php
            $messages = ['disabled' => t('Two-factor authentication has been disabled.')];
            echo htmlspecialchars($messages[$_GET['success']] ?? t('Done.'));
            ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="col-lg-9 col-md-8">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <?php if ($this->status['enabled']): ?>
                            <span class="badge bg-success fs-6 me-3"><?php echo htmlspecialchars(t('Enabled'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary fs-6 me-3"><?php echo htmlspecialchars(t('Disabled'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <h5 class="card-title mb-0">
                            <?php echo htmlspecialchars($this->status['enabled'] ? t('Your account is protected') : t('Add extra security'), ENT_QUOTES, 'UTF-8'); ?>
                        </h5>
                    </div>

                    <p class="card-text text-muted">
                        <?php echo htmlspecialchars(t('Two-factor authentication adds a second layer of security to your account. After entering your password you will be asked for a code from your authenticator app.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>

                    <?php if ($this->status['enabled']): ?>
                        <div class="row g-3 mb-4">
                            <div class="col-sm-6">
                                <div class="border rounded p-3 text-center">
                                    <div class="fs-3 fw-bold text-primary"><?php echo (int) $this->status['backup_codes_remaining']; ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars(t('Backup codes remaining'), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <a href="<?php echo sURL; ?>TwoFactorAuth/backup" class="btn btn-outline-primary">
                                <?php echo htmlspecialchars(t('Manage Backup Codes'), ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#disableModal">
                                <?php echo htmlspecialchars(t('Disable 2FA'), ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        </div>
                    <?php else: ?>
                        <a href="<?php echo sURL; ?>TwoFactorAuth/setup" class="btn btn-primary">
                            <?php echo htmlspecialchars(t('Enable Two-Factor Authentication'), ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php endif; ?>
                </div>
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
                <h5 class="modal-title"><?php echo htmlspecialchars(t('Disable Two-Factor Authentication'), ENT_QUOTES, 'UTF-8'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/disable">
                <div class="modal-body">
                    <p><?php echo htmlspecialchars(t('Enter your account password to confirm disabling 2FA.'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label"><?php echo htmlspecialchars(t('Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <input type="password" class="form-control" id="confirm_password"
                               name="confirm_password" required autocomplete="current-password" enterkeyhint="go">
                        <?php echo \Pramnos\Html\PasswordToggle::render(
                            'confirm_password', '', ''
                        ); ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo htmlspecialchars(t('Cancel'), ENT_QUOTES, 'UTF-8'); ?></button>
                    <button type="submit" class="btn btn-danger"><?php echo htmlspecialchars(t('Disable 2FA'), ENT_QUOTES, 'UTF-8'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
