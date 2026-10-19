<?php
/**
 * Two-Factor Authentication backup codes page (plain-CSS theme).
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
<div class="page-section">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2><?php echo htmlspecialchars(t('Backup Codes'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if (!empty($this->success)): ?>
        <div role="status" class="alert alert-success"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div role="alert" class="alert alert-error"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->setupComplete)): ?>
        <div role="status" class="alert alert-info">
            <strong><?php echo htmlspecialchars(t('Setup complete!'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars(t('Save your backup codes before leaving this page.'), ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="account-grid">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div>
            <?php if (!empty($this->newBackupCodes)): ?>
            <div class="card" style="border-color:#f0ad4e;margin-bottom:16px">
                <div class="card-header" style="background:#fcf8e3"><strong><?php echo htmlspecialchars(t('New Backup Codes'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="card-body">
                    <p style="font-size:.9em;color:#666">
                        <strong><?php echo htmlspecialchars(t('Save these codes now.'), ENT_QUOTES, 'UTF-8'); ?></strong> <?php echo htmlspecialchars(t('They replace your previous codes and will not be shown again.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
                        <?php foreach ($this->newBackupCodes as $code): ?>
                            <code style="display:block;text-align:center;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;padding:6px">
                                <?php echo htmlspecialchars($code); ?>
                            </code>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card" style="margin-bottom:16px">
                <div class="card-body">
                    <h4><?php echo htmlspecialchars(t('Remaining codes'), ENT_QUOTES, 'UTF-8'); ?></h4>
                    <p>
                        <?php echo sprintf(
                            htmlspecialchars($this->remainingCodes === 1 ? t('You have %s backup code remaining.') : t('You have %s backup codes remaining.'), ENT_QUOTES, 'UTF-8'),
                            '<strong>' . (int) $this->remainingCodes . '</strong>'
                        ); ?>
                        <?php if ($this->remainingCodes <= 2): ?>
                            <span style="color:#c00"> (<?php echo htmlspecialchars(t('Running low — consider regenerating.'), ENT_QUOTES, 'UTF-8'); ?>)</span>
                        <?php endif; ?>
                    </p>
                    <p style="font-size:.85em;color:#666">
                        <?php echo htmlspecialchars(t('Use a backup code instead of your authenticator app when you don\'t have your device. Each code can only be used once.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><strong><?php echo htmlspecialchars(t('Regenerate Backup Codes'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="card-body">
                    <p style="font-size:.9em;color:#666;margin-bottom:12px">
                        <?php echo htmlspecialchars(t('Generating new codes will invalidate all existing ones. Enter your account password to confirm.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/backup">
                        <div class="form-group">
                            <label for="regenerate_password"><?php echo htmlspecialchars(t('Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="password" id="regenerate_password" name="regenerate_password"
                                   required autocomplete="current-password" enterkeyhint="go" class="form-control">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'regenerate_password', '', ''
                            ); ?>
                        </div>
                        <button type="submit" class="btn"><?php echo htmlspecialchars(t('Regenerate Codes'), ENT_QUOTES, 'UTF-8'); ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
