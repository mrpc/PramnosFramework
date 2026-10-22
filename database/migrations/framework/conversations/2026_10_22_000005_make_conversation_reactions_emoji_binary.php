<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Gives `conversation_reactions.emoji` the binary collation on MySQL and MariaDB.
 *
 * Under `utf8mb4_unicode_ci` every character outside the Basic Multilingual Plane compares
 * equal — 😍 = 💝 — so the unique key on (message, participant, emoji) took a person's second,
 * different emoji for their first and the insert dropped it. Binary tells every emoji apart.
 *
 * No row is lost: values distinct under the old collation stay distinct under a stricter one.
 * PostgreSQL's collations compare emoji by code point already, so it needs nothing.
 */
class MakeConversationReactionsEmojiBinary extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 14;
    public $description = 'Gives conversation_reactions.emoji a binary collation, so different emoji are different reactions';

    /** Switch the column to `utf8mb4_bin`, on MySQL and MariaDB. */
    public function up(): void
    {
        $this->collate('utf8mb4_bin');
    }

    /** Back to the table's default collation. */
    public function down(): void
    {
        $this->collate('utf8mb4_unicode_ci');
    }

    /** The emoji column under the given collation, where there is one to change. */
    private function collate(string $collation): void
    {
        $db     = $this->application->database;
        $schema = $db->schema();
        if ($db->getDriverName() === 'pgsql' || !$schema->hasColumn('conversation_reactions', 'emoji')) {
            return;
        }

        $schema->alterTable('conversation_reactions', function ($table) use ($collation) {
            $table->modifyColumn('emoji', 'string', ['length' => 64])->collation($collation)
                ->comment('The emoji, or its :shortcode: — binary, so each emoji is its own value');
        });
    }
}
