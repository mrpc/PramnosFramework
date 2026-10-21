<?php
/**
 * One scheduled task's runs (Bootstrap theme), newest first, each with what it printed.
 *
 * Variables:
 *   $this->status — the task's row from ScheduleStatus::of()
 *   $this->runs   — this page of its runs from scheduleruns
 *   $this->page   — this page's number
 *   $this->pages  — how many pages
 */
$e     = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$when  = static fn (?int $at): string => $at === null ? '—' : date('Y-m-d H:i:s', $at);
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
$row  = $this->status;
$page = (int) $this->page;
$here = adminUrl('Scheduler/show/') . $row['key'];
$bc   = \Pramnos\Html\Breadcrumb::admin([[$e(t('Scheduler')), adminUrl('Scheduler')], [$e($row['label']), '']]);
?>
<div class="container-fluid py-4">
    <?php echo $bc; ?>
    <h2 class="mb-1"><?php echo $e($row['label']); ?></h2>
    <p class="small text-muted mb-3">
        <span title="<?php echo $e($row['expression']); ?>"><?php echo $e($row['words']); ?></span>
        · <?php echo $e(t('Next run')); ?>: <?php echo $e($when($row['next']?->getTimestamp())); ?>
        <?php if ($row['late']): ?> · <span class="badge bg-danger"><?php echo $e(t('Late')); ?></span><?php endif; ?>
    </p>
    <div class="card table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr>
                <th><?php echo $e(t('Started at')); ?></th>
                <th><?php echo $e(t('Result')); ?></th>
                <th><?php echo $e(t('Duration')); ?></th>
                <th><?php echo $e(t('Exit code')); ?></th>
                <th><?php echo $e(t('Host')); ?></th>
                <th><?php echo $e(t('Output')); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($this->runs as $run): ?>
                <tr>
                    <td class="text-nowrap"><?php echo $e($when((int) $run['started_at'])); ?></td>
                    <td><?php echo $badge((string) $run['result']); ?><?php if (!empty($run['requested_by'])): ?> <small class="text-muted"><?php echo $e(sprintf(t('asked by %s'), $run['requested_by'])); ?></small><?php endif; ?></td>
                    <td><?php echo $e($took($run['duration_ms'])); ?></td>
                    <td><?php echo $run['exit_code'] === null ? '' : (int) $run['exit_code']; ?></td>
                    <td><?php echo $e($run['host']); ?></td>
                    <td><?php if ((string) ($run['output'] ?? '') !== ''): ?><details><summary class="small"><?php echo $e(t('Show')); ?></summary><pre class="bg-light p-2 small" style="white-space:pre-wrap;word-break:break-word"><?php echo $e($run['output']); ?></pre></details><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($this->runs)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><?php echo $e(t('No runs recorded yet.')); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($this->pages > 1): ?>
        <div class="mt-3 d-flex gap-2 align-items-center">
            <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo $e($here . '?page=' . ($page - 1)); ?>">&larr; <?php echo $e(t('Newer')); ?></a><?php endif; ?>
            <span class="small text-muted"><?php echo $page; ?> / <?php echo (int) $this->pages; ?></span>
            <?php if ($page < $this->pages): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo $e($here . '?page=' . ($page + 1)); ?>"><?php echo $e(t('Older')); ?> &rarr;</a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
