<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_conversations.ended_at`: the assistant ended this conversation, because the person kept
 * insulting it ({@see \Pramnos\Llm\Chat\Ending}) — state, not history: a conversation that is ended
 * takes no more questions, and the person may start another. Null for one still open.
 */
class AddEndedAtToChatConversations extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Adds ended_at to chat_conversations';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('assist.chat_conversations') || $schema->hasColumn('assist.chat_conversations', 'ended_at')) {
            return;
        }

        $schema->alterTable('assist.chat_conversations', function ($table) {
            $table->timestamp('ended_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('assist.chat_conversations', 'ended_at')) {
            $schema->alterTable('assist.chat_conversations', function ($table) {
                $table->dropColumn('ended_at');
            });
        }
    }
}
