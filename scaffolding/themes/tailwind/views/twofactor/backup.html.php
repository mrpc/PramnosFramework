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
    <h2 class="text-2xl font-bold text-base-content mb-6"><?php echo htmlspecialchars(t('Backup Codes'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if (!empty($this->success)): ?>
        <div role="status" class="alert alert-success mb-4">
            <?php echo htmlspecialchars($this->success); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div role="alert" class="alert alert-error mb-4">
            <?php echo htmlspecialchars($this->error); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($this->setupComplete)): ?>
        <div role="status" class="alert alert-info mb-4 block">
            <strong><?php echo htmlspecialchars(t('Setup complete!'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars(t('Save your backup codes before leaving this page.'), ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3 space-y-4">
            <?php if (!empty($this->newBackupCodes)): ?>
            <div class="card bg-base-100 shadow-xs">
                <div role="status" class="alert alert-warning border-b">
                    <?php echo htmlspecialchars(t('New Backup Codes'), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="p-5">
                    <p class="text-xs text-base-content/70 mb-3">
                        <strong class="text-base-content"><?php echo htmlspecialchars(t('Save these codes now.'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php echo htmlspecialchars(t('They replace your previous codes and will not be shown again.'), ENT_QUOTES, 'UTF-8'); ?>
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
                <h3 class="font-medium text-base-content mb-2"><?php echo htmlspecialchars(t('Remaining codes'), ENT_QUOTES, 'UTF-8'); ?></h3>
                <p class="text-sm text-base-content/80 mb-3">
                    <?php echo sprintf(
                        htmlspecialchars($this->remainingCodes === 1 ? t('You have %s backup code remaining.') : t('You have %s backup codes remaining.'), ENT_QUOTES, 'UTF-8'),
                        '<strong class="' . ($this->remainingCodes <= 2 ? 'text-error' : 'text-success') . ' text-lg">' . (int) $this->remainingCodes . '</strong>'
                    ); ?>
                </p>
                <?php if ($this->remainingCodes <= 2): ?>
                    <p class="text-xs text-error"><?php echo htmlspecialchars(t('Running low — consider regenerating your codes.'), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <p class="text-xs text-base-content/60 mt-2">
                    <?php echo htmlspecialchars(t("Use a backup code instead of your authenticator app when you don't have your device. Each code can only be used once."), ENT_QUOTES, 'UTF-8'); ?>
                </p>
            </div>

            <!-- Regenerate -->
            <div class="card bg-base-100 border border-base-300 shadow-xs">
                <div class="px-5 py-3 border-b border-base-300 font-medium text-base-content text-sm"><?php echo htmlspecialchars(t('Regenerate Backup Codes'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="p-5">
                    <p class="text-xs text-base-content/70 mb-4">
                        <?php echo htmlspecialchars(t('Generating new codes will invalidate all existing ones. Enter your account password to confirm.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/backup">
                        <label class="block text-sm font-medium text-base-content mb-1" for="regenerate_password"><?php echo htmlspecialchars(t('Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <input type="password" id="regenerate_password" name="regenerate_password"
                               required autocomplete="current-password" enterkeyhint="go"
                               class="input input-sm block w-full mb-4">
                        <?php echo \Pramnos\Html\PasswordToggle::render(
                            'regenerate_password', '', ''
                        ); ?>
                        <button type="submit"
                                class="btn btn-soft btn-warning btn-sm">
                            <?php echo htmlspecialchars(t('Regenerate Codes'), ENT_QUOTES, 'UTF-8'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
