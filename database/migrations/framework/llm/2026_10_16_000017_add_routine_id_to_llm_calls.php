<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `llm_calls.routine_id` — the scheduled task a call was made for, so the call log and the
 * analyst's spend can tell a task's runs from conversations. Null for every other call, and for
 * every row before this.
 */
class AddRoutineIdToLlmCalls extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description = 'Adds llm_calls.routine_id, the scheduled task a call was made for';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('llm_calls') || $schema->hasColumn('llm_calls', 'routine_id')) {
            return;
        }

        $schema->table('llm_calls', fn ($table) => $table->integer('routine_id')->nullable()
            ->comment('The scheduled task (ai_routines) a call was made for; null for a conversation'));
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('llm_calls') && $schema->hasColumn('llm_calls', 'routine_id')) {
            $schema->table('llm_calls', fn ($table) => $table->dropColumn(['routine_id']));
        }
    }
}
