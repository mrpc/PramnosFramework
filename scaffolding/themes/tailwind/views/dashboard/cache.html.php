<?php
/**
 * Cache details page (Tailwind theme).
 *
 * Variables:
 *   $this->cacheStats                 — array from Cache::getStats(): method, categories, items
 *   $this->cacheStatus                — bool: true if cache is active
 *   $this->categories                 — raw array of category names/objects
 *   $this->items                      — array keyed by category name → array of item rows
 *   $this->namespaceStats             — assoc array: namespace → item count
 *   $this->cacheCategories            — flat array of category name strings
 *   $this->cacheItems                 — flat merged array of all items (with 'namespace' key added)
 *   $this->memcachedLimitation        — bool: true when Memcached prevents item enumeration
 *   $this->memcachedLimitationMessage — string: explanation of the Memcached limitation
 */
$cacheStats   = $this->cacheStats   ?? ['method' => 'unknown', 'categories' => 0, 'items' => 0];
$cacheStatus  = $this->cacheStatus  ?? false;
$namespaceStats  = $this->namespaceStats  ?? [];
$cacheCategories = $this->cacheCategories ?? [];
$cacheItems      = $this->cacheItems      ?? [];
$memcachedLimitation = $this->memcachedLimitation ?? false;
$memcachedLimitationMessage = $this->memcachedLimitationMessage ?? '';
$method = strtolower($cacheStats['method'] ?? 'unknown');
?>
<script>
function escHtml(t) { var d=document.createElement('div');d.textContent=t;return d.innerHTML; }

function viewCacheItem(key) {
    document.getElementById('cc-detail-key').textContent = key;
    document.getElementById('cc-detail-body').innerHTML = '<p class="text-center text-gray-400 py-4">Loading…</p>';
    document.getElementById('cc-detail-modal').style.display = 'block';
    fetch('<?php echo sURL; ?>dashboard/cacheitem?key=' + encodeURIComponent(key))
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (!data.success) {
                document.getElementById('cc-detail-body').innerHTML = '<p class="text-red-600">' + escHtml(data.error||'Error') + '</p>';
                return;
            }
            var m = data.metadata || {};
            var html = '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;font-size:.85rem">';
            if (m.size)    html += '<span><strong>Size:</strong> '    + escHtml(String(m.size))    + '</span>';
            if (m.created) html += '<span><strong>Created:</strong> ' + escHtml(String(m.created)) + '</span>';
            if (m.ttl)     html += '<span><strong>TTL:</strong> '     + escHtml(String(m.ttl))     + '</span>';
            if (m.type)    html += '<span><strong>Type:</strong> '    + escHtml(String(m.type))    + '</span>';
            html += '</div><strong>Content:</strong><pre style="white-space:pre-wrap;word-break:break-all;font-size:.8rem;background:#f8f8f8;padding:12px;border-radius:6px;max-height:360px;overflow-y:auto;margin-top:8px">';
            var c = data.content;
            html += (typeof c === 'object' ? escHtml(JSON.stringify(c, null, 2)) : escHtml(String(c))) + '</pre>';
            document.getElementById('cc-detail-body').innerHTML = html;
        })
        .catch(function(e) {
            document.getElementById('cc-detail-body').innerHTML = '<p class="text-red-600">' + escHtml(e.message) + '</p>';
        });
}

function closeCacheDetail() { document.getElementById('cc-detail-modal').style.display = 'none'; }

function clearAllCache() {
    if (!confirm('Clear all cache entries? This cannot be undone.')) return;
    var btn = document.getElementById('clearCacheBtn');
    btn.disabled = true; btn.textContent = 'Clearing…';
    fetch('<?php echo sURL; ?>dashboard/clearcache', {method:'POST',headers:{'Content-Type':'application/json'},body:'{}'})
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (d.success) location.reload();
            else { alert('Error: ' + (d.error||'Unknown')); btn.disabled=false; btn.textContent='Clear All Cache'; }
        })
        .catch(function(e){ alert('Error: '+e.message); btn.disabled=false; btn.textContent='Clear All Cache'; });
}

