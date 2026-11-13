<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * `chat.thread_reads` — one row per person and thread: where they have read up to in it, and
 * when. State (rule 14), not history: a thread's conversation-level read point
 * (`conversation_members.lastreadid`/`readat`) moves with its top-level messages, never with a
 * reply, so a reply in an open thread kept showing as unread activity, or as read the moment an
 * unrelated top-level message arrived — whichever the thread had never actually been opened for
 * (FW-498).
 */
class CreateThreadReadsTable extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 18;
    public $description = 'Creates chat.thread_reads, a person\'s own read point in a thread';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        $schema->ensureSchema('chat');

        if ($schema->hasTable('chat.thread_reads')) {
            return;
        }

        $schema->createTable('chat.thread_reads', function ($table) {
            $table->comment('A person\'s own read point in one thread — a conversation\'s lastreadid moves with its top-level messages, never with a reply');
            $table->bigInteger('conversationid')->comment('chat.conversations; kept alongside rootid so a thread\'s reads can be read in one query with its conversation\'s');
            $table->bigInteger('rootid')->comment('The thread\'s root message');
            $table->bigInteger('userid')->comment('users.userid of who read it');
            $table->bigInteger('lastreadid')->comment('The newest reply read; never moves back');
            $table->unsignedInteger('readat')->comment('When this row last changed, whatever lastreadid did — the same idea as conversation_members.readat');
            $table->primary(['rootid', 'userid']);
            $table->index(['conversationid', 'userid'], 'idx_thread_reads_conversation_user');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('chat.thread_reads');
    }
}
