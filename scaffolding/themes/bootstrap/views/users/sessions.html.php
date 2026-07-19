<?php
/**
 * User sessions list (Bootstrap theme).
 *
 * Variables:
 *   $this->user        — user row array
 *   $this->sessionList — iterable session rows
 */
?>
<div class="container-fluid py-4">
    <?php $this->activeNav = 'users_sessions'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="d-flex align-items-center gap-3 mb-3">
        <h2 class="mb-0">Sessions — <?php echo htmlspecialchars($this->user['username'] ?? ''); ?></h2>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Session ID</th><th>IP Address</th><th>User Agent</th><th>Last Active</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->sessionList ?? []) as $s): ?>
                    <?php $active = (int) ($s['logout'] ?? 0) === 0; ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars(substr((string) ($s['visitorid'] ?? ''), 0, 16)) . '…'; ?></code></td>
                        <td><?php echo htmlspecialchars($s['host_addr'] ?? ''); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars(substr((string) ($s['agent'] ?? ''), 0, 60)); ?></td>
                        <td><?php echo isset($s['time']) ? htmlspecialchars(date('d/m/Y H:i', (int) $s['time'])) : ''; ?></td>
                        <td><span class="badge bg-<?php echo $active ? 'success' : 'secondary'; ?>"><?php echo $active ? 'Active' : 'Logged out'; ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->sessionList)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No active sessions.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
