<?php
/**
 * One scheduled task's runs (Bootstrap theme), newest first, each with what it printed.
 *
 * Variables:
 *   $this->status — the task's row from ScheduleStatus::of()
 *   $this->runs   — this page of its runs from scheduleruns
 *   $this->page   — this page's number
 *   $this->pages  — how many pages
 *   $this->adjust — whether this account changes the task: its settings form is shown
 *   $this->full   — whether this account opens the whole screen
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
        <?php if (!empty($row['paused'])): ?> · <span class="badge bg-warning text-dark"><?php echo $e(sprintf(t('Paused since %s'), date('Y-m-d H:i', (int) ($row['override']['pausedat'] ?? 0)))); ?></span><?php elseif (!empty($row['outside'])): ?> · <span class="badge bg-secondary"><?php echo $e(sprintf(t('Outside hours · next %s'), $row['next'] ? $row['next']->format('H:i') : '—')); ?></span><?php endif; ?>
        <?php if ($row['late']): ?> · <span class="badge bg-danger"><?php echo $e(t('Late')); ?></span><?php endif; ?>
    </p>
    <?php if (!empty($this->adjust)):
        $ov    = $row['override'] ?? [];
        $offer = $row['adjustable'];
        $freq  = (string) ($ov['frequency'] ?? '');
        $parts = explode(':', $freq);
        $kind  = in_array($parts[0], ['daily', 'weekly'], true) ? $parts[0] : $freq;
        $at    = $parts[0] === 'daily' ? ($parts[1] ?? '') . ':' . ($parts[2] ?? '') : ($parts[0] === 'weekly' ? ($parts[2] ?? '') . ':' . ($parts[3] ?? '') : '');
        $day   = $parts[0] === 'weekly' ? (int) ($parts[1] ?? 1) : 1;
        $words = static function (string $k): string {
            [$type, $n] = array_pad(explode(':', $k), 2, '');
            return match ($type) {
                'every' => (int) $n === 1 ? t('Every minute') : sprintf(t('Every %d minutes'), (int) $n),
                'hours' => (int) $n === 1 ? t('Every hour') : sprintf(t('Every %d hours'), (int) $n),
                'daily' => t('Daily at'),
                default => t('Weekly on'),
            };
        };
        ?>
    <form method="post" action="<?php echo $e(adminUrl('Scheduler/adjust/') . $row['key']); ?>" class="card card-body mb-3" data-field="settings">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
            <label><input type="checkbox" name="enabled" value="1" class="form-check-input"<?php echo empty($row['paused']) ? ' checked' : ''; ?>> <?php echo $e(t('Runs')); ?></label>
            <label><span class="form-label small mb-1 d-block"><?php echo $e(t('Frequency')); ?></span>
                <select name="frequency" class="form-select form-select-sm">
                    <option value=""><?php echo $e(sprintf(t('As in the code (%s)'), $row['task']->getCronExpression()->describe())); ?></option>
                    <?php foreach (\Pramnos\Scheduling\ScheduleOverrides::kinds($offer['presets']) as $option): ?>
                        <option value="<?php echo $e($option); ?>"<?php echo $option === $kind ? ' selected' : ''; ?>><?php echo $e($words($option)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span class="form-label small mb-1 d-block"><?php echo $e(t('Day')); ?></span>
                <select name="weekday" class="form-select form-select-sm">
                    <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $number => $name): ?>
                        <option value="<?php echo $number; ?>"<?php echo $number === $day ? ' selected' : ''; ?>><?php echo $e(t($name)); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span class="form-label small mb-1 d-block"><?php echo $e(t('Time')); ?></span><input type="time" name="time" value="<?php echo $e($at === ':' ? '' : $at); ?>" class="form-control form-control-sm"></label>
        </div>
        <div class="d-flex flex-wrap gap-3 align-items-end mb-3">
            <label><span class="form-label small mb-1 d-block"><?php echo $e(t('Only between')); ?></span><input type="time" name="hoursfrom" value="<?php echo $e($ov['hoursfrom'] ?? ''); ?>" class="form-control form-control-sm"></label>
            <label><span class="form-label small mb-1 d-block"><?php echo $e(t('and')); ?></span><input type="time" name="hoursto" value="<?php echo $e($ov['hoursto'] ?? ''); ?>" class="form-control form-control-sm"></label>
            <?php if ($offer['limit'] !== null): ?>
                <label><span class="form-label small mb-1 d-block"><?php echo $e(sprintf(t('Per run (%d–%d)'), $offer['limit'][0], $offer['limit'][1])); ?></span><input type="number" name="runlimit" min="<?php echo (int) $offer['limit'][0]; ?>" max="<?php echo (int) $offer['limit'][1]; ?>" value="<?php echo $e($ov['runlimit'] ?? ''); ?>" class="form-control form-control-sm"></label>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-sm"><?php echo $e(t('Save')); ?></button>
        </div>
        <?php if ($ov !== []): ?>
            <p class="small text-muted"><?php echo $e(sprintf(t('Changed by %s on %s'), (string) ($ov['updatedby'] ?? ''), date('Y-m-d H:i', (int) ($ov['updatedat'] ?? 0)))); ?></p>
        <?php endif; ?>
    </form>
    <?php if ($ov !== []): ?>
        <form method="post" action="<?php echo $e(adminUrl('Scheduler/reset/') . $row['key']); ?>" class="mb-3"><?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?><button type="submit" class="btn btn-outline-secondary btn-sm" data-confirm="<?php echo $e(t('Back to the schedule in the code?')); ?>"><?php echo $e(t('Back to the schedule in the code')); ?></button></form>
    <?php endif; ?>
    <?php endif; ?>

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
