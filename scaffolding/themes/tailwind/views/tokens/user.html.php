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
    $map = [0 => ['bg-base-200 text-base-content/80', 'Inactive'], 1 => ['bg-success/10 text-success', 'Active'], 2 => ['bg-neutral text-white', 'Deleted'], 3 => ['bg-error/10 text-error', 'Revoked']];
    [$cls, $txt] = $map[$s] ?? ['bg-base-200 text-base-content/80', 'Unknown'];
    return '<span class="inline-block px-2 py-0.5 rounded-sm text-xs font-medium ' . $cls . '">' . $txt . '</span>';
};
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'users_tokens'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold">Tokens — <?php echo htmlspecialchars($user['username'] ?? ''); ?></h2>
    </div>

    <div class="card bg-base-100 border border-base-300 shadow-xs overflow-x-auto">
        <table class="table table-sm text-sm">
            <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
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
            <tbody class="divide-y divide-base-300">
            <?php foreach ($tokens as $tok): ?>
                <?php
                $tokenId   = (int) ($tok['tokenid'] ?? 0);
                $status    = (int) ($tok['status']  ?? 0);
                $exp       = (int) ($tok['expires']  ?? 0);
                $isExpired = $exp > 0 && $exp < time();
                $rowBg     = $isExpired ? 'bg-warning/10' : '';
                $tokActionsUrl = adminUrl('TokenActions') . '?token_id=' . $tokenId . '&from=user&uid=' . $uid;
                ?>
                <tr class="hover:bg-base-200 cursor-pointer <?php echo $rowBg; ?>"
                    data-href="<?php echo $tokActionsUrl; ?>" title="View token actions">
                    <td class="px-4 py-2 font-mono text-xs text-base-content/70"><?php echo $tokenId; ?></td>
                    <td class="px-4 py-2"><span class="inline-block px-2 py-0.5 bg-base-200 text-base-content/80 text-xs rounded-sm"><?php echo htmlspecialchars($tok['tokentype'] ?? 'auth'); ?></span></td>
                    <td class="px-4 py-2"><?php echo $statusBadge($status); ?></td>
                    <td class="px-4 py-2 text-xs text-base-content/60"><?php echo htmlspecialchars($tok['ipaddress'] ?? '—'); ?></td>
                    <td class="px-4 py-2 text-xs"><?php echo ($tok['created'] ?? 0) > 0 ? localDateTime( (int)$tok['created']) : '—'; ?></td>
                    <td class="px-4 py-2 text-xs"><?php echo ($tok['lastused'] ?? 0) > 0 ? localDateTime( (int)$tok['lastused']) : '—'; ?></td>
                    <td class="px-4 py-2 text-xs <?php echo $isExpired ? 'text-warning' : ''; ?>">
                        <?php echo $exp > 0 ? localDateTime( $exp) . ($isExpired ? ' (expired)' : '') : 'Never'; ?>
                    </td>
                    <td class="px-4 py-2 text-right flex gap-1 justify-end">
                        <?php if ($status === 1): ?>
                            <form method="post" action="<?php echo adminUrl('Tokens/deactivate'); ?>">
                                <input type="hidden" name="userid" value="<?php echo $uid; ?>">
                                <input type="hidden" name="tokenid" value="<?php echo $tokenId; ?>">
                                <button type="submit" class="btn btn-outline btn-warning btn-xs">Deactivate</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?php echo adminUrl('Tokens/delete'); ?>">
                            <input type="hidden" name="userid" value="<?php echo $uid; ?>">
                            <input type="hidden" name="tokenid" value="<?php echo $tokenId; ?>">
                            <button type="submit" class="btn btn-outline btn-error btn-xs"
                                data-confirm="Delete this token?">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($tokens)): ?>
                <tr><td colspan="8" class="px-4 py-8 text-center text-base-content/60">No tokens found for this user.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
