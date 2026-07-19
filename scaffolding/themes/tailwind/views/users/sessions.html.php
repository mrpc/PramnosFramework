<?php
/**
 * User sessions list (Tailwind theme).
 *
 * Variables:
 *   $this->user        — user row array
 *   $this->sessionList — iterable session rows
 */
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'users_sessions'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex items-center gap-3 mb-4">
        <h2 >Sessions — <?php echo htmlspecialchars($this->user['username'] ?? ''); ?></h2>
    </div>
    <div class="bg-white rounded-xl shadow-xs border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th>Session ID</th><th>IP Address</th><th>User Agent</th><th>Last Active</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->sessionList ?? []) as $s): ?>
                    <?php $active = (int) ($s['logout'] ?? 0) === 0; ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap font-mono text-sm text-gray-500"><?php echo htmlspecialchars(substr((string) ($s['visitorid'] ?? ''), 0, 16)) . '…'; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($s['host_addr'] ?? ''); ?></td>
                        <td class="px-6 py-4 text-sm text-gray-500"><?php echo htmlspecialchars(substr((string) ($s['agent'] ?? ''), 0, 60)); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo isset($s['time']) ? htmlspecialchars(date('d/m/Y H:i', (int) $s['time'])) : ''; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm"><span class="px-2 py-0.5 rounded-full text-xs font-medium <?php echo $active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'; ?>"><?php echo $active ? 'Active' : 'Logged out'; ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->sessionList)): ?>
                    <tr><td colspan="5" class="text-center text-gray-400 py-8">No active sessions.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
