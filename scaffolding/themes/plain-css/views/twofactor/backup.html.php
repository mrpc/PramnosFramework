<?php
/**
 * Two-Factor Authentication backup codes page (plain-CSS theme).
 *
 * Variables:
 *   $this->user             — User object
 *   $this->remainingCodes   — int
 *   $this->newBackupCodes   — string[] (set only after regeneration)
 *   $this->success          — string|null
 *   $this->error            — string|null
 *   $this->setupComplete    — bool
 */
?>
<div class="page-section" style="max-width:540px;margin:0 auto">

    <p><a href="<?php echo sURL; ?>TwoFactorAuth">← Back to 2FA settings</a></p>
    <h2>Backup Codes</h2>

    <?php if (!empty($this->success)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($this->success); ?></div>
    <?php endif; ?>

    <?php if (!empty($this->error)): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($this->error); ?></div>
    <?php endif; ?>

    <?php if (!empty($this->setupComplete)): ?>
        <div class="alert alert-info">
            <strong>Setup complete!</strong> Save your backup codes before leaving this page.
        </div>
    <?php endif; ?>

    <?php if (!empty($this->newBackupCodes)): ?>
    <div class="card" style="border-color:#f0ad4e;margin-bottom:16px">
        <div class="card-header" style="background:#fcf8e3"><strong>New Backup Codes</strong></div>
        <div class="card-body">
            <p style="font-size:.9em;color:#666">
                <strong>Save these codes now.</strong> They replace your previous codes and will not be shown again.
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
            <h4>Remaining codes</h4>
            <p>
                You have <strong><?php echo (int) $this->remainingCodes; ?></strong>
                backup <?php echo $this->remainingCodes === 1 ? 'code' : 'codes'; ?> remaining.
                <?php if ($this->remainingCodes <= 2): ?>
                    <span style="color:#c00"> (Running low — consider regenerating.)</span>
                <?php endif; ?>
            </p>
            <p style="font-size:.85em;color:#666">
                Use a backup code instead of your authenticator app when you don't have your device.
                Each code can only be used once.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>Regenerate Backup Codes</strong></div>
        <div class="card-body">
            <p style="font-size:.9em;color:#666;margin-bottom:12px">
                Generating new codes will invalidate all existing ones.
                Enter your account password to confirm.
            </p>
            <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/backup">
                <div class="form-group">
                    <label for="regenerate_password">Password</label>
                    <input type="password" id="regenerate_password" name="regenerate_password"
                           required autocomplete="current-password" class="form-control">
                </div>
                <button type="submit" class="btn">Regenerate Codes</button>
            </form>
        </div>
    </div>

</div>
