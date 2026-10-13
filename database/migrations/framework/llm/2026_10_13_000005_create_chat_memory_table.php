<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_memory` — what a conversation remembers about the person: a fact or a preference each.
 *
 * A table that already exists — an application that kept these under the same name — is left
 * as it is. `organization_id` is nullable and has no foreign key: an application without
 * organisations writes none, and the framework does not assume one.
 */
class CreateChatMemoryTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Creates chat_memory';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('chat_memory')) {
            return;
        }

        $schema->createTable('chat_memory', function ($table) {
            $table->increments('memory_id');
            $table->integer('organization_id')->nullable();
            $table->integer('userid');
            $table->string('kind', 16);
            $table->string('content', 300);
            $table->timestamps();
            $table->index(['organization_id', 'userid']);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('chat_memory');
    }
}
