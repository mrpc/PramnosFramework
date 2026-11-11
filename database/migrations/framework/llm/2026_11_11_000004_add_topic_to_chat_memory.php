<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_memory.topic`: what a memory is about — one of `Memory::TOPICS` (identity, values, goals,
 * relationships, mood, interests, work, talk, other) — so a channel reads from the others only
 * the topics the application lets it, and a feeling stays with the channel that heard it.
 *
 * State, not history: what the memory is about now. A memory kept before it has none, read as
 * `other`, which no other channel reads.
 */
class AddTopicToChatMemory extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Adds topic to chat_memory';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('assist.chat_memory') || $schema->hasColumn('assist.chat_memory', 'topic')) {
            return;
        }

        $schema->alterTable('assist.chat_memory', function ($table) {
            $table->string('topic', 16)->nullable();
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('assist.chat_memory', 'topic')) {
            $schema->alterTable('assist.chat_memory', function ($table) {
                $table->dropColumn('topic');
            });
        }
    }
}
