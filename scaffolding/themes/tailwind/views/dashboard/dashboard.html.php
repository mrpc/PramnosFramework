<?php
/**
 * Account Dashboard overview (Tailwind theme).
 *
 * Variables:
 *   $this->user            — User object
 *   $this->authorizedApps  — array[] {appid, name, apikey, description, last_used, token_count}
 *   $this->recentActivity  — array[] {action, created_at, ip_address, user_agent}
 *   $this->twoFactorEnabled — bool
 */
?>
<div class="max-w-4xl mx-auto px-4 py-8">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Account Dashboard</h2>
            <p class="text-sm text-gray-500 mt-1">
                Welcome back, <?php echo htmlspecialchars($this->user->firstname ?? $this->user->username ?? ''); ?>
            </p>
        </div>
        <?php if ($this->twoFactorEnabled): ?>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                &#10003; 2FA Active
            </span>
        <?php else: ?>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">
                &#9888; 2FA Inactive
            </span>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <!-- Navigation -->
        <div class="md:col-span-1">
            <div class="bg-white rounded-lg shadow divide-y divide-gray-100">
                <div class="px-4 py-3 font-semibold text-gray-700 bg-gray-50 rounded-t-lg">Account Settings</div>
                <?php
                $routeBase = $this->routeBase ?? 'Dashboard';
                $navItems = [
                    ['href' => $routeBase . '/applications', 'label' => 'Authorized Applications'],
                    ['href' => $routeBase . '/security',     'label' => 'Security'],
                    ['href' => $routeBase . '/privacy',      'label' => 'Privacy Settings'],
                    ['href' => $routeBase . '/changepassword','label' => 'Change Password'],
                    ['href' => 'TwoFactorAuth',               'label' => 'Two-Factor Auth'],
                    ['href' => $routeBase . '/exportdata',   'label' => 'Export My Data'],
                ];
                foreach ($navItems as $item): ?>
                    <a href="<?php echo sURL . $item['href']; ?>"
                       class="block px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                        <?php echo $item['label']; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Main content -->
        <div class="md:col-span-2 space-y-6">

            <!-- Authorized apps -->
            <div class="bg-white rounded-lg shadow">
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-700">Authorized Applications</h3>
                    <a href="<?php echo sURL . ($this->routeBase ?? 'Dashboard'); ?>/applications"
                       class="text-sm text-blue-600 hover:underline">Manage</a>
                </div>
                <?php if (empty($this->authorizedApps)): ?>
                    <p class="px-4 py-4 text-sm text-gray-400">No authorized applications.</p>
                <?php else: ?>
                    <ul class="divide-y divide-gray-100">
                        <?php foreach (array_slice($this->authorizedApps, 0, 3) as $app): ?>
                            <li class="flex items-center justify-between px-4 py-3">
                                <div>
                                    <span class="font-medium text-sm text-gray-800">
                                        <?php echo htmlspecialchars($app['name']); ?>
                                    </span>
                                    <?php if (!empty($app['description'])): ?>
                                        <p class="text-xs text-gray-400 mt-0.5">
                                            <?php echo htmlspecialchars($app['description']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <span class="text-xs text-gray-400">
                                    <?php echo (int) $app['token_count']; ?> token<?php echo $app['token_count'] != 1 ? 's' : ''; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                        <?php if (count($this->authorizedApps) > 3): ?>
                            <li class="px-4 py-3 text-center text-sm text-blue-600">
                                <a href="<?php echo sURL . ($this->routeBase ?? 'Dashboard'); ?>/applications">
                                    + <?php echo count($this->authorizedApps) - 3; ?> more
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Recent activity -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-4 py-3 border-b border-gray-100 font-semibold text-gray-700">
                    Recent Activity
                </div>
                <?php if (empty($this->recentActivity)): ?>
                    <p class="px-4 py-4 text-sm text-gray-400">No recent activity.</p>
                <?php else: ?>
                    <ul class="divide-y divide-gray-100">
                        <?php foreach ($this->recentActivity as $entry): ?>
                            <li class="px-4 py-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-700">
                                        <?php echo htmlspecialchars($entry['action']); ?>
                                    </span>
                                    <span class="text-xs text-gray-400">
                                        <?php echo htmlspecialchars($entry['created_at']); ?>
                                    </span>
                                </div>
                                <?php if (!empty($entry['ip_address'])): ?>
                                    <span class="text-xs text-gray-400">
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
