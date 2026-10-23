<?php

declare(strict_types=1);

namespace Pramnos\Scheduling;

/**
 * The history of scheduled task runs, in `scheduleruns`: what ran, when, for how long, how it
 * ended, and what it printed — and the runs an operator asked for from the screen.
 *
 * Written by {@see ScheduledTask::run()}, so a run is recorded whoever started it: `work`,
 * `schedule:run`, or a "run now". **Never in the way of the schedule**: an installation that has
 * not run the migration, or a database that is down, records nothing and the task runs as it
 * would have — the history is an account of the schedule, not a condition of it.
 */
final class RunLog
{
    /** How many bytes of a run's output are kept: the end, where the error is. */
    public const OUTPUT_BYTES = 4000;

    /** Days of history kept, unless `schedule.history_days` in `app.php` says otherwise. */
    public const DEFAULT_DAYS = 14;

    /** @var int When this process last forgot old runs */
    private static int $purgedAt = 0;

    /** @var bool|null Whether the table exists, asked once per process */
    private static ?bool $available = null;

    /**
     * A run begins: a `running` row, or the operator's `requested` row turned into one.
     *
     * With `$holdsLock` — the run holds the task's overlap lock, so no other run of it can be
     * alive on this host — a `running` row this host left behind for the task, a worker killed
     * mid-run, is marked `interrupted` first, so the screen does not show it running for ever.
     *
     * @return int|null The row's id, or null where there is no history to write
     */
    public static function start(ScheduledTask $task, ?int $requestId = null, bool $holdsLock = false): ?int
    {
        return self::attempt(static function () use ($task, $requestId, $holdsLock): ?int {
            $host = self::host();
            if ($holdsLock) {
                self::table()->where('task', $task->key())->where('host', $host)->where('result', 'running')
                    ->update(['result' => 'interrupted']);
            }
            if ($requestId !== null) {
                self::table()->where('id', $requestId)
                    ->update(['result' => 'running', 'started_at' => time(), 'host' => $host]);

                return $requestId;
            }
            $db = \Pramnos\Framework\Factory::getDatabase();
            self::table()->insert([
                'task'       => $task->key(),
                'label'      => self::cut($task->label(), 255),
                'started_at' => time(),
                'result'     => 'running',
                'host'       => $host,
            ]);

            return (int) $db->getInsertId() ?: null;
        });
    }

    /**
     * A run ends: `ok` or `failed`, how long it took, its exit status and the end of its output.
     */
    public static function finish(?int $id, string $result, int $durationMs, ?int $exitCode = null, ?string $output = null): void
    {
        if ($id === null) {
            return;
        }
        self::attempt(static function () use ($id, $result, $durationMs, $exitCode, $output): void {
            self::table()->where('id', $id)->update([
                'result'      => $result,
                'duration_ms' => $durationMs,
                'exit_code'   => $exitCode,
                'output'      => $output === null || trim($output) === '' ? null : substr(trim($output), -self::OUTPUT_BYTES),
            ]);
        });
    }

    /**
     * A run that did not happen because a previous one still holds the overlap lock, or another
     * server took it: a `skipped` row, or the operator's request marked so.
     */
    public static function skipped(ScheduledTask $task, ?int $requestId = null): void
    {
        self::attempt(static function () use ($task, $requestId): void {
            if ($requestId !== null) {
                self::table()->where('id', $requestId)->update(['result' => 'skipped', 'duration_ms' => 0]);

                return;
            }
            self::table()->insert([
                'task'        => $task->key(),
                'label'       => self::cut($task->label(), 255),
                'started_at'  => time(),
                'duration_ms' => 0,
                'result'      => 'skipped',
                'host'        => self::host(),
            ]);
        });
    }

    /**
     * An operator asks for a task to run once: a `requested` row, which the next pass of `work`
     * or `schedule:run` takes. Nothing runs in the request that asked.
     *
     * @return bool Whether it was recorded
     */
    public static function request(ScheduledTask $task, string $requestedBy): bool
    {
        return (bool) self::attempt(static function () use ($task, $requestedBy): bool {
            self::table()->insert([
                'task'         => $task->key(),
                'label'        => self::cut($task->label(), 255),
                'started_at'   => time(),
                'result'       => 'requested',
                'host'         => '',
                'requested_by' => self::cut($requestedBy, 100),
            ]);

            return true;
        });
    }

