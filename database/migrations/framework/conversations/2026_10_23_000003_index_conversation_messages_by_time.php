<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Indexes a conversation's messages by where they stand — when written, then id — the order every
 * list, cursor and unread count follows, so history brought in later sits by when it was written.
 */
class IndexConversationMessagesByTime extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 17;
    public $description = 'Indexes conversation_messages by conversation, time and id';

    /** Add the index, where the table has none yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_messages') || $schema->hasIndex('conversation_messages', 'idx_conversation_messages_time')) {
            return;
        }

        $schema->table('conversation_messages', function ($table) {
            $table->index(['conversationid', 'created_at', 'messageid'], 'idx_conversation_messages_time');
        });
    }

    /** Remove it again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_messages') || !$schema->hasIndex('conversation_messages', 'idx_conversation_messages_time')) {
            return;
        }

        $schema->table('conversation_messages', function ($table) {
            $table->dropIndex('idx_conversation_messages_time');
        });
    }
}
