<?php
/**
 * Two-Factor Authentication backup codes management page.
 *
 * Variables:
 *   $this->user             — User object
 *   $this->remainingCodes   — int  (count of unused backup codes)
 *   $this->newBackupCodes   — string[] (set only after regeneration)
 *   $this->success          — string|null
 *   $this->error            — string|null
 *   $this->setupComplete    — bool (true when arriving right after setup)
 */
?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">

        <div class="d-flex align-items-center mb-4 gap-3">
            <a href="<?php echo sURL; ?>TwoFactorAuth" class="btn btn-sm btn-outline-secondary">← Back</a>
            <h2 class="mb-0">Backup Codes</h2>
        </div>

        <?php if (!empty($this->success)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($this->success); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($this->error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($this->error); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($this->setupComplete)): ?>
            <div class="alert alert-info">
                <strong>Setup complete!</strong> Save your backup codes before leaving this page.
            </div>
        <?php endif; ?>

        <?php if (!empty($this->newBackupCodes)): ?>
        <!-- New codes just generated — show them prominently -->
        <div class="card mb-4 border-warning">
            <div class="card-header bg-warning-subtle fw-semibold">New Backup Codes</div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    <strong>Save these codes now.</strong> They replace your previous codes and will not be shown again.
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
                <h5 class="card-title">Remaining codes</h5>
                <p class="card-text">
                    You have
                    <strong class="<?php echo $this->remainingCodes <= 2 ? 'text-danger' : 'text-success'; ?>">
                        <?php echo (int) $this->remainingCodes; ?>
                    </strong>
                    backup <?php echo $this->remainingCodes === 1 ? 'code' : 'codes'; ?> remaining.
                </p>
                <?php if ($this->remainingCodes <= 2): ?>
                    <p class="text-danger small">Running low — consider regenerating your codes.</p>
                <?php endif; ?>
                <p class="small text-muted">
                    Use a backup code instead of your authenticator app when you don't have your device.
                    Each code can only be used once.
                </p>
            </div>
        </div>

        <!-- Regenerate codes -->
        <div class="card">
            <div class="card-header fw-semibold">Regenerate Backup Codes</div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Generating new codes will invalidate all existing ones.
                    Enter your account password to confirm.
                </p>
                <form method="post" action="<?php echo sURL; ?>TwoFactorAuth/backup">
                    <div class="mb-3">
                        <label for="regenerate_password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="regenerate_password"
                               name="regenerate_password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-warning">Regenerate Codes</button>
                </form>
            </div>
        </div>

    </div>
</div>
