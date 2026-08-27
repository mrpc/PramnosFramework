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
    'info'      => 'bg-info hover:bg-info text-white',
    'primary'   => 'bg-primary hover:bg-primary text-white',
    'warning'   => 'bg-warning hover:bg-warning text-white',
    'secondary' => 'bg-neutral hover:bg-neutral text-white',
    'danger'    => 'bg-error hover:bg-error text-white',
];
?>
<div class="card bg-base-100 border border-base-300 shadow-sm p-4 mb-6">
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
        <p class="text-xs text-base-content/60 mt-3">
            &#9432; "Clear Logs" will clear: <?php echo htmlspecialchars(implode(', ', $this->clearList)); ?>
        </p>
    <?php endif; ?>
</div>
