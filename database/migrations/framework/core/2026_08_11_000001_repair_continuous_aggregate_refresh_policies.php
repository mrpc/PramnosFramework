<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\ContinuousAggregateRegistry;
use Pramnos\Database\Migration;

/**
 * Gives the framework's rolled-up views the refresh they were never given.
 *
 * Four migrations create the same thing twice: a TimescaleDB continuous
 * aggregate where the extension is present, a plain materialized view where it
 * is not. Only the first branch registered a refresh policy. On plain
 * PostgreSQL that leaves a view which exists, answers every query, and returns
 * the data it held on the day the migration ran — for ever. A view that is
 * merely missing fails and gets noticed; one that is silently stale does not.
 *
 * Those migrations are fixed, but they are recorded as applied everywhere they
 * have already run and will never run again — the same gap that made this
 * necessary in the first place. Hence a dated migration.
 *
 * Nothing here creates a view. It only adds the missing policy to views that
 * are already there, so an installation without a given feature is untouched,
 * and one that already has the policy is untouched too.
 *
 * Current-date timestamp (§9) so it auto-runs on installations whose
 * migration_cutoff skips the 2020_01_01 baseline.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class RepairContinuousAggregateRefreshPolicies extends Migration
{
    public string $feature      = 'core';
    public string $scope        = 'framework';
    public int    $priority     = 210;
    public $description  = 'Adds the refresh policy the rolled-up views were created without';

    public function up(): void
    {
        $schema = $this->schema();

        foreach (array_keys(ContinuousAggregateRegistry::all()) as $view) {
            $done = ContinuousAggregateRegistry::apply($schema, $view);

            foreach ($done as $step) {
                \Pramnos\Logs\Logger::log(
                    'Continuous aggregate ' . $view . ': ' . $step,
                    'migrations'
                );
            }
        }
    }

    /**
     * Deliberately does nothing.
     *
     * The policies this adds are what the creating migrations always intended;
     * removing them on a rollback of *this* migration would put the database
     * further from its declared state than before it ran, and would silently
     * freeze the views again.
     */
    public function down(): void
    {
    }
}
