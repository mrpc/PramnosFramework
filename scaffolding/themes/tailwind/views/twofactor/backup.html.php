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
 *   $this->newBackupCodes   — string[] (set after enrolment and after regeneration)
 *   $this->success          — string|null
 *   $this->error            — string|null
 *   $this->setupComplete    — bool
 */
$this->accountBase = 'Account';
$this->activeNav   = 'twofactor_backup';
?>
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="text-2xl font-bold text-base-content mb-6">Backup Codes</h2>

    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success mb-4">
            <?php echo htmlspecialchars($this->success); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div class="alert alert-error mb-4">
            <?php echo htmlspecialchars($this->error); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($this->setupComplete)): ?>
        <div class="alert alert-info mb-4">
            <strong>Setup complete!</strong> Save your backup codes before leaving this page.
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3 space-y-4">
            <?php if (!empty($this->newBackupCodes)): ?>
            <div class="card bg-base-100 shadow-xs">
                <div class="alert alert-warning border-b">
                    New Backup Codes
                </div>
                <div class="p-5">
                    <p class="text-xs text-base-content/70 mb-3">
                        <strong class="text-base-content">Save these codes now.</strong>
                        They replace your previous codes and will not be shown again.
                    </p>
                    <div class="grid grid-cols-2 gap-2">
                        <?php foreach ($this->newBackupCodes as $code): ?>
                            <code class="block text-center text-sm font-mono bg-base-200 border border-base-300 rounded-sm py-1.5">
                                <?php echo htmlspecialchars($code); ?>
                            </code>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Status card -->
            <div class="card bg-base-100 border border-base-300 shadow-xs p-5">
                <h3 class="font-medium text-base-content mb-2">Remaining codes</h3>
                <p class="text-sm text-base-content/80 mb-3">
                    You have
                    <strong class="<?php echo $this->remainingCodes <= 2 ? 'text-error' : 'text-success'; ?> text-lg">
                        <?php echo (int) $this->remainingCodes; ?>
                    </strong>
                    backup <?php echo $this->remainingCodes === 1 ? 'code' : 'codes'; ?> remaining.
                </p>
                <?php if ($this->remainingCodes <= 2): ?>
                    <p class="text-xs text-error">Running low — consider regenerating your codes.</p>
                <?php endif; ?>
                <p class="text-xs text-base-content/60 mt-2">
                    Use a backup code instead of your authenticator app when you don't have your device.
                    Each code can only be used once.
                </p>
            </div>

            <!-- Regenerate -->
            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-5 py-3 border-b border-base-300 font-medium text-base-content text-sm">Regenerate Backup Codes</div>
                <div class="p-5">
                    <p class="text-xs text-base-content/70 mb-4">
                        Generating new codes will invalidate all existing ones.
                        Enter your account password to confirm.
                    </p>
                    <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/backup">
                        <label class="block text-sm font-medium text-base-content mb-1" for="regenerate_password">Password</label>
                        <input type="password" id="regenerate_password" name="regenerate_password"
                               required autocomplete="current-password" enterkeyhint="go"
                               class="input input-sm block w-full mb-4">
                        <?php echo \Pramnos\Html\PasswordToggle::render(
                            'regenerate_password', '', '', 'btn btn-ghost btn-xs'
                        ); ?>
                        <button type="submit"
                                class="btn btn-soft btn-warning btn-sm">
                            Regenerate Codes
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
