<?php

namespace Pramnos\Framework\Migrations\Stats;

use Pramnos\Database\Migration;

/**
 * `stats.goals`: a target per metric, grain (a day or a month), period, organisation and filters
 * ({@see \Pramnos\Metrics\Goals}). State, not history (rule 14): a goal is what is aimed at now;
 * a goal missed is an alert, and alerts that fire go to the changelog.
 */
class CreateGoalsTable extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 64;
    public array  $dependencies = [];
    public $description = 'Creates stats.goals, the targets set on metrics';

    /** Create it. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        $schema->ensureSchema('stats');
        if ($schema->hasTable('stats.goals')) {
            return;
        }
        $schema->createTable('stats.goals', function ($table) {
            $table->comment('Targets set on metrics, per grain, period, organisation and filters');
            $table->increments('goalid');
            $table->string('metric', 100);
            $table->string('grain', 8)->comment('day or month');
            $table->string('period', 10)->default('')->comment('The period it is for (YYYY-MM-DD); empty: every period of its grain');
            $table->bigInteger('tenant')->default(0);
            $table->text('filters')->nullable()->comment('The metric\'s filters, as JSON');
            $table->double('target')->comment('The target; 0 where it is relative (growth)');
            $table->double('growth')->nullable()->comment('Relative: the reference period\'s total times 1 + growth; null: the target as set');
            $table->string('reference', 12)->default('lastyear')->comment('The curve it is expected to follow: lastyear or weekdays');
            $table->integer('createdby')->nullable();
            $table->integer('createdat')->default(0);
            $table->index(['metric', 'grain', 'period'], 'idx_goals_metric');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('stats.goals');
    }
}
