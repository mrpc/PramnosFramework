<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_asks` — questions answered in the queue worker: waiting, answered or failed, and why.
 *
 * A table that already exists — an application that kept these under the same name — is left
 * as it is. `organization_id` is nullable and has no foreign key: an application without
 * organisations writes none, and the framework does not assume one.
 */
class CreateChatAsksTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Creates chat_asks';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('chat_asks')) {
            return;
        }

        $schema->createTable('chat_asks', function ($table) {
            $table->increments('ask_id');
            $table->integer('organization_id')->nullable();
            $table->integer('userid');
            $table->integer('conversation_id')->nullable();
            $table->string('status', 16);
            $table->string('why', 32)->nullable();
            $table->text('says')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'userid', 'ask_id']);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('chat_asks');
    }
}