    /**
     * Run what an operator asked for, each request once.
     *
     * A request is claimed by turning it `running` where it is still `requested`: two workers on
     * two servers both see it, and only the one whose update changed the row runs it. A request
     * for a task this schedule no longer has is marked `failed` with that reason.
     *
     * @param array<int, ScheduledTask> $tasks The schedule, as {@see Scheduler::all()}
     * @return int How many requested runs failed
     */
    public static function runRequested(array $tasks): int
    {
        // The history's own housekeeping, from the pass that already runs every minute: at
        // most hourly in a long-lived worker, each minute's DELETE on an index under cron.
        if (time() - self::$purgedAt >= 3600) {
            self::$purgedAt = time();
            self::purge();
        }
        $rows = self::attempt(static fn (): array => self::table()->select(['id', 'task'])
            ->where('result', 'requested')->orderBy('id')->limit(20)->getAll()) ?? [];
        $byKey = [];
        foreach ($tasks as $task) {
            $byKey[$task->key()] = $task;
        }

        $failed = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $claimed = self::attempt(static function () use ($id): bool {
                $result = self::table()->where('id', $id)->where('result', 'requested')
                    ->update(['result' => 'claimed', 'host' => self::host()]);

                return is_object($result) && (int) $result->getAffectedRows() === 1;
            });
            if (!$claimed) {
                continue;
            }
            $task = $byKey[(string) $row['task']] ?? null;
            if ($task === null) {
                self::finish($id, 'failed', 0, null, 'This task is no longer in the schedule.');
                $failed++;
                continue;
            }
            try {
                $task->runRequested($id);
            } catch (\Throwable) {
                $failed++;
            }
        }

        return $failed;
    }

    /**
     * The latest finished, skipped or running run of each task, by task key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function latestByTask(): array
    {
        $rows = self::attempt(static function (): array {
            // The newest row per task: its id is the largest, and ids only grow.
            $ids = array_column(self::table()->select([self::raw('MAX(id) AS id')])
                ->whereIn('result', ['requested', 'claimed'], 'and', true)->groupBy('task')->getAll(), 'id');

            return $ids === [] ? [] : self::table()->whereIn('id', array_map('intval', $ids))->getAll();
        }) ?? [];

        $latest = [];
        foreach ($rows as $row) {
            $latest[(string) $row['task']] = $row;
        }

        return $latest;
    }

    /**
     * The tasks with a request waiting, by task key.
     *
     * @return array<string, true>
     */
    public static function pendingRequests(): array
    {
        $keys = self::attempt(static fn (): array => self::table()->whereIn('result', ['requested', 'claimed'])->pluck('task')) ?? [];

        return array_fill_keys(array_map('strval', $keys), true);
    }

    /**
     * When the history begins — the oldest row's start — or null with none. A run expected
     * before it is not "missed": nothing was recording then.
     */
    public static function since(): ?int
    {
        $oldest = self::attempt(static fn (): mixed => self::table()->min('started_at'));

        return $oldest === null || $oldest === false ? null : (int) $oldest;
    }

    /**
     * Forget runs older than `$days` days.
     *
     * @return int|null Rows removed, or null where there is no history
     */
    public static function purge(?int $days = null): ?int
    {
        $days ??= self::retentionDays();

        return self::attempt(static function () use ($days): int {
            $result = self::table()->where('started_at', '<', time() - $days * 86400)
                ->whereIn('result', ['requested', 'claimed', 'running'], 'and', true)->delete();

            return is_object($result) ? (int) $result->getAffectedRows() : 0;
        });
    }

    /** `schedule.history_days` in `app.php`, or {@see DEFAULT_DAYS}. */
    public static function retentionDays(): int
    {
        $config = \Pramnos\Application\Application::currentInstance()?->applicationInfo['schedule'] ?? [];

        return max(1, (int) ($config['history_days'] ?? self::DEFAULT_DAYS));
    }

    /** Whether this installation keeps a history: the migration has run. */
    public static function available(): bool
    {
        if (self::$available === null) {
            try {
                self::$available = \Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('pramnos.scheduleruns');
            } catch (\Throwable) {
                return false;
            }
        }

        return self::$available;
    }

    /** Forget whether the table exists — after a migration, or between tests. */
    public static function reset(): void
    {
        self::$available = null;
        self::$purgedAt  = 0;
    }

    /** A builder on the table. */
    public static function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('pramnos.scheduleruns');
    }

    /** A raw expression for a select list. */
    private static function raw(string $sql): \Pramnos\Database\Expression
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->raw($sql);
    }

    /**
     * Run `$work` against the table, or answer null where there is none or the database fails.
     *
     * @template T
     * @param callable(): T $work
     * @return T|null
     */
    private static function attempt(callable $work): mixed
    {
        if (!self::available()) {
            return null;
        }
        try {
            return $work();
        } catch (\Throwable) {
            return null;
        }
    }

    /** This machine's name, as the history records it. */
    private static function host(): string
    {
        return self::cut((string) (gethostname() ?: ''), 100);
    }

    /** At most `$length` characters. */
    private static function cut(string $text, int $length): string
    {
        return mb_substr($text, 0, $length);
    }
}
