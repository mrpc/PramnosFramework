<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * `conversation_members.readat` — when the person last read the conversation, set every time
 * {@see \Pramnos\Conversations\Conversations::markRead()} runs, even when `lastreadid` itself
 * does not move.
 *
 * `lastreadid` alone cannot answer "did they see this" for something that is not a message: a
 * reaction arriving while they are already caught up on every message leaves `lastreadid`
 * exactly where it was, so nothing told the Activity badge they had, in fact, just looked
 * (FW-497). `readat` is that read point, independent of which message it happens to be.
 */
class AddReadatToConversationMembers extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 17;
    public $description = 'Adds conversation_members.readat, the read point markRead() always moves';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('chat.conversation_members') || $schema->hasColumn('chat.conversation_members', 'readat')) {
            return;
        }

        $schema->table('chat.conversation_members', function ($table) {
            $table->unsignedInteger('readat')->nullable()->comment('When this person last read the conversation; set on every markRead(), whatever lastreadid does');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('chat.conversation_members') || !$schema->hasColumn('chat.conversation_members', 'readat')) {
            return;
        }

        $schema->table('chat.conversation_members', function ($table) {
            $table->dropColumn('readat');
        });
    }
}
