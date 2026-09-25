<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationRunner;

/** A migration that does all of its work through `addQuery()`, as many real ones do. */
class QueueingProbeMigration extends Migration
{
    public function __construct(Application $app, private string $slug, private string $table)
    {
        parent::__construct($app);
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function up(): void
    {
        $this->addQuery(
            'CREATE TABLE IF NOT EXISTS `' . $this->table . '` '
            . '(`id` INT NOT NULL, `label` VARCHAR(64) NULL)'
        );
        $this->addQuery('ALTER TABLE `' . $this->table . '` MODIFY `label` VARCHAR(500) NULL');
    }

    public function down(): void
    {
        $this->addQuery('DROP TABLE IF EXISTS `' . $this->table . '`');
    }
}

/** Queues one statement the database will refuse, and one it will accept. */
class HalfRejectedProbeMigration extends Migration
{
    public function __construct(Application $app, private string $slug, private string $table)
    {
        parent::__construct($app);
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function up(): void
    {
        $this->addQuery('ALTER TABLE `' . $this->table . '` ADD COLUMN `no_such_table_column` INT');
        $this->addQuery(
            'CREATE TABLE IF NOT EXISTS `' . $this->table . '` (`id` INT NOT NULL)'
        );
    }

    public function down(): void
    {
    }
}

/**
 * A migration that queues its statements must actually run them.
 *
 * `addQuery()` queues. `executeQueries()` empties the queue, it is `protected`, and **no
 * framework code called it** — so a migration that queued two `ALTER TABLE`s and returned
 * was recorded as **Ran** and changed nothing. That is not hypothetical: it happened on
 * development, on the test database and on production at once, with all three ledgers
 * saying the migration had worked, and it was found only by measuring the schema afterwards.
 *
 * **The recovery is what made it expensive.** A no-op that is *recorded* cannot be re-run,
 * so correcting the migration file helps nobody who already has the row — the fix has to be
 * a second migration. One mistyped line costs two migrations and a paragraph explaining why
 * there are two.
 *
 * The apparatus for reporting a partial failure was already built and unreachable:
 * `executeQueries()` records every rejected statement so the runner can write "ran, with N
 * rejected", the runner already reads `failedStatementSummary()`, and there is a result code
 * for it. The care had gone into the half nothing called.
 *
 * Asserted against the real database rather than by counting calls, because "the runner
 * invoked a method" is not the claim — the claim is that the column is wider afterwards.
 */
#[CoversClass(MigrationRunner::class)]
#[CoversClass(Migration::class)]
class QueuedQueriesActuallyRunTest extends TestCase
{
    private Database $db;

    private Application $app;

    private string $historyTable = 'qqar_schemaversion';

    private string $table = 'qqar_probe';

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
        $this->db->query('DROP TABLE IF EXISTS `' . $this->table . '`');
    }

    /** The declared type of a column, straight from the server. */
    private function columnType(string $column): ?string
    {
        $result = $this->db->query(
            "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $this->table . "' "
            . "AND COLUMN_NAME = '" . $column . "'"
        );

        if (!$result || (int) ($result->numRows ?? 0) === 0) {
            return null;
        }

        // MySQL answers information_schema in UPPERCASE.
        return strtolower((string) ($result->fields['t'] ?? $result->fields['T'] ?? ''));
    }

    /**
     * The whole finding: the schema changes, and the ledger is telling the truth.
     */
    public function testQueuedStatementsReachTheDatabase(): void
    {
        // Arrange
        $migration = new QueueingProbeMigration($this->app, 'qqar_widen', $this->table);

        // Act
        $result = (new MigrationRunner($this->db, $this->historyTable))->run([$migration]);

        // Assert — the ledger says it ran…
        $this->assertSame(['qqar_widen'], $result['ran']);

        // …and the database agrees, which is the half that was missing.
        $this->assertSame(
            'varchar(500)',
            $this->columnType('label'),
            'the migration reported Ran and changed nothing'
        );
    }

    /**
     * A rejected statement is recorded, and the ones behind it still run.
     *
     * Two claims that only hold together. The tolerance is deliberate and load-bearing — a
     * re-run whose `ADD COLUMN` has already been applied must not abandon the eleven
     * statements behind it — and losing the fact that it happened is not. Both halves were
     * unreachable while nothing emptied the queue.
     */
    public function testARejectedStatementIsReportedAndDoesNotStopTheRest(): void
    {
        // Arrange — the first statement alters a table that does not exist yet
        $migration = new HalfRejectedProbeMigration($this->app, 'qqar_half', $this->table);

        // Act
        $result = (new MigrationRunner($this->db, $this->historyTable))->run([$migration]);

        // Assert — recorded as run, and the warning names the rejection
        $this->assertSame(['qqar_half'], $result['ran']);
        $this->assertArrayHasKey('qqar_half', $result['warned'] ?? []);

        // The statement behind the rejected one still ran.
        $this->assertNotNull(
            $this->columnType('id'),
            'a rejected statement abandoned the ones behind it'
        );
    }

    /**
     * A migration that empties the queue itself is not run twice.
     *
     * `executeQueries()` clears the queue, so the runner's call finds nothing left. Without
     * that, every existing migration that calls it explicitly would have its statements
     * issued a second time — which is harmless for `IF NOT EXISTS` and not for an `INSERT`.
     */
    public function testAMigrationThatRunsItsOwnQueueIsNotRunTwice(): void
    {
        // Arrange — a migration that inserts a row and flushes the queue itself
        $this->db->query('CREATE TABLE `' . $this->table . '` (`id` INT NOT NULL)');

        $migration = new class ($this->app, $this->table) extends Migration {
            public function __construct(Application $app, private string $table)
            {
                parent::__construct($app);
            }

            public function getSlug(): string
            {
                return 'qqar_selfflush';
            }

            public function up(): void
            {
                $this->addQuery('INSERT INTO `' . $this->table . '` (`id`) VALUES (1)');
                $this->executeQueries();
            }

            public function down(): void
            {
            }
        };

        // Act
        (new MigrationRunner($this->db, $this->historyTable))->run([$migration]);

        // Assert — one row, not two
        $rows = $this->db->query('SELECT COUNT(*) AS c FROM `' . $this->table . '`');
        $this->assertSame(1, (int) ($rows->fields['c'] ?? 0), 'the queue was run twice');
    }
}
