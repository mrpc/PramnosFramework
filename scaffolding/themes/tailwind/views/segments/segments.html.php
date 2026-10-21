<?php
/**
 * The library of segments and groups (Tailwind theme): how many are in each now, who came and went
 * this week, and where each is used.
 *
 * Variables:
 *   $this->rows — per segment or group: kind (segment|group), id, name, members, movement
 *                 (['entered', 'left'] over seven days; null for a group), usedBy (names),
 *                 computed (false before a segment's first recomputation), url
 *   $this->kind — the filter: '', 'segments' or 'groups'
 *   $this->mine — whether only the account's own segments are listed
 */
$e    = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$kind = (string) ($this->kind ?? '');
$tab  = static fn (string $label, string $query, bool $on) => '<a href="' . $e(adminUrl('Segments') . $query) . '" class="tab' . ($on ? ' tab-active' : '') . '"' . ($on ? ' aria-current="page"' : '') . '>' . $e($label) . '</a>';
?>
<div class="px-4 py-6">
    <?php $this->activeNav = 'segments'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="flex flex-wrap justify-between items-center gap-2 mb-4">
        <h2><?php echo $e(t('Segments and groups')); ?></h2>
        <a href="<?php echo $e(adminUrl('Segments/edit')); ?>" class="btn btn-primary btn-sm"><?php echo $e(t('+ New segment')); ?></a>
    </div>
    <nav role="tablist" class="tabs tabs-boxed mb-4 w-fit">
        <?php echo $tab(t('All'), '', $kind === '' && empty($this->mine)); ?>
        <?php echo $tab(t('Segments'), '?kind=segments', $kind === 'segments'); ?>
        <?php echo $tab(t('Groups'), '?kind=groups', $kind === 'groups'); ?>
        <?php echo $tab(t('Mine'), '?mine=1', !empty($this->mine)); ?>
    </nav>
    <div class="card bg-base-100 border border-base-300 shadow-xs mb-4"><div class="p-5 overflow-x-auto">
        <table class="table table-sm text-sm">
            <thead class="bg-base-200 text-xs text-base-content/70 uppercase">
                <tr><th><?php echo $e(t('Name')); ?></th><th><?php echo $e(t('Kind')); ?></th><th class="text-right"><?php echo $e(t('Now')); ?></th><th class="text-right"><?php echo $e(t('7 days')); ?></th><th><?php echo $e(t('Used by')); ?></th></tr>
            </thead>
            <tbody>
            <?php foreach (($this->rows ?? []) as $row): ?>
                <tr data-kind="<?php echo $e($row['kind']); ?>">
                    <td><a href="<?php echo $e($row['url']); ?>"><?php echo $e($row['name']); ?></a></td>
                    <td><span class="badge badge-sm <?php echo $row['kind'] === 'segment' ? 'badge-primary badge-outline' : 'badge-ghost'; ?>"><?php echo $e($row['kind'] === 'segment' ? t('segment') : t('group')); ?></span></td>
                    <td class="text-right tabular-nums"><?php echo $row['computed'] ? $e(number_format((int) $row['members'])) : '<span class="text-base-content/60">' . $e(t('not yet worked out')) . '</span>'; ?></td>
                    <td class="text-right tabular-nums"><?php echo $row['movement'] === null ? '—' : $e('+' . number_format($row['movement']['entered']) . ' / −' . number_format($row['movement']['left'])); ?></td>
                    <td class="text-base-content/70"><?php echo $row['usedBy'] === [] ? '—' : $e(implode(' · ', $row['usedBy'])); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->rows)): ?>
                <tr><td colspan="5" class="text-center text-base-content/60 py-6"><?php echo $e(t('Nothing here yet. A segment is a filter over people, kept up to date: people enter and leave it on their own.')); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>
