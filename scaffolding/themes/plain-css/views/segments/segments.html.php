<?php
/**
 * The library of segments and groups: how many are in each now, who came and went this week, and
 * where each is used — tabs with their counts, then the framework's datatable: sorted, paged, searched.
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
            <?php
            // The framework's datatable, its rows given here: sorting, paging and the search,
            // the same as every other list in the administration.
            $table = new \Pramnos\Html\Datatable('pf-segments-table');
            $table->bootstrap = false;
            $table->addColumn($e(t('Name')))
                ->addColumn($e(t('Segment or group')))
                ->addColumn($e(t('In it now')), true, true, false, 'html-num-fmt')
                ->addColumn($e(t('7 days')), true, false, false)
                ->addColumn($e(t('Used by')), true, false, true);
            foreach ($this->rows as $row) {
                $uses = '';
                foreach ($row['uses'] as $use) {
                    $uses .= $use['url'] !== null
                        ? '<a class="pf-chip" href="' . $e($use['url']) . '">' . $e($use['name']) . '</a>'
                        : '<span class="pf-chip">' . $e($use['name']) . '</span>';
                }
                $table->addRow([
                    '<a class="pf-row-link" href="' . $e($row['url']) . '">' . $e($row['name']) . '</a>'
                        . (($row['description'] ?? '') !== '' ? '<span class="pf-row-desc">' . $e($row['description']) . '</span>' : ''),
                    '<span class="pf-badge ' . ($row['kind'] === 'segment' ? 'pf-tone-accent' : 'pf-tone-neutral') . '">' . $e($row['kind'] === 'segment' ? t('segment') : t('group')) . '</span>',
                    $row['computed'] ? $e(number_format((int) $row['members'])) : '<span class="pf-muted">' . $e(t('not yet worked out')) . '</span>',
                    $delta($row['movement']),
                    $uses === '' ? '<span class="pf-zero">—</span>' : '<span class="pf-chips">' . $uses . '</span>',
                ]);
            }
            echo $table->render();
            ?>
        <?php endif; ?>
    </div>
</div>
