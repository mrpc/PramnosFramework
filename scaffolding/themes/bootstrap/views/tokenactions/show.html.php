<?php
/**
 * Token Action detail (Bootstrap theme).
 *
 * Variables:
 *   $this->action — audit log row (tokenactions.* + username/email/endpoint/tokentype/ipaddress)
 */
$a  = $this->action ?? [];
$st = (int) ($a['servertime'] ?? 0);
?>
<div class="container py-4" style="max-width:860px">
    <?php $this->activeNav = 'tokenactions_show'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="d-flex align-items-center gap-3 mb-3">
        <h2 class="mb-0">Audit Log #<?php echo (int)($a['actionid'] ?? 0); ?></h2>
    </div>
    <div class="card mb-3">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">User</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars((string)($a['username'] ?? '')); ?><?php echo !empty($a['email']) ? ' (' . htmlspecialchars((string)$a['email']) . ')' : ''; ?></dd>
                <dt class="col-sm-3">Endpoint</dt>
                <dd class="col-sm-9"><code><?php echo htmlspecialchars((string)($a['endpoint'] ?? ('url #' . (int)($a['urlid'] ?? 0)))); ?></code></dd>
                <dt class="col-sm-3">Method</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars((string)($a['method'] ?? '')); ?></dd>
                <dt class="col-sm-3">HTTP Status</dt>
                <dd class="col-sm-9"><?php echo $a['return_status'] !== null ? (int)$a['return_status'] : '—'; ?></dd>
                <dt class="col-sm-3">Execution Time</dt>
                <dd class="col-sm-9"><?php echo $a['execution_time_ms'] !== null ? number_format((float)$a['execution_time_ms'], 2) . ' ms' : '—'; ?></dd>
                <dt class="col-sm-3">Token</dt>
                <dd class="col-sm-9">#<?php echo (int)($a['tokenid'] ?? 0); ?><?php echo !empty($a['tokentype']) ? ' (' . htmlspecialchars((string)$a['tokentype']) . ')' : ''; ?></dd>
                <?php if (!empty($a['ipaddress'])): ?>
                <dt class="col-sm-3">IP Address</dt>
                <dd class="col-sm-9"><?php echo htmlspecialchars((string)$a['ipaddress']); ?></dd>
                <?php endif; ?>
                <dt class="col-sm-3">Timestamp</dt>
                <dd class="col-sm-9"><?php echo $st > 0 ? htmlspecialchars(localDateTime( $st)) : htmlspecialchars((string)($a['action_time'] ?? '')); ?></dd>
            </dl>
        </div>
    </div>

    <?php if (!empty($a['params']) && $a['params'] !== '[]' && $a['params'] !== '""'): ?>
    <div class="card mb-3">
        <div class="card-header fw-semibold">Request Params</div>
        <div class="card-body"><pre class="mb-0 small" style="white-space:pre-wrap;word-break:break-all"><?php
            $params = (string) $a['params'];
            $decoded = json_decode($params, true);
            echo htmlspecialchars($decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $params);
        ?></pre></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($a['return_data'])): ?>
    <div class="card">
        <div class="card-header fw-semibold">Response Data</div>
        <div class="card-body"><pre class="mb-0 small" style="white-space:pre-wrap;word-break:break-all"><?php
            $rd = is_string($a['return_data']) ? $a['return_data'] : json_encode($a['return_data']);
            $decoded = json_decode((string) $rd, true);
            echo htmlspecialchars($decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $rd);
        ?></pre></div>
    </div>
    <?php endif; ?>
</div>
