<?php
/**
 * Token Action detail (Bootstrap theme).
 *
 * Variables:
 *   $this->action — audit log row array
 */
$a = $this->action ?? [];
?>
<div class="container py-4" style="max-width:860px">
    <div class="d-flex align-items-center gap-3 mb-3">
        <a href="<?php echo sURL; ?>TokenActions" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 class="mb-0">Audit Log #<?php echo (int)($a['id'] ?? 0); ?></h2>
    </div>
    <div class="card mb-3">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Endpoint</dt>
                <dd class="col-sm-9"><code><?php echo htmlspecialchars($a['endpoint'] ?? $a['action'] ?? ''); ?></code></dd>
                <dt class="col-sm-3">HTTP Status</dt>
                <dd class="col-sm-9"><?php echo (int)($a['status_code'] ?? 0); ?></dd>
                <dt class="col-sm-3">Execution Time</dt>
                <dd class="col-sm-9"><?php echo number_format($a['execution_time'] ?? 0, 2); ?> ms</dd>
                <dt class="col-sm-3">Token ID</dt>
                <dd class="col-sm-9"><?php echo (int)($a['token_id'] ?? 0); ?></dd>
                <dt class="col-sm-3">Timestamp</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars($a['servertime'] ?? $a['created_at'] ?? ''); ?></dd>
                <?php if (!empty($a['ip_address'])): ?>
                <dt class="col-sm-3">IP Address</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars($a['ip_address']); ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>
    <?php if (!empty($a['request_params'])): ?>
    <div class="card mb-3">
        <div class="card-header fw-semibold">Request Params</div>
        <div class="card-body"><pre class="mb-0 small"><?php echo htmlspecialchars($a['request_params']); ?></pre></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($a['response_data'])): ?>
    <div class="card">
        <div class="card-header fw-semibold">Response Data</div>
        <div class="card-body"><pre class="mb-0 small"><?php echo htmlspecialchars($a['response_data']); ?></pre></div>
    </div>
    <?php endif; ?>
</div>
