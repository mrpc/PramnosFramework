<?php
/**
 * Cache details page (Bootstrap theme).
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

$methodBadge = match($method) {
    'redis'               => 'danger',
    'memcached', 'memcache' => 'warning',
    'file'                => 'info',
    default               => 'secondary',
};
?>
<style>
.cache-key-clickable {
    cursor: pointer;
    color: #0d6efd;
    transition: background 0.15s;
    padding: 2px 5px;
    border-radius: 3px;
}
.cache-key-clickable:hover { background: #0d6efd; color: #fff; }
.cache-detail-modal {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,.5); display: none; z-index: 1055; overflow-y: auto;
}
.cache-detail-content {
    position: relative; margin: 3% auto; background: #fff;
    border-radius: 8px; width: 820px; max-width: 96%; box-shadow: 0 4px 24px rgba(0,0,0,.15);
}
.cache-detail-header { padding: 1rem 1.5rem; border-bottom: 1px solid #dee2e6; background: #f8f9fa; border-radius: 8px 8px 0 0; }
.cache-detail-body   { padding: 1.5rem; max-height: 60vh; overflow-y: auto; }
.cache-detail-footer { padding: 1rem 1.5rem; border-top: 1px solid #dee2e6; background: #f8f9fa; border-radius: 0 0 8px 8px; text-align: right; }
</style>

<script>
function escapeHtml(text) {
    var d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}

function viewCacheItem(key) {
    document.getElementById('cache-detail-key').textContent = key;
    document.getElementById('cache-detail-content').innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm"></span> Loading…</div>';
    document.getElementById('cache-detail-modal').style.display = 'block';

    fetch('<?php echo sURL; ?>dashboard/cacheitem?key=' + encodeURIComponent(key))
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (!data.success) {
                document.getElementById('cache-detail-content').innerHTML =
                    '<div class="alert alert-danger">' + escapeHtml(data.error || 'Error') + '</div>';
                return;
            }
            var m = data.metadata || {};
            var html = '<div class="row g-2 mb-3 small">';
            if (m.size)    html += '<div class="col-6 col-md-3"><strong>Size:</strong> ' + escapeHtml(String(m.size)) + '</div>';
            if (m.created) html += '<div class="col-6 col-md-3"><strong>Created:</strong> ' + escapeHtml(String(m.created)) + '</div>';
            if (m.ttl)     html += '<div class="col-6 col-md-3"><strong>TTL:</strong> ' + escapeHtml(String(m.ttl)) + '</div>';
            if (m.type)    html += '<div class="col-6 col-md-3"><strong>Type:</strong> ' + escapeHtml(String(m.type)) + '</div>';
            html += '</div><h6>Content:</h6>';
            html += '<div style="background:#f8f9fa;padding:1rem;border-radius:4px;max-height:380px;overflow-y:auto">';
            var c = data.content;
            html += '<pre style="margin:0;white-space:pre-wrap;word-break:break-all;font-size:.8rem">'
                + (typeof c === 'object' ? escapeHtml(JSON.stringify(c, null, 2)) : escapeHtml(String(c)))
                + '</pre>';
            html += '</div>';
            document.getElementById('cache-detail-content').innerHTML = html;
        })
        .catch(function(err) {
            document.getElementById('cache-detail-content').innerHTML =
                '<div class="alert alert-danger">' + escapeHtml(err.message) + '</div>';
        });
}

function closeCacheDetail() {
    document.getElementById('cache-detail-modal').style.display = 'none';
}

function clearAllCache() {
    if (!confirm('Clear all cache entries? This cannot be undone.')) return;
    var btn = document.getElementById('clearAllCacheBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Clearing…';
    fetch('<?php echo sURL; ?>dashboard/clearcache', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: '{}'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { location.reload(); }
        else { alert('Error: ' + (data.error || 'Unknown error')); btn.disabled = false; btn.innerHTML = '<i class="bi bi-trash"></i> Clear All Cache'; }
    })
    .catch(function(e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-trash"></i> Clear All Cache';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('clearAllCacheBtn');
    if (btn) btn.addEventListener('click', clearAllCache);
    var closeBtn = document.getElementById('closeCacheDetailBtn');
    if (closeBtn) closeBtn.addEventListener('click', closeCacheDetail);
});

document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-cache-key]');
    if (el) { e.preventDefault(); viewCacheItem(el.getAttribute('data-cache-key')); }
});
</script>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between gap-2 mb-4 flex-wrap">
        <div class="d-flex align-items-center gap-2">
            <a href="<?php echo sURL; ?>dashboard" class="btn btn-sm btn-outline-secondary">&larr; Dashboard</a>
            <h2 class="mb-0">Cache Details</h2>
            <span class="badge bg-<?php echo $methodBadge; ?> <?php echo $method === 'memcached' ? 'text-dark' : ''; ?>">
                <?php echo htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>
        <button type="button" id="clearAllCacheBtn" class="btn btn-sm btn-danger">
            <i class="bi bi-trash"></i> Clear All Cache
        </button>
    </div>

    <!-- Overview cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <i class="bi bi-database fs-4 text-<?php echo $cacheStatus ? 'success' : 'danger'; ?> mb-2 d-block"></i>
                    <div class="h4 fw-bold mb-1"><?php echo htmlspecialchars(ucfirst($method), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="text-muted small"><?php echo $cacheStatus ? 'Active' : 'Inactive'; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h3 fw-bold text-primary mb-1"><?php echo (int) ($cacheStats['categories'] ?? 0); ?></div>
                    <div class="text-muted small">Namespaces</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h3 fw-bold text-success mb-1"><?php echo (int) ($cacheStats['items'] ?? 0); ?></div>
                    <div class="text-muted small">Total Items</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cache by Namespace -->
    <?php if (!empty($namespaceStats)): ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold">Cache by Namespace</div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($namespaceStats as $ns => $count): ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <div class="border rounded p-3 text-center">
                        <div class="h5 fw-bold text-primary mb-1"><?php echo (int) $count; ?></div>
                        <div class="text-muted small font-monospace"><?php echo htmlspecialchars($ns !== '' ? $ns : '(default)', ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Cache Categories (hidden by default) -->
    <?php if (!empty($cacheCategories)): ?>
    <div class="mb-2 d-flex align-items-center gap-2">
        <button class="btn btn-sm btn-outline-secondary" type="button"
                data-bs-toggle="collapse" data-bs-target="#cacheCategories" aria-expanded="false">
            Categories (<?php echo count($cacheCategories); ?>)
        </button>
    </div>
    <div class="collapse mb-4" id="cacheCategories">
        <div class="card">
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                <?php foreach ($cacheCategories as $cat): ?>
                    <li class="py-1 border-bottom d-flex justify-content-between align-items-center">
                        <span class="font-monospace small"><?php echo htmlspecialchars($cat !== '' ? $cat : '(default)', ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if (isset($namespaceStats[$cat])): ?>
                            <span class="badge bg-primary"><?php echo (int) $namespaceStats[$cat]; ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Cache Items -->
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            Cache Items
            <span class="badge bg-secondary"><?php echo count($cacheItems); ?></span>
        </div>
        <div class="card-body p-0">
            <?php if ($memcachedLimitation): ?>
                <div class="alert alert-warning m-3">
                    <strong>Memcached Limitation:</strong> <?php echo htmlspecialchars($memcachedLimitationMessage, ENT_QUOTES, 'UTF-8'); ?>
                    <br><small>Statistics show <?php echo (int) ($cacheStats['items'] ?? 0); ?> items stored, but individual items cannot be listed.</small>
                </div>
            <?php elseif (empty($cacheItems)): ?>
                <p class="text-muted text-center py-4 mb-0">No cache items available.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Key</th><th>Namespace</th><th class="text-end">Size</th>
                            <th>Created</th><th>TTL</th><th>Type</th><th>Status</th><th></th>
                        </tr>
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

                        if ($size >= 1048576)   $sizeStr = round($size / 1048576, 2) . ' MB';
                        elseif ($size >= 1024)  $sizeStr = round($size / 1024, 2) . ' KB';
                        else                    $sizeStr = $size . ' B';

                        if ($ttl === null)      { $ttlBadge = '<span class="badge bg-secondary">—</span>'; }
                        elseif ($ttl === -1)    { $ttlBadge = '<span class="badge bg-success">Never</span>'; }
                        elseif ($ttl <= 0)      { $ttlBadge = '<span class="badge bg-danger">Expired</span>'; }
                        else                    { $ttlBadge = '<span class="badge bg-info text-dark">' . $ttl . 's</span>'; }
                    ?>
                    <tr class="<?php echo $expired ? 'table-warning' : ''; ?>">
                        <td>
                            <code class="cache-key-clickable" data-cache-key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                                  title="Click to view content"><?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?></code>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($ns !== '' ? $ns : 'default', ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td class="text-end text-muted"><?php echo $sizeStr; ?></td>
                        <td class="text-muted"><?php echo htmlspecialchars($created, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $ttlBadge; ?></td>
                        <td class="text-muted"><?php echo htmlspecialchars($itype, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?php if ($expired): ?>
                                <span class="badge bg-danger">Expired</span>
                            <?php elseif (!empty($item['note'])): ?>
                                <span class="badge bg-warning text-dark" title="<?php echo htmlspecialchars($item['note'], ENT_QUOTES, 'UTF-8'); ?>">Info</span>
                            <?php else: ?>
                                <span class="badge bg-success">Active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-info py-0"
                                    data-cache-key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>">
                                View
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($cacheItems) >= 50): ?>
                <div class="alert alert-info m-3 mb-2">Showing first 50 items. There may be more.</div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Cache Item Detail Modal -->
<div id="cache-detail-modal" class="cache-detail-modal">
    <div class="cache-detail-content">
        <div class="cache-detail-header">
            <h6 class="mb-1">Cache Item Details</h6>
            <code id="cache-detail-key"></code>
        </div>
        <div class="cache-detail-body">
            <div id="cache-detail-content"></div>
        </div>
        <div class="cache-detail-footer">
            <button type="button" id="closeCacheDetailBtn" class="btn btn-secondary btn-sm">Close</button>
        </div>
    </div>
</div>
