<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `pramnos.llm_speech_runs` — one queued `listen`/`speak` call to a self-hosted speech provider,
 * from the moment it is dispatched until a worker of the `speech` pool finishes it
 * ({@see \Pramnos\Llm\Speech\SpeechQueue}). Mirrors `llm_code_exec_runs` (FW-508): state, not
 * history — a row exists only while its call is answered.
 */
class CreateLlmSpeechRunsTable extends Migration
{
    public string $feature      = 'llm';
    public string $scope        = 'framework';
    public int    $priority     = 50;
    public array  $dependencies = [];
    public $description = 'Creates pramnos.llm_speech_runs, one queued self-hosted speech call until a worker finishes it';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('pramnos.llm_speech_runs')) {
            return;
        }

        $schema->createTable('pramnos.llm_speech_runs', function ($table) {
            $table->comment('One queued listen/speak call to a self-hosted provider, until a worker of that pool finishes it');
            $table->bigIncrements('run_id');
            $table->string('kind', 16)->comment('listen or speak');
            $table->integer('user_id')->comment('Whose question this answers');
            $table->mediumText('payload')->comment('The recording (base64) for listen, the text for speak');
            $table->mediumText('meta')->nullable()->comment('JSON: type/seconds/provider for listen, provider/voice for speak');
            $table->string('status', 16)->comment('pending, running, done or error');
            $table->string('owner', 32)->nullable()->comment('Whoever claimed it, so a second claim of an already-running row is told apart from the first');
            $table->mediumText('result')->nullable()->comment('The call\'s own JSON-encoded answer, once done');
            $table->text('error')->nullable()->comment('Why it did not finish, once failed');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->index(['status', 'created_at'], 'idx_llm_speech_runs_status');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.llm_speech_runs');
    }
}
