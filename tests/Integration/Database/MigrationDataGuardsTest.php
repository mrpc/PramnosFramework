<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationRunner;

/**
 * A migration that cannot succeed on this data declines instead of failing.
 *
 * WHAT: `add_unique_constraint_to_settings_table` checks for duplicates before
 *       it drops anything, and the backfill in `add_token_lookup_to_usertokens`
 *       is scoped and bounded.
 * WHY:  `canAddForeignKey()` had the pattern — count the rows that would violate
 *       the change, decline with a message naming them — and nothing else used
 *       it. The settings migration drops the plain index *before* creating the
 *       unique one, so a duplicate left the installation with **neither** index
 *       on a column every settings read uses. The token backfill read the whole
 *       table into PHP, recomputed digests it had already written, and did in
 *       5 617 ms what one statement does in 130.
 *
 * The constraint these are written against: a guard must not turn a real problem
 * into silence. A decline is recorded as `RESULT_DECLINED`, printed by `migrate`,
 * shown by `migrate:status`, and — because `getRanSlugs()` counts only
 * `RESULT_OK` — attempted again next time.
 */
class MigrationDataGuardsTest extends TestCase
{
    private Database $db;
    private Application $app;

    /** A database this test owns outright, so index names are free. */
    private const PROBE_DB = 'pramnos_migration_guards';

    /** Tables this test owns outright. */
    private const SETTINGS = 'guardtest_settings';
    private const TOKENS   = 'guardtest_usertokens';

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        /*
         * A database of this test's own, and that is not tidiness.
         *
         * These tests run the real migrations, which create indexes by fixed name
         * — `idx_settings_name`, `idx_usertokens_token_lookup`. PostgreSQL index
         * names are unique per **schema**, and the real `settings` and
         * `usertokens` in `pramnos_test` already hold both, so a probe table
         * alongside them cannot be given the indexes the migration is supposed to
         * create; the collision reads as a migration fault when it is a fixture
         * one. A separate *schema* does not solve it either — `hasTable()` and
         * `hasColumn()` resolve against `public`, so the migration would decide
         * the table does not exist and do nothing at all.
         *
         * An empty database is the shape a real installation actually has: one
         * table of each name, both index names free.
         */
        $this->createProbeDatabase();

        $this->db           = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = self::PROBE_DB;
        $this->db->port     = 5432;
        $this->db->schema   = 'public';

        if (!$this->db->connect(false)) {
            $this->markTestSkipped('PostgreSQL/TimescaleDB container not reachable');
        }

        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        $this->app     = $app;

        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        foreach ([self::SETTINGS, self::TOKENS] as $table) {
            try {
                $this->db->query('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
            } catch (\Throwable) {
                // Best-effort teardown.
            }
        }
    }

    // ── duplicateGroups() and decline() ──────────────────────────────────────

    /**
     * Duplicates are found, counted and named.
     *
     * Named rather than counted, because «two rows share a name» is not
     * actionable and «`sitename` appears twice» is.
     */
    public function testDuplicateGroupsNamesTheOffendingValues(): void
    {
        // Arrange
        $this->db->query(
            'CREATE TABLE ' . self::SETTINGS . ' (id SERIAL PRIMARY KEY, setting VARCHAR(255))'
        );
        foreach (['sitename', 'sitename', 'sitename', 'theme', 'theme', 'unique_one'] as $v) {
            $this->db->query(
                $this->db->prepareQuery(
                    'INSERT INTO ' . self::SETTINGS . ' (setting) VALUES (%s)',
                    $v
                )
            );
        }
        $migration = $this->probeMigration();

        // Act
        $groups = $migration->exposeDuplicateGroups(self::SETTINGS, 'setting');

        // Assert — ordered by how many, so the worst offender leads
        $this->assertCount(2, $groups, 'only the values that repeat');
        $this->assertSame('sitename', $groups[0]['value']);
        $this->assertSame(3, $groups[0]['rows']);
        $this->assertSame('theme', $groups[1]['value']);
        $this->assertSame(2, $groups[1]['rows']);
        $this->assertSame(2, $migration->exposeDuplicateCount(self::SETTINGS, 'setting'));
    }

