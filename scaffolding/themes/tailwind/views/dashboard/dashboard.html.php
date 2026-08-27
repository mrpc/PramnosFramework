<?php
/**
 * Account Dashboard overview (Tailwind theme).
 *
 * Variables:
 *   $this->user            — User object
 *   $this->authorizedApps  — array[] {appid, name, apikey, description, last_used, token_count}
 *   $this->recentActivity  — array[] {action, created_at, ip_address, user_agent}
 *   $this->twoFactorEnabled — bool
 *   $this->routeBase       — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'dashboard';
?>
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>

    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-base-content">Account Dashboard</h2>
            <p class="text-sm text-base-content/70 mt-1">
                Welcome back, <?php echo htmlspecialchars($this->user->firstname ?? $this->user->username ?? ''); ?>
            </p>
        </div>
        <?php if ($this->twoFactorEnabled): ?>
            <span class="badge badge-success inline-flex items-center">
                &#10003; 2FA Active
            </span>
        <?php else: ?>
            <span class="badge badge-warning inline-flex items-center">
                &#9888; 2FA Inactive
            </span>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <!-- Main content -->
        <div class="md:col-span-3 space-y-6">

            <!-- Authorized apps -->
            <div class="card bg-base-100 shadow-sm">
                <div class="flex items-center justify-between px-4 py-3 border-b border-base-300">
                    <h3 class="font-semibold text-base-content">Authorized Applications</h3>
                    <a href="<?php echo sURL . $routeBase; ?>/applications"
                       class="text-sm text-primary hover:underline">Manage</a>
                </div>
                <?php if (empty($this->authorizedApps)): ?>
                    <p class="px-4 py-4 text-sm text-base-content/60">No authorized applications.</p>
                <?php else: ?>
                    <ul class="divide-y divide-base-300">
                        <?php foreach (array_slice($this->authorizedApps, 0, 3) as $app): ?>
                            <li class="flex items-center justify-between px-4 py-3">
                                <div>
                                    <span class="font-medium text-sm text-base-content">
                                        <?php echo htmlspecialchars($app['name']); ?>
                                    </span>
                                    <?php if (!empty($app['description'])): ?>
                                        <p class="text-xs text-base-content/60 mt-0.5">
                                            <?php echo htmlspecialchars($app['description']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <span class="text-xs text-base-content/60">
                                    <?php echo (int) $app['token_count']; ?> token<?php echo $app['token_count'] != 1 ? 's' : ''; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                        <?php if (count($this->authorizedApps) > 3): ?>
                            <li class="px-4 py-3 text-center text-sm text-primary">
                                <a href="<?php echo sURL . $routeBase; ?>/applications">
                                    + <?php echo count($this->authorizedApps) - 3; ?> more
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Recent activity -->
            <div class="card bg-base-100 shadow-sm">
                <div class="px-4 py-3 border-b border-base-300 font-semibold text-base-content">
                    Recent Activity
                </div>
                <?php if (empty($this->recentActivity)): ?>
                    <p class="px-4 py-4 text-sm text-base-content/60">No recent activity.</p>
                <?php else: ?>
                    <ul class="divide-y divide-base-300">
                        <?php foreach ($this->recentActivity as $entry): ?>
                            <li class="px-4 py-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-base-content">
                                        <?php echo htmlspecialchars($entry['action']); ?>
                                    </span>
                                    <span class="text-xs text-base-content/60">
                                        <?php echo htmlspecialchars($entry['created_at']); ?>
                                    </span>
                                </div>
                                <?php if (!empty($entry['ip_address'])): ?>
                                    <span class="text-xs text-base-content/60">
                                        from <?php echo htmlspecialchars($entry['ip_address']); ?>
                                    </span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
