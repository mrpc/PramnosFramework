<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\Migrations\AddMissingForeignKeysToExistingTables;

/**
 * The FK migration must not assume the schema of tables it does not own.
 *
 * `users.locationid → locations.locationid` is guarded, correctly, by a comment
 * saying the framework does not define `locations` — it is an application
 * concept. The guard itself, however, only asked whether a table by that name
 * existed. An application with its own `locations` keyed on `id`, and no
 * `locationid` column on `users`, therefore got:
 *
 *     ERROR: column "locationid" referenced in foreign key constraint does not exist
 *
 * and the migration reported as failed on every later `migrate`, on an
 * installation that had done nothing wrong. `tokenactions.urlid → urls.urlid`
 * shares the risk: `urls` is just as generic a name.
 *
 * These run against the real database, because the bug only exists there — the
 * SQL is what fails, not any PHP branch.
 */
#[CoversClass(AddMissingForeignKeysToExistingTables::class)]
#[Group('migrations')]
class ForeignKeyGuardMigrationTest extends TestCase
{
    private Database $db;
    private Application $app;
    /** @var string mysql|pgsql */
    private string $driver;
    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $this->db = $this->connect();
        $rawType  = $this->db->type;
        $this->driver = ($rawType === 'postgresql' || $rawType === 'timescaledb') ? 'pgsql' : $rawType;

        $this->app = new Application();
        $this->app->database = $this->db;

        // Start from the known-clean state this file's scenarios build on.
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    /**
     * An application `locations` table keyed on `id`, with no `locationid` on
     * `users`: the migration must complete and simply not create that FK.
     *
     * This is the reported failure. Before the guard checked columns, the
     * ALTER TABLE was issued anyway and aborted the whole batch.
     */
    public function testSkipsTheForeignKeyWhenTheApplicationOwnsLocations(): void
    {
        // Arrange — a perfectly ordinary application table that happens to
        // share the name the framework's optional FK refers to
        $this->statement('CREATE TABLE locations (id ' . $this->autoKey() . ', name VARCHAR(100))');
        $this->assertFalse(
            $this->columnExists('users', 'locationid'),
            'precondition: the framework users table has no locationid'
        );

        // Act — the whole migration, as `migrate` runs it
        (new AddMissingForeignKeysToExistingTables($this->app))->up();

        // Assert — it finished, and the impossible constraint was not attempted
        $this->assertFalse(
            $this->constraintExists('users', 'fk_users_locationid'),
            'the FK must be skipped, not created against a column that does not exist'
        );
    }

    /**
     * When both sides do exist, the FK is created exactly as before — the fix
     * must not change behaviour where things already worked.
     */
    public function testCreatesTheForeignKeyWhenBothSidesMatch(): void
    {
        // Arrange — the schema the framework's FK was written for
        $this->statement('CREATE TABLE locations (locationid ' . $this->autoKey() . ', name VARCHAR(100))');
        $this->statement('ALTER TABLE users ADD COLUMN locationid ' . $this->keyType());

        // Act
        (new AddMissingForeignKeysToExistingTables($this->app))->up();

        // Assert
        $this->assertTrue(
            $this->constraintExists('users', 'fk_users_locationid'),
            'with both columns present the FK must still be created'
        );
    }

    /**
     * With no `locations` table at all — the state of a stock installation —
     * nothing changes: no error, no constraint.
     */
    public function testDoesNothingWhenThereIsNoLocationsTable(): void
    {
        // Arrange — cleanup() in setUp already removed it
        $this->assertFalse($this->tableExists('locations'), 'precondition: no locations table');

        // Act
        (new AddMissingForeignKeysToExistingTables($this->app))->up();

        // Assert
        $this->assertFalse($this->constraintExists('users', 'fk_users_locationid'));
    }

    /**
     * Re-running must stay safe: the migration is already recorded as `Ran` on
     * existing installations, and `migrate` may execute it again after a reset.
     */
    public function testRunningTwiceIsSafe(): void
    {
        // Arrange
        $this->statement('CREATE TABLE locations (id ' . $this->autoKey() . ', name VARCHAR(100))');
        $migration = new AddMissingForeignKeysToExistingTables($this->app);

        // Act — twice, as a re-run would
        $migration->up();
        $migration->up();

        // Assert — still clean, still no bogus constraint
        $this->assertFalse($this->constraintExists('users', 'fk_users_locationid'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Remove everything these tests create, in dependency order.
     */
    private function cleanup(): void
    {
        $this->statement('ALTER TABLE users DROP CONSTRAINT fk_users_locationid', true);
        if ($this->driver === 'mysql') {
            $this->statement('ALTER TABLE users DROP FOREIGN KEY fk_users_locationid', true);
        }
        if ($this->columnExists('users', 'locationid')) {
            $this->statement('ALTER TABLE users DROP COLUMN locationid', true);
        }
        $this->statement('DROP TABLE IF EXISTS locations', true);
    }

    /**
     * Run a statement, optionally tolerating failure (cleanup of things that
     * may not exist).
     */
    private function statement(string $sql, bool $ignoreErrors = false): void
    {
        try {
            $this->db->statement($sql);
        } catch (\Throwable $e) {
            if (!$ignoreErrors) {
                throw $e;
            }
        }
    }

    /** An auto-incrementing primary key clause for this driver. */
    private function autoKey(): string
    {
        return $this->driver === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY';
    }

    /** A column type that can reference the key above. */
    private function keyType(): string
    {
        return $this->driver === 'pgsql' ? 'INTEGER' : 'INT';
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->db->selectOne(
            'SELECT 1 FROM information_schema.tables WHERE table_name = ?'
                . ($this->driver === 'pgsql' ? " AND table_schema = 'public'" : ''),
            [$table]
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->db->selectOne(
            'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?'
                . ($this->driver === 'pgsql' ? " AND table_schema = 'public'" : ''),
            [$table, $column]
        );
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return (bool) $this->db->selectOne(
            'SELECT 1 FROM information_schema.table_constraints '
                . 'WHERE table_name = ? AND constraint_name = ?',
            [$table, $constraint]
        );
    }

    /**
     * Connect the way the other database integration tests do.
     */
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
        $db->database = $_ENV['DB_NAME'] ?? (getenv('DB_NAME') ?: 'pramnos_test');

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
