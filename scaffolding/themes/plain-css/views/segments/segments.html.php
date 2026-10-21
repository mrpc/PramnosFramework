<?php
/**
 * The library of segments and groups (plain-CSS theme): how many are in each now, who came and went
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
$tab  = static fn (string $label, string $query, bool $on) => '<a href="' . $e(adminUrl('Segments') . $query) . '"' . ($on ? ' style="font-weight:700;text-decoration:none"' : '') . ($on ? ' aria-current="page"' : '') . '>' . $e($label) . '</a>';
?>
<div class="page-section">
    <?php $this->activeNav = 'segments'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;margin-bottom:16px">
        <h2><?php echo $e(t('Segments and groups')); ?></h2>
        <a href="<?php echo $e(adminUrl('Segments/edit')); ?>" class="btn btn-primary"><?php echo $e(t('+ New segment')); ?></a>
    </div>
    <nav role="tablist" style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <?php echo $tab(t('All'), '', $kind === '' && empty($this->mine)); ?>
        <?php echo $tab(t('Segments'), '?kind=segments', $kind === 'segments'); ?>
        <?php echo $tab(t('Groups'), '?kind=groups', $kind === 'groups'); ?>
        <?php echo $tab(t('Mine'), '?mine=1', !empty($this->mine)); ?>
    </nav>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px"><div class="card-body" style="padding:16px;overflow-x:auto">
        <table class="table" style="width:100%;border-collapse:collapse">
            <thead>
                <tr><th><?php echo $e(t('Name')); ?></th><th><?php echo $e(t('Kind')); ?></th><th style="text-align:right"><?php echo $e(t('Now')); ?></th><th style="text-align:right"><?php echo $e(t('7 days')); ?></th><th><?php echo $e(t('Used by')); ?></th></tr>
            </thead>
            <tbody>
            <?php foreach (($this->rows ?? []) as $row): ?>
                <tr data-kind="<?php echo $e($row['kind']); ?>">
                    <td><a href="<?php echo $e($row['url']); ?>"><?php echo $e($row['name']); ?></a></td>
                    <td><span class="badge"><?php echo $e($row['kind'] === 'segment' ? t('segment') : t('group')); ?></span></td>
                    <td style="text-align:right"><?php echo $row['computed'] ? $e(number_format((int) $row['members'])) : '<span style="opacity:.7">' . $e(t('not yet worked out')) . '</span>'; ?></td>
                    <td style="text-align:right"><?php echo $row['movement'] === null ? '—' : $e('+' . number_format($row['movement']['entered']) . ' / −' . number_format($row['movement']['left'])); ?></td>
                    <td style="opacity:.8"><?php echo $row['usedBy'] === [] ? '—' : $e(implode(' · ', $row['usedBy'])); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->rows)): ?>
                <tr><td colspan="5" style="text-align:center;opacity:.7;padding:24px"><?php echo $e(t('Nothing here yet. A segment is a filter over people, kept up to date: people enter and leave it on their own.')); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>
