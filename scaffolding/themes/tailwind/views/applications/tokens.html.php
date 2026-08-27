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
        <a href="<?php echo adminUrl('Applications'); ?>" class="btn btn-outline btn-xs">&larr; Back</a>
        <h2 >Tokens — <?php echo htmlspecialchars($this->app['name'] ?? ''); ?></h2>
    </div>
    <div class="card bg-base-100 border border-base-300 shadow-xs">
        <div >
            <table class="table table-sm text-sm">
                <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                    <tr><th>Token ID</th><th>User ID</th><th>Scope</th><th>Last Used</th><th>Expires</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->tokens ?? []) as $tok): ?>
                    <tr>
                        <td><?php echo (int)$tok['tokenid']; ?></td>
                        <td><?php echo (int)($tok['userid'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($tok['scope'] ?? ''); ?></td>
                        <td class="text-base-content/60 text-xs"><?php echo htmlspecialchars($tok['lastused'] ?? ''); ?></td>
                        <td class="text-base-content/60 text-xs"><?php echo !empty($tok['expires']) ? htmlspecialchars($tok['expires']) : '—'; ?></td>
                        <td class="text-right">
                            <a href="<?php echo adminUrl('Tokens' . '/revoke/' . ((int)$tok['tokenid'])); ?>" class="btn btn-outline btn-error btn-xs" data-confirm="Revoke token?">Revoke</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->tokens)): ?>
                    <tr><td colspan="6" class="text-center text-base-content/60 py-8">No active tokens.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
