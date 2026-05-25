<?php
/**
 * Cache details page (plain-CSS theme).
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

$methodColors = [
    'redis'     => ['#f8d7da', '#842029'],
    'memcached' => ['#fff3cd', '#664d03'],
    'memcache'  => ['#fff3cd', '#664d03'],
    'file'      => ['#cff4fc', '#055160'],
];
[$methodBg, $methodFg] = $methodColors[$method] ?? ['#e9ecef', '#444'];
?>
<div class="page-section">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
        <a href="<?php echo sURL; ?>dashboard" class="btn btn-outline-secondary">&larr; Dashboard</a>
        <h2 style="margin:0">Cache Details</h2>
        <span style="background:<?php echo $methodBg; ?>;color:<?php echo $methodFg; ?>;padding:2px 10px;border-radius:4px;font-size:12px;font-weight:700">
            <?php echo htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8'); ?>
        </span>
    </div>

    <!-- Overview cards -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px">
        <div class="card" style="text-align:center;padding:16px">
            <div style="font-size:28px;font-weight:700;color:#0d6efd;margin-bottom:4px"><?php echo (int)($cacheStats['categories'] ?? 0); ?></div>
            <div style="font-size:12px;color:#888">Namespaces</div>
        </div>
        <div class="card" style="text-align:center;padding:16px">
            <div style="font-size:28px;font-weight:700;color:#198754;margin-bottom:4px"><?php echo (int)($cacheStats['items'] ?? 0); ?></div>
            <div style="font-size:12px;color:#888">Total Items</div>
        </div>
        <div class="card" style="text-align:center;padding:16px">
            <span style="background:<?php echo $methodBg; ?>;color:<?php echo $methodFg; ?>;padding:4px 14px;border-radius:4px;font-size:14px;font-weight:700">
                <?php echo htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <div style="font-size:12px;color:#888;margin-top:6px">Adapter</div>
        </div>
    </div>

    <!-- Namespaces -->
    <?php if (!empty($categories)): ?>
    <div class="card" style="margin-bottom:20px;padding:16px">
        <div style="font-size:13px;font-weight:600;margin-bottom:10px">Namespaces / Categories</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach ($categories as $cat):
            $catName  = is_array($cat) ? ($cat['name'] ?? $cat[0] ?? '') : (string) $cat;
            $catCount = count($items[$catName] ?? []);
        ?>
            <span style="background:#f0f0f0;padding:4px 12px;border-radius:20px;font-size:12px;display:inline-flex;align-items:center;gap:6px">
                <?php echo htmlspecialchars($catName, ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($catCount > 0): ?>
                    <span style="background:#0d6efd;color:#fff;padding:1px 6px;border-radius:10px;font-size:11px"><?php echo $catCount; ?></span>
                <?php endif; ?>
            </span>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Items by category -->
    <?php foreach ($items as $catName => $catItems): ?>
    <?php if (empty($catItems)): continue; endif; ?>
    <div class="card" style="margin-bottom:16px">
        <div style="padding:10px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center">
            <span style="font-weight:600;font-size:13px"><?php echo htmlspecialchars($catName !== '' ? $catName : '(default)', ENT_QUOTES, 'UTF-8'); ?></span>
            <span style="background:#e9ecef;padding:1px 8px;border-radius:10px;font-size:12px"><?php echo count($catItems); ?></span>
        </div>
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:12px">
                <thead style="background:#f5f5f5">
                    <tr>
                        <th style="padding:6px 12px;text-align:left">Key</th>
                        <th style="padding:6px 12px;text-align:left">Type</th>
                        <th style="padding:6px 12px;text-align:right">Size</th>
                        <th style="padding:6px 12px;text-align:right">Expires</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($catItems as $item):
                    $key      = is_array($item) ? ($item['key'] ?? $item['id'] ?? '') : (string) $item;
                    $size     = is_array($item) ? ($item['size'] ?? null) : null;
                    $ttl      = is_array($item) ? ($item['ttl'] ?? $item['expires'] ?? null) : null;
                    $itemType = is_array($item) ? ($item['type'] ?? null) : null;
                    $exp      = $ttl !== null ? (int) $ttl : 0;
                    $isExpired = $exp > 0 && $exp < time();
                ?>
                    <tr style="border-top:1px solid #f0f0f0;<?php echo $isExpired ? 'background:#fffbea' : ''; ?>">
                        <td style="padding:5px 12px;font-family:monospace;color:#444;word-break:break-all"><?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:5px 12px;color:#888"><?php echo $itemType !== null ? htmlspecialchars($itemType, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td style="padding:5px 12px;text-align:right;color:#888">
                            <?php echo $size !== null ? (is_numeric($size) ? round((int)$size / 1024, 1) . ' KB' : htmlspecialchars($size, ENT_QUOTES, 'UTF-8')) : '—'; ?>
                        </td>
                        <td style="padding:5px 12px;text-align:right;<?php echo $isExpired ? 'color:#856404' : 'color:#666'; ?>">
                            <?php
                            if ($exp === 0) echo 'Never';
                            elseif ($isExpired) echo '<em>Exp.</em> ' . date('Y-m-d H:i', $exp);
                            else echo date('Y-m-d H:i', $exp);
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($items)): ?>
    <div class="card" style="text-align:center;color:#888;padding:40px;font-size:13px">No cache items available.</div>
    <?php endif; ?>
</div>
