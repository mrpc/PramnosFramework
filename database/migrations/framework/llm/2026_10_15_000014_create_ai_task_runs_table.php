<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `ai_task_runs` — every run of a scheduled AI task: what it produced, what it cost, whether it
 * worked and why not. Written for every run, a report that told nobody included, so a screen can
 * show a task's history and a person can carry one into a conversation.
 */
class CreateAiTaskRunsTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description = 'Creates ai_task_runs, the history of scheduled AI task runs';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('ai_task_runs')) {
            return;
        }

        $schema->createTable('ai_task_runs', function ($table) {
            $table->comment('Each run of a scheduled AI task: its output, cost and outcome');
            $table->increments('run_id');
            $table->integer('routine_id');
            $table->integer('organization_id')->nullable();
            $table->string('kind', 16);
            $table->string('status', 16)->comment('ok, failed or skipped');
            $table->text('content')->nullable()->comment('What it produced, Markdown');
            $table->text('extra')->nullable()->comment('JSON: steps, tools, notified');
            $table->decimal('cost', 12, 6)->nullable();
            $table->string('error', 500)->nullable();
            $table->integer('conversation_id')->nullable()->comment('The conversation it went into, or one opened from it');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->index(['routine_id', 'run_id'], 'idx_ai_task_runs_routine');
            $table->index(['organization_id'], 'idx_ai_task_runs_org');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('ai_task_runs');
    }
}
