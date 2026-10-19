<?php
/**
 * Two-Factor Authentication overview page (plain-CSS theme).
 *
 * Rendered by the TwoFactorAuth controller (routeBase 'TwoFactorAuth'); the
 * account sidebar/breadcrumb point at the Account controller via accountBase.
 *
 * Variables:
 *   $this->user   — User object (userid, username, email)
 *   $this->status — array {enabled: bool, setup: bool, backup_codes_remaining: int}
 */
$this->accountBase = 'Account';
$this->activeNav   = 'twofactor';
?>
<div class="page-section">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2><?php echo htmlspecialchars(t('Two-Factor Authentication'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if (!empty($_GET['error'])): ?>
        <div role="alert" class="alert alert-error">
            <?php
            $messages = [
                'already_enabled'   => t('Two-factor authentication is already enabled.'),
                'password_required' => t('Please enter your password to disable 2FA.'),
                'invalid_password'  => t('Incorrect password. 2FA was not disabled.'),
            ];
            echo htmlspecialchars($messages[$_GET['error']] ?? t('An error occurred.'), ENT_QUOTES, 'UTF-8');
            ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['success'])): ?>
        <div role="status" class="alert alert-success">
            <?php
            $messages = ['disabled' => t('Two-factor authentication has been disabled.')];
            echo htmlspecialchars($messages[$_GET['success']] ?? t('Done.'), ENT_QUOTES, 'UTF-8');
            ?>
        </div>
    <?php endif; ?>

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div>
            <div class="card" style="max-width:540px">
                <div class="card-body">
                    <p>
                        <strong><?php echo htmlspecialchars(t('Status:'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php if ($this->status['enabled']): ?>
                            <span class="badge badge-success"><?php echo htmlspecialchars(t('Enabled'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php else: ?>
                            <span class="badge badge-secondary"><?php echo htmlspecialchars(t('Disabled'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </p>

                    <p>
                        <?php echo htmlspecialchars(t('Two-factor authentication adds a second layer of security to your account. After entering your password you will be asked for a code from your authenticator app.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>

                    <?php if ($this->status['enabled']): ?>
                        <p><?php echo sprintf(htmlspecialchars(t('%s backup codes remaining.'), ENT_QUOTES, 'UTF-8'), '<strong>' . (int) $this->status['backup_codes_remaining'] . '</strong>'); ?></p>

                        <p>
                            <a href="<?php echo sURL; ?>TwoFactorAuth/backup" class="btn"><?php echo htmlspecialchars(t('Manage Backup Codes'), ENT_QUOTES, 'UTF-8'); ?></a>
                        </p>

                        <hr>
                        <h4><?php echo htmlspecialchars(t('Disable Two-Factor Authentication'), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/disable">
                            <div class="form-group">
                                <label for="confirm_password"><?php echo htmlspecialchars(t('Confirm your password to disable 2FA:'), ENT_QUOTES, 'UTF-8'); ?></label>
                                <input type="password" id="confirm_password" name="confirm_password"
                                       required autocomplete="current-password" enterkeyhint="go" class="form-control">
                                <?php echo \Pramnos\Html\PasswordToggle::render(
                                    'confirm_password', '', ''
                                ); ?>
                            </div>
                            <button type="submit" class="btn btn-danger"><?php echo htmlspecialchars(t('Disable 2FA'), ENT_QUOTES, 'UTF-8'); ?></button>
                        </form>

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
