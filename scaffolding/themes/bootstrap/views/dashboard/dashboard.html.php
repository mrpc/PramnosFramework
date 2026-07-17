<?php
/**
 * Account Dashboard overview (Bootstrap theme).
 *
 * Variables:
 *   $this->user            — User object (current user)
 *   $this->authorizedApps  — array[] {appid, name, apikey, description, last_used, token_count}
 *   $this->recentActivity  — array[] {action, created_at, ip_address, user_agent}
 *   $this->twoFactorEnabled — bool
 *   $this->routeBase       — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'dashboard';
?>
<div class="container py-4">

    <?php $this->insert('../partials/account_breadcrumb'); ?>

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

        <?php $this->insert('../partials/account_sidebar'); ?>

        <!-- Main column -->
        <div class="col-lg-9 col-md-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center fw-semibold">
                    <span>Authorized Applications</span>
                    <a href="<?php echo sURL . $routeBase; ?>/applications" class="btn btn-sm btn-outline-secondary">Manage</a>
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
                                    <a href="<?php echo sURL . $routeBase; ?>/applications">
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
