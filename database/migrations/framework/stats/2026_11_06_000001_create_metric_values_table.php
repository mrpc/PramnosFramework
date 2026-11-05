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
 * A plain table by default, also on TimescaleDB: a few thousand rows per metric and year, and
 * `metrics:compute --recompute` rewrites closed periods, which a compressed chunk refuses. Its
 * primary key is the natural one and holds `period_start`, so an installation that wants a
 * hypertable declares it in app.php and runs `timescale:ensure` — see the Metrics guide.
 */
class CreateMetricValuesTable extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 62;
    public array  $dependencies = [];
    public $description = 'Creates stats.metric_values, the values of the application\'s metrics per period';

    /** Create the schema and the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        $schema->ensureSchema('stats');
        if ($schema->hasTable('stats.metric_values')) {
            return;
        }

        $schema->createTable('stats.metric_values', function ($table) {
            $table->comment('The values of the application\'s metrics, per grain, period and breakdown');
            $table->string('metric', 100)->comment('The metric\'s name, as its catalogue declares it');
            $table->string('grain', 8)->comment('day, week, month, quarter or year');
            $table->date('period_start')->comment('The period\'s first day, in the metrics\' time zone');
            $table->string('dimset', 200)->default('')->comment('The dimensions of this breakdown, sorted and comma-joined; empty for the total');
            $table->text('dims')->comment('The breakdown\'s values as a JSON object; {} for the total');
            $table->char('dims_hash', 32)->comment('md5 of dims, for the unique key');
            $table->decimal('value', 20, 4)->default(0);
            $table->string('source', 10)->default('computed')->comment('computed, snapshot (a level) or imported');
            $table->smallInteger('final')->default(0)->comment('1 once the period is closed: never computed again unless asked');
            $table->integer('computed_at')->default(0);
            $table->primary(['metric', 'grain', 'period_start', 'dims_hash']);
            $table->index(['metric', 'grain', 'final'], 'idx_metric_values_final');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('stats.metric_values');
    }
}
