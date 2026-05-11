<?php
/**
 * Security Overview page (plain-CSS theme).
 *
 * Variables:
 *   $this->recentActivity   — array[] {action, created_at, ip_address, user_agent}
 *   $this->twoFactorEnabled — bool
 */
?>
<div class="page-section" style="max-width:700px;margin:0 auto">

    <p><a href="<?php echo sURL; ?>Dashboard">← Back to Dashboard</a></p>
    <h2>Security Overview</h2>

    <?php if (!empty($_GET['message']) && $_GET['message'] === 'password_changed'): ?>
        <div class="alert alert-success">Your password has been updated successfully.</div>
    <?php endif; ?>

    <!-- 2FA status -->
    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><strong>Two-Factor Authentication</strong></div>
        <div class="card-body" style="display:flex;justify-content:space-between;align-items:center">
            <p style="margin:0">
                <?php if ($this->twoFactorEnabled): ?>
                    <span style="color:#28a745;font-weight:bold">&#10003; Enabled</span>
                    — Your account is protected with two-factor authentication.
                <?php else: ?>
                    <span style="color:#dc3545;font-weight:bold">&#10007; Disabled</span>
                    — Enable 2FA to protect your account.
                <?php endif; ?>
            </p>
            <a href="<?php echo sURL; ?>TwoFactorAuth" class="btn">
                <?php echo $this->twoFactorEnabled ? 'Manage 2FA' : 'Enable 2FA'; ?>
            </a>
        </div>
    </div>

    <!-- Change password -->
    <div class="card" style="margin-bottom:16px">
        <div class="card-body" style="display:flex;justify-content:space-between;align-items:center">
            <p style="margin:0;font-size:.9em;color:#666">
                Change your account password regularly to stay secure.
            </p>
            <a href="<?php echo sURL; ?>Dashboard/changepassword" class="btn">Change Password</a>
        </div>
    </div>

    <!-- Activity log -->
    <div class="card">
        <div class="card-header"><strong>Recent Login Activity</strong></div>
        <div class="card-body" style="padding:0">
            <?php if (empty($this->recentActivity)): ?>
                <p style="padding:12px 16px;color:#666;margin:0">No activity recorded yet.</p>
            <?php else: ?>
                <table style="width:100%;border-collapse:collapse;font-size:.9em">
                    <thead style="background:#f8f8f8">
                        <tr>
                            <th style="text-align:left;padding:10px 16px;border-bottom:1px solid #eee">Event</th>
                            <th style="text-align:left;padding:10px 16px;border-bottom:1px solid #eee">Date</th>
                            <th style="text-align:left;padding:10px 16px;border-bottom:1px solid #eee">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->recentActivity as $entry): ?>
                            <tr style="border-bottom:1px solid #f5f5f5">
                                <td style="padding:10px 16px"><?php echo htmlspecialchars($entry['action']); ?></td>
                                <td style="padding:10px 16px;color:#888;font-size:.85em"><?php echo htmlspecialchars($entry['created_at']); ?></td>
                                <td style="padding:10px 16px;color:#888;font-size:.85em"><?php echo htmlspecialchars($entry['ip_address'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

</div>
