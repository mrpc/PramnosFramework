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
    <h2 class="text-2xl font-bold text-base-content mb-6">Security Overview</h2>

    <?php if ($this->hasMessages()): ?>
        <div class="alert alert-success mb-4">
            <?php echo $this->_printMessages(); ?>
        </div>
    <?php endif; ?>
    <?php if ($this->hasErrors()): ?>
        <div class="alert alert-error mb-4">
            <?php echo $this->_printErrors(); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <?php $this->insert('../partials/account_sidebar'); ?>

        <div class="md:col-span-3 space-y-4">
            <!-- 2FA -->
            <div class="card bg-base-100 shadow-sm">
                <div class="px-4 py-3 border-b border-base-300 font-semibold text-base-content">
                    Two-Factor Authentication
                </div>
                <div class="flex items-center justify-between px-4 py-4">
                    <div>
                        <?php if ($this->twoFactorEnabled): ?>
                            <span class="badge badge-success badge-sm mr-2">
                                Enabled
                            </span>
                            <span class="text-sm text-base-content/80">Your account is protected with two-factor authentication.</span>
                        <?php else: ?>
                            <span class="badge badge-warning badge-sm mr-2">
                                Disabled
                            </span>
                            <span class="text-sm text-base-content/80">Enable 2FA to protect your account.</span>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo sURL; ?>TwoFactorAuth"
                       class="text-sm text-primary hover:underline whitespace-nowrap">
                        <?php echo $this->twoFactorEnabled ? 'Manage 2FA' : 'Enable 2FA'; ?>
                    </a>
                </div>
            </div>

            <!-- Passkeys -->
            <div class="card bg-base-100 shadow-sm">
                <div class="flex items-center justify-between px-4 py-4">
                    <span class="text-sm text-base-content/80">Sign in without a password using your device's fingerprint, face or screen lock.</span>
                    <a href="<?php echo sURL; ?>Passkey"
                       class="text-sm text-primary hover:underline whitespace-nowrap">
                        Manage Passkeys
                    </a>
                </div>
            </div>

            <!-- Change password -->
            <div class="card bg-base-100 shadow-sm">
                <div class="flex items-center justify-between px-4 py-4">
                    <span class="text-sm text-base-content/80">Change your account password regularly to stay secure.</span>
                    <a href="<?php echo sURL . $routeBase; ?>/changepassword"
                       class="text-sm text-primary hover:underline whitespace-nowrap">
                        Change Password
                    </a>
                </div>
            </div>

            <!-- Active sessions -->
            <div class="card bg-base-100 shadow-sm">
                <div class="px-4 py-3 border-b border-base-300 font-semibold text-base-content">
                    Active Sessions
                </div>
                <?php if (empty($this->activeSessions)): ?>
                    <p class="px-4 py-4 text-sm text-base-content/60">No active sessions.</p>
                <?php else: ?>
                    <ul class="divide-y divide-base-200">
                        <?php foreach ($this->activeSessions as $s): ?>
                            <?php $isCurrent = (string) ($s['sid'] ?? '') === (string) ($this->currentSid ?? ''); ?>
                            <li class="flex items-center justify-between px-4 py-3">
                                <div>
                                    <div class="text-sm text-base-content"><?php echo htmlspecialchars(substr((string) ($s['agent'] ?? 'Unknown device'), 0, 80)); ?></div>
                                    <div class="text-xs text-base-content/60">
                                        <?php echo htmlspecialchars((string) ($s['host_addr'] ?? '')); ?>
                                        &middot; <?php echo htmlspecialchars(date('Y-m-d H:i', (int) ($s['time'] ?? 0))); ?>
                                    </div>
                                </div>
                                <?php if ($isCurrent): ?>
                                    <span class="badge badge-success badge-sm">This device</span>
                                <?php else: ?>
                                    <form method="post" action="<?php echo sURL . $routeBase; ?>/revokesession" class="m-0">
                                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                        <input type="hidden" name="sid" value="<?php echo htmlspecialchars((string) ($s['sid'] ?? '')); ?>">
                                        <button type="submit" class="text-sm text-error hover:underline">Sign out</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Activity log -->
            <div class="card bg-base-100 shadow-sm">
                <div class="px-4 py-3 border-b border-base-300 font-semibold text-base-content">
                    Recent Account Activity
                </div>
                <?php if (empty($this->recentActivity)): ?>
                    <p class="px-4 py-4 text-sm text-base-content/60">No activity recorded yet.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="table table-sm text-sm">
                            <thead class="bg-base-200 text-xs font-semibold text-base-content/70 uppercase tracking-wide">
                                <tr>
                                    <th class="px-4 py-3 text-left">Event</th>
                                    <th class="px-4 py-3 text-left">Date</th>
                                    <th class="px-4 py-3 text-left">IP Address</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-base-200">
                                <?php foreach ($this->recentActivity as $entry): ?>
                                    <tr class="hover:bg-base-200">
                                        <td class="px-4 py-3 text-base-content"><?php echo htmlspecialchars($entry['action']); ?></td>
                                        <td class="px-4 py-3 text-base-content/60"><?php echo htmlspecialchars($entry['created_at']); ?></td>
                                        <td class="px-4 py-3 text-base-content/60"><?php echo htmlspecialchars($entry['ip_address'] ?? '—'); ?></td>
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
