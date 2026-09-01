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
    <h2 class="text-2xl font-bold text-base-content mb-6">Two-Factor Authentication</h2>

    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-error mb-4">
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
        <div class="alert alert-success mb-4">
            <?php
            $messages = ['disabled' => 'Two-factor authentication has been disabled.'];
            echo htmlspecialchars($messages[$_GET['success']] ?? 'Done.');
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
                            Enabled
                        </span>
                        <h3 class="text-lg font-medium text-base-content">Your account is protected</h3>
                    <?php else: ?>
                        <span class="badge badge-neutral badge-sm inline-flex items-center">
                            Disabled
                        </span>
                        <h3 class="text-lg font-medium text-base-content">Add extra security</h3>
                    <?php endif; ?>
                </div>

                <p class="text-sm text-base-content/70 mb-6">
                    Two-factor authentication adds a second layer of security to your account.
                    After entering your password you will be asked for a code from your authenticator app.
                </p>

                <?php if ($this->status['enabled']): ?>
                    <div class="bg-base-200 rounded-lg p-4 mb-6 inline-block">
                        <div class="text-3xl font-bold text-primary"><?php echo (int) $this->status['backup_codes_remaining']; ?></div>
                        <div class="text-xs text-base-content/70 mt-1">backup codes remaining</div>
                    </div>

                    <div class="flex gap-3 flex-wrap">
                        <a href="<?php echo sURL; ?>TwoFactorAuth/backup"
                           class="btn btn-outline btn-primary btn-sm inline-flex items-center">
                            Manage Backup Codes
                        </a>
                        <button type="button" data-modal-show="disableModal"
                                class="btn btn-outline btn-error btn-sm inline-flex items-center">
                            Disable 2FA
                        </button>
                    </div>
                <?php else: ?>
                    <a href="<?php echo sURL; ?>TwoFactorAuth/setup"
                       class="btn btn-primary btn-sm inline-flex items-center">
                        Enable Two-Factor Authentication
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php if ($this->status['enabled']): ?>
<div id="disableModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
    <div class="card bg-base-100 shadow-xl p-6 w-full max-w-md mx-4">
        <h3 class="text-lg font-semibold mb-2">Disable Two-Factor Authentication</h3>
        <p class="text-sm text-base-content/70 mb-4">Enter your account password to confirm.</p>
        <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/disable">
            <label class="block text-sm font-medium text-base-content mb-1" for="confirm_password">Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required autocomplete="current-password" enterkeyhint="go"
                   class="input input-sm block w-full mb-4">
            <?php echo \Pramnos\Html\PasswordToggle::render(
                'confirm_password', '', '', 'btn btn-ghost btn-xs'
            ); ?>
            <div class="flex justify-end gap-2">
                <button type="button" data-modal-hide="disableModal"
                        class="btn btn-outline btn-sm">
                    Cancel
                </button>
                <button type="submit"
                        class="btn btn-error btn-sm">
                    Disable 2FA
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
