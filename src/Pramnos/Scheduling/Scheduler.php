<?php

namespace Pramnos\Scheduling;

/**
 * Central registry and factory for scheduled tasks.
 *
 * Tasks are typically registered in a ServiceProvider::boot() method.
 *
 * ## Usage
 *
 * ```php
 * use Pramnos\Scheduling\Scheduler;
 *
 * // In ServiceProvider::boot():
 * Scheduler::command('cleanup:temp')->daily()->at('02:00');
 * Scheduler::call(fn() => Cache::flush())->everyHour();
 * Scheduler::job(new RefreshAnalyticsJob())->cron('*\/15 * * * *');
 * ```
 *
 * Running due tasks (called by `schedule:run`):
 * ```php
 * foreach (Scheduler::getDue(new \DateTime()) as $task) {
 *     $task->run();
 * }
 * ```
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Scheduler
{
    /** @var ScheduledTask[] All registered tasks. */
    private static array $tasks = [];

    // =========================================================================
    // Task factory
    // =========================================================================

    /**
     * Schedules a framework CLI command.
     *
     * @param string $command The command name as registered with the Console Application,
     *                        e.g. `'migrate'`, `'cleanup:temp'`.
     */
    public static function command(string $command): ScheduledTask
    {
        $task = new ScheduledTask($command, 'command');
        static::$tasks[] = $task;
        return $task;
    }

    /**
     * Schedules an arbitrary PHP callable.
     *
     * The callable receives no arguments and its return value is ignored.
     */
    public static function call(callable $callable): ScheduledTask
    {
        $task = new ScheduledTask($callable, 'callable');
        static::$tasks[] = $task;
        return $task;
    }

    /**
     * Schedules a job object.  The object must be callable or have a `handle()`
     * method.
     *
     * @param object $job
     */
    public static function job(object $job): ScheduledTask
    {
        $task = new ScheduledTask($job, 'job');
        static::$tasks[] = $task;
        return $task;
    }

    // =========================================================================
    // Querying
    // =========================================================================

    /**
     * Returns all registered tasks.
     *
     * @return ScheduledTask[]
     */
    public static function all(): array
    {
        return static::$tasks;
    }

    /**
     * Returns the tasks that are due at the given moment.
     *
     * @param \DateTimeInterface $when
     * @return ScheduledTask[]
     */
    public static function getDue(\DateTimeInterface $when): array
    {
        return array_values(
            array_filter(static::$tasks, fn(ScheduledTask $t) => $t->isDue($when))
        );
    }

    // =========================================================================
    // State management (tests)
    // =========================================================================

    /**
     * Removes all registered tasks.
     *
     * Intended for test isolation only.
     */
    public static function reset(): void
    {
        static::$tasks = [];
    }
}
