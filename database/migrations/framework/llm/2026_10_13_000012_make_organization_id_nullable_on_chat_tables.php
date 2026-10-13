<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `organization_id` nullable on the conversation tables an application created before the
 * framework did.
 *
 * The framework's migrations leave a table that exists as it is, so an application's own
 * `chat_conversations` with `organization_id NOT NULL` stayed that way — and a row outside any
 * organisation, which the administrators' analyst writes and an application without
 * organisations always writes, failed to insert: `/admin/Ai` answered its first question with a
 * 500. Only the nullability changes, keeping the column's type; a table whose column already
 * allows null, or that has none, is left alone.
 */
class MakeOrganizationIdNullableOnChatTables extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_chat_conversations_table', 'create_chat_messages_table', 'create_chat_memory_table', 'create_chat_asks_table', 'create_ai_consents_table', 'create_ai_routines_table'];
    public $description  = 'Makes organization_id nullable on the conversation tables an application created first';

    /** The tables a row outside an organisation is written to. */
    public const TABLES = ['chat_conversations', 'chat_messages', 'chat_memory', 'chat_asks', 'ai_consents', 'ai_routines'];

    public function up(): void
    {
        $schema = $this->application->database->schema();

        foreach (self::TABLES as $name) {
            $column = $schema->columnDetails($name)['organization_id'] ?? null;

            if ($column === null || $column['nullable']) {
                continue;
            }

            $type = self::blueprintType((string) $column['type']);
            $schema->alterTable($name, function ($table) use ($type) {
                $table->modifyColumn('organization_id', $type)->nullable();
            });
        }
    }

    /** Nothing to undo: a null already written cannot be made to fit a NOT NULL column again. */
    public function down(): void
    {
    }

    /** The builder's name for a database's integer type, as `columnDetails()` reports it. */
    private static function blueprintType(string $type): string
    {
        $type = strtolower($type);

        return match (true) {
            str_starts_with($type, 'bigint')   => 'bigInteger',
            str_starts_with($type, 'smallint') => 'smallInteger',
            default                            => 'integer',
        };
    }
}
