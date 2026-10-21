<?php
/**
 * The A/B tests: each with its status, how many took part, and the variant ahead. The same markup
 * in every theme.
 *
 * Variables:
 *   $this->tests — Experiments::all(), each with people and leading (the variant with the best rate, or null)
 */
use Pramnos\Html\Icon;

$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$tones = ['draft' => 'pf-tone-muted', 'running' => 'pf-tone-ok', 'stopped' => 'pf-tone-warn'];
?>
<div class="pf-ui">
    <?php $this->activeNav = 'experiments'; $this->insert('../partials/admin_breadcrumb'); ?>
    <header class="pf-page-head">
        <div>
            <h1 class="pf-page-title"><?php echo $e(t('A/B tests')); ?></h1>
        </div>
        <div class="pf-page-actions">
            <a href="<?php echo $e(adminUrl('Experiments/edit')); ?>" class="pf-btn pf-btn-primary"><?php echo Icon::svg('plus', ''); ?><?php echo $e(t('New test')); ?></a>
        </div>
    </header>
    <div class="pf-card pf-card-flush">
        <?php if (empty($this->tests)): ?>
            <div class="pf-empty">
                <span class="pf-empty-icon"><?php echo Icon::svg('experiment', ''); ?></span>
                <p class="pf-empty-title"><?php echo $e(t('No tests yet')); ?></p>
                <p class="pf-empty-text"><?php echo $e(t('A test shows people one of two or more variants and counts which reaches the goal more often.')); ?></p>
                <a href="<?php echo $e(adminUrl('Experiments/edit')); ?>" class="pf-btn pf-btn-primary"><?php echo Icon::svg('plus', ''); ?><?php echo $e(t('New test')); ?></a>
            </div>
        <?php else: ?>
            <table class="pf-table">
                <thead>
                    <tr><th><?php echo $e(t('Name')); ?></th><th><?php echo $e(t('Test status')); ?></th><th><?php echo $e(t('Test variants')); ?></th><th class="pf-num"><?php echo $e(t('People in the test')); ?></th><th><?php echo $e(t('Ahead now')); ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($this->tests as $test): ?>
                    <tr>
                        <td>
                            <a class="pf-row-link" href="<?php echo $e(adminUrl('Experiments/view/') . $test['id']); ?>"><?php echo $e($test['name']); ?></a>
                            <span class="pf-row-desc"><code><?php echo $e($test['slug']); ?></code></span>
                        </td>
                        <td><span class="pf-badge pf-status <?php echo $test['winner'] !== null ? 'pf-tone-accent' : ($tones[$test['status']] ?? 'pf-tone-muted'); ?>"><?php echo $e($test['winner'] !== null ? t('decided: %s', $test['winner']) : t($test['status'])); ?></span></td>
                        <td><span class="pf-chips"><?php foreach ($test['variants'] as $v): ?><span class="pf-chip"><?php echo $e($v['key'] . ' · ' . $v['share'] . '%'); ?></span><?php endforeach; ?></span></td>
                        <td class="pf-num"><?php echo $e(number_format($test['people'])); ?></td>
                        <td><?php echo $test['leading'] === null ? '<span class="pf-zero">—</span>' : '<span class="pf-badge pf-tone-ok">' . $e($test['leading']['key'] . ' · ' . number_format($test['leading']['rate'] * 100, 1) . '%') . '</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
