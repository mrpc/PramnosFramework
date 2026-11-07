<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Creates `conversation_drafts`: what somebody has begun writing in a conversation, or in one of
 * its threads, kept on the server so it follows them to every device. State: one row for each
 * person, conversation and thread, gone when it is sent or emptied. Declared personal data.
 */
class CreateConversationDraftsTable extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 15;
    public $description = 'Creates conversation_drafts, what somebody has begun writing';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_drafts')) {
            $schema->createTable('conversation_drafts', function ($table) {
                $table->comment('What somebody has begun writing in a conversation');
                $table->bigInteger('userid')->comment('users.userid of who is writing');
                $table->bigInteger('conversationid')->comment('conversations.conversationid');
                // 0, not null, for the conversation itself: a key with a null in it is never a duplicate on PostgreSQL.
                $table->bigInteger('threadrootid')->default(0)->comment('The thread it replies in; 0 for the conversation itself');
                $table->text('body')->comment('What is written so far');
                $table->unsignedInteger('updatedat')->comment('When it was last changed');
                $table->primary(['userid', 'conversationid', 'threadrootid']);
            });
        }
    }

    /** Drop it again. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('conversation_drafts');
    }
}
