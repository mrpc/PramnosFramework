<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_memory.source`: how a memory came to be — `model` (the `remember` tool, in the open, during
 * a conversation) or `learned` (picked up after an answer, where the application turned learning
 * on) — so the person sees which ones were learned without being asked, and can forget them.
 *
 * A memory the organisation shares rather than one person's has `userid` 0.
 */
class AddSourceToChatMemory extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Adds source to chat_memory';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('chat_memory') || $schema->hasColumn('chat_memory', 'source')) {
            return;
        }

        $schema->alterTable('chat_memory', function ($table) {
            $table->string('source', 16)->nullable();
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('chat_memory', 'source')) {
            $schema->alterTable('chat_memory', function ($table) {
                $table->dropColumn('source');
            });
        }
    }
}
