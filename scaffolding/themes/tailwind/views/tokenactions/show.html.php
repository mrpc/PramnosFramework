<?php
/**
 * Token Action detail (Tailwind theme).
 *
 * Variables:
 *   $this->action — audit log row (tokenactions.* + username/email/endpoint/tokentype/ipaddress)
 */
$a  = $this->action ?? [];
$st = (int) ($a['servertime'] ?? 0);
?>
<div class="max-w-4xl mx-auto py-6 px-4">
    <?php $this->activeNav = 'tokenactions_show'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex items-center gap-3 mb-4">
        <h2 >Audit Log #<?php echo (int)($a['actionid'] ?? 0); ?></h2>
    </div>
    <div class="bg-white rounded-xl shadow-xs border border-gray-200 mb-4">
        <div class="p-5">
            <dl >
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">User</dt>
                <dd class="inline"><?php echo htmlspecialchars((string)($a['username'] ?? '')); ?><?php echo !empty($a['email']) ? ' (' . htmlspecialchars((string)$a['email']) . ')' : ''; ?></dd>
                <div class="block"></div>
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">Endpoint</dt>
                <dd class="inline"><code><?php echo htmlspecialchars((string)($a['endpoint'] ?? ('url #' . (int)($a['urlid'] ?? 0)))); ?></code></dd>
                <div class="block"></div>
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">Method</dt>
                <dd class="inline"><?php echo htmlspecialchars((string)($a['method'] ?? '')); ?></dd>
                <div class="block"></div>
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">HTTP Status</dt>
                <dd class="inline"><?php echo $a['return_status'] !== null ? (int)$a['return_status'] : '—'; ?></dd>
                <div class="block"></div>
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">Execution Time</dt>
                <dd class="inline"><?php echo $a['execution_time_ms'] !== null ? number_format((float)$a['execution_time_ms'], 2) . ' ms' : '—'; ?></dd>
                <div class="block"></div>
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">Token</dt>
                <dd class="inline">#<?php echo (int)($a['tokenid'] ?? 0); ?><?php echo !empty($a['tokentype']) ? ' (' . htmlspecialchars((string)$a['tokentype']) . ')' : ''; ?></dd>
                <div class="block"></div>
                <?php if (!empty($a['ipaddress'])): ?>
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">IP Address</dt>
                <dd class="inline"><?php echo htmlspecialchars((string)$a['ipaddress']); ?></dd>
                <div class="block"></div>
                <?php endif; ?>
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">Timestamp</dt>
                <dd class="inline"><?php echo $st > 0 ? htmlspecialchars(date('Y-m-d H:i:s', $st)) : htmlspecialchars((string)($a['action_time'] ?? '')); ?></dd>
            </dl>
        </div>
    </div>
    <?php if (!empty($a['params']) && $a['params'] !== '[]' && $a['params'] !== '""'): ?>
    <div class="bg-white rounded-xl shadow-xs border border-gray-200 mb-4">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm">Request Params</div>
        <div class="p-5"><pre class="text-sm" style="white-space:pre-wrap;word-break:break-all"><?php
            $params = (string) $a['params'];
            $decoded = json_decode($params, true);
            echo htmlspecialchars($decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $params);
        ?></pre></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($a['return_data'])): ?>
    <div class="bg-white rounded-xl shadow-xs border border-gray-200">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm">Response Data</div>
        <div class="p-5"><pre class="text-sm" style="white-space:pre-wrap;word-break:break-all"><?php
            $rd = is_string($a['return_data']) ? $a['return_data'] : json_encode($a['return_data']);
            $decoded = json_decode((string) $rd, true);
            echo htmlspecialchars($decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $rd);
        ?></pre></div>
    </div>
    <?php endif; ?>
</div>
