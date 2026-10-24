<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\Conversations\AddFormerMembersToConversationMembers;
use Pramnos\Framework\Migrations\Conversations\CreateConversationsTables;

/**
 * The guard must not let one column's presence stand in for the other's.
 *
 * `AddFormerMembersToConversationMembers::up()` used to return early as soon as
 * `conversation_members.name` existed, on the assumption that this migration is the
 * only thing that could have put it there — so `name` and `externalid` always arrive
 * or are missing together. A `conversation_members.name` added by anything else first
 * (an application's own migration, a hand-made `ALTER TABLE`) broke that assumption:
 * the migration marked itself `Ran` without ever adding `externalid`, and the next
 * query that read it failed with "Unknown column 'externalid'" — reported against
 * exactly this shape, on a fresh, from-zero database (FW-279).
 *
 * These run against the real database, because the bug is in what `hasColumn()`
 * reports about a real schema, not in any branch the PHP alone exercises.
 */
#[CoversClass(AddFormerMembersToConversationMembers::class)]
#[Group('migrations')]
class AddFormerMembersGuardMigrationTest extends TestCase
{
    private Database $db;

    private Application $app;

    protected function setUp(): void
    {
        require_once ROOT . '/database/migrations/framework/conversations/2026_10_22_000001_create_conversations_tables.php';
        require_once ROOT . '/database/migrations/framework/conversations/2026_10_23_000002_add_former_members_to_conversation_members.php';

        $this->db  = $this->connect();
        $this->app = new Application();
        $this->app->database = $this->db;

        $this->db->query('DROP TABLE IF EXISTS ' . $this->quote('conversation_members'));
        $this->db->query('DROP TABLE IF EXISTS ' . $this->quote('conversations'));
        (new CreateConversationsTables($this->app))->up();
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->quote('conversation_members'));
        $this->db->query('DROP TABLE IF EXISTS ' . $this->quote('conversations'));
        $this->db->close();
    }

    /**
     * `name` pre-existing, from something other than this migration, still gets `externalid`.
     *
     * This is the exact failure: before the fix, the guard's `hasColumn(..., 'name')` alone
     * made `up()` return, leaving `externalid` missing on a table that now looks "already
     * migrated" to `migrate:status`.
     */
    public function testExternalidIsAddedEvenWhenNameAlreadyExistsFromElsewhere(): void
    {
        // Arrange — a `name` column this migration did not add
        $schema = $this->app->database->schema();
        $schema->table('conversation_members', function ($table) {
            $table->string('name', 150)->nullable()->comment('Added by something other than this migration');
        });
        $this->assertFalse($schema->hasColumn('conversation_members', 'externalid'), 'sanity: not there yet');

        // Act
        (new AddFormerMembersToConversationMembers($this->app))->up();

        // Assert
        $this->assertTrue($schema->hasColumn('conversation_members', 'externalid'));
    }

    /**
     * The ordinary case: neither column exists, and `up()` adds both.
     */
    public function testBothColumnsAreAddedWhenNeitherExists(): void
    {
        // Arrange
        $schema = $this->app->database->schema();
        $this->assertFalse($schema->hasColumn('conversation_members', 'name'));
        $this->assertFalse($schema->hasColumn('conversation_members', 'externalid'));

        // Act
        (new AddFormerMembersToConversationMembers($this->app))->up();

        // Assert
        $this->assertTrue($schema->hasColumn('conversation_members', 'name'));
        $this->assertTrue($schema->hasColumn('conversation_members', 'externalid'));
    }

    /**
     * Running it twice is safe — the second run has nothing left to add.
     */
    public function testRunningTwiceIsSafe(): void
    {
        // Arrange
        $migration = new AddFormerMembersToConversationMembers($this->app);

        // Act
        $migration->up();
        $migration->up();

        // Assert — the second run did not throw, and both columns are there once
        $schema = $this->app->database->schema();
        $this->assertTrue($schema->hasColumn('conversation_members', 'name'));
        $this->assertTrue($schema->hasColumn('conversation_members', 'externalid'));
    }

    /**
     * `down()` only drops the column it is sure this migration could have added —
     * it does not assume the other one exists just because this one does.
     */
    public function testDownDropsOnlyWhicheverColumnIsThere(): void
    {
        // Arrange — only `externalid` present, as `up()` would leave it when `name`
        // already existed from elsewhere
        $schema = $this->app->database->schema();
        $schema->table('conversation_members', function ($table) {
            $table->string('externalid', 100)->nullable();
        });

        // Act
        (new AddFormerMembersToConversationMembers($this->app))->down();

        // Assert — gone, and dropping the absent `name` did not throw
        $this->assertFalse($schema->hasColumn('conversation_members', 'externalid'));
    }

    private function quote(string $table): string
    {
        return $this->db->type === 'postgresql' ? '"' . $table . '"' : '`' . $table . '`';
    }

    /** Connect the way the other database integration tests do. */
    private function connect(): Database
    {
        $driver   = $_ENV['DB_TYPE'] ?? (getenv('DB_TYPE') ?: 'mysql');
        $isPg     = ($driver === 'postgresql' || $driver === 'pgsql' || $driver === 'timescaledb');
        $db       = new Database();
        $db->type     = $driver;
        $db->server   = $_ENV['DB_HOST'] ?? (getenv('DB_HOST') ?: 'db');
        $db->port     = (int) ($_ENV['DB_PORT'] ?? (getenv('DB_PORT') ?: ($isPg ? 5432 : 3306)));
        $db->user     = $_ENV['DB_USER'] ?? (getenv('DB_USER') ?: 'root');
        $db->password = $_ENV['DB_PASS'] ?? (getenv('DB_PASS') ?: 'secret');
        $db->database = $_ENV['DB_NAME'] ?? (getenv('DB_NAME') ?: TEST_DATABASE);

        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped('No database available for this integration test.');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('No database available: ' . $e->getMessage());
        }

        return $db;
    }
}
