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
    <div class="d-flex align-items-center gap-3 mb-3">
        <a href="<?php echo sURL; ?>Users" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 class="mb-0">Sessions — <?php echo htmlspecialchars($this->user['username'] ?? ''); ?></h2>
    </div>
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Session ID</th><th>IP Address</th><th>User Agent</th><th>Started</th><th>Last Active</th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->sessionList ?? []) as $s): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars(substr($s['sessionid'] ?? '', 0, 16)) . '…'; ?></code></td>
                        <td><?php echo htmlspecialchars($s['ip_address'] ?? ''); ?></td>
                        <td class="text-muted small"><?php echo htmlspecialchars(substr($s['user_agent'] ?? '', 0, 60)); ?></td>
                        <td><?php echo htmlspecialchars($s['created_at'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($s['last_active'] ?? ''); ?></td>
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
