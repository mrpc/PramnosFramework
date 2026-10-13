<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_messages` — what was asked and answered, the tools each answer called (`tools`, JSON),
 * its cost, the prompt it filled (`context_tokens`), and the application's own data (`extra`).
 *
 * A table that already exists — an application that kept these under the same name — is left
 * as it is. `organization_id` is nullable and has no foreign key: an application without
 * organisations writes none, and the framework does not assume one.
 */
class CreateChatMessagesTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_chat_conversations_table'];
    public $description  = 'Creates chat_messages';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('chat_messages')) {
            return;
        }

        $schema->createTable('chat_messages', function ($table) {
            $table->increments('message_id');
            $table->integer('conversation_id');
            $table->integer('organization_id')->nullable();
            $table->string('role', 16);
            $table->text('content');
            $table->text('tools')->nullable();
            $table->decimal('cost', 10, 6)->nullable();
            $table->integer('context_tokens')->nullable();
            $table->text('extra')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'message_id']);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('chat_messages');
    }
}
