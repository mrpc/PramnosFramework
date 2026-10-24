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

    /**
     * Add whichever of the two columns the table does not have yet.
     *
     * Checked independently rather than one column standing in for both: the
     * original guard returned on `hasColumn(..., 'name')` alone, so a
     * `conversation_members` that already had a same-named `name` column for
     * any other reason — an application's own migration, a hand-made ALTER —
     * made this migration mark itself `Ran` without ever adding `externalid`,
     * silently (FW-279). Neither column implies the other.
     */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_members')) {
            return;
        }

        $hasName       = $schema->hasColumn('conversation_members', 'name');
        $hasExternalId = $schema->hasColumn('conversation_members', 'externalid');

        if ($hasName && $hasExternalId) {
            return;
        }

        $schema->table('conversation_members', function ($table) use ($hasName, $hasExternalId) {
            if (!$hasName) {
                $table->string('name', 150)->nullable()->comment('A former member\'s name; null for an account');
            }
            if (!$hasExternalId) {
                $table->string('externalid', 100)->nullable()->comment('A former member\'s id where it came from, e.g. slack:U123');
            }
        });
    }

    /** Remove whichever of the two columns this migration could have added. */
    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_members')) {
            return;
        }

        $hasName       = $schema->hasColumn('conversation_members', 'name');
        $hasExternalId = $schema->hasColumn('conversation_members', 'externalid');

        if (!$hasName && !$hasExternalId) {
            return;
        }

        $schema->table('conversation_members', function ($table) use ($hasName, $hasExternalId) {
            if ($hasExternalId) {
                $table->dropColumn('externalid');
            }
            if ($hasName) {
                $table->dropColumn('name');
            }
        });
    }
}
