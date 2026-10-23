<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Lets a reply in a thread be sent to the conversation too: `conversation_messages.broadcast`. Such a
 * reply stays in its thread and is also listed with the conversation's own messages, counted as
 * unread like them.
 */
class AddBroadcastToConversationMessages extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 15;
    public $description = 'Adds conversation_messages.broadcast, for a reply also sent to the conversation';

    /** Add the column, where the table has none yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_messages') || $schema->hasColumn('conversation_messages', 'broadcast')) {
            return;
        }

        $schema->table('conversation_messages', function ($table) {
            $table->tinyInteger('broadcast')->default(0)->comment('A reply in a thread also sent to the conversation');
        });
    }

    /** Remove it again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_messages') || !$schema->hasColumn('conversation_messages', 'broadcast')) {
            return;
        }

        $schema->table('conversation_messages', function ($table) {
            $table->dropColumn('broadcast');
        });
    }
}
