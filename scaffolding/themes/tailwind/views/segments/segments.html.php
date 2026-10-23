<?php
/**
 * The library of segments and groups: tabs with their counts, then the framework's datatable —
 * how many are in each now, who came and went this week, where each is used, whose it is and when
 * it was last worked out; sorted, paged, searched and exported, its rows from `Segments/data`.
 * The same markup in every theme: it is dressed by the `pf-*` rules in components.css.
 *
 * Variables:
 *   $this->datatable — \Pramnos\Html\Datatable, server-side, the tab's filter sent with it
 *   $this->kind      — the filter: '', 'segments' or 'groups'
 *   $this->mine      — whether only the account's own segments are listed
 *   $this->counts    — how many each tab holds: all, segments, groups, mine
 */
use Pramnos\Html\Icon;

$e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$kind   = (string) ($this->kind ?? '');
$counts = (array) ($this->counts ?? []);
$tab    = static fn (string $label, string $query, bool $on, string $key) => '<a href="' . $e(adminUrl('Segments') . $query) . '" class="pf-tab"'
    . ($on ? ' aria-current="page"' : '') . '>' . $e($label) . (isset($counts[$key]) ? ' <span class="pf-count">' . (int) $counts[$key] . '</span>' : '') . '</a>';
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
        <?php if ((int) ($counts['all'] ?? 0) === 0): ?>
            <div class="pf-empty">
                <span class="pf-empty-icon"><?php echo Icon::svg('segment', ''); ?></span>
                <p class="pf-empty-title"><?php echo $e(t('No segments yet')); ?></p>
                <p class="pf-empty-text"><?php echo $e(t('A segment is a filter over people, kept up to date: people enter and leave it on their own, and a rule can act when they do.')); ?></p>
                <a href="<?php echo $e(adminUrl('Segments/edit')); ?>" class="pf-btn pf-btn-primary"><?php echo Icon::svg('plus', ''); ?><?php echo $e(t('New segment')); ?></a>
            </div>
        <?php else: ?>
            <?php echo $this->datatable->render(); ?>
        <?php endif; ?>
    </div>
</div>
