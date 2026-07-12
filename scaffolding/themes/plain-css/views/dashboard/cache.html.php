<?php
/**
 * Cache details page (plain-CSS theme).
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
$card   = 'border:1px solid #ddd;border-radius:4px;margin-bottom:16px;overflow:hidden';
?>
<style>
.cc-table { width:100%;border-collapse:collapse }
.cc-table th,.cc-table td { padding:6px 10px;border-bottom:1px solid #eee;font-size:.85rem;vertical-align:middle }
.cc-table thead tr { background:#f5f5f5 }
.cc-badge { display:inline-block;padding:2px 8px;border-radius:12px;font-size:.78rem;font-weight:600 }
.cc-badge-success { background:#d4edda;color:#155724 }
.cc-badge-danger  { background:#f8d7da;color:#721c24 }
.cc-badge-warning { background:#fff3cd;color:#856404 }
.cc-badge-info    { background:#d1ecf1;color:#0c5460 }
.cc-badge-secondary { background:#e2e3e5;color:#383d41 }
.cc-badge-primary { background:#cce5ff;color:#004085 }
.cc-key { cursor:pointer;font-family:monospace;color:#0d6efd;padding:2px 4px;border-radius:3px }
.cc-key:hover { background:#0d6efd;color:#fff }
.cc-btn  { font-size:.75rem;padding:2px 8px;cursor:pointer;border:1px solid #aaa;border-radius:3px;background:#fff }
.cc-detail-modal {
    position:fixed;top:0;left:0;width:100%;height:100%;
    background:rgba(0,0,0,.5);display:none;z-index:1000;overflow-y:auto
}
.cc-detail-content {
    position:relative;margin:3% auto;background:#fff;border-radius:6px;
    width:820px;max-width:96%;padding:20px;max-height:90vh;overflow-y:auto
}
</style>

<script>
function escHtml(t) { var d=document.createElement('div');d.textContent=t;return d.innerHTML; }

function viewCacheItem(key) {
    document.getElementById('cc-detail-key').textContent = key;
    document.getElementById('cc-detail-body').innerHTML = '<p style="text-align:center;color:#888">Loading…</p>';
    document.getElementById('cc-detail-modal').style.display = 'block';
    fetch('<?php echo sURL; ?>dashboard/cacheitem?key=' + encodeURIComponent(key))
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (!data.success) {
                document.getElementById('cc-detail-body').innerHTML = '<p style="color:#dc3545">' + escHtml(data.error || 'Error') + '</p>';
                return;
            }
            var m = data.metadata || {};
            var html = '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;font-size:.85rem">';
            if (m.size)    html += '<span><strong>Size:</strong> '    + escHtml(String(m.size))    + '</span>';
            if (m.created) html += '<span><strong>Created:</strong> ' + escHtml(String(m.created)) + '</span>';
            if (m.ttl)     html += '<span><strong>TTL:</strong> '     + escHtml(String(m.ttl))     + '</span>';
            if (m.type)    html += '<span><strong>Type:</strong> '    + escHtml(String(m.type))    + '</span>';
            html += '</div><strong>Content:</strong><pre style="margin:8px 0 0;white-space:pre-wrap;word-break:break-all;font-size:.8rem;background:#f8f8f8;padding:12px;border-radius:4px;max-height:360px;overflow-y:auto">';
            var c = data.content;
            html += (typeof c === 'object' ? escHtml(JSON.stringify(c, null, 2)) : escHtml(String(c))) + '</pre>';
            document.getElementById('cc-detail-body').innerHTML = html;
        })
        .catch(function(e) {
            document.getElementById('cc-detail-body').innerHTML = '<p style="color:#dc3545">' + escHtml(e.message) + '</p>';
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

<div class="page-section" style="padding:16px">
    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <a href="<?php echo sURL; ?>dashboard" style="font-size:.85rem">&larr; Dashboard</a>
            <h2 style="margin:0">Cache Details</h2>
            <span class="cc-badge cc-badge-secondary"><?php echo htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <button id="clearCacheBtn" class="cc-btn" style="border-color:#dc3545;color:#dc3545;padding:4px 12px">
            Clear All Cache
        </button>
    </div>

    <!-- Overview -->
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div style="flex:1;min-width:140px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
            <div style="font-size:1.1rem;font-weight:700;margin-bottom:4px">
                <?php echo htmlspecialchars(ucfirst($method), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div style="font-size:.8rem;color:<?php echo $cacheStatus ? '#198754' : '#dc3545'; ?>">
                <?php echo $cacheStatus ? 'Active' : 'Inactive'; ?>
            </div>
        </div>
        <div style="flex:1;min-width:140px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
            <div style="font-size:1.4rem;font-weight:700;color:#0d6efd"><?php echo (int) ($cacheStats['categories'] ?? 0); ?></div>
            <div style="font-size:.8rem;color:#888">Namespaces</div>
        </div>
        <div style="flex:1;min-width:140px;border:1px solid #ddd;border-radius:4px;padding:12px;text-align:center">
            <div style="font-size:1.4rem;font-weight:700;color:#198754"><?php echo (int) ($cacheStats['items'] ?? 0); ?></div>
            <div style="font-size:.8rem;color:#888">Total Items</div>
        </div>
    </div>

    <!-- Namespace Stats -->
    <?php if (!empty($namespaceStats)): ?>
    <div style="<?php echo $card; ?>">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Cache by Namespace</div>
        <div style="padding:16px;display:flex;flex-wrap:wrap;gap:12px">
            <?php foreach ($namespaceStats as $ns => $count): ?>
            <div style="border:1px solid #ddd;border-radius:4px;padding:10px 16px;text-align:center;min-width:120px">
                <div style="font-size:1.2rem;font-weight:700;color:#0d6efd"><?php echo (int) $count; ?></div>
                <div class="db-mono" style="font-size:.8rem;color:#888"><?php echo htmlspecialchars($ns !== '' ? $ns : 'default', ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Categories toggle -->
    <?php if (!empty($cacheCategories)): ?>
    <div style="margin-bottom:12px">
        <button class="cc-btn" id="toggleCategoriesBtn" style="padding:4px 12px">
            Categories (<?php echo count($cacheCategories); ?>) ▼
        </button>
    </div>
    <div id="categoriesSection" style="display:none;<?php echo $card; ?>">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd">Cache Categories</div>
        <ul style="list-style:none;margin:0;padding:0">
        <?php foreach ($cacheCategories as $cat): ?>
            <li style="padding:8px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;font-size:.85rem">
                <span class="db-mono"><?php echo htmlspecialchars($cat !== '' ? $cat : 'default', ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if (isset($namespaceStats[$cat])): ?>
                    <span class="cc-badge cc-badge-primary"><?php echo (int) $namespaceStats[$cat]; ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
    <script>
    document.getElementById('toggleCategoriesBtn').addEventListener('click', function() {
        var s = document.getElementById('categoriesSection');
        s.style.display = s.style.display === 'none' ? 'block' : 'none';
        this.textContent = s.style.display === 'none'
            ? 'Categories (<?php echo count($cacheCategories); ?>) ▼'
            : 'Categories (<?php echo count($cacheCategories); ?>) ▲';
    });
    </script>
    <?php endif; ?>

    <!-- Cache Items -->
    <div style="<?php echo $card; ?>">
        <div style="padding:10px 16px;font-weight:600;background:#f5f5f5;border-bottom:1px solid #ddd;display:flex;justify-content:space-between">
            <span>Cache Items</span>
            <span class="cc-badge cc-badge-secondary"><?php echo count($cacheItems); ?></span>
        </div>
        <div style="overflow-x:auto">
            <?php if ($memcachedLimitation): ?>
                <div style="margin:16px;padding:12px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;font-size:.85rem">
                    <strong>Memcached Limitation:</strong> <?php echo htmlspecialchars($memcachedLimitationMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php elseif (empty($cacheItems)): ?>
                <p style="text-align:center;color:#888;padding:16px 0">No cache items available.</p>
            <?php else: ?>
            <table class="cc-table">
                <thead>
                    <tr><th>Key</th><th>Namespace</th><th style="text-align:right">Size</th><th>Created</th><th>TTL</th><th>Type</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
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

                    if ($ttl === null)   $ttlTxt = '—';
                    elseif ($ttl === -1) $ttlTxt = 'Never';
                    elseif ($ttl <= 0)   $ttlTxt = 'Expired';
                    else                 $ttlTxt = $ttl.'s';
                    $ttlCls = $ttl === -1 ? 'success' : ($ttl !== null && $ttl <= 0 ? 'danger' : ($ttl !== null ? 'info' : 'secondary'));
                ?>
                <tr style="<?php echo $expired ? 'background:#fff8e1' : ''; ?>">
                    <td>
                        <code class="cc-key" data-cache-key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                              title="Click to view"><?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?></code>
                    </td>
                    <td><span class="cc-badge cc-badge-secondary"><?php echo htmlspecialchars($ns !== '' ? $ns : 'default', ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td style="text-align:right;color:#888"><?php echo $sizeStr; ?></td>
                    <td style="color:#888"><?php echo htmlspecialchars($created, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="cc-badge cc-badge-<?php echo $ttlCls; ?>"><?php echo $ttlTxt; ?></span></td>
                    <td style="color:#888"><?php echo htmlspecialchars($itype, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <?php if ($expired): ?>
                            <span class="cc-badge cc-badge-danger">Expired</span>
                        <?php elseif (!empty($item['note'])): ?>
                            <span class="cc-badge cc-badge-warning">Info</span>
                        <?php else: ?>
                            <span class="cc-badge cc-badge-success">Active</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="cc-btn" style="border-color:#0d6efd;color:#0d6efd"
                                data-cache-key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">View</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($cacheItems) >= 50): ?>
                <p style="padding:8px 16px;color:#888;font-size:.85rem">Showing first 50 items.</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Cache Item Detail Modal -->
<div id="cc-detail-modal" class="cc-detail-modal">
    <div class="cc-detail-content">
        <h6 style="margin:0 0 6px">Cache Item Details</h6>
        <p style="margin:0 0 12px;font-family:monospace;font-size:.85rem" id="cc-detail-key"></p>
        <div id="cc-detail-body"></div>
        <div style="text-align:right;margin-top:12px">
            <button id="closeCacheDetailBtn" class="cc-btn" style="padding:4px 12px">Close</button>
        </div>
    </div>
</div>
