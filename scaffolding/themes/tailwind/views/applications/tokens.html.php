<?php
/**
 * Tokens for an application (Tailwind theme).
 *
 * Variables:
 *   $this->app    — application row array
 *   $this->tokens — iterable token rows
 */
?>
<div class="px-4 py-6">
    <div class="flex items-center gap-3 mb-4">
        <a href="<?php echo sURL; ?>Applications" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">&larr; Back</a>
        <h2 >Tokens — <?php echo htmlspecialchars($this->app['name'] ?? ''); ?></h2>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th>Token ID</th><th>User ID</th><th>Scope</th><th>Last Used</th><th>Expires</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->tokens ?? []) as $tok): ?>
                    <tr>
                        <td><?php echo (int)$tok['tokenid']; ?></td>
                        <td><?php echo (int)($tok['userid'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($tok['scope'] ?? ''); ?></td>
                        <td class="text-gray-400 text-xs"><?php echo htmlspecialchars($tok['lastused'] ?? ''); ?></td>
                        <td class="text-gray-400 text-xs"><?php echo !empty($tok['expires']) ? htmlspecialchars($tok['expires']) : '—'; ?></td>
                        <td class="text-right">
                            <a href="<?php echo sURL; ?>Tokens/revoke/<?php echo (int)$tok['tokenid']; ?>" class="px-3 py-1 border border-red-300 text-red-700 text-xs rounded hover:bg-red-50" data-confirm="Revoke token?">Revoke</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->tokens)): ?>
                    <tr><td colspan="6" class="text-center text-gray-400 py-8">No active tokens.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
