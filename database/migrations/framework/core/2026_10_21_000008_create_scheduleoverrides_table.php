<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates scheduleoverrides: what an operator changed about an adjustable scheduled task from the
 * Scheduler screen — paused, another frequency from a fixed list, an hours window, a per-run limit.
 *
 * One row per task, keyed by the id the application gave it in `->adjustable()`; no row is the
 * code's own schedule, and deleting the row is how the screen resets it. `work` and `schedule:run`
 * read the table once per pass, so a change applies without a restart. Times are Unix seconds, as
 * `scheduleruns` keeps them.
 */
class CreateScheduleoverridesTable extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 241;
    public $description = 'Creates scheduleoverrides, the operator settings of adjustable scheduled tasks';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('scheduleoverrides')) {
            return;
        }

        $schema->createTable('scheduleoverrides', function ($table) {
            $table->comment('Operator settings of adjustable scheduled tasks, set from the Scheduler screen; no row is the code\'s schedule');
            $table->string('taskid', 100)->primary()->comment('The id the application gave the task in ->adjustable()');
            $table->tinyInteger('enabled')->default(1)->comment('0 while paused: the task is not due');
            $table->string('frequency', 32)->nullable()->comment('every:N, hours:N, daily:HH:MM, weekly:D:HH:MM; NULL is the code\'s own');
            $table->string('hoursfrom', 5)->nullable()->comment('HH:MM the hours window opens, in the application\'s time zone');
            $table->string('hoursto', 5)->nullable()->comment('HH:MM it closes; before hoursfrom, it crosses midnight');
            $table->integer('runlimit')->nullable()->comment('The per-run limit handed to the task; NULL runs it as written');
            $table->unsignedInteger('pausedat')->nullable()->comment('Unix time it was paused, while it is');
            $table->string('updatedby', 100)->nullable()->comment('Who last changed it');
            $table->unsignedInteger('updatedat')->comment('Unix time it was last changed');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('scheduleoverrides');
    }
}
