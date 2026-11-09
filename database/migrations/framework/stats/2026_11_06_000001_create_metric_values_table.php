<?php

namespace Pramnos\Framework\Migrations\Stats;

use Pramnos\Database\Migration;

/**
 * The values of the application's metrics ({@see \Pramnos\Metrics\Metrics}): one row per metric,
 * grain, period and breakdown.
 *
 * In a schema of its own, `stats`, because these are the business's figures, not the engine's
 * state (`pramnos.*`) nor sign-in (`authserver.*`) — read by reports, dashboards and the assistant,
 * and kept as long as the business keeps its reports.
 *
 * Not a log in the sense of rule 14: a row is the value a period has — while the period is open it
 * is replaced at each computation, once closed (`final`) it is the number the period's reports
 * show. None of the four logs holds a value per period and breakdown, with a unique key to read it
 * back by.
 *
 * A table per grain — `stats.metric_values_day` … `_year` — and a view over them all,
 * `stats.metric_values`, made by {@see \Pramnos\Metrics\Store::ensure()}: hypertables with
 * compression where TimescaleDB is installed. Changed in place from a single table (rule 14's
 * rule for a migration that has run): an installation that ran the single-table version calls
 * `Store::ensure()` once, which converts it and keeps every value.
 */
class CreateMetricValuesTable extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 62;
    public array  $dependencies = [];
    public $description = 'Creates the stats.metric_values_<grain> tables and their view, the values of the application\'s metrics';

    /** The grains' tables and their view. */
    public function up(): void
    {
        \Pramnos\Metrics\Store::ensure($this->application->database);
    }

    /** Drop them. */
    public function down(): void
    {
        \Pramnos\Metrics\Store::drop($this->application->database);
    }
}
