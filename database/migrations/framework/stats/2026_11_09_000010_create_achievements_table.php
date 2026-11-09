<?php

namespace Pramnos\Framework\Migrations\Stats;

use Pramnos\Database\Migration;

/**
 * Creates `stats.achievements`: what a business reached, kept as a trophy case — a level passing
 * a milestone, a flow's best week, a goal met ({@see \Pramnos\Metrics\Achievements}).
 *
 * Why a table and not one of the four logs (rule 14): an achievement is kept for good, where the
 * change log keeps two years; each is one row per organisation, kind, metric, dimension values and
 * value, a unique key no log has, so a scan that runs every night never writes one twice; and it
 * is listed as a case of records, newest first, not read back as one record's history. It is
 * state in that sense: what has been reached, which does not stop being true.
 */
class CreateAchievementsTable extends Migration
{
    public string $feature     = 'metrics';
    public string $scope       = 'framework';
    public int    $priority    = 60;
    public $description = 'Creates stats.achievements, the milestones, best weeks and goals reached';

    /** Create it. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        $schema->ensureSchema('stats');
        if ($schema->hasTable('stats.achievements')) {
            return;
        }
        $schema->createTable('stats.achievements', function ($table) {
            $table->comment('What was reached: milestones, best weeks, goals met — each once');
            $table->increments('achievementid');
            $table->bigInteger('tenant')->default(0);
            $table->string('kind', 12)->comment('milestone, best or goal');
            $table->string('metric', 100);
            $table->string('dims', 255)->default('')->comment('The dimension values it is for, as JSON; empty for the total');
            $table->double('value')->comment('The rung passed, the best week\'s total, or the goal\'s target');
            $table->integer('achievedat')->comment('When it happened (unix), not when it was noticed');
            $table->integer('goalid')->nullable()->comment('The goal it met; kept when the goal is deleted');
            $table->integer('createdat')->default(0);
            $table->unique(['tenant', 'kind', 'metric', 'dims', 'value'], 'uq_achievements_once');
            $table->index(['tenant', 'achievedat'], 'idx_achievements_tenant');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('stats.achievements');
    }
}
