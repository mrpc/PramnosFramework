@php /**
 * Queue statistics (plain-CSS theme): the hourly roll-up per task type.
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
$bc = \Pramnos\Html\Breadcrumb::admin([[$e(t('Queue')), adminUrl('Queue')], [$e(t('Statistics')), '']]); @endphp
<div class="page-section">
    {!! $bc !!}
    <h2 style="margin-bottom:16px">{{ sprintf(t('Queue statistics — last %d hours'), (int) $this->hours) }}</h2>
    @if (empty($this->history))
        <p style="color:#666">{{ t('Nothing recorded yet. The hourly figures are written when finished tasks are cleaned up.') }}</p>
    @else
        <div class="card" style="overflow-x:auto">
            <table class="table" data-field="history">
                <thead><tr>
                    <th>{{ t('Hour') }}</th>
                    <th>{{ t('Task type') }}</th>
                    <th style="text-align:right">{{ t('Tasks') }}</th>
                    <th style="text-align:right">{{ t('Failed') }}</th>
                    <th style="text-align:right">{{ t('Retried') }}</th>
                    <th style="text-align:right">{{ t('Execution mean / max') }}</th>
                    <th style="text-align:right">{{ t('CPU mean') }}</th>
                    <th style="text-align:right">{{ t('Wait mean / max') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($this->history as $row)
                        <tr>
                            <td>{{ $row['bucket'] }}</td>
                            <td><code>{{ $row['type'] }}</code></td>
                            <td style="text-align:right">{!! number_format((int) $row['tasks']) !!}</td>
                            <td style="text-align:right">{!! (int) $row['failed'] !!}</td>
                            <td style="text-align:right">{!! (int) $row['retried'] !!}</td>
                            <td style="text-align:right">{{ $row['exec_mean'] . ' / ' . $row['exec_max'] }} s</td>
                            <td style="text-align:right">{!! $row['cpu_mean'] === null ? '—' : $e($row['cpu_mean']) . ' s' !!}</td>
                            <td style="text-align:right">{{ $row['wait_mean'] . ' / ' . $row['wait_max'] }} s</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
