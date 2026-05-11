<?php
/**
 * Two-Factor Authentication overview page (Tailwind theme).
 *
 * Variables:
 *   $this->user   — User object (userid, username, email)
 *   $this->status — array {enabled: bool, setup: bool, backup_codes_remaining: int}
 */
?>
<div class="max-w-xl mx-auto">

    <h2 class="text-2xl font-bold text-gray-900 mb-6">Two-Factor Authentication</h2>

    <?php if (!empty($_GET['error'])): ?>
        <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-red-800 text-sm">
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
        <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-4 text-green-800 text-sm">
            <?php
            $messages = ['disabled' => 'Two-factor authentication has been disabled.'];
            echo htmlspecialchars($messages[$_GET['success']] ?? 'Done.');
            ?>
        </div>
    <?php endif; ?>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-6">

        <div class="flex items-center gap-3 mb-4">
            <?php if ($this->status['enabled']): ?>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    Enabled
                </span>
                <h3 class="text-lg font-medium text-gray-900">Your account is protected</h3>
            <?php else: ?>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                    Disabled
                </span>
                <h3 class="text-lg font-medium text-gray-900">Add extra security</h3>
            <?php endif; ?>
        </div>

        <p class="text-sm text-gray-500 mb-6">
            Two-factor authentication adds a second layer of security to your account.
            After entering your password you will be asked for a code from your authenticator app.
        </p>

        <?php if ($this->status['enabled']): ?>
            <div class="bg-gray-50 rounded-lg p-4 mb-6 inline-block">
                <div class="text-3xl font-bold text-indigo-600"><?php echo (int) $this->status['backup_codes_remaining']; ?></div>
                <div class="text-xs text-gray-500 mt-1">backup codes remaining</div>
            </div>

            <div class="flex gap-3 flex-wrap">
                <a href="<?php echo sURL; ?>TwoFactorAuth/backup"
                   class="inline-flex items-center px-4 py-2 border border-indigo-300 rounded-md text-sm font-medium text-indigo-700 bg-white hover:bg-indigo-50 transition-colors">
                    Manage Backup Codes
                </a>
                <button type="button" onclick="document.getElementById('disableModal').classList.remove('hidden')"
                        class="inline-flex items-center px-4 py-2 border border-red-300 rounded-md text-sm font-medium text-red-700 bg-white hover:bg-red-50 transition-colors">
                    Disable 2FA
                </button>
            </div>
        <?php else: ?>
            <a href="<?php echo sURL; ?>TwoFactorAuth/setup"
               class="inline-flex items-center px-4 py-2 rounded-md text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 transition-colors">
                Enable Two-Factor Authentication
            </a>
        <?php endif; ?>
    </div>

</div>

<?php if ($this->status['enabled']): ?>
<div id="disableModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
        <h3 class="text-lg font-semibold mb-2">Disable Two-Factor Authentication</h3>
        <p class="text-sm text-gray-500 mb-4">Enter your account password to confirm.</p>
        <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/disable">
            <label class="block text-sm font-medium text-gray-700 mb-1" for="confirm_password">Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required autocomplete="current-password"
                   class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm mb-4 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('disableModal').classList.add('hidden')"
                        class="px-4 py-2 text-sm font-medium text-gray-700 border border-gray-300 rounded-md hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="submit"
                        class="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700 transition-colors">
                    Disable 2FA
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
