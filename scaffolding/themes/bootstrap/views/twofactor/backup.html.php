<?php
/**
 * Two-Factor Authentication backup codes page (Bootstrap theme).
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
<div class="container py-4">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="mb-4"><?php echo htmlspecialchars(t('Backup Codes'), ENT_QUOTES, 'UTF-8'); ?></h2>

    <?php if (!empty($this->success)): ?>
        <div role="status" class="alert alert-success"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->error)): ?>
        <div role="alert" class="alert alert-danger"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>
    <?php if (!empty($this->setupComplete)): ?>
        <div role="status" class="alert alert-info">
            <?php echo sprintf(htmlspecialchars(t('%s Save your backup codes before leaving this page.'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars(t('Setup complete!'), ENT_QUOTES, 'UTF-8') . '</strong>'); ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="col-lg-9 col-md-8">
            <?php if (!empty($this->newBackupCodes)): ?>
            <div class="card mb-4 border-warning">
                <div class="card-header bg-warning-subtle fw-semibold"><?php echo htmlspecialchars(t('New Backup Codes'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        <?php echo sprintf(htmlspecialchars(t('%s They replace your previous codes and will not be shown again.'), ENT_QUOTES, 'UTF-8'), '<strong>' . htmlspecialchars(t('Save these codes now.'), ENT_QUOTES, 'UTF-8') . '</strong>'); ?>
                    </p>
                    <div class="row row-cols-2 g-2 mb-2">
                        <?php foreach ($this->newBackupCodes as $code): ?>
                            <div class="col">
                                <code class="d-block text-center border rounded py-1 bg-light">
                                    <?php echo htmlspecialchars($code); ?>
                                </code>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title"><?php echo htmlspecialchars(t('Remaining codes'), ENT_QUOTES, 'UTF-8'); ?></h5>
                    <p class="card-text">
                        <?php echo sprintf(
                            htmlspecialchars($this->remainingCodes === 1 ? t('You have %s backup code remaining.') : t('You have %s backup codes remaining.'), ENT_QUOTES, 'UTF-8'),
                            '<strong class="' . ($this->remainingCodes <= 2 ? 'text-danger' : 'text-success') . '">' . (int) $this->remainingCodes . '</strong>'
                        ); ?>
                    </p>
                    <?php if ($this->remainingCodes <= 2): ?>
                        <p class="text-danger small"><?php echo htmlspecialchars(t('Running low — consider regenerating your codes.'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <p class="small text-muted">
                        <?php echo htmlspecialchars(t('Use a backup code instead of your authenticator app when you don\'t have your device. Each code can only be used once.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-semibold"><?php echo htmlspecialchars(t('Regenerate Backup Codes'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        <?php echo htmlspecialchars(t('Generating new codes will invalidate all existing ones. Enter your account password to confirm.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/backup">
                        <div class="mb-3">
                            <label for="regenerate_password" class="form-label"><?php echo htmlspecialchars(t('Password'), ENT_QUOTES, 'UTF-8'); ?></label>
                            <input type="password" class="form-control" id="regenerate_password"
                                   name="regenerate_password" required autocomplete="current-password" enterkeyhint="go">
                            <?php echo \Pramnos\Html\PasswordToggle::render(
                                'regenerate_password', '', ''
                            ); ?>
                        </div>
                        <button type="submit" class="btn btn-warning"><?php echo htmlspecialchars(t('Regenerate Codes'), ENT_QUOTES, 'UTF-8'); ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>
