<?php
/**
 * Queue statistics (Bootstrap theme): the hourly roll-up per task type.
 *
 * Variables:
 *   $this->hours   — how far back
 *   $this->history — rows of QueueManager::history(): type, bucket, tasks, failed, warning,
 *                    retried, exec_mean, exec_max, cpu_mean, wait_mean, wait_max, fail_rate
 *
 * From the hourly buckets the cleanup keeps when it removes the tasks themselves, so it answers
 * for longer than the queue table does. Wait is how long a task sat before a worker took it.
 */
$e  = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$bc = \Pramnos\Html\Breadcrumb::admin([[$e(t('Queue')), adminUrl('Queue')], [$e(t('Statistics')), '']]);
?>
<div class="container-fluid py-4">
    <?php echo $bc; ?>
    <h2 class="mb-3"><?php echo $e(sprintf(t('Queue statistics — last %d hours'), (int) $this->hours)); ?></h2>
    <?php if (empty($this->history)): ?>
        <p class="text-muted"><?php echo $e(t('Nothing recorded yet. The hourly figures are written when finished tasks are cleaned up.')); ?></p>
    <?php else: ?>
        <div class="card table-responsive">
            <table class="table table-sm mb-0" data-field="history">
                <thead><tr>
                    <th><?php echo $e(t('Hour')); ?></th>
                    <th><?php echo $e(t('Task type')); ?></th>
                    <th class="text-end"><?php echo $e(t('Tasks')); ?></th>
                    <th class="text-end"><?php echo $e(t('Failed')); ?></th>
                    <th class="text-end"><?php echo $e(t('Retried')); ?></th>
                    <th class="text-end"><?php echo $e(t('Execution mean / max')); ?></th>
                    <th class="text-end"><?php echo $e(t('CPU mean')); ?></th>
                    <th class="text-end"><?php echo $e(t('Wait mean / max')); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($this->history as $row): ?>
                        <tr>
                            <td><?php echo $e($row['bucket']); ?></td>
                            <td><code><?php echo $e($row['type']); ?></code></td>
                            <td class="text-end"><?php echo number_format((int) $row['tasks']); ?></td>
                            <td class="text-end"><?php echo (int) $row['failed']; ?></td>
                            <td class="text-end"><?php echo (int) $row['retried']; ?></td>
                            <td class="text-end"><?php echo $e($row['exec_mean'] . ' / ' . $row['exec_max']); ?> s</td>
                            <td class="text-end"><?php echo $row['cpu_mean'] === null ? '—' : $e($row['cpu_mean']) . ' s'; ?></td>
                            <td class="text-end"><?php echo $e($row['wait_mean'] . ' / ' . $row['wait_max']); ?> s</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
