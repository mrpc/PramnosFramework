<?php
/**
 * Log-management toolbar (plain-CSS theme) — shared partial.
 *
 * Rendered via $this->insert('_toolbar') from every logs view that shows the
 * navigation bar (display/stats/search/archive/rotate).
 *
 * Variables:
 *   $this->toolbar   — array[] {url, label, variant, icon, confirm?}
 *   $this->clearList — string[] log files the "Clear Logs" action will wipe
 */
$variantColors = [
    'info'      => '#0ea5e9',
    'primary'   => '#2563eb',
    'warning'   => '#f59e0b',
    'secondary' => '#4b5563',
    'danger'    => '#dc2626',
];
?>
<div class="card" style="margin-bottom:24px">
    <div class="card-body" style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach (($this->toolbar ?? []) as $link): ?>
            <?php $bg = $variantColors[$link['variant']] ?? $variantColors['secondary']; ?>
            <a href="<?php echo htmlspecialchars($link['url']); ?>"
               class="btn"
               style="background:<?php echo $bg; ?>;color:#fff"
               <?php if (!empty($link['confirm'])): ?>data-confirm="<?php echo htmlspecialchars($link['confirm']); ?>"<?php endif; ?>>
                <?php echo htmlspecialchars($link['label']); ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($this->clearList)): ?>
        <p style="font-size:12px;color:#888;margin:12px 0 0">
            &#9432; "Clear Logs" will clear: <?php echo htmlspecialchars(implode(', ', $this->clearList)); ?>
        </p>
    <?php endif; ?>
</div>
