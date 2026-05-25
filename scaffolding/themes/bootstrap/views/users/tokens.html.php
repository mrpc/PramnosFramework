<?php
/**
 * User token management view (Bootstrap theme).
 *
 * Variables:
 *   $this->user      — ['userid', 'username']
 *   $this->tokenList — array of token rows from User::getAllTokens()
 */
$user   = $this->user ?? [];
$tokens = $this->tokenList ?? [];
$uid    = (int) ($user['userid'] ?? 0);

$statusLabel = function (int $s): string {
    $map = [0 => ['secondary', 'Inactive'], 1 => ['success', 'Active'], 2 => ['dark', 'Deleted'], 3 => ['danger', 'Revoked']];
    [$cls, $txt] = $map[$s] ?? ['secondary', 'Unknown'];
    return '<span class="badge bg-' . $cls . '">' . $txt . '</span>';
};
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">Tokens — <?php echo htmlspecialchars($user['username'] ?? ''); ?></h2>
        <a href="<?php echo sURL; ?>users/edit/<?php echo $uid; ?>" class="btn btn-outline-secondary btn-sm">Back to User</a>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>IP Address</th>
                        <th>Created</th>
                        <th>Last Used</th>
                        <th>Expires</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tokens as $tok): ?>
                    <?php
                    $tokenId = (int) ($tok['tokenid'] ?? 0);
                    $status  = (int) ($tok['status']  ?? 0);
                    $exp     = (int) ($tok['expires']  ?? 0);
                    $isExpired = $exp > 0 && $exp < time();
                    ?>
                    <tr class="<?php echo $isExpired ? 'table-warning' : ''; ?>">
                        <td><code class="small"><?php echo $tokenId; ?></code></td>
                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($tok['tokentype'] ?? 'auth'); ?></span></td>
                        <td><?php echo $statusLabel($status); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($tok['ipaddress'] ?? '—'); ?></td>
                        <td class="small"><?php echo ($tok['created'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$tok['created']) : '—'; ?></td>
                        <td class="small"><?php echo ($tok['lastused'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$tok['lastused']) : '—'; ?></td>
                        <td class="small <?php echo $isExpired ? 'text-warning' : ''; ?>">
                            <?php echo $exp > 0 ? date('Y-m-d H:i', $exp) . ($isExpired ? ' <em>(expired)</em>' : '') : 'Never'; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($status === 1): ?>
                                <form method="post" action="<?php echo sURL; ?>users/deactivateToken" style="display:inline">
                                    <input type="hidden" name="userid" value="<?php echo $uid; ?>">
                                    <input type="hidden" name="tokenid" value="<?php echo $tokenId; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">Deactivate</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?php echo sURL; ?>users/deleteToken" style="display:inline">
                                <input type="hidden" name="userid" value="<?php echo $uid; ?>">
                                <input type="hidden" name="tokenid" value="<?php echo $tokenId; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm('Delete this token permanently?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($tokens)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No tokens found for this user.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
