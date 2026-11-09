<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\SchemaBuilder;
use Pramnos\Framework\Migrations\Conversations\MoveConversationsAndAskToSchemas;

/**
 * An installation made before the conversations and the assistant had schemas of their own: its
 * tables in the application's schema, rows in them, the messages a TimescaleDB hypertable. The
 * migration moves each — rows, ids and all — the assistant's first, so the conversations' new
 * name on MySQL is free; an application's own `chat_clients` is not touched; running it again
 * does nothing; and until it runs, `chat.x` is read where the table was, on PostgreSQL.
 */
#[CoversClass(MoveConversationsAndAskToSchemas::class)]
#[CoversClass(SchemaBuilder::class)]
class MoveConversationsToSchemasMigrationTest extends TestCase
{
    /** The throwaway database. */
    private const PROBE = 'pf_move_schemas_probe';

    /** @return array<string, array{string, string, int, string, string}> */
    public static function databases(): array
    {
        return [
            'mysql'           => ['mysql', 'db', 3306, 'root', ''],
            'mysql, prefixed' => ['mysql', 'db', 3306, 'root', 'pf_'],
            'timescaledb'     => ['postgresql', 'timescaledb', 5432, 'postgres', ''],
        ];
    }

    /**
     * The old layout moved: the assistant's `chat_conversations` and the conversations'
     * `conversations` and `conversation_messages` (a hypertable on TimescaleDB), each with its
     * rows; the application's `chat_clients` left where it is; a second run a no-op; and, before
     * the run on PostgreSQL, `chat.conversations` read in the old place.
     */
    #[DataProvider('databases')]
    public function testTheOldTablesMoveWithTheirRows(string $type, string $host, int $port, string $user, string $prefix): void
    {
        // Arrange
        require_once ROOT . '/database/migrations/framework/conversations/2026_11_09_000008_move_conversations_and_ask_to_schemas.php';
        $db  = $this->probe($type, $host, $port, $user, $prefix);
        $app = new Application();
        $app->database = $db;
        $t = static fn (string $name): string => $type === 'mysql' ? '`' . $prefix . $name . '`' : $prefix . $name;
        $db->query('CREATE TABLE ' . $t('chat_conversations') . ' (conversation_id INT PRIMARY KEY, title VARCHAR(50))');
        $db->query('CREATE TABLE ' . $t('conversations') . ' (conversationid INT PRIMARY KEY, slug VARCHAR(50))');
        $db->query('CREATE TABLE ' . $t('conversation_messages') . ' (messageid INT, conversationid INT, created_at ' . ($type === 'mysql' ? 'DATETIME' : 'TIMESTAMPTZ') . ' NOT NULL, PRIMARY KEY (messageid, created_at))');
        $db->query('CREATE TABLE ' . $t('chat_clients') . ' (id INT PRIMARY KEY)');
        if ($type === 'postgresql') {
            $db->query("SELECT create_hypertable('conversation_messages', 'created_at', chunk_time_interval => INTERVAL '30 days')");
            // A `chat` schema the application made already, for a table of its own.
            $db->query('CREATE SCHEMA chat');
            $db->query('CREATE TABLE chat.devices (id INT PRIMARY KEY)');
        }
        $db->query('INSERT INTO ' . $t('chat_conversations') . " VALUES (1, 'Ask')");
        $db->query('INSERT INTO ' . $t('conversations') . " VALUES (7, 'general')");
        $db->query('INSERT INTO ' . $t('conversation_messages') . " VALUES (70, 7, '2026-11-01 10:00:00')");
        $db->query('INSERT INTO ' . $t('chat_clients') . ' VALUES (3)');
        SchemaBuilder::forgetWhatExists($db);
        $before = $type === 'postgresql' ? $db->queryBuilder()->table('chat.conversations')->value('slug') : null;
        $migration = new MoveConversationsAndAskToSchemas($app);

        try {
            // Act
            $migration->up();
            $migration->up();
            $read = static fn (string $table, string $column) => $db->queryBuilder()->table($table)->value($column);

            // Assert
            if ($type === 'postgresql') {
                $this->assertSame('general', $before, 'not moved yet — though a `chat` schema of the application\'s is there: read in the old place');
            }
            $this->assertSame('Ask', $read('assist.chat_conversations', 'title'), 'the assistant\'s, moved');
            $this->assertSame('general', $read('chat.conversations', 'slug'), 'the conversations\', moved — on MySQL to the assistant\'s old name');
            $this->assertSame(70, (int) $read('chat.conversation_messages', 'messageid'));
            $this->assertTrue($db->schema()->hasTable($prefix . 'chat_clients') || $db->schema()->hasTable('chat_clients'), 'the application\'s own, left alone');
            $this->assertFalse($db->schema()->hasTable('assist.chat_clients'));
            if ($type === 'postgresql') {
                $this->assertTrue($db->schema()->hasTable('chat.devices'), 'the application\'s table in the same schema, untouched');
                $hyper = $db->query("SELECT 1 FROM timescaledb_information.hypertables WHERE hypertable_schema = 'chat' AND hypertable_name = 'conversation_messages'");
                $this->assertSame(1, $hyper->numRows, 'still a hypertable, in its schema');
            }

            // Act — and back
            $migration->down();
            SchemaBuilder::forgetWhatExists($db);

            // Assert
            $this->assertSame('Ask', $db->query('SELECT title FROM ' . $t('chat_conversations'))->fields['title'], 'back where it was');
            $this->assertSame('general', $db->query('SELECT slug FROM ' . $t('conversations'))->fields['slug']);
        } finally {
            $db->close();
            $this->drop($type, $host, $port, $user);
        }
    }

    /** A database of its own, made empty. */
    private function probe(string $type, string $host, int $port, string $user, string $prefix): Database
    {
        $admin = $this->connection($type, $host, $port, $user, TEST_DATABASE);
        $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
        $admin->query('CREATE DATABASE ' . self::PROBE);
        $admin->close();
        $db         = $this->connection($type, $host, $port, $user, self::PROBE);
        $db->prefix = $prefix;
        if ($type === 'postgresql') {
            $db->query('CREATE EXTENSION IF NOT EXISTS timescaledb');
        }

        return $db;
    }

    /** The database gone. */
    private function drop(string $type, string $host, int $port, string $user): void
    {
        $admin = $this->connection($type, $host, $port, $user, TEST_DATABASE);
        $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
        $admin->close();
    }

    /** A connection to a database by name, or the test skipped where the server is not there. */
    private function connection(string $type, string $host, int $port, string $user, string $name): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = $name;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        return $db;
    }
}
