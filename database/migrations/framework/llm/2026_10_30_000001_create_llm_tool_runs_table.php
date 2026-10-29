<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `pramnos.llm_tool_runs` — the tool calls of one round being run at once ({@see \Pramnos\Llm\Chat\ParallelTools}).
 *
 * A row per call, for as long as the round lasts: whoever claims it first — the answer itself,
 * or a queue worker of the `llm_tool` pool — runs it and writes its result here, and the answer
 * reads the results back in the order the model asked for them and deletes the round's rows.
 *
 * State, not history: a row exists only while its round is answered. None of the logs can hold
 * it, because the claim is an update other processes race on and the result is read back within
 * the second; what was called, and with what, is in the call log already.
 */
class CreateLlmToolRunsTable extends Migration
{
    public string $feature      = 'llm';
    public string $scope        = 'framework';
    public int    $priority     = 50;
    public array  $dependencies = [];
    public $description = 'Creates pramnos.llm_tool_runs, the tool calls of a round run at once';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('pramnos.llm_tool_runs')) {
            return;
        }

        $schema->createTable('pramnos.llm_tool_runs', function ($table) {
            $table->comment('Tool calls of one round being run at once, until the round reads them back');
            $table->bigIncrements('run_id');
            $table->string('batch', 32)->comment('The round: every call the model asked for at once');
            $table->integer('position')->comment('Where the model asked for it, from 0');
            $table->string('tool', 64)->comment('The tool\'s name');
            $table->text('arguments')->comment('The call\'s arguments, as JSON');
            $table->string('status', 16)->comment('pending, running or done');
            $table->string('owner', 32)->nullable()->comment('Whoever claimed it: the answer, or a worker');
            $table->mediumText('result')->nullable()->comment('What the tool returned, once done');
            $table->timestamp('created_at')->nullable();
            $table->index(['batch'], 'idx_llm_tool_runs_batch');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.llm_tool_runs');
    }
}
