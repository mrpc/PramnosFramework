<?php
/**
 * User token management view (Tailwind theme).
 *
 * Variables:
 *   $this->user      — ['userid', 'username']
 *   $this->tokenList — array of token rows from User::getAllTokens()
 */
$user   = $this->user ?? [];
$tokens = $this->tokenList ?? [];
$uid    = (int) ($user['userid'] ?? 0);

$statusBadge = function (int $s): string {
    $map = [0 => ['bg-gray-100 text-gray-600', 'Inactive'], 1 => ['bg-green-100 text-green-700', 'Active'], 2 => ['bg-gray-800 text-white', 'Deleted'], 3 => ['bg-red-100 text-red-700', 'Revoked']];
    [$cls, $txt] = $map[$s] ?? ['bg-gray-100 text-gray-600', 'Unknown'];
    return '<span class="inline-block px-2 py-0.5 rounded text-xs font-medium ' . $cls . '">' . $txt . '</span>';
};
?>
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold">Tokens — <?php echo htmlspecialchars($user['username'] ?? ''); ?></h2>
        <a href="<?php echo sURL; ?>users/edit/<?php echo $uid; ?>" class="px-3 py-1.5 border border-gray-300 text-gray-700 text-sm rounded hover:bg-gray-50">Back to User</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                <tr>
                    <th class="px-4 py-3 text-left">ID</th>
                    <th class="px-4 py-3 text-left">Type</th>
                    <th class="px-4 py-3 text-left">Status</th>
                    <th class="px-4 py-3 text-left">IP</th>
                    <th class="px-4 py-3 text-left">Created</th>
                    <th class="px-4 py-3 text-left">Last Used</th>
                    <th class="px-4 py-3 text-left">Expires</th>
                    <th class="px-4 py-3 text-right w-40"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            <?php foreach ($tokens as $tok): ?>
                <?php
                $tokenId   = (int) ($tok['tokenid'] ?? 0);
                $status    = (int) ($tok['status']  ?? 0);
                $exp       = (int) ($tok['expires']  ?? 0);
                $isExpired = $exp > 0 && $exp < time();
                $rowBg     = $isExpired ? 'bg-yellow-50' : '';
                ?>
                <tr class="hover:bg-gray-50 <?php echo $rowBg; ?>">
                    <td class="px-4 py-2 font-mono text-xs text-gray-500"><?php echo $tokenId; ?></td>
                    <td class="px-4 py-2"><span class="inline-block px-2 py-0.5 bg-gray-100 text-gray-600 text-xs rounded"><?php echo htmlspecialchars($tok['tokentype'] ?? 'auth'); ?></span></td>
                    <td class="px-4 py-2"><?php echo $statusBadge($status); ?></td>
                    <td class="px-4 py-2 text-xs text-gray-400"><?php echo htmlspecialchars($tok['ipaddress'] ?? '—'); ?></td>
                    <td class="px-4 py-2 text-xs"><?php echo ($tok['created'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$tok['created']) : '—'; ?></td>
                    <td class="px-4 py-2 text-xs"><?php echo ($tok['lastused'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$tok['lastused']) : '—'; ?></td>
                    <td class="px-4 py-2 text-xs <?php echo $isExpired ? 'text-yellow-600' : ''; ?>">
                        <?php echo $exp > 0 ? date('Y-m-d H:i', $exp) . ($isExpired ? ' (expired)' : '') : 'Never'; ?>
                    </td>
                    <td class="px-4 py-2 text-right flex gap-1 justify-end">
                        <?php if ($status === 1): ?>
                            <form method="post" action="<?php echo sURL; ?>users/deactivateToken">
                                <input type="hidden" name="userid" value="<?php echo $uid; ?>">
                                <input type="hidden" name="tokenid" value="<?php echo $tokenId; ?>">
                                <button type="submit" class="px-2 py-1 text-xs border border-yellow-400 text-yellow-700 rounded hover:bg-yellow-50">Deactivate</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?php echo sURL; ?>users/deleteToken">
                            <input type="hidden" name="userid" value="<?php echo $uid; ?>">
                            <input type="hidden" name="tokenid" value="<?php echo $tokenId; ?>">
                            <button type="submit" class="px-2 py-1 text-xs border border-red-300 text-red-700 rounded hover:bg-red-50"
                                onclick="return confirm('Delete this token?')">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($tokens)): ?>
                <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">No tokens found for this user.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
