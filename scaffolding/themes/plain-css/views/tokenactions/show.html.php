<?php
/**
 * Token Action detail (plain-CSS theme).
 *
 * Variables:
 *   $this->action — audit log row array
 */
$a = $this->action ?? [];
?>
<div class="page-section"max-width:860px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <a href="<?php echo sURL; ?>TokenActions" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
        <h2 >Audit Log #<?php echo (int)($a['id'] ?? 0); ?></h2>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <dl >
                <dt style="font-weight:600;min-width:140px;display:inline-block">Endpoint</dt>
                <dd ><code><?php echo htmlspecialchars($a['endpoint'] ?? $a['action'] ?? ''); ?></code></dd>
                <dt style="font-weight:600;min-width:140px;display:inline-block">HTTP Status</dt>
                <dd ><?php echo (int)($a['status_code'] ?? 0); ?></dd>
                <dt style="font-weight:600;min-width:140px;display:inline-block">Execution Time</dt>
                <dd ><?php echo number_format($a['execution_time'] ?? 0, 2); ?> ms</dd>
                <dt style="font-weight:600;min-width:140px;display:inline-block">Token ID</dt>
                <dd ><?php echo (int)($a['token_id'] ?? 0); ?></dd>
                <dt style="font-weight:600;min-width:140px;display:inline-block">Timestamp</dt>
                <dd ><?php echo htmlspecialchars($a['servertime'] ?? $a['created_at'] ?? ''); ?></dd>
                <?php if (!empty($a['ip_address'])): ?>
                <dt style="font-weight:600;min-width:140px;display:inline-block">IP Address</dt>
                <dd ><?php echo htmlspecialchars($a['ip_address']); ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>
    <?php if (!empty($a['request_params'])): ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Request Params</div>
        <div class="card-body" style="padding:16px"><pre class="mb-0 small"><?php echo htmlspecialchars($a['request_params']); ?></pre></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($a['response_data'])): ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Response Data</div>
        <div class="card-body" style="padding:16px"><pre class="mb-0 small"><?php echo htmlspecialchars($a['response_data']); ?></pre></div>
    </div>
    <?php endif; ?>
</div>
