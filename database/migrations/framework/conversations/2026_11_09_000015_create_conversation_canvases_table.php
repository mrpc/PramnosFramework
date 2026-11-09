<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Creates `chat.conversation_canvases`: a conversation's documents, as Slack's canvases — a title,
 * a body of rich text, its version, and who is editing it now (`lockedby`, `lockedat`, the
 * editor's heartbeat).
 *
 * State, not history (rule 14): a row is the canvas as it is now. Its earlier versions — each
 * save's title, body, who and when — are `canvas.saved` events in the changelog
 * (`changelog_events`), kept two years, which is what restoring one reads. None of the four logs
 * can hold *what is true now* (the current body, who holds the edit lock), and that is all this
 * table keeps.
 */
class CreateConversationCanvasesTable extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 95;
    public $description = 'Creates chat.conversation_canvases, a conversation\'s documents';

    /** Create it. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('chat.conversation_canvases')) {
            return;
        }
        $schema->ensureSchema('chat');
        $schema->createTable('chat.conversation_canvases', function ($table) {
            $table->comment('A conversation\'s documents; their versions are canvas.saved events in the changelog');
            $table->increments('canvasid');
            $table->bigInteger('conversationid')->comment('conversations.conversationid');
            $table->string('title', 150);
            $table->text('body')->nullable()->comment('Rich text, as the editor writes it, cleaned');
            $table->integer('version')->default(0)->comment('How many times it was saved');
            $table->bigInteger('createdby')->nullable();
            $table->integer('createdat')->default(0);
            $table->bigInteger('updatedby')->nullable();
            $table->integer('updatedat')->default(0);
            $table->bigInteger('lockedby')->nullable()->comment('Who is editing it now');
            $table->integer('lockedat')->nullable()->comment('Their last heartbeat');
            $table->index(['conversationid'], 'idx_conversation_canvases_conversation');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('chat.conversation_canvases');
    }
}
