<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates scheduleruns: every run of a scheduled task, and a "run now" an operator asked for.
 *
 * Before it, the only account of what the schedule did was a `ran: … in Nms` line in
 * `schedule.log`. An administrator could not see what runs and when, which task had not run
 * in its minute, or what a failed one printed — and an application moving its crontab onto the
 * scheduler wanted exactly that before trusting it.
 *
 * One row per run, written by `ScheduledTask::run()` whoever calls it — `work`, `schedule:run`,
 * a "run now". `requested` is a run asked for from the screen and not yet taken by a worker;
 * `running` is one in progress. Times are Unix seconds, as `javascripterrors` keeps them: the
 * screen does arithmetic on them, and no driver has an opinion about an integer's time zone.
 */
class CreateSchedulerunsTable extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 240;
    public $description = 'Creates scheduleruns, the history of scheduled task runs';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('scheduleruns')) {
            return;
        }

        $schema->createTable('scheduleruns', function ($table) {
            $table->comment('Every run of a scheduled task, written by ScheduledTask::run(), and runs requested from the screen');
            $table->bigIncrements('id');
            $table->string('task', 64)->comment('The task\'s key, ScheduledTask::key(): stable across deploys while its definition is');
            $table->string('label', 255)->comment('Its description, or its handler and expression');
            $table->unsignedInteger('started_at')->comment('Unix time the run started, or was requested');
            $table->integer('duration_ms')->nullable()->comment('How long it took; NULL while running or requested');
            $table->string('result', 16)->comment('ok | failed | skipped (overlap, or another server) | running | requested | interrupted');
            $table->integer('exit_code')->nullable()->comment('A command\'s exit status');
            $table->text('output')->nullable()->comment('The last lines it printed, or the error');
            $table->string('host', 100)->default('')->comment('The machine that ran it');
            $table->string('requested_by', 100)->nullable()->comment('Who asked for it from the screen');
            $table->index(['task', 'started_at'], 'idx_scheduleruns_task');
            $table->index(['result'], 'idx_scheduleruns_result');
            $table->index(['started_at'], 'idx_scheduleruns_started');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('scheduleruns');
    }
}
