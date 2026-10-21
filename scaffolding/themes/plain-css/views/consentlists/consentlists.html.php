<?php
/**
 * The consent lists: what each send and rule asks before it reaches somebody — its channel, where
 * it reads from, and how many are in it now. The same markup in every theme.
 *
 * Variables:
 *   $this->lists — ConsentLists::all(), each with count (null when it cannot be counted), problem
 *                  (why not), and sourceLabel
 */
use Pramnos\Html\Icon;

$e        = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$channels = \Pramnos\Application\Controllers\ConsentLists::channelWords();
$tones    = ['email' => 'pf-tone-accent', 'sms' => 'pf-tone-ok', 'push' => 'pf-tone-info'];
?>
<div class="pf-ui">
    <?php $this->activeNav = 'consentlists'; $this->insert('../partials/admin_breadcrumb'); ?>
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title"><?php echo $e(t('Consent lists')); ?></h1>
            <p class="pf-page-sub"><?php echo $e(t('Every send and rule names one of these lists, never a field.')); ?></p>
        </div>
        <div class="pf-page-actions">
            <a href="<?php echo $e(adminUrl('ConsentLists/edit')); ?>" class="pf-btn pf-btn-primary"><?php echo Icon::svg('plus', ''); ?><?php echo $e(t('New list')); ?></a>
        </div>
    </header>
    <div class="pf-card pf-card-flush">
        <?php if (empty($this->lists)): ?>
            <div class="pf-empty">
                <span class="pf-empty-icon"><?php echo Icon::svg('consent', ''); ?></span>
                <p class="pf-empty-title"><?php echo $e(t('No consent lists yet')); ?></p>
                <p class="pf-empty-text"><?php echo $e(t('A list says who agreed to a kind of message on a channel. A send or a rule then reaches only them, and an email list also leaves out everybody who unsubscribed from everything.')); ?></p>
                <a href="<?php echo $e(adminUrl('ConsentLists/edit')); ?>" class="pf-btn pf-btn-primary"><?php echo Icon::svg('plus', ''); ?><?php echo $e(t('New list')); ?></a>
            </div>
        <?php else: ?>
            <table class="pf-table">
                <thead>
                    <tr><th><?php echo $e(t('Name')); ?></th><th><?php echo $e(t('Channel')); ?></th><th><?php echo $e(t('Read from')); ?></th><th><?php echo $e(t('New accounts')); ?></th><th class="pf-num"><?php echo $e(t('In it now')); ?></th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($this->lists as $list): ?>
                    <tr>
                        <td>
                            <a class="pf-row-link" href="<?php echo $e(adminUrl('ConsentLists/edit/') . (int) $list['id']); ?>"><?php echo $e($list['name']); ?></a>
                            <?php if ($list['description'] !== ''): ?><span class="pf-row-desc"><?php echo $e($list['description']); ?></span><?php endif; ?>
                        </td>
                        <td><span class="pf-badge <?php echo $tones[$list['channel']] ?? 'pf-tone-muted'; ?>"><?php echo $e($channels[$list['channel']] ?? $list['channel']); ?></span></td>
                        <td><?php echo $e($list['sourceLabel']); ?><?php if ($list['optOutList'] !== null): ?><span class="pf-row-desc"><?php echo $e(t('and the opt-outs of %s', $list['optOutList'])); ?></span><?php endif; ?></td>
                        <td><span class="pf-badge pf-status <?php echo $list['defaultOn'] ? 'pf-tone-ok' : 'pf-tone-muted'; ?>"><?php echo $e($list['defaultOn'] ? t('in') : t('out')); ?></span></td>
                        <td class="pf-num"><?php echo $list['count'] === null ? '<span class="pf-badge pf-tone-bad" title="' . $e($list['problem']) . '">' . $e(t('cannot count')) . '</span>' : $e(number_format($list['count'])); ?></td>
                        <td class="pf-num">
                            <form method="post" action="<?php echo $e(adminUrl('ConsentLists/delete/') . (int) $list['id']); ?>" class="pf-inline-form" data-confirm="<?php echo $e(t('Delete this consent list?')); ?>">
                                <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                                <button type="submit" class="pf-btn pf-btn-ghost pf-btn-sm pf-btn-icon pf-btn-danger" aria-label="<?php echo $e(t('Delete')); ?>" title="<?php echo $e(t('Delete')); ?>"><?php echo Icon::svg('delete', ''); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
