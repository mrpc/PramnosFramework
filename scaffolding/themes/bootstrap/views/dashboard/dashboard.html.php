<?php
/**
 * Account Dashboard overview (Bootstrap theme).
 *
 * Variables:
 *   $this->user            — User object (current user)
 *   $this->authorizedApps  — array[] {appid, name, apikey, description, last_used, token_count}
 *   $this->recentActivity  — array[] {action, created_at, ip_address, user_agent}
 *   $this->twoFactorEnabled — bool
 */
?>
<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0">Account Dashboard</h2>
            <small class="text-muted">Welcome back, <?php echo htmlspecialchars($this->user->firstname ?? $this->user->username ?? ''); ?></small>
        </div>
        <div>
            <?php if ($this->twoFactorEnabled): ?>
                <span class="badge bg-success fs-6"><i class="bi bi-shield-check"></i> 2FA Active</span>
            <?php else: ?>
                <span class="badge bg-warning text-dark fs-6"><i class="bi bi-shield-exclamation"></i> 2FA Inactive</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">

        <!-- Quick links -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header fw-semibold">Account Settings</div>
                <div class="list-group list-group-flush">
                    <a href="<?php echo sURL; ?>Dashboard/applications" class="list-group-item list-group-item-action">
                        <i class="bi bi-grid me-2"></i> Authorized Applications
                        <?php if (count($this->authorizedApps) > 0): ?>
                            <span class="badge bg-secondary float-end"><?php echo count($this->authorizedApps); ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?php echo sURL; ?>Dashboard/security" class="list-group-item list-group-item-action">
                        <i class="bi bi-shield me-2"></i> Security
                    </a>
                    <a href="<?php echo sURL; ?>Dashboard/privacy" class="list-group-item list-group-item-action">
                        <i class="bi bi-eye-slash me-2"></i> Privacy Settings
                    </a>
                    <a href="<?php echo sURL; ?>Dashboard/changepassword" class="list-group-item list-group-item-action">
                        <i class="bi bi-key me-2"></i> Change Password
                    </a>
                    <a href="<?php echo sURL; ?>TwoFactorAuth" class="list-group-item list-group-item-action">
                        <i class="bi bi-phone me-2"></i> Two-Factor Auth
                    </a>
                    <a href="<?php echo sURL; ?>Dashboard/exportdata" class="list-group-item list-group-item-action">
                        <i class="bi bi-download me-2"></i> Export My Data
                    </a>
                </div>
            </div>
        </div>

        <!-- Authorized apps summary -->
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center fw-semibold">
                    <span>Authorized Applications</span>
                    <a href="<?php echo sURL; ?>Dashboard/applications" class="btn btn-sm btn-outline-secondary">Manage</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($this->authorizedApps)): ?>
                        <p class="text-muted p-3 mb-0">No authorized applications.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach (array_slice($this->authorizedApps, 0, 3) as $app): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($app['name']); ?></strong>
                                        <?php if (!empty($app['description'])): ?>
                                            <small class="d-block text-muted"><?php echo htmlspecialchars($app['description']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo (int) $app['token_count']; ?> token<?php echo $app['token_count'] != 1 ? 's' : ''; ?>
                                    </small>
                                </li>
                            <?php endforeach; ?>
                            <?php if (count($this->authorizedApps) > 3): ?>
                                <li class="list-group-item text-center">
                                    <a href="<?php echo sURL; ?>Dashboard/applications">
                                        + <?php echo count($this->authorizedApps) - 3; ?> more
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent activity -->
            <div class="card">
                <div class="card-header fw-semibold">Recent Activity</div>
                <div class="card-body p-0">
                    <?php if (empty($this->recentActivity)): ?>
                        <p class="text-muted p-3 mb-0">No recent activity.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($this->recentActivity as $entry): ?>
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo htmlspecialchars($entry['action']); ?></span>
                                        <small class="text-muted"><?php echo htmlspecialchars($entry['created_at']); ?></small>
                                    </div>
                                    <?php if (!empty($entry['ip_address'])): ?>
                                        <small class="text-muted">from <?php echo htmlspecialchars($entry['ip_address']); ?></small>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>
