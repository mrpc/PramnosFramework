<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_conversations` — one person's conversations with a model, and the data tags their answers
 * carried (`tags`, a JSON list), so a conversation holding data of a kind stays where it may go.
 *
 * A table that already exists — an application that kept these under the same name — is left
 * as it is. `organization_id` is nullable and has no foreign key: an application without
 * organisations writes none, and the framework does not assume one.
 */
class CreateChatConversationsTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Creates chat_conversations';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('chat_conversations')) {
            return;
        }

        $schema->createTable('chat_conversations', function ($table) {
            $table->increments('conversation_id');
            $table->integer('organization_id')->nullable();
            $table->integer('userid');
            $table->string('title', 200);
            $table->text('tags')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'userid', 'updated_at']);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('chat_conversations');
    }
}