    /**
     * A clean column has no duplicates, and NULLs are not duplicates of each other.
     *
     * A unique index accepts any number of NULLs on both backends, so counting
     * them would decline a migration that would have succeeded — a guard turning
     * a non-problem into a refusal.
     */
    public function testNullsAreNotDuplicates(): void
    {
        // Arrange
        $this->db->query(
            'CREATE TABLE ' . self::SETTINGS . ' (id SERIAL PRIMARY KEY, setting VARCHAR(255))'
        );
        $this->db->query('INSERT INTO ' . self::SETTINGS . ' (setting) VALUES (NULL), (NULL), (NULL)');
        $this->db->query("INSERT INTO " . self::SETTINGS . " (setting) VALUES ('a'), ('b')");
        $migration = $this->probeMigration();

        // Act + Assert
        $this->assertSame([], $migration->exposeDuplicateGroups(self::SETTINGS, 'setting'));
        $this->assertSame(0, $migration->exposeDuplicateCount(self::SETTINGS, 'setting'));
    }

    /**
     * A count that cannot be taken is not evidence of a problem.
     *
     * Declining because a table is missing, or a permission is refused, would
     * make an unrelated fault look like dirty data and send an operator hunting
     * rows that are not there.
     */
    public function testAnUnaskableCountIsNotADuplicate(): void
    {
        // Arrange — the table does not exist
        $migration = $this->probeMigration();

        // Act + Assert
        $this->assertSame([], $migration->exposeDuplicateGroups('no_such_table_at_all', 'setting'));
        $this->assertSame(0, $migration->exposeDuplicateCount('no_such_table_at_all', 'setting'));
    }

    /**
     * A decline is visible: on the object, in the log, and in the ledger.
     *
     * The last one is what the constraint is about — a guard that refused and
     * recorded nothing would leave the schema wrong with nothing to read.
     */
    public function testADeclineIsRecordedAsItsOwnStateAndStaysPending(): void
    {
        // Arrange
        $migration = $this->probeMigration();

        // Act
        $migration->declineNow('two rows share a name');

        // Assert — on the object
        $this->assertTrue($migration->hasDeclined());
        $this->assertSame('two rows share a name', $migration->declinedReason());

        // Assert — and the runner has a state for it that is not success
        $this->assertSame(3, MigrationRunner::RESULT_DECLINED);
        $this->assertNotSame(MigrationRunner::RESULT_OK, MigrationRunner::RESULT_DECLINED);
        $this->assertNotSame(MigrationRunner::RESULT_FAILED, MigrationRunner::RESULT_DECLINED);
    }

    // ── The settings unique index ────────────────────────────────────────────

    /**
     * With duplicates present the migration declines and changes nothing.
     *
     * The order in `up()` is what makes this matter: the plain index is dropped
     * *before* the unique one is created, so a `CREATE UNIQUE INDEX` that aborts
     * on a duplicate leaves the installation with **neither** index on a column
     * every settings read uses.
     */
    public function testTheSettingsMigrationDeclinesOnDuplicatesAndChangesNothing(): void
    {
        // Arrange — a settings table of the shape that predates the constraint
        $this->createSettingsTable();
        foreach (['sitename', 'sitename', 'theme'] as $v) {
            $this->db->query(
                $this->db->prepareQuery(
                    'INSERT INTO ' . self::SETTINGS . ' (setting) VALUES (%s)',
                    $v
                )
            );
        }
        $this->db->query('CREATE INDEX idx_settings_name ON ' . self::SETTINGS . ' (setting)');

        // Act
        $migration = $this->runSettingsMigration();

        // Assert — it declined, and said what to repair
        $this->assertTrue($migration->hasDeclined());
        $this->assertStringContainsString('sitename', $migration->declinedReason());
        $this->assertStringContainsString('2 rows', $migration->declinedReason());
        $this->assertStringContainsString('migrate again', $migration->declinedReason());

        // Assert — and the existing index is still there, which is the whole point
        $this->assertTrue(
            $this->indexExists('idx_settings_name'),
            'the plain index must survive a decline: dropping it and then failing is the bug'
        );
        $this->assertFalse($this->indexExists('uq_settings_name'));
    }

