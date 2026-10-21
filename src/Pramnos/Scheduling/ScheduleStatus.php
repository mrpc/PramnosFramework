<?php

declare(strict_types=1);

namespace Pramnos\Scheduling;

/**
 * Each scheduled task's state at a moment, for a screen: when it runs next, how its last run
 * went, whether it is running or waiting for an operator's run, and whether it is late.
 *
 * One place, so the scheduler screen and the dashboard card cannot disagree about what "late"
 * means.
 */
final class ScheduleStatus
{
    /** Seconds a run may start after its minute before it counts as missing. */
    public const GRACE_SECONDS = 120;

    /**
     * Every task the schedule holds, with its state at `$now`.
     *
     * **Late** is a run its expression asked for that the history does not have: the last minute
     * it was due, at least {@see GRACE_SECONDS} ago, after the history began, with no run of the
     * task started since. A minute before the history began is not missed — nothing was
     * recording then.
     *
     * A task an operator paused, or whose hours window is closed, is never late, and neither is
     * a run its expression asked for outside the window: none was meant to happen. Its next run
     * is the first one inside the window; null while paused. The operator's changes are read
     * here, so every screen built on this agrees with what `work` runs.
     *
     * @param array<int, ScheduledTask> $tasks As {@see Scheduler::all()}
     * @return list<array{task: ScheduledTask, key: string, label: string, expression: string, words: string, next: ?\DateTimeImmutable, last: ?array<string, mixed>, expected: ?\DateTimeImmutable, late: bool, failed: bool, running: bool, requested: bool, summary: array<string, mixed>, adjustable: ?array<string, mixed>, override: ?array<string, mixed>, paused: bool, outside: bool}>
     */
    public static function of(array $tasks, \DateTimeImmutable $now): array
    {
        $latest    = RunLog::latestByTask();
        $requested = RunLog::pendingRequests();
        $since     = RunLog::since();
        ScheduleOverrides::apply($tasks);

        $rows = [];
        foreach ($tasks as $task) {
            $key      = $task->key();
            $cron     = $task->effectiveCron();
            $last     = $latest[$key] ?? null;
            $paused   = $task->isPaused();
            $expected = $paused ? null : $cron->previousRunBefore($now->modify('-' . self::GRACE_SECONDS . ' seconds'));
            if ($expected !== null && $task->isOutsideHours($expected)) {
                $expected = null;
            }
            $rows[] = [
                'task'       => $task,
                'key'        => $key,
                'label'      => $task->label(),
                'expression' => $cron->getExpression(),
                'words'      => $cron->describe(),
                'next'       => $task->nextDue($now),
                'last'       => $last,
                'expected'   => $expected,
                'late'       => $expected !== null && $since !== null && $expected->getTimestamp() >= $since
                    && ($last === null || (int) $last['started_at'] < $expected->getTimestamp()),
                'failed'     => $last !== null && $last['result'] === 'failed',
                'running'    => ($last !== null && $last['result'] === 'running') || $task->isRunning(),
                'requested'  => isset($requested[$key]),
                'summary'    => $task->getSummary(),
                'adjustable' => $task->adjustment(),
                'override'   => $task->override(),
                'paused'     => $paused,
                'outside'    => $task->isOutsideHours($now),
            ];
        }

        return $rows;
    }

    /**
     * The rows as a screen lists them: the adjustable tasks under their groups, then the rest.
     *
     * One unnamed group when nothing is adjustable, so a schedule that adjusts nothing looks as it
     * always did.
     *
     * @param list<array<string, mixed>> $rows As {@see of()}
     * @return array<string, list<array<string, mixed>>> Group label => rows
     */
    public static function grouped(array $rows): array
    {
        $groups = [];
        $rest   = [];
        foreach ($rows as $row) {
            if ($row['adjustable'] === null) {
                $rest[] = $row;
                continue;
            }
            $label = $row['adjustable']['group'] !== '' ? $row['adjustable']['group'] : (string) t('Adjustable');
            $groups[$label][] = $row;
        }
        if ($groups === []) {
            return $rest === [] ? [] : ['' => $rest];
        }
        if ($rest !== []) {
            $groups[(string) t('Other tasks')] = array_merge($groups[(string) t('Other tasks')] ?? [], $rest);
        }

        return $groups;
    }

    /**
     * How many tasks are late, failed and running — the dashboard card's three figures.
     *
     * @param list<array<string, mixed>> $rows As {@see of()}
     * @return array{late: int, failed: int, running: int, tasks: int}
     */
    public static function counts(array $rows): array
    {
        return [
            'late'    => count(array_filter($rows, static fn (array $row): bool => $row['late'])),
            'failed'  => count(array_filter($rows, static fn (array $row): bool => $row['failed'])),
            'running' => count(array_filter($rows, static fn (array $row): bool => $row['running'])),
            'tasks'   => count($rows),
        ];
    }

    /**
     * Whether a `work` process is executing the schedule: it holds its lock. A `schedule:run`
     * crontab line cannot be seen from here.
     */
    public static function workerRunning(): bool
    {
        return (new \Pramnos\Console\WorkerLock('pramnos-work.lock', (new \Pramnos\Console\Commands\Work())->lockFilePath()))
            ->isHeldByAnother();
    }
}
