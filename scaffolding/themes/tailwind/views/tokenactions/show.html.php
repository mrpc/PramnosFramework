<?php
/**
 * Token Action detail (Tailwind theme).
 *
 * Variables:
 *   $this->action — audit log row array
 */
$a = $this->action ?? [];
?>
<div class="max-w-4xl mx-auto py-6 px-4">
    <div class="flex items-center gap-3 mb-4">
        <a href="<?php echo sURL; ?>TokenActions" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">&larr; Back</a>
        <h2 >Audit Log #<?php echo (int)($a['id'] ?? 0); ?></h2>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-4">
        <div class="p-5">
            <dl >
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">Endpoint</dt>
                <dd ><code><?php echo htmlspecialchars($a['endpoint'] ?? $a['action'] ?? ''); ?></code></dd>
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">HTTP Status</dt>
                <dd ><?php echo (int)($a['status_code'] ?? 0); ?></dd>
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">Execution Time</dt>
                <dd ><?php echo number_format($a['execution_time'] ?? 0, 2); ?> ms</dd>
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">Token ID</dt>
                <dd ><?php echo (int)($a['token_id'] ?? 0); ?></dd>
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">Timestamp</dt>
                <dd ><?php echo htmlspecialchars($a['servertime'] ?? $a['created_at'] ?? ''); ?></dd>
                <?php if (!empty($a['ip_address'])): ?>
                <dt class="font-semibold text-gray-600 text-sm w-40 inline-block">IP Address</dt>
                <dd ><?php echo htmlspecialchars($a['ip_address']); ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>
    <?php if (!empty($a['request_params'])): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-4">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm">Request Params</div>
        <div class="p-5"><pre class="mb-0 small"><?php echo htmlspecialchars($a['request_params']); ?></pre></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($a['response_data'])): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm">Response Data</div>
        <div class="p-5"><pre class="mb-0 small"><?php echo htmlspecialchars($a['response_data']); ?></pre></div>
    </div>
    <?php endif; ?>
</div>
