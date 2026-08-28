<?php
/**
 * Token Action detail (plain-CSS theme).
 *
 * Variables:
 *   $this->action — audit log row (tokenactions.* + username/email/endpoint/tokentype/ipaddress)
 */
$a  = $this->action ?? [];
$st = (int) ($a['servertime'] ?? 0);
?>
<style>
.pf-dl{margin:0}
.pf-dl div{display:flex;border-bottom:1px solid #f0f0f0;padding:8px 0}
.pf-dl dt{font-weight:600;min-width:160px;color:#444}
.pf-dl dd{margin:0;color:#333;word-break:break-word}
</style>
<?php $this->activeNav = 'tokenactions_show'; ?>
<div class="page-section" style="max-width:860px">
    <?php $this->insert('../partials/admin_breadcrumb'); ?>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
        <h2 style="margin:0">Audit Log #<?php echo (int)($a['actionid'] ?? 0); ?></h2>
    </div>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-body" style="padding:16px">
            <dl class="pf-dl">
                <div><dt>User</dt><dd><?php echo htmlspecialchars((string)($a['username'] ?? '')); ?><?php echo !empty($a['email']) ? ' (' . htmlspecialchars((string)$a['email']) . ')' : ''; ?></dd></div>
                <div><dt>Endpoint</dt><dd><code><?php echo htmlspecialchars((string)($a['endpoint'] ?? ('url #' . (int)($a['urlid'] ?? 0)))); ?></code></dd></div>
                <div><dt>Method</dt><dd><?php echo htmlspecialchars((string)($a['method'] ?? '')); ?></dd></div>
                <div><dt>HTTP Status</dt><dd><?php echo $a['return_status'] !== null ? (int)$a['return_status'] : '—'; ?></dd></div>
                <div><dt>Execution Time</dt><dd><?php echo $a['execution_time_ms'] !== null ? number_format((float)$a['execution_time_ms'], 2) . ' ms' : '—'; ?></dd></div>
                <div><dt>Token</dt><dd>#<?php echo (int)($a['tokenid'] ?? 0); ?><?php echo !empty($a['tokentype']) ? ' (' . htmlspecialchars((string)$a['tokentype']) . ')' : ''; ?></dd></div>
                <?php if (!empty($a['ipaddress'])): ?>
                <div><dt>IP Address</dt><dd><?php echo htmlspecialchars((string)$a['ipaddress']); ?></dd></div>
                <?php endif; ?>
                <div><dt>Timestamp</dt><dd><?php echo $st > 0 ? htmlspecialchars(localDateTime( $st)) : htmlspecialchars((string)($a['action_time'] ?? '')); ?></dd></div>
            </dl>
        </div>
    </div>

    <?php if (!empty($a['params']) && $a['params'] !== '[]' && $a['params'] !== '""'): ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Request Params</div>
        <div class="card-body" style="padding:16px"><pre style="margin:0;white-space:pre-wrap;word-break:break-all;font-size:.85em"><?php
            $params = (string) $a['params'];
            $decoded = json_decode($params, true);
            echo htmlspecialchars($decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $params);
        ?></pre></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($a['return_data'])): ?>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px">
        <div class="card-header" style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Response Data</div>
        <div class="card-body" style="padding:16px"><pre style="margin:0;white-space:pre-wrap;word-break:break-all;font-size:.85em"><?php
            $rd = is_string($a['return_data']) ? $a['return_data'] : json_encode($a['return_data']);
            $decoded = json_decode((string) $rd, true);
            echo htmlspecialchars($decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $rd);
        ?></pre></div>
    </div>
    <?php endif; ?>
</div>
