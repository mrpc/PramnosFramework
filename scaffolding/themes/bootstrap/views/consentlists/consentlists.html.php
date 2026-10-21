<?php
/**
 * The consent lists (Bootstrap theme): what each send and rule asks before it reaches somebody.
 *
 * Variables:
 *   $this->lists — ConsentLists::all(), each with count (null when it cannot be counted), problem
 *                  (why not), and sourceLabel
 */
$e        = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$channels = \Pramnos\Application\Controllers\ConsentLists::channelWords();
?>
<div class="container-fluid py-4">
    <?php $this->activeNav = 'consentlists'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2><?php echo $e(t('Consent lists')); ?></h2>
        <a href="<?php echo $e(adminUrl('ConsentLists/edit')); ?>" class="btn btn-primary btn-sm"><?php echo $e(t('+ New list')); ?></a>
    </div>
    <p class="mb-3 text-muted"><?php echo $e(t('Every send and rule names one of these lists, never a field, so a list can change where it reads from without touching them. An email list also leaves out everybody who unsubscribed from everything.')); ?></p>
    <div class="card mb-3"><div class="card-body table-responsive">
        <table class="table table-sm">
            <thead class="table-light">
                <tr><th><?php echo $e(t('Name')); ?></th><th><?php echo $e(t('Channel')); ?></th><th><?php echo $e(t('Read from')); ?></th><th><?php echo $e(t('New accounts')); ?></th><th class="text-end"><?php echo $e(t('In it now')); ?></th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach (($this->lists ?? []) as $list): ?>
                <tr>
                    <td><a href="<?php echo $e(adminUrl('ConsentLists/edit/') . (int) $list['id']); ?>"><?php echo $e($list['name']); ?></a>
                        <?php if ($list['description'] !== ''): ?><div class="text-muted"><?php echo $e($list['description']); ?></div><?php endif; ?></td>
                    <td><?php echo $e($channels[$list['channel']] ?? $list['channel']); ?></td>
                    <td><?php echo $e($list['sourceLabel']); ?><?php if ($list['optOutList'] !== null): ?> <span class="text-muted"><?php echo $e(t('and the opt-outs of %s', $list['optOutList'])); ?></span><?php endif; ?></td>
                    <td><?php echo $e($list['defaultOn'] ? t('in') : t('out')); ?></td>
                    <td class="text-end"><?php echo $list['count'] === null ? '<span class="text-danger" title="' . $e($list['problem']) . '">' . $e(t('cannot count')) . '</span>' : $e(number_format($list['count'])); ?></td>
                    <td class="text-end">
                        <form method="post" action="<?php echo $e(adminUrl('ConsentLists/delete/') . (int) $list['id']); ?>" data-confirm="<?php echo $e(t('Delete this consent list?')); ?>">
                            <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo $e(t('Delete')); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->lists)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><?php echo $e(t('No consent lists yet.')); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>
