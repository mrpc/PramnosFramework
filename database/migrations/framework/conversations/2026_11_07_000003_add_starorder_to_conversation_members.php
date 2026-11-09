<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Lets somebody order their starred conversations themselves, as Slack's Custom order:
 * `conversation_members.starorder`, their own and the same on every device. Null where they have
 * not placed it.
 */
class AddStarorderToConversationMembers extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 16;
    public $description = 'Adds conversation_members.starorder, the reader\'s own order of the Starred section';

    /** Add the column, where the table has none yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('chat.conversation_members') || $schema->hasColumn('chat.conversation_members', 'starorder')) {
            return;
        }

        $schema->table('chat.conversation_members', function ($table) {
            $table->integer('starorder')->nullable()->comment('Where they placed it among their starred; null when not placed');
        });
    }

    /** Remove it again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('chat.conversation_members') || !$schema->hasColumn('chat.conversation_members', 'starorder')) {
            return;
        }

        $schema->table('chat.conversation_members', function ($table) {
            $table->dropColumn('starorder');
        });
    }
}
