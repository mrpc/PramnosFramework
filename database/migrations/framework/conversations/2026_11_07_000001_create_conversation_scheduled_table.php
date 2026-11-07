<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Creates `conversation_scheduled`: a message somebody wrote to be sent later — state, not
 * history: the row goes when the message is sent, and the message is its record. One that could
 * not be sent stays, with why, for its writer to see. Declared personal data.
 */
class CreateConversationScheduledTable extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 14;
    public $description = 'Creates conversation_scheduled, the messages waiting to be sent later';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_scheduled')) {
            $schema->createTable('conversation_scheduled', function ($table) {
                $table->comment('A message written to be sent later');
                $table->bigIncrements('scheduledid')->comment('Auto-increment identifier');
                $table->bigInteger('conversationid')->comment('conversations.conversationid');
                $table->bigInteger('authorid')->comment('users.userid of its writer, who sends it');
                $table->text('body')->comment('What it says');
                $table->bigInteger('threadrootid')->nullable()->comment('The thread it replies in, if any');
                $table->smallInteger('broadcast')->default(0)->comment('1: a reply also sent to the conversation');
                $table->text('files')->nullable()->comment('JSON list of conversation_files.fileid sent with it');
                $table->unsignedInteger('sendat')->comment('When it is sent, Unix seconds');
                $table->unsignedInteger('createdat')->comment('When it was written');
                $table->text('error')->nullable()->comment('Why it could not be sent; null while waiting');
                $table->index(['sendat'], 'idx_conversation_scheduled_due');
                $table->index(['authorid'], 'idx_conversation_scheduled_author');
            });
        }
    }

    /** Drop it again. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('conversation_scheduled');
    }
}
