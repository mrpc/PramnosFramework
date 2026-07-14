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

    /**
     * Whether the app's schedule definition file has already been loaded this
     * process (prevents double-registration if called more than once).
     */
    private static bool $loaded = false;

    /**
     * Load the application's scheduled-task definitions.
     *
     * Tasks are declared in code (not persisted in a database). By convention the
     * definitions live in `app/schedule.php`, a plain PHP file that calls the
     * Scheduler API (`Scheduler::command(...)->daily()`, etc.). The schedule:run
     * and schedule:list commands call this before reading tasks, so a system cron
     * running `schedule:run` every minute picks up whatever the file registers.
     *
     * Idempotent within a process. Returns false when no definition file exists.
     *
     * @param string|null $file Absolute path; defaults to ROOT/app/schedule.php.
     */
    public static function loadDefinitions(?string $file = null): bool
    {
        if (static::$loaded) {
            return true;
        }
        $file ??= defined('ROOT')
            ? ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'schedule.php'
            : '';
        if ($file === '' || !is_file($file)) {
            return false;
        }
        static::$loaded = true;
        require $file; // the file registers tasks via the static Scheduler API
        return true;
    }

    // =========================================================================
    // State management (tests)
    // =========================================================================

    /**
     * Removes all registered tasks (and resets the load guard).
     *
     * Intended for test isolation only.
     */
    public static function reset(): void
    {
        static::$tasks  = [];
        static::$loaded = false;
    }
}
