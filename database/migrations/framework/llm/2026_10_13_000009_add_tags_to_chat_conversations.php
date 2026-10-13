<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_conversations.tags`, on a table that existed before the framework created it.
 *
 * Only the column: what an application kept in its own columns before is the application's to
 * carry over.
 */
class AddTagsToChatConversations extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_chat_conversations_table'];
    public $description  = 'Adds tags to chat_conversations';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('chat_conversations') || $schema->hasColumn('chat_conversations', 'tags')) {
            return;
        }

        $schema->alterTable('chat_conversations', function ($table) {
            $table->text('tags')->nullable();
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('chat_conversations', 'tags')) {
            $schema->alterTable('chat_conversations', function ($table) {
                $table->dropColumn('tags');
            });
        }
    }
}
