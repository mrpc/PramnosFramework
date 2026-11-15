<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `pramnos.llm_code_exec_runs` — one sandboxed `code_exec` call, from the moment the model asks
 * for it until a worker of the `code_exec` pool finishes it ({@see \Pramnos\Llm\Sandbox\CodeExecTool}).
 *
 * State, not history: a row exists only while its call is answered, the way `llm_tool_runs`
 * ({@see \Pramnos\Llm\Chat\ParallelTools}) exists only while its round is. What was asked, and
 * what came back, is in the call log already once the answer reads the row back and deletes it.
 */
class CreateLlmCodeExecRunsTable extends Migration
{
    public string $feature      = 'llm';
    public string $scope        = 'framework';
    public int    $priority     = 50;
    public array  $dependencies = [];
    public $description = 'Creates pramnos.llm_code_exec_runs, one sandboxed code_exec call until a worker finishes it';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('pramnos.llm_code_exec_runs')) {
            return;
        }

        $schema->createTable('pramnos.llm_code_exec_runs', function ($table) {
            $table->comment('One sandboxed code_exec call, until a worker of that pool finishes it');
            $table->bigIncrements('run_id');
            $table->integer('user_id')->comment('Whose question this answers — the sandbox\'s own files are never shared between people');
            $table->text('code')->comment('The Python asked to run');
            $table->mediumText('input')->nullable()->comment('A CSV given alongside the code, as text');
            $table->string('status', 16)->comment('pending, running, done or error');
            $table->string('owner', 32)->nullable()->comment('Whoever claimed it, so a second claim of an already-running row is told apart from the first');
            $table->mediumText('result')->nullable()->comment('Its stdout, once done');
            $table->text('error')->nullable()->comment('Why it did not finish, once failed');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->index(['status', 'created_at'], 'idx_llm_code_exec_runs_status');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.llm_code_exec_runs');
    }
}
