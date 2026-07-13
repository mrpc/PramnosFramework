<?php
/**
 * Log-management toolbar (Tailwind theme) — shared partial.
 *
 * Rendered via $this->insert('_toolbar') from every logs view that shows the
 * navigation bar (display/stats/search/archive/rotate).
 *
 * Variables:
 *   $this->toolbar   — array[] {url, label, variant, icon, confirm?}
 *   $this->clearList — string[] log files the "Clear Logs" action will wipe
 */
$variantClasses = [
    'info'      => 'bg-sky-600 hover:bg-sky-700 text-white',
    'primary'   => 'bg-blue-600 hover:bg-blue-700 text-white',
    'warning'   => 'bg-amber-500 hover:bg-amber-600 text-white',
    'secondary' => 'bg-gray-600 hover:bg-gray-700 text-white',
    'danger'    => 'bg-red-600 hover:bg-red-700 text-white',
];
?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
    <div class="flex flex-wrap gap-2">
        <?php foreach (($this->toolbar ?? []) as $link): ?>
            <a href="<?php echo htmlspecialchars($link['url']); ?>"
               class="inline-flex items-center px-4 py-2 rounded-md text-sm font-medium transition-colors <?php echo $variantClasses[$link['variant']] ?? $variantClasses['secondary']; ?>"
               <?php if (!empty($link['confirm'])): ?>data-confirm="<?php echo htmlspecialchars($link['confirm']); ?>"<?php endif; ?>>
                <?php echo htmlspecialchars($link['label']); ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($this->clearList)): ?>
        <p class="text-xs text-gray-400 mt-3">
            &#9432; "Clear Logs" will clear: <?php echo htmlspecialchars(implode(', ', $this->clearList)); ?>
        </p>
    <?php endif; ?>
</div>
