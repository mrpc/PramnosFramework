<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationRunner;

/**
 * The version an application reports is the version its ledger says it reached.
 *
 * WHAT: `MigrationRunner::latestVersion()` — and `Application::databaseVersion()` over it —
 *       answer with the highest `$version` among the successfully applied migrations of a
 *       scope, and with null when there is none.
 * WHY:  the ledger was keyed by slug and stored no version at all, so an installation that
 *       had run three more migrations still printed the number sitting in
 *       `applicationInfo['database_version']` — a settings value that is only correct while
 *       somebody keeps editing it by hand, and is therefore correct until the first deploy
 *       that forgets to. The observable symptom is a footer and an API field reporting a
 *       schema version several migrations behind the schema.
 *
 * The shape of this test is the shape of the failure: two migrations that ran, one that did
 * not, and a bookkeeping row that is not a migration at all — because every one of those is
 * a row in the same table, and the wrong answer is whichever of them the query forgets to
 * exclude.
 */
class DatabaseVersionFromLedgerTest extends TestCase
{
    protected Database $db;
    protected Application $app;

    protected const HISTORY = 'schemaversion_dbversiontest';

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $this->db = $this->connection();

        // Only the history table name is doubled: `databaseVersion()` itself has to be the
        // real method, since it is what the filing asks about.
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMigrationHistoryTable'])
            ->getMock();
        $app->method('getMigrationHistoryTable')->willReturn(self::HISTORY);
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
        foreach ([self::HISTORY, 'dbversion_probe_one', 'dbversion_probe_two'] as $table) {
            try {
                $this->db->query(
                    'DROP TABLE IF EXISTS ' . $table
                    . ($this->db->type === 'postgresql' ? ' CASCADE' : '')
                );
            } catch (\Throwable) {
                // Best-effort teardown.
            }
        }
    }

    /**
     * The connection every case runs against.
     *
     * A seam rather than an inline block, because the history table is written by two
     * dialect-specific statements — an `ON CONFLICT` upsert and an `ON DUPLICATE KEY` one —
     * and a column added to one of them and not the other is invisible until an installation
     * on the other engine reports the wrong version. The MySQL subclass reruns every case
     * here against the other statement.
     */
    protected function connection(): Database
    {
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = 'pramnos_test';
        $db->port     = 5432;
        $db->schema   = 'public';

        if (!$db->connect(false)) {
            $this->markTestSkipped('PostgreSQL/TimescaleDB container not reachable');
        }

        return $db;
    }

    /** The identifier quote this connection's dialect uses. */
    private function q(string $identifier): string
    {
        $quote = $this->db->type === 'postgresql' ? '"' : '`';

        return $quote . $identifier . $quote;
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->db, self::HISTORY);
    }

    /** A migration that declares a version and creates one table. */
    private function versioned(string $version, string $slug, string $table): Migration
    {
        return new class ($this->app, $version, $slug, $table) extends Migration {
            public function __construct(
                $app,
                public $version,
                private string $slugName,
                private string $table
            ) {
                parent::__construct($app);
            }

            public function getSlug(): string
            {
                return $this->slugName;
            }

            public function up(): void
            {
                $this->schema()->createTable($this->table, fn($t) => $t->increments('id'));
            }
        };
    }

    /** A migration that declares a high version and then throws. */
    private function failing(string $version, string $slug): Migration
    {
        return new class ($this->app, $version, $slug) extends Migration {
            // Not wrapped: the point is the history row it leaves behind, and an aborted
            // PostgreSQL transaction would only add noise to that.
            public bool $transactional = false;

            public function __construct($app, public $version, private string $slugName)
            {
                parent::__construct($app);
            }

            public function getSlug(): string
            {
                return $this->slugName;
            }

            public function up(): void
            {
                throw new \RuntimeException('this migration does not apply');
            }
        };
    }

    /** The bookkeeping row `Application::runAutoMigrations()` writes; it is not a migration. */
    private function insertFingerprintRow(): void
    {
        $this->db->query(
            $this->db->prepareQuery(
                'INSERT INTO ' . $this->q(self::HISTORY) . ' (' . $this->q('key') . ', '
                . $this->q('scope') . ') VALUES (%s, %s)',
                '__fw_auto_3_2026_09_28_000003',
                'app'
            )
        );
    }

    // ── The reported case ────────────────────────────────────────────────────

    /**
     * Two applied versions, one that failed and one row that is not a migration.
     *
     * This is the filing's own scenario, and each of its four rows excludes a different way
     * of getting the answer wrong: the second migration proves the newest one wins, the
     * failing one proves a recorded row is not an applied row, and the fingerprint proves a
     * versionless row cannot become the answer by being the last one read.
     *
     * The two versions are `0.099` and `0.100` deliberately. Sorted as strings, `0.100` is
     * *below* `0.099`, so a query that ordered by the column would report the database as
     * having gone backwards on the hundredth migration — the one point in an application's
     * life where this is guaranteed to happen and nobody is expecting it.
     */
    public function testTheHighestSuccessfullyAppliedVersionIsReported(): void
    {
        // Arrange
        $runner = $this->runner();
        $runner->run([
            $this->versioned('0.099', 'migration0099', 'dbversion_probe_one'),
            $this->versioned('0.100', 'migration0100', 'dbversion_probe_two'),
        ]);
        $failed = $runner->run([$this->failing('0.200', 'migration0200')]);
        $this->insertFingerprintRow();

        // Act
        $reported = $this->app->databaseVersion();

        // Assert
        $this->assertArrayHasKey(
            'migration0200',
            $failed['failed'],
            'the fixture must actually have failed, or this case proves nothing'
        );
        $this->assertSame('0.100', $reported);
    }

    /**
     * A ledger with nothing versioned in it answers null, not an invented number.
     *
     * Every modern migration is a timestamped file with no `$version`, so this is the common
     * case rather than an edge one: an application that never used the legacy ledger has a
     * full history table and no version anywhere in it. Returning `'0'` or the first slug
     * would put a wrong number in a footer, which is worse than an empty one because it
     * looks like an answer.
     */
    public function testAnUnversionedHistoryReportsNothing(): void
    {
        // Arrange — a migration that ran, declaring no version, under a slug-shaped key
        $this->runner()->run([
            $this->versioned('', 'migration_without_a_version', 'dbversion_probe_one'),
        ]);
        $this->insertFingerprintRow();

        // Act
        $reported = $this->app->databaseVersion();

        // Assert — neither source has anything, and neither key was mistaken for a version
        $this->assertNull($reported);
    }

    /**
     * A ledger that only ever knew the legacy convention still answers.
     *
     * `Application::runMigration()` writes the version as the row's **key** and nothing
     * else — `INSERT INTO schemaversion (key) VALUES ('0.147')`. An installation that has
     * never run this runner has a ledger made entirely of those, and the version column it
     * has just grown is empty in every row. Answering null there would be reporting nothing
     * about a database that has recorded its version on every upgrade for years.
     *
     * Nothing has to be run or adopted first: this is a read of what is already there.
     */
    public function testTheLegacyConventionIsReadFromTheKey(): void
    {
        // Arrange — three legacy rows and no version column values at all
        $this->runner()->ensureHistoryTable();
        foreach (['0.099', '0.147', '0.100'] as $legacy) {
            $this->db->query(
                $this->db->prepareQuery(
                    'INSERT INTO ' . $this->q(self::HISTORY) . ' (' . $this->q('key')
                    . ') VALUES (%s)',
                    $legacy
                )
            );
        }

        // Act
        $reported = $this->app->databaseVersion();

        // Assert — and `0.147` beats `0.100`, which beats `0.099`
        $this->assertSame('0.147', $reported);
    }

    /**
     * A slug is never mistaken for a version.
     *
     * This is the whole risk of reading the key: the column holds two different things and
     * nothing labels which. The test is on characters, not meaning — every slug this runner
     * writes carries a letter or an underscore, and the fingerprint row carries both — so
     * the two sets cannot overlap. A bare year is excluded too: `2026` is a number, not a
     * version, and requiring a dot is what keeps it out.
     */
    public function testNothingThatIsNotAVersionIsReadAsOne(): void
    {
        // Arrange — every shape of key the ledger actually contains, none a version
        $this->runner()->ensureHistoryTable();
        $notVersions = [
            '2026_09_28_000001_add_something',   // a modern slug
            'migration0148',                     // a legacy class slug
            '__fw_auto_3_2026_09_28_000003',     // the fingerprint row
            '2026',                              // digits, but no dot
            'v1.2',                              // a tag, not a recorded version
        ];
        foreach ($notVersions as $key) {
            $this->db->query(
                $this->db->prepareQuery(
                    'INSERT INTO ' . $this->q(self::HISTORY) . ' (' . $this->q('key')
                    . ') VALUES (%s)',
                    $key
                )
            );
        }
        $this->assertNotEmpty($notVersions, 'the sweep found nothing to check');

        // Act
        $reported = $this->app->databaseVersion();

        // Assert
        $this->assertNull($reported);
    }

    /**
     * The two sources are one answer, not one falling back to the other.
     *
     * A ledger can hold both at once — this is what an installation mid-migration to the
     * runner looks like — and the question is what version the database is on, not which
     * convention recorded it. Reading the column and stopping there would report `0.010`
     * on a database the legacy path had taken to `0.200`.
     */
    public function testTheHighestOfBothConventionsWins(): void
    {
        // Arrange — one row from each convention, the legacy one higher
        $this->runner()->run([
            $this->versioned('0.010', 'migration0010', 'dbversion_probe_one'),
        ]);
        $this->db->query(
            $this->db->prepareQuery(
                'INSERT INTO ' . $this->q(self::HISTORY) . ' (' . $this->q('key')
                . ') VALUES (%s)',
                '0.200'
            )
        );

        // Act & Assert
        $this->assertSame('0.200', $this->app->databaseVersion());
    }

    /**
     * The framework's versions are not the application's.
     *
     * Both scopes share one table, and the framework ships its own migrations on its own
     * numbering. Folding them together would let a framework upgrade appear to advance the
     * application's schema version, which is the number the application prints.
     */
    public function testTheScopesAreCountedSeparately(): void
    {
        // Arrange
        $app = $this->versioned('0.010', 'migration0010', 'dbversion_probe_one');
        $fw  = $this->versioned('9.900', 'framework_migration', 'dbversion_probe_two');
        $fw->scope = 'framework';
        $this->runner()->run([$app, $fw]);

        // Act
        $applicationVersion = $this->app->databaseVersion();
        $frameworkVersion   = $this->runner()->latestVersion('framework');

        // Assert — the framework's 9.900 does not leak into the application's answer
        $this->assertSame('0.010', $applicationVersion);
        $this->assertSame('9.900', $frameworkVersion);
    }

    /**
     * An adopted legacy row carries its version too.
     *
     * `adoptLegacyVersions()` is how an installation that migrated through the old path gets
     * its history into this ledger, and that installation is precisely the one with versions
     * to report. If adoption recorded the slug without the version, upgrading to the runner
     * would blank the number the application had been printing for years.
     */
    public function testAnAdoptedLegacyRowKeepsItsVersion(): void
    {
        // Arrange — the legacy path recorded '0.147' years ago, keyed by version
        $runner = $this->runner();
        $runner->ensureHistoryTable();
        $this->db->query(
            $this->db->prepareQuery(
                'INSERT INTO ' . $this->q(self::HISTORY) . ' (' . $this->q('key')
                . ') VALUES (%s)',
                '0.147'
            )
        );

        // Act
        $adopted = $runner->adoptLegacyVersions([
            $this->versioned('0.147', 'migration0147', 'dbversion_probe_one'),
        ]);

        // Assert
        $this->assertSame(['migration0147' => '0.147'], $adopted);
        $this->assertSame('0.147', $this->app->databaseVersion());
    }

    /**
     * A row recorded before the column existed has its version filled in.
     *
     * This is the case the change would otherwise miss entirely, and it is the one the
     * report came from: an installation's migrations have all run, so `getPending()`
     * excludes every one of them and `recordHistory()` never touches those rows again.
     * Adding the column without this leaves the whole existing history at null and
     * `databaseVersion()` answering null — on precisely the installations that have a
     * version worth reporting — until the next new migration happens to run.
     *
     * The migration object is the only place the number exists: the row holds a slug, and
     * nothing anywhere maps one to the other.
     */
    public function testAHistoryRowFromBeforeTheColumnIsFilledInOnTheNextRun(): void
    {
        // Arrange — a migration that ran, with its version then cleared, which is exactly
        // the row the previous version of this code wrote
        $migrations = [$this->versioned('0.150', 'migration0150', 'dbversion_probe_one')];
        $this->runner()->run($migrations);
        $this->db->query(
            'UPDATE ' . $this->q(self::HISTORY) . ' SET ' . $this->q('version') . ' = NULL'
        );
        $this->assertNull(
            $this->app->databaseVersion(),
            'the fixture must start from the broken state, or this case proves nothing'
        );

        // Act — the same migrations, with nothing pending
        $this->runner()->run($migrations);

        // Assert
        $this->assertSame('0.150', $this->app->databaseVersion());
    }

    /**
     * The backfill does not re-run the migration it is filling in for.
     *
     * The row says the work was done. Touching one of its columns must not become a reason
     * to do the work again — which is the failure this whole subsystem is most afraid of,
     * and the reason `--dry-run` exists on the command next door.
     */
    public function testTheBackfillRecordsAndDoesNotExecute(): void
    {
        // Arrange — recorded as run, version cleared, and the table it creates dropped so
        // that a second execution would be visible
        $migrations = [$this->versioned('0.151', 'migration0151', 'dbversion_probe_one')];
        $this->runner()->run($migrations);
        $this->db->query(
            'UPDATE ' . $this->q(self::HISTORY) . ' SET ' . $this->q('version') . ' = NULL'
        );
        $this->db->query('DROP TABLE ' . $this->q('dbversion_probe_one'));

        // Act
        $result = $this->runner()->run($migrations);

        // Assert — filled in, and its up() was never reached
        $this->assertSame('0.151', $this->app->databaseVersion());
        $this->assertSame([], $result['ran'], 'nothing was pending, so nothing may have run');
        $this->assertFalse(
            $this->db->schema()->hasTable('dbversion_probe_one'),
            'the backfill executed the migration it was only supposed to annotate'
        );
    }

    /**
     * A table that predates the column grows one.
     *
     * The history table is older than most of what it now records: installations have it
     * with the original three columns and nothing else. `ensureHistoryTable()` adds what is
     * missing rather than asking for a migration, and this is the case that proves the new
     * column is on that list — on both engines, since MySQL has no
     * `ADD COLUMN IF NOT EXISTS` and gets a hand-written introspection branch instead.
     *
     * Without it the ledger writes would fail on an unknown column on every existing
     * installation, which is the loudest possible version of this bug and still worth a test,
     * because the two branches are independent and only one of them is read in review.
     */
    public function testATableFromBeforeTheColumnExistedIsUpgraded(): void
    {
        // Arrange — the three-column table an old installation has
        $this->db->query(
            'CREATE TABLE ' . $this->q(self::HISTORY) . ' ('
            . $this->q('when') . ' TIMESTAMP NULL, '
            . $this->q('key') . ' VARCHAR(255) NOT NULL PRIMARY KEY, '
            . $this->q('extra') . ' VARCHAR(255) NULL)'
        );

        // Act — the runner brings it up to date, then records against it
        $this->runner()->run([
            $this->versioned('0.042', 'migration0042', 'dbversion_probe_one'),
        ]);

        // Assert — the column was added and the row landed in it
        $this->assertSame('0.042', $this->app->databaseVersion());
    }

    /**
     * A ledger that is not there yet answers null instead of throwing.
     *
     * `databaseVersion()` is called from footers and API envelopes. On an installation whose
     * history table has not been created — the first request after a deploy, before any
     * migration has run — the question has no answer, and a page that cannot render because
     * it could not print a version number is a worse outcome than a missing number.
     */
    public function testAMissingHistoryTableReportsNothing(): void
    {
        // Arrange — the table is dropped in setUp() and nothing has recreated it
        $this->assertFalse(
            $this->db->schema()->hasTable(self::HISTORY),
            'the fixture must start without the table, or this case proves nothing'
        );

        // Act & Assert
        $this->assertNull($this->app->databaseVersion());
    }

    /**
     * No connection is not a failure.
     *
     * `databaseVersion()` is called from footers and API envelopes — places where throwing
     * costs a page to report a number nobody blocked on. An installation whose history table
     * does not exist yet gets null and renders.
     */
    public function testAnApplicationWithNoDatabaseReportsNothing(): void
    {
        // Arrange
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMigrationHistoryTable'])
            ->getMock();
        $app->method('getMigrationHistoryTable')->willReturn(self::HISTORY);
        $app->database = null;

        // Act & Assert
        $this->assertNull($app->databaseVersion());
    }
}
