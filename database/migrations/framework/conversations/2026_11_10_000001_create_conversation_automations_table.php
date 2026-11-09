<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Creates `chat.conversation_automations`: what a conversation does by itself — a message sent
 * again on a schedule, a welcome to whoever comes in, a question put to the analyst on a schedule.
 *
 * State, not history (rule 14): a row is an automation as it is set now — its text, its schedule,
 * when it runs next, whether it is paused. Who added, changed, paused or deleted one, and when, are
 * `conversation.automation_*` events in the changelog (`changelog_events`); what it sent is the
 * conversation's own messages. None of the four logs can hold what is to run next and when, which
 * is all this table keeps.
 */
class CreateConversationAutomationsTable extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 95;
    public $description = 'Creates chat.conversation_automations, what a conversation does by itself';

    /** Create it. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('chat.conversation_automations')) {
            return;
        }
        $schema->ensureSchema('chat');
        $schema->createTable('chat.conversation_automations', function ($table) {
            $table->comment('What a conversation does by itself; changes to them are conversation.automation_* events in the changelog');
            $table->increments('automationid');
            $table->bigInteger('conversationid')->comment('conversations.conversationid');
            $table->string('kind', 20)->comment('repeat, welcome or ask');
            $table->text('body')->comment('The message, the welcome or the question');
            $table->text('options')->nullable()->comment('JSON: the schedule (every, days, day, time) or the welcome\'s reach (to)');
            $table->string('sender', 20)->default('automation')->comment('automation, or creator: who the message is from');
            $table->bigInteger('createdby')->comment('Who set it up; a question is asked as them');
            $table->integer('createdat')->default(0);
            $table->integer('nextat')->nullable()->comment('When it runs next; null for a welcome');
            $table->smallInteger('paused')->default(0);
            $table->string('error', 255)->nullable()->comment('Why its last run could not send');
            $table->index(['conversationid'], 'idx_conversation_automations_conversation');
            $table->index(['nextat'], 'idx_conversation_automations_next');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('chat.conversation_automations');
    }
}
