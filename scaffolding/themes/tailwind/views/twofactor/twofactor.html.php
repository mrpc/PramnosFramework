<?php
/**
 * Two-Factor Authentication overview page (Tailwind theme).
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
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="text-2xl font-bold text-base-content mb-6"><?php echo htmlspecialchars(t('Two-Factor Authentication'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if (!empty($_GET['error'])): ?>
        <div role="alert" class="alert alert-error mb-4">
            <?php
            $messages = [
                'already_enabled'   => t('Two-factor authentication is already enabled.'),
                'password_required' => t('Please enter your password to disable 2FA.'),
                'invalid_password'  => t('Incorrect password. 2FA was not disabled.'),
            ];
            echo htmlspecialchars($messages[$_GET['error']] ?? t('An error occurred.'));
            ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['success'])): ?>
        <div role="status" class="alert alert-success mb-4">
            <?php
            $messages = ['disabled' => t('Two-factor authentication has been disabled.')];
            echo htmlspecialchars($messages[$_GET['success']] ?? t('Done.'));
            ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3">
            <div class="card bg-base-100 border border-base-300 shadow-xs p-6 max-w-xl">

                <div class="flex items-center gap-3 mb-4">
                    <?php if ($this->status['enabled']): ?>
                        <span class="badge badge-success badge-sm inline-flex items-center">
                            <?php echo htmlspecialchars(t('Enabled'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <h3 class="text-lg font-medium text-base-content"><?php echo htmlspecialchars(t('Your account is protected'), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <?php else: ?>
                        <span class="badge badge-neutral badge-sm inline-flex items-center">
                            <?php echo htmlspecialchars(t('Disabled'), ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <h3 class="text-lg font-medium text-base-content"><?php echo htmlspecialchars(t('Add extra security'), ENT_QUOTES, 'UTF-8'); ?></h3>
                    <?php endif; ?>
                </div>

                <p class="text-sm text-base-content/70 mb-6">
                    <?php echo htmlspecialchars(t('Two-factor authentication adds a second layer of security to your account. After entering your password you will be asked for a code from your authenticator app.'), ENT_QUOTES, 'UTF-8'); ?>
                </p>

                <?php if ($this->status['enabled']): ?>
                    <div class="bg-base-200 rounded-lg p-4 mb-6 inline-block">
                        <div class="text-3xl font-bold text-primary"><?php echo (int) $this->status['backup_codes_remaining']; ?></div>
                        <div class="text-xs text-base-content/70 mt-1"><?php echo htmlspecialchars(t('backup codes remaining'), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>

                    <div class="flex gap-3 flex-wrap">
                        <a href="<?php echo sURL; ?>TwoFactorAuth/backup"
                           class="btn btn-outline btn-primary btn-sm inline-flex items-center">
                            <?php echo htmlspecialchars(t('Manage Backup Codes'), ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                        <button type="button" data-modal-show="disableModal"
                                class="btn btn-outline btn-error btn-sm inline-flex items-center">
                            <?php echo htmlspecialchars(t('Disable 2FA'), ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                    </div>
                <?php else: ?>
                    <a href="<?php echo sURL; ?>TwoFactorAuth/setup"
                       class="btn btn-primary btn-sm inline-flex items-center">
                        <?php echo htmlspecialchars(t('Enable Two-Factor Authentication'), ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php if ($this->status['enabled']): ?>
<div id="disableModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
    <div class="card bg-base-100 shadow-xl p-6 w-full max-w-md mx-4">
        <h3 class="text-lg font-semibold mb-2"><?php echo htmlspecialchars(t('Disable Two-Factor Authentication'), ENT_QUOTES, 'UTF-8'); ?></h3>
        <p class="text-sm text-base-content/70 mb-4"><?php echo htmlspecialchars(t('Enter your account password to confirm.'), ENT_QUOTES, 'UTF-8'); ?></p>
        <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/disable">
            <label class="block text-sm font-medium text-base-content mb-1" for="confirm_password"><?php echo htmlspecialchars(t('Password'), ENT_QUOTES, 'UTF-8'); ?></label>
            <input type="password" id="confirm_password" name="confirm_password" required autocomplete="current-password" enterkeyhint="go"
                   class="input input-sm block w-full mb-4">
            <?php echo \Pramnos\Html\PasswordToggle::render(
                'confirm_password', '', ''
            ); ?>
            <div class="flex justify-end gap-2">
                <button type="button" data-modal-hide="disableModal"
                        class="btn btn-outline btn-sm">
                    <?php echo htmlspecialchars(t('Cancel'), ENT_QUOTES, 'UTF-8'); ?>
                </button>
                <button type="submit"
                        class="btn btn-error btn-sm">
                    <?php echo htmlspecialchars(t('Disable 2FA'), ENT_QUOTES, 'UTF-8'); ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
