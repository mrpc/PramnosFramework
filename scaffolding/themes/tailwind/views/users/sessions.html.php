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
    <div class="flex items-center gap-3 mb-4">
        <a href="<?php echo sURL; ?>Users" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">&larr; Back</a>
        <h2 >Sessions — <?php echo htmlspecialchars($this->user['username'] ?? ''); ?></h2>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th>Session ID</th><th>IP Address</th><th>User Agent</th><th>Started</th><th>Last Active</th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->sessionList ?? []) as $s): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars(substr($s['sessionid'] ?? '', 0, 16)) . '…'; ?></code></td>
                        <td><?php echo htmlspecialchars($s['ip_address'] ?? ''); ?></td>
                        <td class="text-gray-400 text-xs"><?php echo htmlspecialchars(substr($s['user_agent'] ?? '', 0, 60)); ?></td>
                        <td><?php echo htmlspecialchars($s['created_at'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($s['last_active'] ?? ''); ?></td>
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
