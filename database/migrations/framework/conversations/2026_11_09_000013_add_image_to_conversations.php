<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Gives channels, system channels and groups a picture of their own, as Slack does:
 * `conversations.image` (the file, under the conversations' files directory) and `imageat` (when it
 * was set, which versions its address). After the move to `chat.*`, so an installation that moves
 * its tables gains the columns on them.
 */
class AddImageToConversations extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 95;
    public $description = 'Adds conversations.image and imageat, a channel\'s or a group\'s picture';

    /** Add the columns, where the table has none yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('chat.conversations') || $schema->hasColumn('chat.conversations', 'image')) {
            return;
        }
        $schema->table('chat.conversations', function ($table) {
            $table->string('image', 255)->nullable()->comment('Its picture, under the conversations\' files; null for none');
            $table->integer('imageat')->nullable()->comment('When the picture was set: its address\'s version');
        });
    }

    /** Remove them again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('chat.conversations') || !$schema->hasColumn('chat.conversations', 'image')) {
            return;
        }
        $schema->table('chat.conversations', function ($table) {
            $table->dropColumn('image');
            $table->dropColumn('imageat');
        });
    }
}
