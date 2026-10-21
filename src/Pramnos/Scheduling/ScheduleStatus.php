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
     * @param array<int, ScheduledTask> $tasks As {@see Scheduler::all()}
     * @return list<array{task: ScheduledTask, key: string, label: string, expression: string, words: string, next: ?\DateTimeImmutable, last: ?array<string, mixed>, expected: ?\DateTimeImmutable, late: bool, failed: bool, running: bool, requested: bool, summary: array<string, mixed>}>
     */
    public static function of(array $tasks, \DateTimeImmutable $now): array
    {
        $latest    = RunLog::latestByTask();
        $requested = RunLog::pendingRequests();
        $since     = RunLog::since();

        $rows = [];
        foreach ($tasks as $task) {
            $key      = $task->key();
            $cron     = $task->getCronExpression();
            $last     = $latest[$key] ?? null;
            $expected = $cron->previousRunBefore($now->modify('-' . self::GRACE_SECONDS . ' seconds'));
            $rows[] = [
                'task'       => $task,
                'key'        => $key,
                'label'      => $task->label(),
                'expression' => $cron->getExpression(),
                'words'      => $cron->describe(),
                'next'       => $cron->nextRunAfter($now),
                'last'       => $last,
                'expected'   => $expected,
                'late'       => $expected !== null && $since !== null && $expected->getTimestamp() >= $since
                    && ($last === null || (int) $last['started_at'] < $expected->getTimestamp()),
                'failed'     => $last !== null && $last['result'] === 'failed',
                'running'    => ($last !== null && $last['result'] === 'running') || $task->isRunning(),
                'requested'  => isset($requested[$key]),
                'summary'    => $task->getSummary(),
            ];
        }

        return $rows;
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
