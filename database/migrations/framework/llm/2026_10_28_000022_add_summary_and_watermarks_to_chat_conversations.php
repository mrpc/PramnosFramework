<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_conversations`: what a conversation has already been read for — state, not history.
 *
 * - `summary`, `summary_upto`: the older part of a long conversation, folded into a few lines,
 *   and the last message folded into it. What comes after is sent as it was said.
 * - `learned_upto`: the last message the memory has learned from. Learning waits for several of
 *   the person's messages, or for the conversation to go quiet, rather than costing a call after
 *   every answer.
 *
 * A conversation that already exists counts as learned up to its last message: it was learned
 * from after every answer, or by choice not at all, and is not read again.
 */
class AddSummaryAndWatermarksToChatConversations extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Adds summary, summary_upto and learned_upto to chat_conversations';

    public function up(): void
    {
        $database = $this->application->database;
        $schema   = $database->schema();

        if (!$schema->hasTable('chat_conversations') || $schema->hasColumn('chat_conversations', 'learned_upto')) {
            return;
        }

        $schema->alterTable('chat_conversations', function ($table) {
            $table->text('summary')->nullable();
            $table->bigInteger('summary_upto')->default(0);
            $table->bigInteger('learned_upto')->default(0);
        });

        $query  = $database->queryBuilder()->table('chat_messages');
        $result = $query->select(['conversation_id', $query->raw('MAX(message_id) AS last_id')])->groupBy('conversation_id')->get();
        while ($result && ($row = $result->fetch()) !== null) {
            $database->queryBuilder()->table('chat_conversations')->where('conversation_id', (int) $row['conversation_id'])
                ->update(['learned_upto' => (int) $row['last_id']]);
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('chat_conversations', 'learned_upto')) {
            $schema->alterTable('chat_conversations', function ($table) {
                $table->dropColumn('summary');
                $table->dropColumn('summary_upto');
                $table->dropColumn('learned_upto');
            });
        }
    }
}
