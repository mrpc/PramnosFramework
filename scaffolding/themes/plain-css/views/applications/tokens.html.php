<?php
/**
 * Tokens for an application (plain-CSS theme).
 *
 * Variables:
 *   $this->app    — application row array
 *   $this->tokens — iterable token rows
 */
?>
<div class="page-section">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <a href="<?php echo sURL; ?>Applications" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 >Tokens — <?php echo htmlspecialchars($this->app['name'] ?? ''); ?></h2>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
                    <tr><th>Token ID</th><th>User ID</th><th>Scope</th><th>Last Used</th><th>Expires</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->tokens ?? []) as $tok): ?>
                    <tr>
                        <td><?php echo (int)$tok['tokenid']; ?></td>
                        <td><?php echo (int)($tok['userid'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($tok['scope'] ?? ''); ?></td>
                        <td style="color:#888;font-size:0.8em"><?php echo htmlspecialchars($tok['lastused'] ?? ''); ?></td>
                        <td style="color:#888;font-size:0.8em"><?php echo !empty($tok['expires']) ? htmlspecialchars($tok['expires']) : '—'; ?></td>
                        <td style="text-align:right">
                            <a href="<?php echo sURL; ?>Tokens/revoke/<?php echo (int)$tok['tokenid']; ?>" class="btn btn-sm btn-outline-danger" data-confirm="Revoke token?">Revoke</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->tokens)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#888;padding:24px">No active tokens.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
