<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationRunner;

/** A migration that records which of its runs actually executed. */
class ReplayProbeMigration extends Migration
{
    /** @var array<string,int> slug => how many times up() ran, across the process */
    public static array $ran = [];

    public function __construct(Application $app, private string $slug, string $version = '')
    {
        parent::__construct($app);
        $this->version = $version;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function up(): void
    {
        self::$ran[$this->slug] = (self::$ran[$this->slug] ?? 0) + 1;
    }

    public function down(): void
    {
    }
}

/**
 * Moving the ledger replayed 91 already-applied migrations against live production data.
 *
 * Both systems write to `schemaversion` and key it differently: the legacy path stores a
 * migration's `$version` (`0.088`), the runner stores its slug (`migration0088`). Nothing
 * carried the history across, so the runner found none of `migration0011` … `migration0125`
 * and did exactly what it is supposed to do with migrations that are not recorded — 91 of
 * them, in about fifty seconds, every one recorded as a success.
 *
 * **Most were harmless by accident.** Re-running old DDL mostly fails on arrival and
 * `addQuery()` is tolerant, so the ledger is full of `23 of 80 statements failed`. The
 * dangerous ones are the *idempotent* ones, because they succeed: one re-added a compression
 * policy that a later migration had removed with measurements behind it, and the replay
 * stopped before reaching the later one. For a day the recorded state and the real state
 * disagreed with nothing to indicate it.
 *
 * Two things are asserted here, because either alone leaves the hole open:
 *
 * - a version-keyed history is **carried across** rather than replayed, without anybody
 *   having to know a command exists;
 * - and a whole history is **refused** on a database that plainly is not new, which is the
 *   net for every case there is nothing to carry across from.
 */
#[CoversClass(MigrationRunner::class)]
class MigrationHistoryIsNotReplayedTest extends TestCase
{
    private Database $db;

    private Application $app;

    private string $historyTable = 'mhr_schemaversion';

    private string $probeTable = 'mhr_existing_data';

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $this->db = new Database();
        $this->db->type     = 'mysql';
        $this->db->server   = 'db';
        $this->db->user     = 'root';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 3306;

        try {
            $this->db->connect(true);
        } catch (\Exception) {
            $this->markTestSkipped('MySQL container not reachable (db:3306)');
        }

