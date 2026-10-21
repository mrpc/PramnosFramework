<?php

declare(strict_types=1);

namespace Pramnos\Scheduling;

/**
 * What an operator changed about an adjustable task from the Scheduler screen: paused, a different
 * frequency from a fixed list, an hours window, a per-run limit. One row per task in
 * `scheduleoverrides`, keyed by the id the application gave it in `->adjustable()`; no row is the
 * code's own schedule.
 *
 * Read once per pass by `work` and `schedule:run` ({@see apply()}), so a change takes effect on the
 * next pass without a restart. Every change is written to the audit log.
 */
final class ScheduleOverrides
{
    /** Minutes for "every N minutes", and hours for "every N hours" — the fixed list. */
    public const MINUTES = [1, 5, 10, 15, 30];
    public const HOURS   = [1, 2, 3, 6, 12];

    private static ?bool $available = null;

    /** Whether the table exists — the migration has run. */
    public static function available(): bool
    {
        if (self::$available === null) {
            try {
                self::$available = \Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('scheduleoverrides');
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
    }

    /** A builder on the table. */
    public static function table(): \Pramnos\Database\QueryBuilder
    {
        return \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#scheduleoverrides');
    }

    /**
     * Every row, by task id. Empty where the table does not exist or cannot be read: a broken
     * store means the code's own schedule, never no schedule.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::attempt(static function (): array {
            $rows = [];
            foreach (self::table()->getAll() as $row) {
                $rows[(string) $row['taskid']] = $row;
            }

            return $rows;
        }) ?? [];
    }

    /**
     * Run `$work` against the table, or answer null where there is none or the database fails —
     * logged, never thrown: an operator's setting must not take a pass or a page down with it.
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
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::logError('ScheduleOverrides: ' . $e->getMessage(), $e);

            return null;
        }
    }

    /**
     * Hand each adjustable task its row, or none: one query for the whole schedule.
     *
     * @param array<int, ScheduledTask> $tasks
     */
    public static function apply(array $tasks): void
    {
        $rows = null;
        foreach ($tasks as $task) {
            $id = $task->adjustment()['id'] ?? null;
            if ($id === null) {
                continue;
            }
            $rows ??= self::all();
            $task->useOverride($rows[$id] ?? null);
        }
    }

    /**
     * The cron expression of a frequency from the list, or null for one that is not on it.
     *
     * `every:5` (minutes), `hours:2`, `daily:07:30`, `weekly:1:11:15` (0 is Sunday, as cron).
     */
    public static function cronFor(?string $frequency): ?string
    {
        $parts = explode(':', (string) $frequency);
        $time  = static fn (string $h, string $m): ?array => preg_match('/^\d{2}$/', $h) && preg_match('/^\d{2}$/', $m)
            && (int) $h < 24 && (int) $m < 60 ? [(int) $h, (int) $m] : null;

        return match (true) {
            count($parts) === 2 && $parts[0] === 'every' && in_array((int) $parts[1], self::MINUTES, true) && ctype_digit($parts[1])
                => (int) $parts[1] === 1 ? '* * * * *' : '*/' . (int) $parts[1] . ' * * * *',
            count($parts) === 2 && $parts[0] === 'hours' && in_array((int) $parts[1], self::HOURS, true) && ctype_digit($parts[1])
                => (int) $parts[1] === 1 ? '0 * * * *' : '0 */' . (int) $parts[1] . ' * * *',
            count($parts) === 3 && $parts[0] === 'daily' && ($at = $time($parts[1], $parts[2])) !== null
                => $at[1] . ' ' . $at[0] . ' * * *',
            count($parts) === 4 && $parts[0] === 'weekly' && preg_match('/^[0-6]$/', $parts[1]) && ($at = $time($parts[2], $parts[3])) !== null
                => $at[1] . ' ' . $at[0] . ' * * ' . $parts[1],
            default => null,
        };
    }

    /**
     * The frequencies a task offers, as the kinds `->adjustable(presets:)` names: `every:N`,
     * `hours:N`, `daily`, `weekly`. All of them where the task names none.
     *
     * @param list<string>|null $presets
     * @return list<string>
     */
    public static function kinds(?array $presets): array
    {
        $all = array_merge(
            array_map(static fn (int $n): string => 'every:' . $n, self::MINUTES),
            array_map(static fn (int $n): string => 'hours:' . $n, self::HOURS),
            ['daily', 'weekly']
        );

        return $presets === null ? $all : array_values(array_intersect($all, $presets));
    }

    /** Whether a frequency is one this task offers. */
    public static function offers(?array $presets, string $frequency): bool
    {
        if (self::cronFor($frequency) === null) {
            return false;
        }
        $kind = in_array(explode(':', $frequency)[0], ['daily', 'weekly'], true) ? explode(':', $frequency)[0] : $frequency;

        return in_array($kind, self::kinds($presets), true);
    }

    /** `HH:MM` as minutes of the day, or null. */
    public static function minuteOfDay(?string $time): ?int
    {
        return is_string($time) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

    /**
     * Whether `$when` is inside the row's hours window; a row with no window, or half of one, is
     * always inside. A window that ends before it starts crosses midnight: 22:00–06:00.
     *
     * @param array<string, mixed>|null $row
     */
    public static function inWindow(?array $row, \DateTimeInterface $when): bool
    {
        $from = self::minuteOfDay($row['hoursfrom'] ?? null);
        $to   = self::minuteOfDay($row['hoursto'] ?? null);
        if ($from === null || $to === null || $from === $to) {
            return true;
        }
        $now = (int) $when->format('G') * 60 + (int) $when->format('i');

        return $from < $to ? $now >= $from && $now < $to : $now >= $from || $now < $to;
    }

    /**
     * The first moment after `$after` at which the row's window opens.
     *
     * @param array<string, mixed> $row
     */
    public static function opensAfter(array $row, \DateTimeImmutable $after): \DateTimeImmutable
    {
        $from  = (int) self::minuteOfDay($row['hoursfrom'] ?? null);
        $opens = $after->setTime(intdiv($from, 60), $from % 60);

        return $opens > $after ? $opens : $opens->modify('+1 day');
    }

    /**
     * Save an operator's settings for a task, and audit them.
     *
     * @param array{enabled: bool, frequency: ?string, hoursfrom: ?string, hoursto: ?string, runlimit: ?int} $values Already validated
     */
    public static function save(ScheduledTask $task, array $values, ?int $userid, string $who, int $now): bool
    {
        $id  = (string) $task->adjustment()['id'];
        $old = self::all()[$id] ?? null;
        $row = [
            'enabled'   => $values['enabled'] ? 1 : 0,
            'frequency' => $values['frequency'],
            'hoursfrom' => $values['hoursfrom'],
            'hoursto'   => $values['hoursto'],
            'runlimit'  => $values['runlimit'],
            'pausedat'  => $values['enabled'] ? null : (($old !== null && (int) $old['enabled'] === 0 && $old['pausedat'] !== null) ? (int) $old['pausedat'] : $now),
            'updatedby' => substr($who, 0, 100),
            'updatedat' => $now,
        ];
        $saved = self::attempt(static function () use ($id, $old, $row): bool {
            $old === null
                ? self::table()->insert(['taskid' => $id] + $row)
                : self::table()->where('taskid', $id)->update($row);

            return true;
        });
        if ($saved === null) {
            return false;
        }
        \Pramnos\Auth\AuditLog::change('scheduler.adjusted', $userid, 'scheduled_task', $id, self::audited($old), self::audited($row), ['label' => $task->label()]);

        return true;
    }

    /** Back to the code's own schedule: the row goes, and the audit log says who did it. */
    public static function forget(ScheduledTask $task, ?int $userid): bool
    {
        $id  = (string) $task->adjustment()['id'];
        $old = self::all()[$id] ?? null;
        if ($old === null) {
            return self::available();
        }
        $deleted = self::attempt(static function () use ($id): bool {
            self::table()->where('taskid', $id)->delete();

            return true;
        });
        if ($deleted === null) {
            return false;
        }
        \Pramnos\Auth\AuditLog::change('scheduler.reset', $userid, 'scheduled_task', $id, self::audited($old), [], ['label' => $task->label()]);

        return true;
    }

    /**
     * The settings part of a row, for the audit log.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private static function audited(?array $row): array
    {
        if ($row === null) {
            return [];
        }

        return [
            'enabled'   => (int) $row['enabled'] === 1,
            'frequency' => $row['frequency'],
            'hoursfrom' => $row['hoursfrom'],
            'hoursto'   => $row['hoursto'],
            'runlimit'  => $row['runlimit'] === null ? null : (int) $row['runlimit'],
        ];
    }
}
