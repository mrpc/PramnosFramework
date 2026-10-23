<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Lets a conversation keep somebody who has no account here — a colleague who left, brought in by
 * an import: a member of `participanttype` `former`, with the name it was known by and, where it
 * came from elsewhere, its id there (`externalid`), so a second import finds it. Such a member never
 * reads: no unread, no notifications.
 */
class AddFormerMembersToConversationMembers extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 16;
    public $description = 'Adds conversation_members.name and externalid, for a member with no account here';

    /** Add the columns, where the table has none yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_members') || $schema->hasColumn('conversation_members', 'name')) {
            return;
        }

        $schema->table('conversation_members', function ($table) {
            $table->string('name', 150)->nullable()->comment('A former member\'s name; null for an account');
            $table->string('externalid', 100)->nullable()->comment('A former member\'s id where it came from, e.g. slack:U123');
        });
    }

    /** Remove them again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_members') || !$schema->hasColumn('conversation_members', 'name')) {
            return;
        }

        $schema->table('conversation_members', function ($table) {
            $table->dropColumn('externalid');
            $table->dropColumn('name');
        });
    }
}