        $this->cleanUp();
        ReplayProbeMigration::$ran = [];

        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        $this->app = $app;
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `' . $this->historyTable . '`');
        $this->db->query('DROP TABLE IF EXISTS `' . $this->probeTable . '`');
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->db, $this->historyTable);
    }

    /**
     * @param int    $count   How many migrations
     * @param string $version A version prefix, or '' for slug-only migrations
     * @return list<ReplayProbeMigration>
     */
    private function migrations(int $count, string $version = ''): array
    {
        $migrations = [];
        for ($i = 1; $i <= $count; $i++) {
            $number = str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $migrations[] = new ReplayProbeMigration(
                $this->app,
                'migration' . $number,
                $version === '' ? '' : $version . '.' . $number
            );
        }

        return $migrations;
    }

    /** Write a legacy version-keyed ledger, as the old path left one. */
    private function legacyLedger(array $migrations): void
    {
        $this->runner()->ensureHistoryTable();

        foreach ($migrations as $migration) {
            $this->db->query(
                $this->db->prepareQuery(
                    'INSERT INTO `' . $this->historyTable . '` (`key`, `when`) VALUES (%s, %s)',
                    (string) $migration->version,
                    date('Y-m-d H:i:s')
                )
            );
        }
    }

    /** A table that is not the ledger, so the database is plainly not new. */
    private function populatedDatabase(): void
    {
        $this->db->query(
            'CREATE TABLE `' . $this->probeTable . '` (id INT PRIMARY KEY)'
        );
    }

    /**
     * A version-keyed history is adopted, not replayed.
     *
     * The reported incident in one assertion: 12 migrations whose versions the ledger
     * already holds must be recorded under their slugs and **not executed**.
     *
     * Twelve rather than two, so the run also crosses the refusal threshold — this proves
     * the two mechanisms compose in the right order. Adoption first means the ledger is no
     * longer empty when the guard looks, so the installation the guard was written for is
     * exactly the one it does not fire on.
     */
    public function testAVersionKeyedHistoryIsAdoptedRatherThanReplayed(): void
    {
        // Arrange — a populated database whose history is all version-keyed
        $migrations = $this->migrations(12, '0');
        $this->legacyLedger($migrations);
        $this->populatedDatabase();

        // Act
        $result = $this->runner()->run($migrations);

        // Assert — nothing executed
        $this->assertSame(
            [],
            ReplayProbeMigration::$ran,
            'an already-applied migration was executed again'
        );
        $this->assertSame([], $result['ran']);
        $this->assertSame([], $result['failed']);

        // and every slug is now recorded, so the next run has nothing pending either
        $this->assertSame([], $this->runner()->getPending($migrations));
    }

    /**
     * A whole history is refused on a database that already has tables.
     *
     * The net. A runner that finds *zero* of many recorded is not looking at a fresh
     * database — a fresh database has no tables either, and the one this happened on had
     * 407 GB of them.
     */
    public function testAWholeHistoryIsRefusedOnAPopulatedDatabase(): void
    {
        // Arrange — nothing recorded, and something else in the database
        $migrations = $this->migrations(MigrationRunner::WHOLE_HISTORY);
        $this->runner()->ensureHistoryTable();
        $this->populatedDatabase();

        // Act & Assert
        try {
            $this->runner()->run($migrations);
            $this->fail('the runner replayed a whole history against a populated database');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to run', $exception->getMessage());
            // The message has to carry the way out, or it is an obstacle rather than a guard
            $this->assertStringContainsString('adopt-baseline', $exception->getMessage());
            $this->assertStringContainsString('migration_cutoff', $exception->getMessage());
            $this->assertStringContainsString('adopt-legacy', $exception->getMessage());
        }

        $this->assertSame([], ReplayProbeMigration::$ran, 'it ran something before refusing');
    }

    /**
     * And it runs when the operator says the database really is a baseline.
     *
     * A guard with no way past it is a guard people route around — by deleting rows, or by
     * pinning an old version, either of which is worse than the thing it prevents.
     */
    public function testAdoptBaselineRunsItAnyway(): void
    {
        // Arrange
        $migrations = $this->migrations(MigrationRunner::WHOLE_HISTORY);
        $this->runner()->ensureHistoryTable();
        $this->populatedDatabase();

        // Act
        $result = $this->runner()->run(
            $migrations,
            [MigrationRunner::OPTION_ADOPT_BASELINE => true]
        );

        // Assert
        $this->assertCount(MigrationRunner::WHOLE_HISTORY, $result['ran']);
        $this->assertCount(MigrationRunner::WHOLE_HISTORY, ReplayProbeMigration::$ran);
    }

    /**
     * A new project is not refused: no tables, so nothing to re-apply.
     *
     * The case the guard must never touch. A fresh installation has an empty ledger by
     * definition and a hundred-odd framework migrations to run.
     */
    public function testAFreshDatabaseRunsTheWholeHistory(): void
    {
        // Arrange — a genuinely empty database, because the suite's own has 39 tables and
        // is therefore the *populated* case. Testing "fresh" against it would assert the
        // opposite of its name, which is how a guard ends up looking correct and being
        // inverted.
        $fresh = 'pramnos_mhr_fresh';
        $this->db->query('DROP DATABASE IF EXISTS `' . $fresh . '`');
        $this->db->query('CREATE DATABASE `' . $fresh . '`');

        $connection = new Database();
        $connection->type     = 'mysql';
        $connection->server   = 'db';
        $connection->user     = 'root';
        $connection->password = 'secret';
        $connection->database = $fresh;
        $connection->port     = 3306;
        $connection->connect(true);

        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $connection;

        $migrations = [];
        for ($i = 1; $i <= MigrationRunner::WHOLE_HISTORY; $i++) {
            $migrations[] = new ReplayProbeMigration(
                $app,
                'fresh' . str_pad((string) $i, 4, '0', STR_PAD_LEFT)
            );
        }

        try {
            $runner = new MigrationRunner($connection, $this->historyTable);
            $runner->ensureHistoryTable();

            // Act
            $result = $runner->run($migrations);

            // Assert
            $this->assertCount(
                MigrationRunner::WHOLE_HISTORY,
                $result['ran'],
                'a new installation must be able to run its whole history'
            );
        } finally {
            $this->db->query('DROP DATABASE IF EXISTS `' . $fresh . '`');
        }
    }

    /**
     * And a handful of migrations is an upgrade, not a history.
     *
     * The threshold is what keeps the guard from being something people learn to bypass:
     * an empty ledger with two migrations behind it is a new project or a test harness that
     * truncated the table, and refusing those teaches the wrong lesson about the message.
     */
    public function testAFewMigrationsAreNotAHistory(): void
    {
        // Arrange — below the threshold, on a populated database
        $migrations = $this->migrations(MigrationRunner::WHOLE_HISTORY - 1);
        $this->runner()->ensureHistoryTable();
        $this->populatedDatabase();

        // Act
        $result = $this->runner()->run($migrations);

        // Assert
        $this->assertCount(MigrationRunner::WHOLE_HISTORY - 1, $result['ran']);
    }

    /**
     * A database with a recorded history is never refused, however much is pending.
     *
     * The common case, and the one a false positive would break: an installation that has
     * been migrating for years and has fifty new migrations to apply.
     */
    public function testARecordedHistoryIsNeverRefused(): void
    {
        // Arrange — one slug recorded, then a whole history of new ones
        $first = $this->migrations(1);
        $this->runner()->run($first);

        $this->populatedDatabase();
        $later = $this->migrations(MigrationRunner::WHOLE_HISTORY + 5);

        // Act
        $result = $this->runner()->run($later);

        // Assert — the first is already recorded, the rest run
        $this->assertCount(MigrationRunner::WHOLE_HISTORY + 4, $result['ran']);
    }
}
