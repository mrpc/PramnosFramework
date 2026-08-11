<?php

declare(strict_types=1);

namespace Pramnos\Database;

/**
 * The framework's declaration of which rolled-up views must keep refreshing.
 *
 * Four migrations create the same thing twice: a TimescaleDB continuous
 * aggregate where the extension is present, and a plain materialized view where
 * it is not. Only the first branch registered a refresh policy. A materialized
 * view that nothing refreshes is frozen at the moment it was created — it
 * exists, it answers, and every answer is the same one it gave on the day the
 * migration ran. That is worse than a missing view, which at least fails.
 *
 * The refresh mechanism was never the problem: `addContinuousAggregatePolicy()`
 * already branches by backend — a native job on TimescaleDB, a row in
 * `pramnos.framework_policies` executed by the policy engine everywhere else.
 * It simply was not called on the second path.
 *
 * So the parameters live here, once, and two things read them: the migrations
 * that create these views, and `timescale:ensure`, which repairs an
 * installation whose migrations already ran without a policy.
 *
 * Applications register their own the same way:
 *
 * ```php
 * ContinuousAggregateRegistry::register('reports.daily_totals', [
 *     'start_offset'      => '3 days',
 *     'end_offset'        => '1 day',
 *     'schedule_interval' => '1 day',
 * ]);
 * ```
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ContinuousAggregateRegistry
{
    /**
     * Every declared aggregate, keyed by its logical view name.
     *
     * @var array<string, array<string, string>>
     */
    protected static array $views = [];

    /** @var bool Whether the framework's own declarations have been loaded */
    protected static bool $defaultsLoaded = false;

    /**
     * Declare a rolled-up view, or replace an existing declaration.
     *
     * @param string                $view View name, e.g. `authserver.daily_2fa_stats`
     * @param array<string, string> $spec {
     *     @type string $start_offset      How far back a refresh reaches
     *     @type string $end_offset        How close to now it stops
     *     @type string $schedule_interval How often it runs
     * }
     */
    public static function register(string $view, array $spec): void
    {
        static::ensureDefaults();

        static::$views[$view] = $spec + [
            'start_offset'      => '1 month',
            'end_offset'        => '1 hour',
            'schedule_interval' => '1 hour',
        ];
    }

    /**
     * Every declared aggregate.
     *
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        static::ensureDefaults();

        return static::$views;
    }

    /**
     * One declaration, or null when the view is not declared.
     *
     * @return array<string, string>|null
     */
    public static function spec(string $view): ?array
    {
        static::ensureDefaults();

        return static::$views[$view] ?? null;
    }

    /**
     * Forget every declaration, including the framework's own.
     */
    public static function reset(): void
    {
        static::$views         = [];
        static::$defaultsLoaded = false;
    }

    /**
     * Make sure this view is being refreshed, whatever the backend.
     *
     * Guarded, so it is safe from a migration that has just created the view and
     * from a repair run against a database that already has the policy. Does
     * nothing when the view itself is absent: the aggregate belongs to a feature
     * this installation may not have enabled.
     *
     * @param  SchemaBuilder $schema
     * @param  string        $view Logical view name, as declared
     * @return array<int, string> What was done; empty means nothing needed doing
     */
    public static function apply(SchemaBuilder $schema, string $view): array
    {
        $spec = static::spec($view);
        if ($spec === null) {
            return [];
        }

        if (!static::viewExists($schema, $view)) {
            return [];
        }

        if ($schema->hasContinuousAggregatePolicy($view)) {
            return [];
        }

        // Nowhere to record it. On a backend without TimescaleDB the refresh is
        // a row in `pramnos.framework_policies`, and an installation whose core
        // migrations have not created that table yet cannot be given one — the
        // insert would fail and take the surrounding migration with it.
        if (!static::canRecordPolicy($schema)) {
            return [];
        }

        $schema->addContinuousAggregatePolicy(
            $view,
            $spec['start_offset'],
            $spec['end_offset'],
            $spec['schedule_interval']
        );

        return ['refresh policy added (every ' . $spec['schedule_interval'] . ')'];
    }

    /**
     * Is there somewhere to record a software policy?
     *
     * Always true on TimescaleDB, where the policy is a background job rather
     * than a row.
     */
    protected static function canRecordPolicy(SchemaBuilder $schema): bool
    {
        try {
            if ($schema->getCapabilities()->hasTimescaleDB()) {
                return true;
            }

            return $schema->hasTable('pramnos.framework_policies');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Is the view there at all?
     *
     * A continuous aggregate is not a table, and a materialized view is not
     * either, so `hasTable()` is the wrong question on both backends — it is
     * asked through the catalogue the driver actually records them in. An error
     * while looking is treated as "not there", because acting on a view that may
     * not exist is the thing worth avoiding.
     */
    protected static function viewExists(SchemaBuilder $schema, string $view): bool
    {
        try {
            return $schema->hasView($view);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Load the framework's own declarations once.
     */
    protected static function ensureDefaults(): void
    {
        if (static::$defaultsLoaded) {
            return;
        }
        static::$defaultsLoaded = true;

        foreach (static::frameworkViews() as $view => $spec) {
            static::$views[$view] = $spec;
        }
    }

    /**
     * The framework's rolled-up views and their refresh parameters.
     *
     * These are the values the four migrations used inside their TimescaleDB
     * branch — unchanged, now reachable from the other one too.
     *
     * @return array<string, array<string, string>>
     */
    protected static function frameworkViews(): array
    {
        return [
            'authserver.daily_activity_summary' => [
                'start_offset'      => '1 month',
                'end_offset'        => '1 hour',
                'schedule_interval' => '1 hour',
            ],
            'authserver.daily_2fa_stats' => [
                'start_offset'      => '1 month',
                'end_offset'        => '1 hour',
                'schedule_interval' => '1 hour',
            ],
            'applications.tokenactions_hourly' => [
                'start_offset'      => '3 hours',
                'end_offset'        => '1 hour',
                'schedule_interval' => '1 hour',
            ],
            'applications.application_stats_daily' => [
                'start_offset'      => '3 days',
                'end_offset'        => '1 day',
                'schedule_interval' => '1 day',
            ],
            'applications.application_stats_hourly' => [
                'start_offset'      => '3 hours',
                'end_offset'        => '1 hour',
                'schedule_interval' => '1 hour',
            ],
        ];
    }
}
