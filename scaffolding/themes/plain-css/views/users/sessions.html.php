<?php
/**
 * User sessions list (plain-CSS theme).
 *
 * Variables:
 *   $this->user        — user row array
 *   $this->sessionList — iterable session rows
 */
?>
<div class="page-section">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <a href="<?php echo sURL; ?>Users" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 >Sessions — <?php echo htmlspecialchars($this->user['username'] ?? ''); ?></h2>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px" style="padding:0">
            <table style="width:100%;border-collapse:collapse">
                <thead style="background:#f5f5f5">
                    <tr><th>Session ID</th><th>IP Address</th><th>User Agent</th><th>Started</th><th>Last Active</th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->sessionList ?? []) as $s): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars(substr($s['sessionid'] ?? '', 0, 16)) . '…'; ?></code></td>
                        <td><?php echo htmlspecialchars($s['ip'] ?? ''); ?></td>
                        <td style="color:#888;font-size:0.8em"><?php echo htmlspecialchars(substr($s['useragent'] ?? '', 0, 60)); ?></td>
                        <td><?php echo htmlspecialchars(isset($s['date']) ? date('d/m/Y H:i', $s['date']) : ''); ?></td>
                        <td></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->sessionList)): ?>
                    <tr><td colspan="5" style="text-align:center;color:#888;padding:24px">No active sessions.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
