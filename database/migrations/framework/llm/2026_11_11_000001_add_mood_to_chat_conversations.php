<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_conversations`: the assistant's mood in a conversation — state, not history
 * ({@see \Pramnos\Llm\Chat\Mood}).
 *
 * - `mood`: the word the assistant last said it felt, from the application's vocabulary;
 * - `mood_intensity`: how strongly, 0–100, as it was set;
 * - `mood_at`: when it was set, from which it fades (25 an hour), read rather than swept.
 *
 * What it felt before is not kept: a mood replaced is no longer true, and nothing reads it back.
 */
class AddMoodToChatConversations extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Adds mood, mood_intensity and mood_at to chat_conversations';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('assist.chat_conversations') || $schema->hasColumn('assist.chat_conversations', 'mood')) {
            return;
        }

        $schema->alterTable('assist.chat_conversations', function ($table) {
            $table->string('mood', 32)->nullable();
            $table->smallInteger('mood_intensity')->nullable();
            $table->timestamp('mood_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('assist.chat_conversations', 'mood')) {
            $schema->alterTable('assist.chat_conversations', function ($table) {
                $table->dropColumn('mood');
                $table->dropColumn('mood_intensity');
                $table->dropColumn('mood_at');
            });
        }
    }
}
