<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_artifacts`: a result of an Ask conversation kept as an object of its own — a table, a
 * list of records, a text — so it can be opened, shared and acted on apart from the answer it
 * came with.
 *
 * State, not history: an artifact is what exists now, and is deleted with its conversation.
 * Beside `chat_conversations` in the application's schema, with the same scope columns, because
 * it is read with the conversation it belongs to.
 *
 * `content` is the artifact's own data as JSON: `{columns, rows}` for a table, `{ids, labels}`
 * for a list (whose `entity` names what the ids are), `{text}` for a text. `tags` are the data
 * tags the conversation held when it was made, which sharing it has to respect.
 */
class CreateChatArtifactsTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Creates chat_artifacts';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('chat_artifacts')) {
            return;
        }

        $schema->createTable('chat_artifacts', function ($table) {
            $table->increments('artifact_id');
            $table->integer('organization_id')->nullable();
            $table->integer('userid');
            $table->string('space', 32)->default('chat');
            $table->integer('conversation_id')->nullable();
            $table->integer('message_id')->nullable();
            $table->string('kind', 16);
            $table->string('title', 200);
            $table->string('entity', 64)->nullable();
            $table->longText('content');
            $table->text('tags')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'userid', 'space', 'created_at']);
            $table->index(['conversation_id']);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('chat_artifacts');
    }
}
