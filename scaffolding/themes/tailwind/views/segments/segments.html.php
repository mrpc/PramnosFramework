<?php
/**
 * The library of segments and groups: how many are in each now, who came and went this week, and
 * where each is used — tabs with their counts, a search over the names, a row that is one link.
 * The same markup in every theme: it is dressed by the `pf-*` rules in components.css.
 *
 * Variables:
 *   $this->rows   — per segment or group: kind (segment|group), id, name, description, members,
 *                   movement (['entered', 'left'] over seven days; null for a group), uses
 *                   ([name, url] of what uses it), computed (false before a segment's first
 *                   recomputation), url
 *   $this->kind   — the filter: '', 'segments' or 'groups'
 *   $this->mine   — whether only the account's own segments are listed
 *   $this->counts — how many each tab holds: all, segments, groups, mine
 */
use Pramnos\Html\Icon;

$e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$kind   = (string) ($this->kind ?? '');
$counts = (array) ($this->counts ?? []);
$tab    = static fn (string $label, string $query, bool $on, string $key) => '<a href="' . $e(adminUrl('Segments') . $query) . '" class="pf-tab"'
    . ($on ? ' aria-current="page"' : '') . '>' . $e($label) . (isset($counts[$key]) ? ' <span class="pf-count">' . (int) $counts[$key] . '</span>' : '') . '</a>';
$delta  = static function (?array $movement) use ($e): string {
    if ($movement === null) {
        return '<span class="pf-zero">—</span>';
    }
    $in  = (int) $movement['entered'];
    $out = (int) $movement['left'];

    return '<span class="pf-delta"><span class="' . ($in > 0 ? 'pf-up' : 'pf-zero') . '" title="' . $e(t('Entered, 7 days')) . '">▲ ' . $e(number_format($in)) . '</span>'
        . '<span class="' . ($out > 0 ? 'pf-down' : 'pf-zero') . '" title="' . $e(t('Left, 7 days')) . '">▼ ' . $e(number_format($out)) . '</span></span>';
};
?>
<div class="pf-ui">
    <?php $this->activeNav = 'segments'; $this->insert('../partials/admin_breadcrumb'); ?>
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title"><?php echo $e(t('Segments and groups')); ?></h1>
        </div>
        <div class="pf-page-actions">
            <a href="<?php echo $e(adminUrl('Segments/edit')); ?>" class="pf-btn pf-btn-primary"><?php echo Icon::svg('plus', ''); ?><?php echo $e(t('New segment')); ?></a>
        </div>
    </header>
    <div class="pf-toolbar">
        <nav class="pf-tabs" aria-label="<?php echo $e(t('Show')); ?>">
            <?php echo $tab(t('All'), '', $kind === '' && empty($this->mine), 'all'); ?>
            <?php echo $tab(t('Segments'), '?kind=segments', $kind === 'segments', 'segments'); ?>
            <?php echo $tab(t('Groups'), '?kind=groups', $kind === 'groups', 'groups'); ?>
            <?php echo $tab(t('Mine'), '?mine=1', !empty($this->mine), 'mine'); ?>
        </nav>
        <?php if (!empty($this->rows)): ?>
            <label class="pf-search">
                <?php echo Icon::svg('search', ''); ?>
                <input type="search" class="pf-input" data-pf-filter-rows="#pf-segments-table" placeholder="<?php echo $e(t('Search by name')); ?>" aria-label="<?php echo $e(t('Search by name')); ?>">
            </label>
        <?php endif; ?>
    </div>
    <div class="pf-card pf-card-flush">
        <?php if (empty($this->rows) && (int) ($counts['all'] ?? 0) > 0): ?>
            <div class="pf-empty"><p class="pf-empty-text"><?php echo $e(t('Nothing in this view.')); ?></p></div>
        <?php elseif (empty($this->rows)): ?>
            <div class="pf-empty">
                <span class="pf-empty-icon"><?php echo Icon::svg('segment', ''); ?></span>
                <p class="pf-empty-title"><?php echo $e(t('No segments yet')); ?></p>
                <p class="pf-empty-text"><?php echo $e(t('A segment is a filter over people, kept up to date: people enter and leave it on their own, and a rule can act when they do.')); ?></p>
                <a href="<?php echo $e(adminUrl('Segments/edit')); ?>" class="pf-btn pf-btn-primary"><?php echo Icon::svg('plus', ''); ?><?php echo $e(t('New segment')); ?></a>
            </div>
        <?php else: ?>
            <table class="pf-table" id="pf-segments-table">
                <thead>
                    <tr><th><?php echo $e(t('Name')); ?></th><th><?php echo $e(t('Segment or group')); ?></th><th class="pf-num"><?php echo $e(t('In it now')); ?></th><th class="pf-num"><?php echo $e(t('7 days')); ?></th><th><?php echo $e(t('Used by')); ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($this->rows as $row): ?>
                    <tr data-kind="<?php echo $e($row['kind']); ?>" data-pf-row-text="<?php echo $e(mb_strtolower($row['name'] . ' ' . ($row['description'] ?? ''))); ?>">
                        <td>
                            <a class="pf-row-link" href="<?php echo $e($row['url']); ?>"><?php echo $e($row['name']); ?></a>
                            <?php if (($row['description'] ?? '') !== ''): ?><span class="pf-row-desc"><?php echo $e($row['description']); ?></span><?php endif; ?>
                        </td>
                        <td><span class="pf-badge <?php echo $row['kind'] === 'segment' ? 'pf-tone-accent' : 'pf-tone-neutral'; ?>"><?php echo $e($row['kind'] === 'segment' ? t('segment') : t('group')); ?></span></td>
                        <td class="pf-num"><?php echo $row['computed'] ? $e(number_format((int) $row['members'])) : '<span class="pf-muted">' . $e(t('not yet worked out')) . '</span>'; ?></td>
                        <td class="pf-num"><?php echo $delta($row['movement']); ?></td>
                        <td>
                            <?php if (empty($row['uses'])): ?>
                                <span class="pf-zero">—</span>
                            <?php else: ?>
                                <span class="pf-chips"><?php foreach ($row['uses'] as $use): ?><?php echo $use['url'] !== null
                                    ? '<a class="pf-chip" href="' . $e($use['url']) . '">' . $e($use['name']) . '</a>'
                                    : '<span class="pf-chip">' . $e($use['name']) . '</span>'; ?><?php endforeach; ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
