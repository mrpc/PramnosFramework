<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Lets somebody star a conversation: `conversation_members.starred`, what the sidebar's Starred
 * section shows, first. A mark of the member's own, as muting is.
 */
class AddStarredToConversationMembers extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 12;
    public $description = 'Adds conversation_members.starred, for the sidebar\'s Starred section';

    /** Add the column, where the table has none yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_members') || $schema->hasColumn('conversation_members', 'starred')) {
            return;
        }

        $schema->table('conversation_members', function ($table) {
            $table->tinyInteger('starred')->default(0)->comment('Whether they starred it');
        });
    }

    /** Remove it again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_members') || !$schema->hasColumn('conversation_members', 'starred')) {
            return;
        }

        $schema->table('conversation_members', function ($table) {
            $table->dropColumn('starred');
        });
    }
}
