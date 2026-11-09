<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Creates `conversation_emoji`: the team's own emoji, a picture under a name, and other names for
 * the same picture ({@see \Pramnos\Conversations\CustomEmoji}). State: what exists now; an emoji
 * removed goes, and who added, renamed or removed one is in the audit log (rule 14).
 */
class CreateConversationEmojiTable extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 15;
    public $description = 'Creates conversation_emoji, the team\'s own emoji';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_emoji')) {
            $schema->createTable('conversation_emoji', function ($table) {
                $table->comment('The team\'s own emoji, written :name:');
                $table->string('name', 32)->primary()->comment('What it is written as, between colons');
                $table->string('path', 255)->default('')->comment('The picture, under the conversations\' files directory; empty for an alias');
                $table->string('mime', 32)->default('')->comment('The picture\'s type; empty for an alias');
                $table->string('aliasof', 32)->nullable()->comment('The emoji this name is another name for; null for a picture of its own');
                $table->bigInteger('createdby')->comment('users.userid of who added it');
                $table->unsignedInteger('createdat')->comment('When it was added (unix)');
                $table->index(['aliasof'], 'idx_conversation_emoji_aliasof');
            });
        }
    }

    /** Drop it again. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('conversation_emoji');
    }
}