    /**
     * With no duplicates it does its job, and the decline stays silent.
     *
     * The complement: a guard that declined on clean data would be a new way to
     * never apply the constraint.
     */
    public function testTheSettingsMigrationProceedsWhenTheDataIsClean(): void
    {
        // Arrange
        $this->createSettingsTable();
        foreach (['sitename', 'theme', 'locale'] as $v) {
            $this->db->query(
                $this->db->prepareQuery(
                    'INSERT INTO ' . self::SETTINGS . ' (setting) VALUES (%s)',
                    $v
                )
            );
        }
        $this->db->query('CREATE INDEX idx_settings_name ON ' . self::SETTINGS . ' (setting)');

        // Act
        $migration = $this->runSettingsMigration();

        // Assert
        $this->assertFalse($migration->hasDeclined(), (string) $migration->declinedReason());
        $this->assertTrue($this->indexExists('uq_settings_name'), 'the unique index was created');
        $this->assertFalse($this->indexExists('idx_settings_name'), 'and the plain one replaced');
    }

    // ── The token backfill ───────────────────────────────────────────────────

    /**
     * Without a key the whole backfill is one statement, and it skips dead rows.
     *
     * Both halves matter. One statement is what turns two minutes of a deploy
     * into three seconds at a million rows; the scope is what stops it computing
     * digests for tokens that can never be looked up again — most of the table on
     * a long-lived installation, because revoked and expired tokens are kept on
     * purpose.
     *
     * This drives the **real migration**, pointed at a table of this test's own
     * by setting the connection prefix, rather than re-issuing the statement the
     * migration contains. A test that reproduced the SQL would keep passing after
     * the migration stopped doing it.
     */
    public function testTheLookupBackfillFillsOnlyRowsThatCanStillAuthenticate(): void
    {
        // Arrange — one live, one revoked, one expired, one live-with-a-future-expiry
        $this->createTokensTable(false);
        $now = time();
        $this->insertToken(1, 'live-token',    1, 0);
        $this->insertToken(2, 'revoked-token', 3, 0);
        $this->insertToken(3, 'expired-token', 1, $now - 3600);
        $this->insertToken(4, 'future-token',  1, $now + 3600);

        // Act — the real migration, aimed at guardtest_usertokens by the prefix
        $this->runTokenLookupMigration();

        // Assert — the live rows carry the digest Token::lookup() computes
        $this->assertSame(
            \Pramnos\User\Token::lookup('live-token'),
            $this->lookupOf(1),
            'the digest the database wrote must equal the one PHP matches on'
        );
        $this->assertSame(\Pramnos\User\Token::lookup('future-token'), $this->lookupOf(4));

        // Assert — and the dead ones were left alone
        $this->assertNull($this->lookupOf(2), 'a revoked token is never looked up again');
        $this->assertNull($this->lookupOf(3), 'nor an expired one');
    }

    /**
     * The migration adds the column when it is missing, fills it, then indexes it.
     *
     * The order is the point: a unique index over a column that is NULL everywhere
     * is satisfied by the NULLs and violated the moment two are filled in by the
     * same statement, so it has to go on after the backfill.
     */
    public function testTheMigrationAddsTheColumnFillsItAndIndexesIt(): void
    {
        // Arrange — the shape of an installation that predates the column
        $this->createTokensTable(false);
        $this->insertToken(1, 'live-token', 1, 0);

        // Act
        $this->runTokenLookupMigration();

        // Assert
        $this->assertTrue(
            $this->db->schema()->hasColumn(self::TOKENS, 'token_lookup'),
            'the column has to exist before anything can be written to it'
        );
        $this->assertNotNull($this->lookupOf(1));
        $this->assertTrue(
            $this->indexExistsOn('idx_usertokens_token_lookup', self::TOKENS),
            'and the unique index goes on last'
        );
    }

