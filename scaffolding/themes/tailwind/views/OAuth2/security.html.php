<?php
/**
 * Security Overview page (Tailwind theme).
 *
 * Variables:
 *   $this->recentActivity   — array[] {action, created_at, ip_address, user_agent}
 *   $this->twoFactorEnabled — bool
 *   $this->activeSessions   — array[] {sid, host_addr, agent, time, url}
 *   $this->currentSid       — sid of the session viewing this page
 *   $this->routeBase        — Account controller route base
 */
$routeBase = $this->routeBase ?? 'Account';
$this->activeNav = 'security';
?>
<div class="container mx-auto px-4 py-8">

    <?php $this->insert('../partials/account_breadcrumb'); ?>
    <h2 class="text-2xl font-bold text-gray-800 mb-6">Security Overview</h2>

    <?php if ($this->hasMessages()): ?>
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded-sm">
            <?php echo $this->_printMessages(); ?>
        </div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-sm">
            <?php echo $this->_printErrors(); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3 space-y-4">
            <!-- 2FA -->
            <div class="bg-white rounded-lg shadow-sm">
                <div class="px-4 py-3 border-b border-gray-100 font-semibold text-gray-700">
                    Two-Factor Authentication
                </div>
                <div class="flex items-center justify-between px-4 py-4">
                    <div>
                        <?php if ($this->twoFactorEnabled): ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-green-100 text-green-800 mr-2">
                                Enabled
                            </span>
                            <span class="text-sm text-gray-600">Your account is protected with two-factor authentication.</span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-yellow-100 text-yellow-800 mr-2">
                                Disabled
                            </span>
                            <span class="text-sm text-gray-600">Enable 2FA to protect your account.</span>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo sURL; ?>TwoFactorAuth"
                       class="text-sm text-blue-600 hover:underline whitespace-nowrap">
                        <?php echo $this->twoFactorEnabled ? 'Manage 2FA' : 'Enable 2FA'; ?>
                    </a>
                </div>
            </div>

            <!-- Passkeys -->
            <div class="bg-white rounded-lg shadow-sm">
                <div class="flex items-center justify-between px-4 py-4">
                    <span class="text-sm text-gray-600">Sign in without a password using your device's fingerprint, face or screen lock.</span>
                    <a href="<?php echo sURL; ?>Passkey"
                       class="text-sm text-blue-600 hover:underline whitespace-nowrap">
                        Manage Passkeys
                    </a>
                </div>
            </div>

            <!-- Change password -->
            <div class="bg-white rounded-lg shadow-sm">
                <div class="flex items-center justify-between px-4 py-4">
                    <span class="text-sm text-gray-600">Change your account password regularly to stay secure.</span>
                    <a href="<?php echo sURL . $routeBase; ?>/changepassword"
                       class="text-sm text-blue-600 hover:underline whitespace-nowrap">
                        Change Password
                    </a>
                </div>
            </div>

            <!-- Active sessions -->
            <div class="bg-white rounded-lg shadow-sm">
                <div class="px-4 py-3 border-b border-gray-100 font-semibold text-gray-700">
                    Active Sessions
                </div>
                <?php if (empty($this->activeSessions)): ?>
                    <p class="px-4 py-4 text-sm text-gray-400">No active sessions.</p>
                <?php else: ?>
                    <ul class="divide-y divide-gray-50">
                        <?php foreach ($this->activeSessions as $s): ?>
                            <?php $isCurrent = (string) ($s['sid'] ?? '') === (string) ($this->currentSid ?? ''); ?>
                            <li class="flex items-center justify-between px-4 py-3">
                                <div>
                                    <div class="text-sm text-gray-800"><?php echo htmlspecialchars(substr((string) ($s['agent'] ?? 'Unknown device'), 0, 80)); ?></div>
                                    <div class="text-xs text-gray-400">
                                        <?php echo htmlspecialchars((string) ($s['host_addr'] ?? '')); ?>
                                        &middot; <?php echo htmlspecialchars(date('Y-m-d H:i', (int) ($s['time'] ?? 0))); ?>
                                    </div>
                                </div>
                                <?php if ($isCurrent): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium bg-green-100 text-green-800">This device</span>
                                <?php else: ?>
                                    <form method="post" action="<?php echo sURL . $routeBase; ?>/revokesession" class="m-0">
                                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                        <input type="hidden" name="sid" value="<?php echo htmlspecialchars((string) ($s['sid'] ?? '')); ?>">
                                        <button type="submit" class="text-sm text-red-600 hover:underline">Sign out</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Activity log -->
            <div class="bg-white rounded-lg shadow-sm">
                <div class="px-4 py-3 border-b border-gray-100 font-semibold text-gray-700">
                    Recent Account Activity
                </div>
                <?php if (empty($this->recentActivity)): ?>
                    <p class="px-4 py-4 text-sm text-gray-400">No activity recorded yet.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wide">
                                <tr>
                                    <th class="px-4 py-3 text-left">Event</th>
                                    <th class="px-4 py-3 text-left">Date</th>
                                    <th class="px-4 py-3 text-left">IP Address</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php foreach ($this->recentActivity as $entry): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-gray-800"><?php echo htmlspecialchars($entry['action']); ?></td>
                                        <td class="px-4 py-3 text-gray-400"><?php echo htmlspecialchars($entry['created_at']); ?></td>
                                        <td class="px-4 py-3 text-gray-400"><?php echo htmlspecialchars($entry['ip_address'] ?? '—'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
