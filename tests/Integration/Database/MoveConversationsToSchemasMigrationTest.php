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
 * does nothing; a `chat` schema the application made for a table of its own is shared.
 */
#[CoversClass(MoveConversationsAndAskToSchemas::class)]
#[CoversClass(SchemaBuilder::class)]
#[CoversClass(\Pramnos\Framework\Migrations\Llm\MoveAskToAssistSchema::class)]
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
     * rows; the application's `chat_clients` left where it is, and its `chat.devices` beside the
     * moved ones; a second run a no-op.
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
        $migration = new MoveConversationsAndAskToSchemas($app);

        try {
            // Act
            $migration->up();
            $migration->up();
            $read = static fn (string $table, string $column) => $db->queryBuilder()->table($table)->value($column);

            // Assert
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

    /**
     * An installation with language models but without the conversations never runs the
     * conversations' migrations: the assistant's move lives with the language models too. Its
     * `chat_*` tables move to `assist.*` with their rows; a table named like the conversations' is
     * not touched; a second run does nothing; and a new installation makes them there at once.
     */
    #[DataProvider('databases')]
    public function testWithoutTheConversationsTheAssistantStillMoves(string $type, string $host, int $port, string $user, string $prefix): void
    {
        // Arrange
        require_once ROOT . '/database/migrations/framework/llm/2026_11_09_000012_move_ask_to_assist_schema.php';
        require_once ROOT . '/database/migrations/framework/llm/2026_10_13_000003_create_chat_conversations_table.php';
        $db  = $this->probe($type, $host, $port, $user, $prefix);
        $app = new Application();
        $app->database = $db;
        $t = static fn (string $name): string => $type === 'mysql' ? '`' . $prefix . $name . '`' : $prefix . $name;
        $db->query('CREATE TABLE ' . $t('chat_conversations') . ' (conversation_id INT PRIMARY KEY, title VARCHAR(50))');
        $db->query('CREATE TABLE ' . $t('chat_messages') . ' (message_id INT PRIMARY KEY, conversation_id INT)');
        $db->query('CREATE TABLE ' . $t('conversations') . ' (conversationid INT PRIMARY KEY)');
        $db->query('INSERT INTO ' . $t('chat_conversations') . " VALUES (1, 'Ask')");
        $db->query('INSERT INTO ' . $t('chat_messages') . ' VALUES (5, 1)');
        SchemaBuilder::forgetWhatExists($db);
        $migration = new \Pramnos\Framework\Migrations\Llm\MoveAskToAssistSchema($app);
        $features  = new class extends Application {
            /** The directories an installation with only these features runs. @return string[] */
            public function dirsFor(array $dirs): array
            {
                return $this->filterMigrationDirsByEnabledFeatures($dirs);
            }
        };
        $enabled = \Pramnos\Application\FeatureRegistry::getEnabled();
        \Pramnos\Application\FeatureRegistry::reset();
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['llm']);
        $base = \Pramnos\Database\MigrationLoader::resolveFrameworkMigrationsBase();

        try {
            // Act
            $runs = array_map('basename', $features->dirsFor([$base . '/llm', $base . '/conversations']));
            $migration->up();
            $migration->up();
            $read = static fn (string $table, string $column) => $db->queryBuilder()->table($table)->value($column);

            // Assert
            $this->assertSame(['llm'], $runs, 'an installation with only language models runs the move that is theirs');
            $this->assertSame('Ask', $read('assist.chat_conversations', 'title'), 'moved, with its rows');
            $this->assertSame(5, (int) $read('assist.chat_messages', 'message_id'));
            $this->assertTrue($db->schema()->hasTable($prefix . 'conversations') || $db->schema()->hasTable('conversations'), 'the conversations\' table: not this move\'s');

            // Act — a new installation
            $db->query('DROP TABLE ' . ($type === 'mysql' ? '`' . $prefix . 'assist_chat_conversations`' : 'assist.chat_conversations'));
            SchemaBuilder::forgetWhatExists($db);
            (new \Pramnos\Framework\Migrations\Llm\CreateChatConversationsTable($app))->up();
            SchemaBuilder::forgetWhatExists($db);

            // Assert
            $this->assertTrue($db->schema()->hasTable('assist.chat_conversations'), 'made in its schema at once');
        } finally {
            \Pramnos\Application\FeatureRegistry::reset();
            \Pramnos\Application\FeatureRegistry::loadFromConfig($enabled);
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
