<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Moves the conversations' tables into a schema of their own, `chat.*`, and the assistant's
 * (Ask) into `assist.*`, out of the application's: the framework's tables are not the
 * application's (rule 15), and two features this size each deserve their own place.
 *
 * On PostgreSQL each table is moved with `ALTER TABLE … SET SCHEMA` — its rows, indexes,
 * sequences and keys with it, a TimescaleDB hypertable included. On MySQL, where a schema is a
 * name prefix, each is renamed: `chat_messages` → `assist_chat_messages`, then
 * `conversations` → `chat_conversations`. The assistant's go first, because the conversations'
 * new name `chat_conversations` is the assistant's old one.
 *
 * Only the framework's own tables, by name: an application's `chat_clients` or `todos` stay where
 * they are. A table already moved is left alone, so it may run again; a new installation made
 * them in their schemas and finds nothing to move. A table made empty in its new place beside an
 * old one with rows is replaced by the old one.
 */
class MoveConversationsAndAskToSchemas extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 90;
    public $description = 'Moves the conversations into chat.* and the assistant into assist.*';

    /** Where each table goes: the assistant's first, the conversations' after. */
    public const MOVES = [
        'assist' => ['chat_conversations', 'chat_messages', 'chat_memory', 'chat_asks', 'chat_artifacts', 'chat_shares', 'chat_calendar', 'chat_dossier'],
        'chat'   => ['conversations', 'conversation_members', 'conversation_messages', 'conversation_reactions', 'conversation_mentions', 'conversation_pins',
            'conversation_files', 'conversation_saved', 'conversation_scheduled', 'conversation_drafts', 'conversation_emoji'],
    ];

    /** Move them. */
    public function up(): void
    {
        foreach (static::MOVES as $target => $tables) {
            foreach ($tables as $table) {
                $this->move($table, $target);
            }
        }
    }

    /** Move them back, the conversations' first. */
    public function down(): void
    {
        foreach (array_reverse(static::MOVES, true) as $source => $tables) {
            foreach ($tables as $table) {
                $this->back($table, $source);
            }
        }
    }

    /** One table to its schema, where it is still in the application's. */
    private function move(string $table, string $target): void
    {
        $db     = $this->application->database;
        $schema = $db->schema();
        $prefix = (string) ($db->prefix ?? '');
        if ($db->type === 'postgresql') {
            $old = $prefix . $table;
            if (!$this->pgExists('public', $old) && !$this->pgExists($this->pgSchema($db), $old)) {
                return;
            }
            $schema->ensureSchema($target);
            if ($this->pgExists($target, $table)) {
                if ($this->rows($target . '.' . $table) > 0) {
                    return;   // moved already
                }
                $db->query('DROP TABLE ' . $target . '.' . $table);
            }
            $from = ($this->pgExists($this->pgSchema($db), $old) ? $this->pgSchema($db) : 'public') . '.' . $old;
            if ($prefix !== '') {
                // A schema of its own needs no prefix: renamed first, then moved.
                $db->query('ALTER TABLE ' . $from . ' RENAME TO ' . $table);
                $from = substr($from, 0, (int) strrpos($from, '.')) . '.' . $table;
            }
            $db->query('ALTER TABLE ' . $from . ' SET SCHEMA ' . $target);
        } else {
            $old = $prefix . $table;
            $new = $prefix . $target . '_' . $table;
            // The conversations' `chat_conversations` is the assistant's old name: never taken for it.
            if (!$this->myExists($old) || ($table === 'chat_conversations' && $this->myHasColumn($old, 'slug'))) {
                return;
            }
            if ($this->myExists($new)) {
                if ($this->rows('`' . $new . '`') > 0) {
                    return;
                }
                $db->query('DROP TABLE `' . $new . '`');
            }
            $db->query('RENAME TABLE `' . $old . '` TO `' . $new . '`');
        }
        \Pramnos\Database\SchemaBuilder::forgetWhatExists($db);
    }

    /** One table back to the application's schema. */
    private function back(string $table, string $source): void
    {
        $db     = $this->application->database;
        $prefix = (string) ($db->prefix ?? '');
        if ($db->type === 'postgresql') {
            if (!$this->pgExists($source, $table) || $this->pgExists($this->pgSchema($db), $prefix . $table)) {
                return;
            }
            $db->query('ALTER TABLE ' . $source . '.' . $table . ' SET SCHEMA ' . $this->pgSchema($db));
            if ($prefix !== '') {
                $db->query('ALTER TABLE ' . $this->pgSchema($db) . '.' . $table . ' RENAME TO ' . $prefix . $table);
            }
        } else {
            $new = $prefix . $source . '_' . $table;
            if ($this->myExists($new) && !$this->myExists($prefix . $table)) {
                $db->query('RENAME TABLE `' . $new . '` TO `' . $prefix . $table . '`');
            }
        }
        \Pramnos\Database\SchemaBuilder::forgetWhatExists($db);
    }

    /** The application's schema on PostgreSQL. */
    private function pgSchema(\Pramnos\Database\Database $db): string
    {
        return (string) ($db->schema ?? '') !== '' ? (string) $db->schema : 'public';
    }

    /** Whether a table is in a schema, on PostgreSQL. The names are the framework's own. */
    private function pgExists(string $schema, string $table): bool
    {
        $result = $this->application->database->query("SELECT 1 FROM information_schema.tables WHERE table_schema = '" . $schema . "' AND table_name = '" . $table . "'");

        return $result && $result->numRows > 0;
    }

    /** Whether a table is in the database, on MySQL. */
    private function myExists(string $table): bool
    {
        $result = $this->application->database->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . $table . "'");

        return $result && $result->numRows > 0;
    }

    /** Whether a MySQL table has a column. */
    private function myHasColumn(string $table, string $column): bool
    {
        $result = $this->application->database->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '" . $table . "' AND column_name = '" . $column . "'");

        return $result && $result->numRows > 0;
    }

    /** How many rows a table, named as SQL takes it, has. */
    private function rows(string $quoted): int
    {
        $result = $this->application->database->query('SELECT COUNT(*) AS n FROM ' . $quoted);

        return (int) ($result->fields['n'] ?? 0);
    }
}
