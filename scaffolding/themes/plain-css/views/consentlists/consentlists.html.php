<?php
/**
 * The consent lists (plain-CSS theme): what each send and rule asks before it reaches somebody.
 *
 * Variables:
 *   $this->lists — ConsentLists::all(), each with count (null when it cannot be counted), problem
 *                  (why not), and sourceLabel
 */
$e        = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$channels = \Pramnos\Application\Controllers\ConsentLists::channelWords();
?>
<div class="page-section">
    <?php $this->activeNav = 'consentlists'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;margin-bottom:16px">
        <h2><?php echo $e(t('Consent lists')); ?></h2>
        <a href="<?php echo $e(adminUrl('ConsentLists/edit')); ?>" class="btn btn-primary"><?php echo $e(t('+ New list')); ?></a>
    </div>
    <p style="margin-bottom:16px;opacity:.8"><?php echo $e(t('Every send and rule names one of these lists, never a field, so a list can change where it reads from without touching them. An email list also leaves out everybody who unsubscribed from everything.')); ?></p>
    <div class="card" style="border:1px solid #ddd;border-radius:4px;margin-bottom:16px"><div class="card-body" style="padding:16px;overflow-x:auto">
        <table class="table" style="width:100%;border-collapse:collapse">
            <thead>
                <tr><th><?php echo $e(t('Name')); ?></th><th><?php echo $e(t('Channel')); ?></th><th><?php echo $e(t('Read from')); ?></th><th><?php echo $e(t('New accounts')); ?></th><th style="text-align:right"><?php echo $e(t('In it now')); ?></th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach (($this->lists ?? []) as $list): ?>
                <tr>
                    <td><a href="<?php echo $e(adminUrl('ConsentLists/edit/') . (int) $list['id']); ?>"><?php echo $e($list['name']); ?></a>
                        <?php if ($list['description'] !== ''): ?><div style="opacity:.7"><?php echo $e($list['description']); ?></div><?php endif; ?></td>
                    <td><?php echo $e($channels[$list['channel']] ?? $list['channel']); ?></td>
                    <td><?php echo $e($list['sourceLabel']); ?><?php if ($list['optOutList'] !== null): ?> <span style="opacity:.7"><?php echo $e(t('and the opt-outs of %s', $list['optOutList'])); ?></span><?php endif; ?></td>
                    <td><?php echo $e($list['defaultOn'] ? t('in') : t('out')); ?></td>
                    <td style="text-align:right"><?php echo $list['count'] === null ? '<span style="color:#b91c1c" title="' . $e($list['problem']) . '">' . $e(t('cannot count')) . '</span>' : $e(number_format($list['count'])); ?></td>
                    <td style="text-align:right">
                        <form method="post" action="<?php echo $e(adminUrl('ConsentLists/delete/') . (int) $list['id']); ?>" data-confirm="<?php echo $e(t('Delete this consent list?')); ?>">
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <button type="submit" class="btn btn-secondary"><?php echo $e(t('Delete')); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->lists)): ?>
                <tr><td colspan="6" style="text-align:center;opacity:.7;padding:24px"><?php echo $e(t('No consent lists yet.')); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>
