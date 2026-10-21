<?php
/**
 * The A/B tests (Bootstrap theme): each with its status, how many took part, and the variant ahead.
 *
 * Variables:
 *   $this->tests — Experiments::all(), each with people and leading (the variant with the best rate, or null)
 */
$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<div class="container-fluid py-4">
    <?php $this->activeNav = 'experiments'; $this->insert('../partials/admin_breadcrumb'); ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2><?php echo $e(t('A/B tests')); ?></h2>
        <a href="<?php echo $e(adminUrl('Experiments/edit')); ?>" class="btn btn-primary btn-sm"><?php echo $e(t('+ New test')); ?></a>
    </div>
    <div class="card mb-3"><div class="card-body table-responsive">
        <table class="table table-sm">
            <thead class="table-light">
                <tr><th><?php echo $e(t('Name')); ?></th><th><?php echo $e(t('Status')); ?></th><th><?php echo $e(t('Variants')); ?></th><th class="text-end"><?php echo $e(t('People')); ?></th><th><?php echo $e(t('Ahead')); ?></th></tr>
            </thead>
            <tbody>
            <?php foreach (($this->tests ?? []) as $test): ?>
                <tr>
                    <td><a href="<?php echo $e(adminUrl('Experiments/view/') . $test['id']); ?>"><?php echo $e($test['name']); ?></a> <code><?php echo $e($test['slug']); ?></code></td>
                    <td><?php echo $e($test['winner'] !== null ? t('decided: %s', $test['winner']) : t($test['status'])); ?></td>
                    <td><?php echo $e(implode(' · ', array_map(static fn (array $v): string => $v['key'] . ' ' . $v['share'] . '%', $test['variants']))); ?></td>
                    <td class="text-end"><?php echo $e(number_format($test['people'])); ?></td>
                    <td><?php echo $test['leading'] === null ? '—' : $e($test['leading']['key'] . ' · ' . number_format($test['leading']['rate'] * 100, 1) . '%'); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->tests)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4"><?php echo $e(t('No tests yet. A test shows people one of two or more variants and counts which reaches the goal more often.')); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div></div>
</div>
