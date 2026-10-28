<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_shares`: an Ask conversation or artifact one person has shared with another.
 *
 * State, not history: a row is a share that stands now, deleted when it is withdrawn. One row per
 * recipient. A conversation is shared as it stood — `up_to_message_id` is the last message the
 * recipient sees — and sharing it again moves that on. `copy_conversation_id` is the recipient's
 * own copy once they have opened it, so opening it again returns there instead of copying twice.
 */
class CreateChatSharesTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Creates chat_shares';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('chat_shares')) {
            return;
        }

        $schema->createTable('chat_shares', function ($table) {
            $table->increments('share_id');
            $table->string('kind', 16);
            $table->integer('target_id');
            $table->integer('organization_id')->nullable();
            $table->integer('userid');
            $table->string('space', 32)->default('chat');
            $table->integer('recipient');
            $table->string('title', 200);
            $table->integer('up_to_message_id')->nullable();
            $table->integer('copy_conversation_id')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'target_id', 'recipient']);
            $table->index(['recipient', 'created_at']);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('chat_shares');
    }
}
