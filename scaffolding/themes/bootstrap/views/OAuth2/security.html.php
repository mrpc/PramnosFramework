<?php
/**
 * Security Overview page (Bootstrap theme).
 *
 * Variables:
 *   $this->recentActivity   — array[] {action, created_at, ip_address, user_agent}
 *   $this->twoFactorEnabled — bool
 */
?>
<div class="container py-4" style="max-width:760px">

    <p><a href="<?php echo sURL; ?>Dashboard">← Back to Dashboard</a></p>
    <h2>Security Overview</h2>

    <?php if (!empty($_GET['message']) && $_GET['message'] === 'password_changed'): ?>
        <div class="alert alert-success">Your password has been updated successfully.</div>
    <?php endif; ?>

    <!-- 2FA status -->
    <div class="card mb-4">
        <div class="card-header fw-semibold">Two-Factor Authentication</div>
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <?php if ($this->twoFactorEnabled): ?>
                    <span class="badge bg-success me-2">Enabled</span>
                    Your account is protected with two-factor authentication.
                <?php else: ?>
                    <span class="badge bg-warning text-dark me-2">Disabled</span>
                    Protect your account by enabling two-factor authentication.
                <?php endif; ?>
            </div>
            <a href="<?php echo sURL; ?>TwoFactorAuth" class="btn btn-sm btn-outline-secondary">
                <?php echo $this->twoFactorEnabled ? 'Manage' : 'Enable'; ?> 2FA
            </a>
        </div>
    </div>

    <!-- Change password -->
    <div class="card mb-4">
        <div class="card-header fw-semibold">Password</div>
        <div class="card-body d-flex justify-content-between align-items-center">
            <span>Change your account password regularly to stay secure.</span>
            <a href="<?php echo sURL; ?>Dashboard/changepassword" class="btn btn-sm btn-outline-primary">
                Change Password
            </a>
        </div>
    </div>

    <!-- Recent activity -->
    <div class="card">
        <div class="card-header fw-semibold">Recent Login Activity</div>
        <?php if (empty($this->recentActivity)): ?>
            <div class="card-body text-muted">No activity recorded yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Event</th>
                            <th>Date</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->recentActivity as $entry): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($entry['action']); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($entry['created_at']); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($entry['ip_address'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>
