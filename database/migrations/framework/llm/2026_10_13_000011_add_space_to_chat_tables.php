<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `space` on the conversation tables: which kind of conversation a row belongs to.
 *
 * `chat` is a person's own Ask AI; `admin` is an administrator's analyst. The two share the
 * tables and never each other's rows — a person's list of conversations, what the model
 * remembers about them, their queued questions and schedules are read within one space.
 * Every row that exists already is `chat`, which is what it was.
 */
class AddSpaceToChatTables extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_chat_conversations_table', 'create_chat_memory_table', 'create_chat_asks_table', 'create_ai_routines_table'];
    public $description  = 'Adds space to chat_conversations, chat_memory, chat_asks and ai_routines';

    /** The tables a conversation's rows live in, outside its messages. */
    private const TABLES = ['chat_conversations', 'chat_memory', 'chat_asks', 'ai_routines'];

    public function up(): void
    {
        $schema = $this->application->database->schema();

        foreach (self::TABLES as $name) {
            if (!$schema->hasTable($name) || $schema->hasColumn($name, 'space')) {
                continue;
            }

            $schema->alterTable($name, function ($table) {
                $table->string('space', 32)->default('chat');
            });
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        foreach (self::TABLES as $name) {
            if ($schema->hasTable($name) && $schema->hasColumn($name, 'space')) {
                $schema->alterTable($name, function ($table) {
                    $table->dropColumn('space');
                });
            }
        }
    }
}