document.addEventListener('DOMContentLoaded', function() {
    var b1 = document.getElementById('clearCacheBtn');
    if (b1) b1.addEventListener('click', clearAllCache);
    var b2 = document.getElementById('closeCacheDetailBtn');
    if (b2) b2.addEventListener('click', closeCacheDetail);
});
document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-cache-key]');
    if (el) { e.preventDefault(); viewCacheItem(el.getAttribute('data-cache-key')); }
});
</script>

<div class="px-4 py-6">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div class="flex flex-wrap items-center gap-3">
            <a href="<?php echo sURL; ?>dashboard" class="text-sm text-blue-600 hover:underline">&larr; Dashboard</a>
            <h2 class="mb-0">Cache Details</h2>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-200 text-gray-700">
                <?php echo htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>
        <button id="clearCacheBtn" class="text-sm border border-red-400 text-red-600 rounded px-4 py-1.5 hover:bg-red-50">
            Clear All Cache
        </button>
    </div>

    <!-- Overview -->
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <div class="text-lg font-bold mb-1"><?php echo htmlspecialchars(ucfirst($method), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="text-xs <?php echo $cacheStatus ? 'text-green-600' : 'text-red-600'; ?>">
                <?php echo $cacheStatus ? 'Active' : 'Inactive'; ?>
            </div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-blue-600 mb-1"><?php echo (int) ($cacheStats['categories'] ?? 0); ?></div>
            <div class="text-xs text-gray-500">Namespaces</div>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
            <div class="text-2xl font-bold text-green-600 mb-1"><?php echo (int) ($cacheStats['items'] ?? 0); ?></div>
            <div class="text-xs text-gray-500">Total Items</div>
        </div>
    </div>

    <!-- Namespace Stats -->
    <?php if (!empty($namespaceStats)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm">Cache by Namespace</div>
        <div class="p-5 flex flex-wrap gap-4">
            <?php foreach ($namespaceStats as $ns => $count): ?>
            <div class="border border-gray-200 rounded-lg px-5 py-3 text-center min-w-24">
                <div class="text-xl font-bold text-blue-600"><?php echo (int) $count; ?></div>
                <div class="text-xs text-gray-500 font-mono"><?php echo htmlspecialchars($ns !== '' ? $ns : 'default', ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Categories toggle -->
    <?php if (!empty($cacheCategories)): ?>
    <div class="mb-4">
        <button id="toggleCatBtn" class="text-sm border border-gray-300 rounded px-3 py-1.5 hover:bg-gray-50">
            Categories (<?php echo count($cacheCategories); ?>) ▼
        </button>
    </div>
    <div id="catSection" style="display:none" class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm">Cache Categories</div>
        <ul class="divide-y divide-gray-100">
            <?php foreach ($cacheCategories as $cat): ?>
            <li class="flex justify-between items-center px-5 py-2 text-sm">
                <span class="font-mono text-gray-700"><?php echo htmlspecialchars($cat !== '' ? $cat : 'default', ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if (isset($namespaceStats[$cat])): ?>
                    <span class="bg-blue-100 text-blue-800 text-xs px-2 py-0.5 rounded-full"><?php echo (int) $namespaceStats[$cat]; ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <script>
    document.getElementById('toggleCatBtn').addEventListener('click', function() {
        var s = document.getElementById('catSection');
        s.style.display = s.style.display === 'none' ? 'block' : 'none';
        this.textContent = s.style.display === 'none'
            ? 'Categories (<?php echo count($cacheCategories); ?>) ▼'
            : 'Categories (<?php echo count($cacheCategories); ?>) ▲';
    });
    </script>
    <?php endif; ?>

    <!-- Cache Items -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 font-semibold text-sm flex justify-between items-center">
            <span>Cache Items</span>
            <span class="bg-gray-200 text-gray-700 text-xs px-2 py-0.5 rounded-full"><?php echo count($cacheItems); ?></span>
        </div>
        <?php if ($memcachedLimitation): ?>
            <div class="m-4 p-4 bg-yellow-50 border border-yellow-300 rounded-lg text-sm">
                <strong>Memcached Limitation:</strong> <?php echo htmlspecialchars($memcachedLimitationMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php elseif (empty($cacheItems)): ?>
            <p class="text-center text-gray-400 py-6 text-sm">No cache items available.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-500 uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">Key</th>
                        <th class="px-3 py-2 text-left">Namespace</th>
                        <th class="px-3 py-2 text-right">Size</th>
                        <th class="px-3 py-2 text-left">Created</th>
                        <th class="px-3 py-2 text-left">TTL</th>
                        <th class="px-3 py-2 text-left">Type</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($cacheItems as $item):
                    if (!is_array($item)) continue;
                    $key     = $item['key'] ?? '';
                    $ns      = $item['namespace'] ?? '';
                    $size    = (int) ($item['size'] ?? 0);
                    $created = $item['created_time'] ?? '—';
                    $ttl     = isset($item['ttl']) ? (int) $item['ttl'] : null;
                    $itype   = $item['type'] ?? '—';
                    $expired = !empty($item['expired']) || ($ttl !== null && $ttl === 0);

                    if ($size >= 1048576)  $sizeStr = round($size/1048576, 2).' MB';
                    elseif ($size >= 1024) $sizeStr = round($size/1024, 2).' KB';
                    else                   $sizeStr = $size.' B';

                    if ($ttl === null)   { $ttlTxt = '—'; $ttlCls = 'bg-gray-100 text-gray-600'; }
                    elseif ($ttl === -1) { $ttlTxt = 'Never'; $ttlCls = 'bg-green-100 text-green-800'; }
                    elseif ($ttl <= 0)   { $ttlTxt = 'Expired'; $ttlCls = 'bg-red-100 text-red-800'; }
                    else                 { $ttlTxt = $ttl.'s'; $ttlCls = 'bg-blue-100 text-blue-800'; }
                ?>
                <tr class="hover:bg-gray-50 <?php echo $expired ? 'bg-yellow-50' : ''; ?>">
                    <td class="px-3 py-2">
                        <code class="font-mono text-blue-600 cursor-pointer hover:bg-blue-100 px-1 rounded"
                              data-cache-key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                              title="Click to view"><?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?></code>
                    </td>
                    <td class="px-3 py-2">
                        <span class="bg-gray-100 text-gray-700 text-xs px-2 py-0.5 rounded"><?php echo htmlspecialchars($ns !== '' ? $ns : 'default', ENT_QUOTES, 'UTF-8'); ?></span>
                    </td>
                    <td class="px-3 py-2 text-right text-gray-400"><?php echo $sizeStr; ?></td>
                    <td class="px-3 py-2 text-gray-400"><?php echo htmlspecialchars($created, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="px-3 py-2">
                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium <?php echo $ttlCls; ?>"><?php echo $ttlTxt; ?></span>
                    </td>
                    <td class="px-3 py-2 text-gray-500"><?php echo htmlspecialchars($itype, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="px-3 py-2">
                        <?php if ($expired): ?>
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">Expired</span>
                        <?php elseif (!empty($item['note'])): ?>
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800">Info</span>
                        <?php else: ?>
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Active</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-2">
                        <button class="text-xs border border-blue-400 text-blue-600 rounded px-2 py-0.5 hover:bg-blue-50"
                                data-cache-key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">View</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($cacheItems) >= 50): ?>
                <p class="px-5 py-2 text-gray-400 text-xs">Showing first 50 items.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Cache Item Detail Modal -->
<div id="cc-detail-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000;overflow-y:auto">
    <div style="position:relative;margin:3% auto;background:#fff;border-radius:8px;width:820px;max-width:96%;padding:24px;max-height:90vh;overflow-y:auto">
        <h6 style="margin:0 0 4px;font-size:1rem;font-weight:600">Cache Item Details</h6>
        <code id="cc-detail-key" style="font-size:.85rem;margin-bottom:12px;display:block"></code>
        <div id="cc-detail-body"></div>
        <div style="text-align:right;margin-top:12px">
            <button id="closeCacheDetailBtn" style="font-size:.8rem;padding:4px 16px;border:1px solid #ccc;border-radius:4px;background:#fff;cursor:pointer">Close</button>
        </div>
    </div>
</div>
