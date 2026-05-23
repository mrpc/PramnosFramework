<?php
/**
 * Tokens list (Tailwind theme).
 *
 * Variables:
 *   $this->tokens — iterable rows
 *   $this->page   — current page
 *   $this->total  — total count
 */
?>
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 >OAuth2 Tokens</h2>
        <form method="get" class="flex gap-2">
            <input type="number" name="userid" class="px-2 py-1 border border-gray-300 rounded text-sm" placeholder="User ID" value="<?php echo (int)($_GET['userid'] ?? 0) ?: ''; ?>">
            <input type="number" name="applicationid" class="px-2 py-1 border border-gray-300 rounded text-sm" placeholder="App ID" value="<?php echo (int)($_GET['applicationid'] ?? 0) ?: ''; ?>">
            <button class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">Filter</button>
        </form>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div >
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th>ID</th><th>User</th><th>Application</th><th>Scope</th><th>Last Used</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->tokens ?? []) as $tok): ?>
                    <tr>
                        <td><?php echo (int)$tok['tokenid']; ?></td>
                        <td><?php echo (int)($tok['userid'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($tok['appname'] ?? (string)($tok['applicationid'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars($tok['scope'] ?? ''); ?></td>
                        <td class="text-gray-400 text-xs"><?php echo htmlspecialchars($tok['lastused'] ?? ''); ?></td>
                        <td>
                            <?php echo (int)($tok['status'] ?? 1) === 1
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-secondary">Revoked</span>'; ?>
                        </td>
                        <td class="text-right">
                            <a href="<?php echo sURL; ?>Tokens/revoke/<?php echo (int)$tok['tokenid']; ?>" class="px-3 py-1 border border-red-300 text-red-700 text-xs rounded hover:bg-red-50" onclick="return confirm('Revoke token?')">Revoke</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->tokens)): ?>
                    <tr><td colspan="7" class="text-center text-gray-400 py-8">No tokens found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
