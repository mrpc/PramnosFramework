<?php
/**
 * Two-Factor Authentication backup codes page (Tailwind theme).
 *
 * Rendered by the TwoFactorAuth controller; account sidebar/breadcrumb point at
 * the Account controller via accountBase.
 *
 * Variables:
 *   $this->user             — User object
 *   $this->remainingCodes   — int
 *   $this->newBackupCodes   — string[] (set only after regeneration)
 *   $this->success          — string|null
 *   $this->error            — string|null
 *   $this->setupComplete    — bool
 */
$this->accountBase = 'Account';
$this->activeNav   = 'twofactor_backup';
?>
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="text-2xl font-bold text-gray-900 mb-6">Backup Codes</h2>

    <?php if (!empty($this->success)): ?>
        <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-4 text-green-800 text-sm">
            <?php echo htmlspecialchars($this->success); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-red-800 text-sm">
            <?php echo htmlspecialchars($this->error); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($this->setupComplete)): ?>
        <div class="mb-4 rounded-md bg-blue-50 border border-blue-200 p-4 text-blue-800 text-sm">
            <strong>Setup complete!</strong> Save your backup codes before leaving this page.
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3 space-y-4">
            <?php if (!empty($this->newBackupCodes)): ?>
            <div class="bg-white border border-amber-200 rounded-xl shadow-xs">
                <div class="px-5 py-3 border-b border-amber-100 bg-amber-50 font-medium text-amber-800 text-sm rounded-t-xl">
                    New Backup Codes
                </div>
                <div class="p-5">
                    <p class="text-xs text-gray-500 mb-3">
                        <strong class="text-gray-700">Save these codes now.</strong>
                        They replace your previous codes and will not be shown again.
                    </p>
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach ($this->newBackupCodes as $code): ?>
                            <code class="block text-center text-sm font-mono bg-gray-50 border border-gray-200 rounded-sm py-1.5">
                                <?php echo htmlspecialchars($code); ?>
                            </code>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Status card -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-xs p-5">
                <h3 class="font-medium text-gray-900 mb-2">Remaining codes</h3>
                <p class="text-sm text-gray-600 mb-3">
                    You have
                    <strong class="<?php echo $this->remainingCodes <= 2 ? 'text-red-600' : 'text-green-700'; ?> text-lg">
                        <?php echo (int) $this->remainingCodes; ?>
                    </strong>
                    backup <?php echo $this->remainingCodes === 1 ? 'code' : 'codes'; ?> remaining.
                </p>
                <?php if ($this->remainingCodes <= 2): ?>
                    <p class="text-xs text-red-600">Running low — consider regenerating your codes.</p>
                <?php endif; ?>
                <p class="text-xs text-gray-400 mt-2">
                    Use a backup code instead of your authenticator app when you don't have your device.
                    Each code can only be used once.
                </p>
            </div>

            <!-- Regenerate -->
            <div class="bg-white border border-gray-200 rounded-xl shadow-xs">
                <div class="px-5 py-3 border-b border-gray-100 font-medium text-gray-700 text-sm">Regenerate Backup Codes</div>
                <div class="p-5">
                    <p class="text-xs text-gray-500 mb-4">
                        Generating new codes will invalidate all existing ones.
                        Enter your account password to confirm.
                    </p>
                    <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/backup">
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="regenerate_password">Password</label>
                        <input type="password" id="regenerate_password" name="regenerate_password"
                               required autocomplete="current-password"
                               class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm mb-4 focus:outline-hidden focus:ring-2 focus:ring-indigo-500">
                        <button type="submit"
                                class="px-4 py-2 text-sm font-medium text-amber-800 bg-amber-100 border border-amber-300 rounded-md hover:bg-amber-200 transition-colors">
                            Regenerate Codes
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
