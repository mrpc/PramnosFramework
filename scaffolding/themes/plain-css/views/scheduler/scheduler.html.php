<?php
/**
 * The schedule (plain-CSS theme): every task, when it runs next, how its last run went.
 *
 * Variables:
 *   $this->tasks   — ScheduleStatus::of(): per task its key, label, expression and words, next
 *                    run, last run row, and whether it is late, failed, running or requested
 *   $this->counts  — late, failed, running, tasks
 *   $this->working — whether a `work` process holds its lock
 *   $this->history — whether the run history exists (the migration has run)
 */
$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$when  = static fn (?int $at): string => $at === null ? '—' : date('Y-m-d H:i', $at);
$took  = static fn ($ms): string => $ms === null ? '' : ((int) $ms < 1000 ? (int) $ms . ' ms' : ((int) $ms < 60000 ? round((int) $ms / 1000, 1) . ' s' : intdiv((int) $ms, 60000) . ' min ' . intdiv((int) $ms % 60000, 1000) . ' s'));
$badge = static function (string $result) use ($e): string {
    [$class, $label] = match ($result) {
        'ok'          => ['bg-success', t('Succeeded')],
        'failed'      => ['bg-danger', t('Error')],
        'running'     => ['bg-info text-dark', t('In progress')],
        'interrupted' => ['bg-warning text-dark', t('Interrupted')],
        'skipped'     => ['bg-secondary', t('Skipped')],
        default       => ['bg-secondary', $result],
    };

    return '<span class="badge ' . $class . '">' . $e($label) . '</span>';
};
$counts = $this->counts ?? [];
$bc = \Pramnos\Html\Breadcrumb::admin([[$e(t('Scheduler')), '']]);
?>
<div class="page-section">
    <?php echo $bc; ?>
    <h2 class="mb-3"><?php echo $e(t('Scheduler')); ?></h2>

    <?php if (empty($this->working)): ?>
        <div role="status" class="alert alert-warning">
            <strong><?php echo $e(t('No work process is running.')); ?></strong>
            <?php echo $e(t('The schedule is registered, not executing — unless cron runs schedule:run, which cannot be seen from here.')); ?>
        </div>
    <?php endif; ?>
    <?php if (empty($this->history)): ?>
        <div role="status" class="alert alert-info"><?php echo $e(t('No run history yet. Run migrate to create the scheduleruns table.')); ?></div>
    <?php endif; ?>

    <div class="row g-2 mb-3" data-field="counts">
        <?php foreach (['tasks' => t('Tasks'), 'running' => t('Running now'), 'late' => t('Late'), 'failed' => t('Last run failed')] as $key => $label): ?>
            <div class="col-6 col-sm-3"><div class="card p-2">
                <div class="small text-muted"><?php echo $e($label); ?></div>
                <div class="fs-5 fw-semibold <?php echo in_array($key, ['late', 'failed'], true) && (int) ($counts[$key] ?? 0) > 0 ? 'text-danger' : ''; ?>"><?php echo (int) ($counts[$key] ?? 0); ?></div>
            </div></div>
        <?php endforeach; ?>
    </div>

    <div class="card table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th><?php echo $e(t('Task')); ?></th>
                <th><?php echo $e(t('When')); ?></th>
                <th><?php echo $e(t('Next run')); ?></th>
                <th><?php echo $e(t('Last run')); ?></th>
                <th><?php echo $e(t('State')); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach (($this->tasks ?? []) as $row): ?>
                <?php $last = $row['last']; $flags = array_keys(array_filter([
                    t('No overlap') => $row['summary']['no_overlap'] ?? false, t('One server') => $row['summary']['one_server'] ?? false,
                    t('In process') => $row['summary']['in_process'] ?? false, t('In the background') => $row['summary']['background'] ?? false,
                ])); ?>
                <tr data-task="<?php echo $e($row['key']); ?>">
                    <td>
                        <a href="<?php echo $e(adminUrl('Scheduler/show/') . $row['key']); ?>" data-pf-modal class="fw-medium"><?php echo $e($row['label']); ?></a>
                        <?php foreach ($flags as $flag): ?><span class="badge bg-light text-dark border ms-1"><?php echo $e($flag); ?></span><?php endforeach; ?>
                    </td>
                    <td title="<?php echo $e($row['expression']); ?>"><?php echo $e($row['words']); ?></td>
                    <td data-field="next"><?php echo $e($when($row['next']?->getTimestamp())); ?></td>
                    <td data-field="last"><?php if ($last !== null): ?><?php echo $e($when((int) $last['started_at'])); ?> <?php echo $badge((string) $last['result']); ?> <span class="text-muted"><?php echo $e($took($last['duration_ms'])); ?></span><?php else: ?>—<?php endif; ?></td>
                    <td data-field="state">
                        <?php if ($row['running']): ?><span class="badge bg-info text-dark"><?php echo $e(t('In progress')); ?></span><?php endif; ?>
                        <?php if ($row['late']): ?><span class="badge bg-danger" title="<?php echo $e(sprintf(t('Expected at %s'), $when($row['expected']?->getTimestamp()))); ?>"><?php echo $e(t('Late')); ?></span><?php endif; ?>
                        <?php if ($row['requested']): ?><span class="badge bg-secondary"><?php echo $e(t('Requested')); ?></span><?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <form method="post" action="<?php echo $e(adminUrl('Scheduler/runnow/') . $row['key']); ?>" class="d-inline m-0"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-sm btn-outline-primary" data-confirm="<?php echo $e(sprintf(t('Run %s now?'), $row['label'])); ?>"<?php echo $row['requested'] ? ' disabled' : ''; ?>><?php echo $e(t('Run now')); ?></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->tasks)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><?php echo $e(t('No tasks are scheduled.')); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
