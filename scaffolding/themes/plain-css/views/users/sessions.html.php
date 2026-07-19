<?php
/**
 * User sessions list (plain-CSS theme).
 *
 * Variables:
 *   $this->user        — user row array
 *   $this->sessionList — iterable session rows
 */
?>
<?php $this->activeNav = 'users_sessions'; ?>
<div class="page-section">
    <?php $this->insert('../partials/admin_breadcrumb'); ?>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <h2 >Sessions — <?php echo htmlspecialchars($this->user['username'] ?? ''); ?></h2>
    </div>
    <style>
    .pf-table{width:100%;border-collapse:collapse}
    .pf-table th,.pf-table td{text-align:left;padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
    .pf-table thead th{background:#f5f5f5;border-bottom:1px solid #e5e5e5;font-weight:600}
    </style>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:0">
            <table class="pf-table">
                <thead>
                    <tr><th>Session ID</th><th>IP Address</th><th>User Agent</th><th>Last Active</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->sessionList ?? []) as $s): ?>
                    <?php $active = (int) ($s['logout'] ?? 0) === 0; ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars(substr((string) ($s['visitorid'] ?? ''), 0, 16)) . '…'; ?></code></td>
                        <td><?php echo htmlspecialchars($s['host_addr'] ?? ''); ?></td>
                        <td style="color:#888;font-size:0.8em"><?php echo htmlspecialchars(substr((string) ($s['agent'] ?? ''), 0, 60)); ?></td>
                        <td><?php echo isset($s['time']) ? htmlspecialchars(date('d/m/Y H:i', (int) $s['time'])) : ''; ?></td>
                        <td>
                            <?php echo $active
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-secondary">Logged out</span>'; ?>
                        </td>
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
