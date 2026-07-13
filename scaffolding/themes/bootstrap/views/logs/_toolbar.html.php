<?php
/**
 * Log-management toolbar (Bootstrap theme) — shared partial.
 *
 * Rendered via $this->insert('_toolbar') from every logs view that shows the
 * navigation bar (display/stats/search/archive/rotate).
 *
 * Variables:
 *   $this->toolbar   — array[] {url, label, variant, icon, confirm?}
 *   $this->clearList — string[] log files the "Clear Logs" action will wipe
 */
$variantClasses = [
    'info'      => 'btn btn-info',
    'primary'   => 'btn btn-primary',
    'warning'   => 'btn btn-warning',
    'secondary' => 'btn btn-secondary',
    'danger'    => 'btn btn-danger',
];
?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
            <?php foreach (($this->toolbar ?? []) as $link): ?>
                <a href="<?php echo htmlspecialchars($link['url']); ?>"
                   class="<?php echo $variantClasses[$link['variant']] ?? $variantClasses['secondary']; ?>"
                   <?php if (!empty($link['confirm'])): ?>data-confirm="<?php echo htmlspecialchars($link['confirm']); ?>"<?php endif; ?>>
                    <?php echo htmlspecialchars($link['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($this->clearList)): ?>
            <p class="small text-muted mt-3 mb-0">
                &#9432; "Clear Logs" will clear: <?php echo htmlspecialchars(implode(', ', $this->clearList)); ?>
            </p>
        <?php endif; ?>
    </div>
</div>
