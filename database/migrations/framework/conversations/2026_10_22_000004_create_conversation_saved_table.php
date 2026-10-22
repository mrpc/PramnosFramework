<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Creates `conversation_saved`: a message somebody kept for later — the sidebar's *Saved*. A mark
 * of their own, nobody else's; declared personal data.
 */
class CreateConversationSavedTable extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 13;
    public $description = 'Creates conversation_saved, the messages somebody kept for later';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('conversation_saved')) {
            $schema->createTable('conversation_saved', function ($table) {
                $table->comment('A message somebody kept for later');
                $table->bigInteger('participantid')->comment('users.userid of who kept it');
                $table->bigInteger('messageid')->comment('conversation_messages.messageid');
                $table->unsignedInteger('savedat')->comment('When');
                $table->primary(['participantid', 'messageid']);
            });
        }
    }

    /** Drop it again. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('conversation_saved');
    }
}
