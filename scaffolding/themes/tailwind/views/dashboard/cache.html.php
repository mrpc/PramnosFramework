<?php
/**
 * Cache details page (Tailwind theme).
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

$methodCls = match($method) {
    'redis'               => 'bg-red-100 text-red-700',
    'memcached', 'memcache' => 'bg-yellow-100 text-yellow-700',
    'file'                => 'bg-blue-100 text-blue-700',
    default               => 'bg-gray-100 text-gray-600',
};
?>
<div class="px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="<?php echo sURL; ?>dashboard" class="px-3 py-1.5 text-sm border border-gray-300 text-gray-600 rounded hover:bg-gray-50">&larr; Dashboard</a>
        <h2 class="text-2xl font-semibold">Cache Details</h2>
        <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold <?php echo $methodCls; ?>">
            <?php echo htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8'); ?>
        </span>
    </div>

    <!-- Overview cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <div class="text-3xl font-bold text-indigo-600 mb-1"><?php echo (int) ($cacheStats['categories'] ?? 0); ?></div>
            <div class="text-xs text-gray-400">Namespaces</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <div class="text-3xl font-bold text-green-600 mb-1"><?php echo (int) ($cacheStats['items'] ?? 0); ?></div>
            <div class="text-xs text-gray-400">Total Items</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-center">
            <span class="inline-block px-3 py-1.5 rounded text-sm font-semibold <?php echo $methodCls; ?>">
                <?php echo htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <div class="text-xs text-gray-400 mt-1">Adapter</div>
        </div>
    </div>

    <!-- Namespaces -->
    <?php if (!empty($categories)): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
        <div class="text-sm font-semibold text-gray-700 mb-3">Namespaces / Categories</div>
        <div class="flex flex-wrap gap-2">
        <?php foreach ($categories as $cat):
            $catName  = is_array($cat) ? ($cat['name'] ?? $cat[0] ?? '') : (string) $cat;
            $catCount = count($items[$catName] ?? []);
        ?>
            <span class="inline-flex items-center gap-1 px-3 py-1 bg-gray-100 text-gray-700 rounded-full text-xs">
                <?php echo htmlspecialchars($catName, ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($catCount > 0): ?>
                    <span class="inline-block px-1.5 py-0.5 bg-indigo-500 text-white rounded-full text-xs"><?php echo $catCount; ?></span>
                <?php endif; ?>
            </span>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Items by category -->
    <?php foreach ($items as $catName => $catItems): ?>
    <?php if (empty($catItems)): continue; endif; ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-4">
        <div class="px-6 py-3 border-b border-gray-100 flex justify-between items-center">
            <span class="font-semibold text-gray-700 text-sm">
                <?php echo htmlspecialchars($catName !== '' ? $catName : '(default)', ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <span class="inline-block px-2 py-0.5 rounded text-xs bg-gray-200 text-gray-600"><?php echo count($catItems); ?></span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Key</th>
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-right">Size</th>
                        <th class="px-4 py-2 text-right">Expires</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($catItems as $item):
                    $key      = is_array($item) ? ($item['key'] ?? $item['id'] ?? '') : (string) $item;
                    $size     = is_array($item) ? ($item['size'] ?? null) : null;
                    $ttl      = is_array($item) ? ($item['ttl'] ?? $item['expires'] ?? null) : null;
                    $itemType = is_array($item) ? ($item['type'] ?? null) : null;
                    $exp      = $ttl !== null ? (int) $ttl : 0;
                    $isExpired = $exp > 0 && $exp < time();
                ?>
                    <tr class="hover:bg-gray-50 <?php echo $isExpired ? 'bg-yellow-50' : ''; ?>">
                        <td class="px-4 py-2 font-mono text-xs text-gray-600 break-all"><?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="px-4 py-2 text-xs text-gray-400"><?php echo $itemType !== null ? htmlspecialchars($itemType, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td class="px-4 py-2 text-right text-xs text-gray-400">
                            <?php echo $size !== null ? (is_numeric($size) ? round((int)$size / 1024, 1) . ' KB' : htmlspecialchars($size, ENT_QUOTES, 'UTF-8')) : '—'; ?>
                        </td>
                        <td class="px-4 py-2 text-right text-xs <?php echo $isExpired ? 'text-yellow-600' : 'text-gray-500'; ?>">
                            <?php
                            if ($exp === 0) echo 'Never';
                            elseif ($isExpired) echo 'Expired ' . date('Y-m-d H:i', $exp);
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
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center text-gray-400 text-sm">
        No cache items available.
    </div>
    <?php endif; ?>
</div>
