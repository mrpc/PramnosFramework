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
            $table->string('grain', 8)->comment('day, week, month or quarter — every one with period empty; once for a one-off');
            $table->string('period', 10)->default('')->comment('The period it is for (YYYY-MM-DD); empty: every period of its grain');
            $table->bigInteger('tenant')->default(0);
            $table->text('filters')->nullable()->comment('The metric\'s filters, as JSON');
            $table->double('target')->comment('The target; 0 where it is relative (growth)');
            $table->double('growth')->nullable()->comment('Relative: the reference period\'s total times 1 + growth; null: the target as set');
            $table->string('reference', 12)->default('lastyear')->comment('What it is measured against: lastyear, weekdays, previous or first');
            $table->string('kind', 12)->nullable()->comment('count or growth on a total; reach, sustain, pace or growth on a level; null: the metric\'s default');
            $table->integer('starts_at')->nullable()->comment('A one-off goal: from (unix); it may be in the past');
            $table->integer('due_at')->nullable()->comment('A one-off goal: by (unix); null for a recurring one');
            $table->integer('owner')->nullable()->comment('users.userid of who looks after it');
            $table->string('note', 255)->nullable();
            $table->string('notify', 16)->nullable()->comment('Who is told when it turns: owner, members, or a conversation (conversation:ID)');
            $table->string('last_state', 12)->nullable()->comment('Its state when last checked: met, on_track, behind, stalled, unknown');
            $table->integer('last_told_at')->nullable();
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
