<?php

namespace Pramnos\Framework\Migrations\Stats;

use Pramnos\Database\Migration;

/**
 * `pramnos.metrics_health`: the last computation of each metric at each grain — when, how long it
 * took, and what failed ({@see \Pramnos\Metrics\Store::health()}, read by `metrics:status` and
 * the operators' `metrics_health` check).
 *
 * Why a table and not the changelog (rule 14): this is **state**, not history — what is true of
 * the *last* run, overwritten every time `metrics:compute` runs, the same way a queue's own
 * worker-pool row is. It is never read as a sequence of past runs, only as "is this metric healthy
 * right now".
 */
class CreateMetricsHealthTable extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 225;
    public array  $dependencies = ['create_pramnos_schema'];
    public $description = 'Creates pramnos.metrics_health, the last compute run of each metric and grain';

    public function up(): void
    {
        $schema = $this->schema();
        $schema->ensureSchema('pramnos');
        if ($schema->hasTable('pramnos.metrics_health')) {
            return;
        }
        $schema->createTable('pramnos.metrics_health', function ($table) {
            $table->comment('The last compute run of each metric at each grain');
            $table->string('metric', 160);
            $table->string('grain', 20);
            $table->integer('ran_at')->unsigned()->comment('Unix time the run finished');
            $table->integer('duration_ms')->unsigned();
            $table->text('error')->nullable()->comment('The last run\'s own failure; null once a run succeeds');
            $table->integer('failed_at')->nullable()->unsigned();
            $table->primary(['metric', 'grain']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropTableIfExists('pramnos.metrics_health');
    }
}
