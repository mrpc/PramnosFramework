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

        // `users` is shared state this class only ever alters — it adds a column and a
        // constraint to it — so it has to exist before the scenarios can run. It usually does,
        // because an earlier class created it, which is why `--filter ForeignKeyGuardMigrationTest`
        // alone failed with `relation "users" does not exist` while the full suite passed. A
        // class that only works in one order cannot be used to narrow down a failure.
        //
        // Created only when absent, and never dropped: other classes own richer versions of this
        // table, and replacing theirs would move the problem rather than remove it.
        $this->ensureUsersTable();

        // Start from the known-clean state this file's scenarios build on.
        $this->cleanup();
    }

    /**
     * Creates a minimal `users` table if the database has none.
     *
     * Just a primary key — this class asserts on a foreign key *to* `locations`, and nothing it
     * does looks at any other column.
     *
     * @return void
     */
    private function ensureUsersTable(): void
    {
        if ($this->tableExists('users')) {
            return;
        }

        $this->statement(
            'CREATE TABLE users (userid ' . $this->autoKey() . ', username VARCHAR(100))',
            true
        );
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

    /**
     * A row whose parent is gone makes the key be skipped, not the batch aborted.
     *
     * `ALTER TABLE … ADD CONSTRAINT` validates every existing row, so one orphan aborts the
     * statement and the whole migration — on every later `migrate`, with a raw constraint error
     * naming a table the operator did not touch.
     *
     * And it is exactly backwards: a database that has run for years without these keys is where
     * a deleted user can have left an audit row behind, while a fresh one has nothing to orphan.
     * So the migration failed on the installations it was written for and succeeded on the ones
     * that did not need it.
     *
     * This is also the fault that made this suite fail intermittently. `authserver.user_activity_log`
     * is shared between many tests; whether one of them had left a row for a user it then removed
     * decided whether this class errored, and that depends on ordering.
     */
    public function testARowWithNoParentSkipsTheKeyRatherThanAbortingTheMigration(): void
    {
        // Arrange — an audit row for a user who is not there any more.
        if (!$this->tableExists('users')) {
            $this->markTestSkipped('No users table on this connection.');
        }

        /*
         * Asked through the schema builder, not through this class's own `tableExists()`.
         *
         * That helper looks in `public`, which is right for the tables these scenarios create and
         * wrong for this one: `authserver.user_activity_log` is a real schema on PostgreSQL and a
         * table-prefix emulation on MySQL, and only the builder knows which.
         */
        $schema = $this->app->database->schema();

        if (!$schema->hasTable('authserver.user_activity_log')) {
            // Created from its own migration rather than skipped: this scenario is the reason
            // the guard exists, and a test that quietly does not run on the connection where the
            // fault appeared is the shape of the problem, not a workaround for it.
            (new \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable($this->app))->up();
        }

        if (!$schema->hasTable('authserver.user_activity_log')) {
            $this->markTestSkipped('The activity log could not be created on this connection.');
        }

        $log = $schema->quoteTable('authserver.user_activity_log');

        /*
         * The constraint is dropped first, because it survives between runs.
         *
         * `canAddForeignKey()` short-circuits when the constraint already exists — rightly, so a
         * re-run is safe — which means a database where an earlier clean run added it would let
         * this test pass for the wrong reason, and one where it is present *and* the data is dirty
         * would fail. Either way the test would be asserting about the previous run rather than
         * about this migration. Dropping it makes the precondition this test's own; the migration
         * puts it back on the next run once the orphan below is gone.
         */
        $this->statement(
            'ALTER TABLE ' . $log . ' DROP CONSTRAINT fk_user_activity_log_userid',
            true
        );
        if ($this->driver === 'mysql') {
            $this->statement(
                'ALTER TABLE ' . $log . ' DROP FOREIGN KEY fk_user_activity_log_userid',
                true
            );
        }

        $orphan = 987654321;
        $this->statement(
            'INSERT INTO ' . $log . ' (userid, action, created_at)'
            . " VALUES ({$orphan}, 'orphan_probe', '2026-01-01 00:00:00')",
            true
        );

        // Act — the whole migration, as `migrate` runs it.
        (new AddMissingForeignKeysToExistingTables($this->app))->up();

        // Assert — it completed, and declined to add the one key the data cannot satisfy.
        $this->assertFalse(
            $this->constraintExists('user_activity_log', 'fk_user_activity_log_userid'),
            'the constraint was added over a row that violates it'
        );

        $this->statement(
            'DELETE FROM ' . $log . " WHERE action = 'orphan_probe'",
            true
        );
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