    /**
     * The digest the database computes is the one PHP matches on.
     *
     * This is the assertion the whole SQL path rests on: if
     * `encode(sha256(...), 'hex')` and `hash('sha256', ...)` disagreed by so much
     * as a case, every authentication lookup would miss and nobody could sign in.
     */
    public function testTheDatabaseDigestMatchesTokenLookupExactly(): void
    {
        // Arrange
        $this->createTokensTable();
        $this->insertToken(1, 'a-token-with-Mixed-Case-and-symbols-!@#$%^', 1, 0);

        // Act
        $this->db->query(
            'UPDATE ' . self::TOKENS . " SET token_lookup = encode(sha256(token::bytea), 'hex')"
        );

        // Assert
        $this->assertSame(
            \Pramnos\User\Token::lookup('a-token-with-Mixed-Case-and-symbols-!@#$%^'),
            $this->lookupOf(1)
        );
    }

    /**
     * A second run does no work, because the rows it would fill have a digest.
     *
     * The backfill had no `token_lookup IS NULL`, so every deploy paid the whole
     * cost again — and, before the scope, recomputed digests for rows that could
     * never be used.
     */
    public function testASecondRunTouchesNothing(): void
    {
        // Arrange — already backfilled
        $this->createTokensTable();
        $this->insertToken(1, 'live-token', 1, 0);
        $filled = "UPDATE " . self::TOKENS
            . " SET token_lookup = encode(sha256(token::bytea), 'hex')"
            . " WHERE token_lookup IS NULL AND status = 1";
        $this->db->query($filled);
        $before = $this->lookupOf(1);

        // Act — the same statement again
        $this->db->query($filled);

        // Assert — unchanged, and it matched no rows the second time
        $this->assertSame($before, $this->lookupOf(1));
        $this->assertSame(
            0,
            (int) $this->db->query(
                'SELECT COUNT(*) AS c FROM ' . self::TOKENS . ' WHERE token_lookup IS NULL AND status = 1'
            )->fields['c'],
            'nothing is left for a third run either'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Make the probe database if it is not there.
     *
     * Through a connection to the server's default database, because
     * `CREATE DATABASE` cannot run inside the database it creates — and it has no
     * `IF NOT EXISTS`, so the catalogue is asked first.
     */
    private function createProbeDatabase(): void
    {
        $admin           = new Database();
        $admin->type     = 'postgresql';
        $admin->server   = 'timescaledb';
        $admin->user     = 'postgres';
        $admin->password = 'secret';
        $admin->database = 'postgres';
        $admin->port     = 5432;
        $admin->schema   = 'public';

        if (!$admin->connect(false)) {
            $this->markTestSkipped('PostgreSQL/TimescaleDB container not reachable');
        }

        $exists = $admin->query(
            $admin->prepareQuery(
                'SELECT COUNT(*) AS c FROM pg_database WHERE datname = %s',
                self::PROBE_DB
            )
        );

        if ((int) ($exists->fields['c'] ?? 0) === 0) {
            $admin->query('CREATE DATABASE ' . self::PROBE_DB);
        }
    }

    private function createSettingsTable(): void
    {
        $this->db->query(
            'CREATE TABLE ' . self::SETTINGS
            . ' (id SERIAL PRIMARY KEY, setting VARCHAR(255), value TEXT NULL)'
        );
    }

    /**
     * Run the real settings migration against this test's own table.
     *
     * It addresses `#PREFIX#settings`, so the connection prefix aims it at
     * `guardtest_settings` — the real code against a table nothing else shares.
     */
    private function runSettingsMigration(): object
    {
        $file = dirname(__DIR__, 3)
            . '/database/migrations/framework/core/2026_05_26_000051_add_unique_constraint_to_settings_table.php';
        require_once $file;

        $class = '\\Pramnos\\Framework\\Migrations\\Core\\AddUniqueConstraintToSettingsTable';

        $previousPrefix   = $this->db->prefix;
        $this->db->prefix = 'guardtest_';

        try {
            $migration = new $class($this->app);
            $migration->up();

            return $migration;
        } finally {
            $this->db->prefix = $previousPrefix;
        }
    }

    private function indexExists(string $name): bool
    {
        return $this->indexExistsOn($name, self::SETTINGS);
    }

    private function indexExistsOn(string $name, string $table): bool
    {
        $row = $this->db->query(
            $this->db->prepareQuery(
                'SELECT COUNT(*) AS c FROM pg_indexes'
                . ' WHERE indexname = %s AND tablename = %s',
                $name,
                $table
            )
        );

        return (int) ($row->fields['c'] ?? 0) > 0;
    }

    /**
     * @param bool $withLookupColumn false to model an installation that predates it
     */
    private function createTokensTable(bool $withLookupColumn = true): void
    {
        $this->db->query(
            'CREATE TABLE ' . self::TOKENS . ' ('
            . ' tokenid INTEGER PRIMARY KEY,'
            . ' token TEXT,'
            . ($withLookupColumn ? ' token_lookup VARCHAR(64) NULL,' : '')
            . ' status INTEGER NOT NULL DEFAULT 1,'
            . ' expires INTEGER NULL)'
        );
    }

    /**
     * Run the real `AddTokenLookupToUsertokens` against this test's own table.
     *
     * The migration addresses `usertokens` through the connection prefix, so
     * setting the prefix to `guardtest_` points every one of its statements at
     * `guardtest_usertokens` — the real code, the real order, a table nothing
     * else in the suite shares.
     */
    private function runTokenLookupMigration(): void
    {
        $file = dirname(__DIR__, 3)
            . '/database/migrations/framework/auth/2026_08_31_000004_add_token_lookup_to_usertokens.php';
        require_once $file;

        $class = '\\Pramnos\\Framework\\Migrations\\Auth\\AddTokenLookupToUsertokens';

        $previousPrefix   = $this->db->prefix;
        $this->db->prefix = 'guardtest_';

        try {
            (new $class($this->app))->up();
        } finally {
            $this->db->prefix = $previousPrefix;
        }
    }

    private function insertToken(int $id, string $token, int $status, ?int $expires): void
    {
        $this->db->query(
            $this->db->prepareQuery(
                'INSERT INTO ' . self::TOKENS
                . ' (tokenid, token, status, expires) VALUES (%d, %s, %d, %d)',
                $id,
                $token,
                $status,
                (int) $expires
            )
        );
    }

    private function lookupOf(int $id): ?string
    {
        $row = $this->db->query(
            $this->db->prepareQuery(
                'SELECT token_lookup FROM ' . self::TOKENS . ' WHERE tokenid = %d',
                $id
            )
        );

        $value = $row->fields['token_lookup'] ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * A migration whose only job is to expose the base class's guards.
     */
    private function probeMigration(): object
    {
        return new class ($this->app) extends \Pramnos\Database\Migration {
            public function up(): void
            {
            }

            /** @return array<int, array{value: string, rows: int}> */
            public function exposeDuplicateGroups(string $table, string $column): array
            {
                return $this->duplicateGroups($table, $column);
            }

            public function exposeDuplicateCount(string $table, string $column): int
            {
                return $this->duplicateCount($table, $column);
            }

            public function declineNow(string $reason): void
            {
                $this->decline($reason);
            }
        };
    }
}
