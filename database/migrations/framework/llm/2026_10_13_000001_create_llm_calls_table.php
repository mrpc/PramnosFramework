<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `llm_calls` — every call to a language model, what it cost, and why it failed.
 *
 * The cost is frozen at write time and nullable on purpose: a model nobody has priced costs
 * something, and a nought would make a total confidently too low. `tokens` is the usage's total,
 * kept as a number so a summary need not parse JSON in SQL — which every database does
 * differently.
 *
 * An installation that already has the table — an application that kept its own call log under
 * this name — keeps it; {@see AddTokensToLlmCalls} gives it the column this one adds.
 */
class CreateLlmCallsTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Creates llm_calls, the language-model call log';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('llm_calls')) {
            return;
        }

        $schema->createTable('llm_calls', function ($table) {
            $table->increments('call_id');
            $table->integer('organization_id')->nullable();
            $table->integer('userid')->nullable();
            $table->string('purpose', 32)->default('assistant')
                ->comment('What the call was for: a log where every row says "chat" cannot answer what is costing this');
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->string('endpoint', 255)->nullable();
            $table->text('system_prompt')->nullable();
            $table->text('messages')->nullable();
            $table->integer('max_tokens')->nullable();
            $table->decimal('temperature', 4, 2)->nullable();
            $table->boolean('reasoning')->default(false);
            $table->integer('http_status')->nullable();
            $table->string('finish_reason', 32)->nullable();
            $table->text('reply')->nullable();
            $table->text('usage')->nullable();
            $table->integer('tokens')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->decimal('cost', 12, 8)->nullable();
            $table->string('currency', 8)->nullable();
            $table->timestampTz('created_at');
            $table->index('created_at');
            $table->index('provider');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('llm_calls');
    }
}
