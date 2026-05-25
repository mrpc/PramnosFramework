<?php
/**
 * Cache details page (Bootstrap theme).
 *
 * Variables:
 *   $this->cacheStats — array from Cache::getStats(): method, categories, items
 *   $this->categories — array of category names/objects
 *   $this->items      — array keyed by category name, each value is array of item rows
 */
$cacheStats = $this->cacheStats ?? ['method' => 'unknown', 'categories' => 0, 'items' => 0];
$categories = $this->categories ?? [];
$items      = $this->items ?? [];
$method     = strtolower($cacheStats['method'] ?? 'unknown');

$methodBadge = match($method) {
    'redis'     => 'danger',
    'memcached', 'memcache' => 'warning',
    'file'      => 'info',
    default     => 'secondary',
};
?>
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="<?php echo sURL; ?>dashboard" class="btn btn-sm btn-outline-secondary">&larr; Dashboard</a>
        <h2 class="mb-0">Cache Details</h2>
        <span class="badge bg-<?php echo $methodBadge; ?> text-<?php echo $method === 'memcached' ? 'dark' : 'white'; ?>">
            <?php echo htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8'); ?>
        </span>
    </div>

    <!-- Overview cards -->
    <div class="row g-3 mb-4">
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
        <div class="col-6 col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <div class="h3 fw-bold mb-1">
                        <span class="badge bg-<?php echo $methodBadge; ?>"><?php echo htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="text-muted small">Adapter</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Namespaces -->
    <?php if (!empty($categories)): ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold">Namespaces / Categories</div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2">
            <?php foreach ($categories as $cat):
                $catName = is_array($cat) ? ($cat['name'] ?? $cat[0] ?? '') : (string) $cat;
                $catCount = count($items[$catName] ?? []);
            ?>
                <span class="badge bg-light text-dark border" style="font-size:.85em;padding:.4em .8em">
                    <?php echo htmlspecialchars($catName, ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($catCount > 0): ?>
                        <span class="badge bg-primary ms-1"><?php echo $catCount; ?></span>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Items by category -->
    <?php foreach ($items as $catName => $catItems): ?>
    <?php if (empty($catItems)): continue; endif; ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><?php echo htmlspecialchars($catName !== '' ? $catName : '(default)', ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="badge bg-secondary"><?php echo count($catItems); ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th>Key</th><th>Type</th><th class="text-end">Size</th><th class="text-end">Expires</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($catItems as $item): ?>
                        <?php
                        $key      = is_array($item) ? ($item['key'] ?? $item['id'] ?? '') : (string) $item;
                        $size     = is_array($item) ? ($item['size'] ?? null) : null;
                        $ttl      = is_array($item) ? ($item['ttl'] ?? $item['expires'] ?? null) : null;
                        $itemType = is_array($item) ? ($item['type'] ?? null) : null;
                        $exp      = $ttl !== null ? (int) $ttl : 0;
                        $isExpired = $exp > 0 && $exp < time();
                        ?>
                        <tr class="<?php echo $isExpired ? 'table-warning' : ''; ?>">
                            <td class="font-monospace text-break"><?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-muted"><?php echo $itemType !== null ? htmlspecialchars($itemType, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                            <td class="text-end text-muted">
                                <?php echo $size !== null ? (is_numeric($size) ? round((int)$size / 1024, 1) . ' KB' : htmlspecialchars($size, ENT_QUOTES, 'UTF-8')) : '—'; ?>
                            </td>
                            <td class="text-end <?php echo $isExpired ? 'text-warning' : ''; ?>">
                                <?php
                                if ($exp === 0) echo 'Never';
                                elseif ($isExpired) echo '<em>Expired</em> ' . date('Y-m-d H:i', $exp);
                                else echo date('Y-m-d H:i', $exp);
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($items)): ?>
    <div class="card">
        <div class="card-body text-center text-muted py-5">No cache items available.</div>
    </div>
    <?php endif; ?>
</div>
