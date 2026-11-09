<?php

namespace Pramnos\Framework\Migrations\Stats;

use Pramnos\Database\Migration;

/**
 * `stats.alert_rules`: what is watched on a metric, and who is told ({@see \Pramnos\Metrics\Alerts}).
 * State, not history (rule 14): a rule is what is watched now, and `lastfired` only when it last
 * told anybody, so that it does not repeat too often. Each firing goes to the changelog
 * (`alert.fired`), which keeps the history.
 */
class CreateAlertRulesTable extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 65;
    public array  $dependencies = [];
    public $description = 'Creates stats.alert_rules, what is watched on metrics and who is told';

    /** Create it. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        $schema->ensureSchema('stats');
        if ($schema->hasTable('stats.alert_rules')) {
            return;
        }
        $schema->createTable('stats.alert_rules', function ($table) {
            $table->comment('What is watched on a metric, and who is told');
            $table->increments('ruleid');
            $table->string('name', 150)->default('');
            $table->string('metric', 100);
            $table->string('grain', 8)->default('day');
            $table->text('filters')->nullable();
            $table->bigInteger('tenant')->default(0);
            $table->string('kind', 10)->comment('threshold, change or stopped');
            $table->string('op', 6)->default('above')->comment('above or below');
            $table->double('value')->default(0)->comment('The threshold, the change as a fraction, or the seconds without data');
            $table->string('against', 10)->default('previous')->comment('For a change: previous or year');
            $table->string('hours', 5)->default('')->comment('Only between these hours of the day, e.g. 9-23; empty: always');
            $table->text('notify')->nullable()->comment('Who is told: {users: [ids], conversation: id}');
            $table->integer('every')->default(3600)->comment('At least this many seconds between two firings');
            $table->integer('lastfired')->default(0);
            $table->smallInteger('enabled')->default(1);
            $table->integer('createdby')->nullable();
            $table->integer('createdat')->default(0);
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('stats.alert_rules');
    }
}
