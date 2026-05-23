<?php
/**
 * Health Checks compact table — Tailwind theme.
 *
 * Variables (set by Health::display() or custom controller):
 *   $this->overallStatus — 'ok' | 'degraded' | 'down'
 *   $this->checks        — array<name, array{status, message, details}>
 */

$badgeColor = match ($this->overallStatus) {
    'ok'       => 'bg-green-100 text-green-800',
    'degraded' => 'bg-yellow-100 text-yellow-800',
    'down'     => 'bg-red-100 text-red-800',
    default    => 'bg-gray-100 text-gray-800',
};
?>
<div class="health-check-summary">
    <div class="flex items-center gap-2 mb-3">
        <span class="text-sm font-medium text-gray-600">Status:</span>
        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $badgeColor; ?> status-badge status-<?php echo htmlspecialchars($this->overallStatus); ?>">
            <?php echo strtoupper(htmlspecialchars($this->overallStatus)); ?>
        </span>
    </div>
    <table class="health-table w-full text-sm border border-gray-200 rounded">
        <thead>
            <tr class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase">
                <th class="px-3 py-2 border-b">Check</th>
                <th class="px-3 py-2 border-b">Status</th>
                <th class="px-3 py-2 border-b">Message</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <?php if (empty($this->checks)): ?>
            <tr><td colspan="3" class="px-3 py-3 text-gray-400">No health checks registered.</td></tr>
        <?php else: ?>
            <?php foreach ($this->checks as $name => $check): ?>
            <?php
                $rb = match ($check['status']) {
                    'ok'       => 'bg-green-100 text-green-800',
                    'degraded' => 'bg-yellow-100 text-yellow-800',
                    'down'     => 'bg-red-100 text-red-800',
                    default    => 'bg-gray-100 text-gray-800',
                };
            ?>
            <tr>
                <td class="px-3 py-2"><?php echo htmlspecialchars($name); ?></td>
                <td class="px-3 py-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $rb; ?> status-badge status-<?php echo htmlspecialchars($check['status']); ?>">
                        <?php echo strtoupper(htmlspecialchars($check['status'])); ?>
                    </span>
                </td>
                <td class="px-3 py-2 text-gray-600"><?php echo htmlspecialchars($check['message'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
