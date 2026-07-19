<?php
/**
 * Token Actions audit log list (Tailwind theme).
 *
 * Variables:
 *   $this->actions — iterable rows
 *   $this->page    — current page
 *   $this->total   — total count
 */
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'tokenactions'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex justify-between items-center mb-4">
        <h2 >API Audit Log</h2>
        <div class="flex gap-2">
            <button type="button" class="btn btn-outline-info btn-sm" data-stats-open data-stats-url="<?php echo sURL; ?>TokenActions/stats">Stats</button>
            <a href="<?php echo sURL; ?>TokenActions/export<?php echo !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : ''; ?>" class="btn btn-outline-secondary btn-sm">Export CSV</a>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-xs border border-gray-200 mb-4">
        <div class="px-4 py-2">
            <form method="get" class="flex flex-wrap gap-2 items-end">
                <div >
                    <input type="number" name="token_id" class="px-2 py-1 border border-gray-300 rounded-sm text-sm" placeholder="Token ID" value="<?php echo (int)($_GET['token_id'] ?? 0) ?: ''; ?>">
                </div>
                <div >
                    <input type="number" name="user_id" class="px-2 py-1 border border-gray-300 rounded-sm text-sm" placeholder="User ID" value="<?php echo (int)($_GET['user_id'] ?? 0) ?: ''; ?>">
                </div>
                <div >
                    <input type="number" name="status_code" class="px-2 py-1 border border-gray-300 rounded-sm text-sm" placeholder="HTTP Status" value="<?php echo (int)($_GET['status_code'] ?? 0) ?: ''; ?>">
                </div>
                <div >
                    <input type="date" name="date_from" class="px-2 py-1 border border-gray-300 rounded-sm text-sm" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>">
                </div>
                <div >
                    <input type="date" name="date_to" class="px-2 py-1 border border-gray-300 rounded-sm text-sm" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>">
                </div>
                <div >
                    <button class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded-sm hover:bg-gray-50">Filter</button>
                </div>
            </form>
        </div>
    </div>
    <style>
    .pf-tw-table{width:100%;border-collapse:collapse}
    .pf-tw-table th,.pf-tw-table td{text-align:left;padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
    .pf-tw-table thead th{background:#f9fafb;border-bottom:1px solid #e5e7eb;font-weight:600;font-size:.7rem;text-transform:uppercase;letter-spacing:.03em;color:#6b7280}
    .pf-tw-table tbody tr:hover{background:#f9fafb}
    .pf-tw-table td:last-child,.pf-tw-table th:last-child{text-align:right}
    .pf-badge{display:inline-block;padding:2px 8px;border-radius:9999px;font-size:.72rem;font-weight:600}
    </style>
    <div class="bg-white rounded-xl shadow-xs border border-gray-200 overflow-x-auto">
        <div >
            <table class="pf-tw-table text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr><th>ID</th><th>User</th><th>Endpoint</th><th>Method</th><th>Status</th><th>Time (ms)</th><th>When</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach (($this->actions ?? []) as $a): ?>
                    <tr>
                        <td><?php echo (int)$a['actionid']; ?></td>
                        <td class="text-gray-400 text-xs"><?php echo htmlspecialchars($a['username'] ?? ('#' . (int)($a['tokenid'] ?? 0))); ?></td>
                        <td class="truncate max-w-xs"><?php echo htmlspecialchars($a['endpoint'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($a['method'] ?? ''); ?></td>
                        <td>
                            <?php $sc = (int)($a['return_status'] ?? 0); ?>
                            <?php if ($sc <= 0): ?>
                                <span class="text-gray-400">—</span>
                            <?php else: ?>
                                <span class="pf-badge <?php echo $sc >= 500 ? 'bg-red-100 text-red-700' : ($sc >= 400 ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-700'); ?>"><?php echo $sc; ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $a['execution_time_ms'] !== null ? number_format((float)$a['execution_time_ms'], 0) : '—'; ?></td>
                        <td class="text-gray-400 text-xs"><?php
                            $st = (int)($a['servertime'] ?? 0);
                            echo $st > 0 ? htmlspecialchars(date('Y-m-d H:i', $st)) : '—';
                        ?></td>
                        <td><a href="<?php echo sURL; ?>TokenActions/show/<?php echo (int)$a['actionid']; ?>" class="px-3 py-1 border border-gray-300 text-gray-700 text-xs rounded-sm hover:bg-gray-50">View</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($this->actions)): ?>
                    <tr><td colspan="8" class="text-center text-gray-400 py-8">No records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Stats modal: pf-utils.js fetches data-stats-url (JSON) and renders it here,
     instead of navigating the browser to a raw JSON dump. Behaviour is wired via
     data-attributes (no inline JS) to comply with the nonce-based CSP. -->
<div id="pf-stats-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1050">
    <div style="max-width:720px;margin:5vh auto;background:#fff;border-radius:6px;box-shadow:0 10px 40px rgba(0,0,0,.3);max-height:90vh;overflow:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #eee">
            <h3 style="margin:0;font-size:18px">API Performance (last 24h)</h3>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-stats-close>&times; Close</button>
        </div>
        <div id="pf-stats-body" style="padding:18px">
            <p style="color:#888">Loading…</p>
        </div>
    </div>
</div>
