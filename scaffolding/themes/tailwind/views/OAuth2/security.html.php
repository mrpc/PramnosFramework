<?php
/**
 * Security Overview page (Tailwind theme).
 *
 * Variables:
 *   $this->recentActivity   — array[] {action, created_at, ip_address, user_agent}
 *   $this->twoFactorEnabled — bool
 */
?>
<div class="max-w-2xl mx-auto px-4 py-8">

    <p class="text-sm mb-4"><a href="<?php echo sURL; ?>Dashboard" class="text-blue-600 hover:underline">← Back to Dashboard</a></p>
    <h2 class="text-2xl font-bold text-gray-800 mb-6">Security Overview</h2>

    <?php if (!empty($_GET['message']) && $_GET['message'] === 'password_changed'): ?>
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-700 rounded">
            Your password has been updated successfully.
        </div>
    <?php endif; ?>

    <!-- 2FA -->
    <div class="bg-white rounded-lg shadow mb-4">
        <div class="px-4 py-3 border-b border-gray-100 font-semibold text-gray-700">
            Two-Factor Authentication
        </div>
        <div class="flex items-center justify-between px-4 py-4">
            <div>
                <?php if ($this->twoFactorEnabled): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 mr-2">
                        Enabled
                    </span>
                    <span class="text-sm text-gray-600">Your account is protected with two-factor authentication.</span>
                <?php else: ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 mr-2">
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

    <!-- Change password -->
    <div class="bg-white rounded-lg shadow mb-4">
        <div class="flex items-center justify-between px-4 py-4">
            <span class="text-sm text-gray-600">Change your account password regularly to stay secure.</span>
            <a href="<?php echo sURL; ?>Dashboard/changepassword"
               class="text-sm text-blue-600 hover:underline whitespace-nowrap">
                Change Password
            </a>
        </div>
    </div>

    <!-- Activity log -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-4 py-3 border-b border-gray-100 font-semibold text-gray-700">
            Recent Login Activity
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
