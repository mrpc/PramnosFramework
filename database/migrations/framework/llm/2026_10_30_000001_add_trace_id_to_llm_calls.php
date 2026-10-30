<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `llm_calls.trace_id` — one id shared by every call of the same turn: the answer, a degraded
 * fallback to another tier, the summary fold, and the memory learned from it. Null for every
 * other call, and for every row before this.
 */
class AddTraceIdToLlmCalls extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description = 'Adds llm_calls.trace_id, one id shared by every call of the same turn';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('llm_calls') || $schema->hasColumn('llm_calls', 'trace_id')) {
            return;
        }

        $schema->table('llm_calls', function ($table) {
            $table->string('trace_id', 32)->nullable()
                ->comment('Groups the answer, a tier fallback, the summary fold and the memory learned from one turn');
            $table->index('trace_id');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('llm_calls') && $schema->hasColumn('llm_calls', 'trace_id')) {
            $schema->table('llm_calls', fn ($table) => $table->dropColumn(['trace_id']));
        }
    }
}
