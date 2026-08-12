<?php

declare(strict_types=1);

namespace Pramnos\Scheduling;

/**
 * The framework's own periodic work, declared once so nobody has to.
 *
 * A framework that adds a background command and then leaves the application to
 * remember it has added an obligation, not a feature. `app/schedule.php` is the
 * right place for an application's own tasks and the wrong place for the
 * framework's: it is written once, at scaffold time, and every later framework
 * upgrade that needs a periodic job would need every project to edit it — which
 * is a migration nobody runs.
 *
 * So the framework registers its own, here, and {@see Scheduler::loadDefinitions()}
 * loads these before the application's file. An application that installs the
 * cron line, or runs the worker, gets the framework's housekeeping without
 * knowing it exists.
 *
 * ## What is registered, and why it is safe to register unconditionally
 *
 * Each of these is a no-op when the feature behind it is unused: draining an
 * empty spool reads nothing, `timescale:drain` on a database with no queue table
 * returns immediately, and the queue cleanup deletes nothing when there is
 * nothing old. That matters, because these run on every installation — the cost
 * of a task nobody needs has to be indistinguishable from not registering it.
 *
 * ## Overriding
 *
 * An application that wants a different cadence registers the same command in
 * its own `app/schedule.php`; both will run, so the way to *replace* one is to
 * disable it here:
 *
 * ```php
 * // app/schedule.php
 * FrameworkSchedule::disable('spool:drain');
 * Scheduler::command('spool:drain')->everyFiveMinutes();
 * ```
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class FrameworkSchedule
{
    /**
     * Commands the application has asked the framework not to schedule.
     *
     * @var array<string, true>
     */
    protected static array $disabled = [];

    /**
     * Whether the framework's tasks have been registered this process.
     *
     * @var bool
     */
    protected static bool $registered = false;

    /**
     * Do not schedule this framework command.
     *
     * Call it from `app/schedule.php` before registering your own version, or
     * to switch the job off entirely.
     *
     * @param  string $command The command name, e.g. `spool:drain`
     * @return void
     */
    public static function disable(string $command): void
    {
        static::$disabled[$command] = true;
    }

    /**
     * Do not schedule any of the framework's periodic work.
     *
     * For an application that wants its schedule to be exactly what its own
     * file says — and for tests, which need a scheduler holding only what they
     * put in it. Naming each command instead would leave both callers broken by
     * the next framework version that adds one.
     *
     * The work still needs doing: an application that disables these is taking
     * on the job of running `spool:drain` and `timescale:drain` itself.
     *
     * @return void
     */
    public static function disableAll(): void
    {
        foreach (array_keys(static::tasks()) as $command) {
            static::$disabled[$command] = true;
        }
    }

    /**
     * Is this framework command going to be scheduled?
     *
     * @param  string $command
     * @return bool
     */
    public static function isEnabled(string $command): bool
    {
        return !isset(static::$disabled[$command]);
    }

    /**
     * The names of the commands the framework schedules.
     *
     * @return list<string>
     */
    public static function commands(): array
    {
        return array_keys(static::tasks());
    }

    /**
     * Forget everything, including the disabled list.
     *
     * @return void
     */
    public static function reset(): void
    {
        static::$disabled   = [];
        static::$registered = false;
    }

    /**
     * Register the framework's periodic work with the scheduler.
     *
     * Idempotent: a second call in the same process does nothing, so a command
     * that loads definitions twice does not double-register.
     *
     * @return int How many tasks were registered
     */
    public static function register(): int
    {
        if (static::$registered) {
            return 0;
        }
        static::$registered = true;

        $registered = 0;

        foreach (static::tasks() as $command => $definition) {
            if (!static::isEnabled($command)) {
                continue;
            }

            $task = Scheduler::command($command)
                ->description($definition['description']);

            // Every framework task takes a lock. They are idempotent, but a
            // drain that overlaps itself does the same work twice and, for the
            // ones that decompress a chunk, does it expensively.
            $task->withoutOverlapping();

            // The cadence is a list of calls, because it is a fluent chain:
            // `daily()` sets the expression and `at()` then adjusts its time.
            foreach ($definition['cadence'] as $step) {
                $method = array_shift($step);
                $task->{$method}(...$step);
            }

            $registered++;
        }

        return $registered;
    }

    /**
     * The framework's periodic work.
     *
     * The cadences are chosen so that the default installation is quiet: the
     * two drains are cheap when there is nothing to do, and the cleanups run
     * once a day at a time nobody is looking.
     *
     * @return array<string, array{cadence: list<array<int, mixed>>, description: string}>
     */
    protected static function tasks(): array
    {
        return [
            // Buffered writes. Frequent, because the whole point is that the
            // rows land soon; cheap, because an empty spool is one stat() call
            // or one Redis key lookup.
            'spool:drain' => [
                'cadence'     => [['everyMinute']],
                'description' => 'Write rows buffered out of the request path',
            ],

            // Writes that were queued because their chunk was compressed.
            // Hourly: these are late by definition, and each run may decompress
            // and recompress a chunk, which is not something to do every minute.
            'timescale:drain' => [
                'cadence'     => [['hourly']],
                'description' => 'Write queued rows into their compressed chunks',
            ],

            // Finished and abandoned queue items.
            'queue:cleanup' => [
                'cadence'     => [['daily'], ['at', '03:10']],
                'description' => 'Remove completed and expired queue items',
            ],
        ];
    }
}
