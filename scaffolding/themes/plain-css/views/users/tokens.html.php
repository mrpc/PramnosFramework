<?php
/**
 * User token management view (plain-CSS theme).
 *
 * Variables:
 *   $this->user      — ['userid', 'username']
 *   $this->tokenList — array of token rows from User::getAllTokens()
 */
$user   = $this->user ?? [];
$tokens = $this->tokenList ?? [];
$uid    = (int) ($user['userid'] ?? 0);

$statusLabel = [0 => 'Inactive', 1 => 'Active', 2 => 'Deleted', 3 => 'Revoked'];
$statusColor = [0 => '#6c757d', 1 => '#28a745', 2 => '#343a40', 3 => '#dc3545'];
?>
<div class="page-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2>Tokens — <?php echo htmlspecialchars($user['username'] ?? ''); ?></h2>
        <a href="<?php echo sURL; ?>users/edit/<?php echo $uid; ?>" class="btn btn-outline-secondary">Back to User</a>
    </div>

    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div style="padding:0;overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <thead style="background:#f5f5f5">
                    <tr>
                        <th style="padding:8px 12px;text-align:left">ID</th>
                        <th style="padding:8px 12px;text-align:left">Type</th>
                        <th style="padding:8px 12px;text-align:left">Status</th>
                        <th style="padding:8px 12px;text-align:left">IP</th>
                        <th style="padding:8px 12px;text-align:left">Created</th>
                        <th style="padding:8px 12px;text-align:left">Last Used</th>
                        <th style="padding:8px 12px;text-align:left">Expires</th>
                        <th style="padding:8px 12px;width:160px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tokens as $tok): ?>
                    <?php
                    $tokenId   = (int) ($tok['tokenid'] ?? 0);
                    $status    = (int) ($tok['status']  ?? 0);
                    $exp       = (int) ($tok['expires']  ?? 0);
                    $isExpired = $exp > 0 && $exp < time();
                    $rowBg     = $isExpired ? 'background:#fffbea' : '';
                    ?>
                    <tr style="border-top:1px solid #f0f0f0;<?php echo $rowBg; ?>">
                        <td style="padding:6px 12px;font-family:monospace;color:#666"><?php echo $tokenId; ?></td>
                        <td style="padding:6px 12px"><span style="background:#e9ecef;padding:2px 6px;border-radius:3px;font-size:11px"><?php echo htmlspecialchars($tok['tokentype'] ?? 'auth'); ?></span></td>
                        <td style="padding:6px 12px"><span style="color:<?php echo $statusColor[$status] ?? '#666'; ?>;font-weight:600;font-size:12px"><?php echo $statusLabel[$status] ?? 'Unknown'; ?></span></td>
                        <td style="padding:6px 12px;color:#888;font-size:12px"><?php echo htmlspecialchars($tok['ipaddress'] ?? '—'); ?></td>
                        <td style="padding:6px 12px;font-size:12px"><?php echo ($tok['created'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$tok['created']) : '—'; ?></td>
                        <td style="padding:6px 12px;font-size:12px"><?php echo ($tok['lastused'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$tok['lastused']) : '—'; ?></td>
                        <td style="padding:6px 12px;font-size:12px;<?php echo $isExpired ? 'color:#856404' : ''; ?>">
                            <?php echo $exp > 0 ? date('Y-m-d H:i', $exp) . ($isExpired ? ' (exp)' : '') : 'Never'; ?>
                        </td>
                        <td style="padding:6px 12px;text-align:right">
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
                                    onclick="return confirm('Delete this token?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($tokens)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#888;padding:24px">No tokens found for this user.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
