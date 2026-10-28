<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\Migrate;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationRunner;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Connection;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `migrate --pretend`: the SQL the pending migrations would run, shown and not run.
 *
 * WHAT: while the connection pretends, every statement that might change the database is
 *       recorded and answered with an empty result; reads that cannot change anything still
 *       run, so a migration that asks `hasTable()` gets the true answer.
 *
 * WHY:  the point of a preview is that it is safe to run against production. So the test
 *       that matters most is the negative one — after pretending, the table a migration
 *       "created" does not exist, the row it "inserted" is not there, and the ledger has no
 *       entry. The classification fails safe: a statement not recognisably a plain read is
 *       recorded, never run.
 *
 * Against a real database, because "nothing reached the server" can only be checked there.
 */
#[CoversClass(Database::class)]
#[CoversClass(MigrationRunner::class)]
#[CoversClass(Migrate::class)]
#[\PHPUnit\Framework\Attributes\Group('serial')]
class MigratePretendTest extends BaseTestCase
{
    private Database $db;

    /** A table of this test's own, which exists before anything pretends. */
    private string $existing = '';

    /** A table only a pretended statement creates — so it must never exist. */
    private string $created = '';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $this->db = Connection::fresh();
        if (!$this->db->connected) {
            $this->markTestSkipped('The database is not reachable.');
        }

        $suffix         = bin2hex(random_bytes(4));
        $this->existing = 'pretend_existing_' . $suffix;
        $this->created  = 'pretend_created_' . $suffix;
        $this->drop();
        $this->db->query('CREATE TABLE ' . $this->q($this->existing) . ' (' . $this->q('id', false) . ' INT NOT NULL, '
            . $this->q('name', false) . ' VARCHAR(64) NULL)');
        $this->db->query('INSERT INTO ' . $this->q($this->existing) . " VALUES (1, 'one')");
    }

    protected function tearDown(): void
    {
        $this->db->stopPretending();
        $this->drop();
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $reference = &Database::getInstance();
        $reference = null;

        parent::tearDown();
    }

    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /**
     * Writes are recorded and not run; a read still runs and answers from the real table.
     *
     * Covers the three entry points a migration reaches the server through: `query()` (raw
     * DDL), `execute()` (what the query builder uses) and `upsert()`.
     */
    public function testPretendingRecordsWritesAndStillRunsReads(): void
    {
        // Arrange
        $this->db->startPretending();

        // Act
        $this->db->query('CREATE TABLE ' . $this->q($this->created) . ' (' . $this->q('id', false) . ' INT)');
        $this->db->queryBuilder()->table($this->existing)->insert(['id' => 2, 'name' => 'two']);
        $this->db->queryBuilder()->table($this->existing)->where('id', 1)->update(['name' => 'changed']);
        $this->db->upsert($this->existing, ['id' => 3, 'name' => 'three'], ['id']);
        $count = (int) $this->db->queryBuilder()->table($this->existing)->count();
        $log   = $this->db->stopPretending();

        // Assert — the read ran against the table as it really is: one row, not two
        $this->assertSame(1, $count);
        $this->assertCount(4, $log, 'every write recorded, the read not: ' . json_encode($log));
        $this->assertStringStartsWith('CREATE TABLE', $log[0]);
        $this->assertStringStartsWith('INSERT', ltrim($log[1]));
        $this->assertStringContainsString('two', $log[1], 'the bindings are shown in the statement');
        $this->assertStringStartsWith('UPDATE', ltrim($log[2]));
        $this->assertStringStartsWith('UPSERT', $log[3]);
        // Nothing reached the server
        $this->assertFalse($this->db->schema()->hasTable($this->created));
        $row = $this->db->queryBuilder()->table($this->existing)->where('id', 1)->first();
        $this->assertSame('one', $row->fields['name']);
        $this->assertSame(1, (int) $this->db->queryBuilder()->table($this->existing)->count());
    }

    /**
     * A SELECT is run only when it cannot change anything; otherwise it is recorded.
     *
     * `SELECT nextval()` advances a sequence, `SELECT … FOR UPDATE` takes locks, a call to an
     * unknown function may write, `EXPLAIN ANALYZE` executes. A catalogue read with `COUNT()`
     * and a string literal holding a parenthesis is a read.
     */
    public function testOnlyAReadThatCannotWriteIsRun(): void
    {
        // Arrange
        $table = $this->q($this->existing);
        $this->db->startPretending();

        // Act
        $read = $this->db->query("SELECT COUNT(*) AS n FROM {$table} WHERE " . $this->q('name', false) . " <> 'not(a call)'");
        $this->db->query("SELECT some_function_that_may_write(1)");
        $this->db->query("SELECT * FROM {$table} FOR UPDATE");
        $this->db->query("EXPLAIN ANALYZE SELECT * FROM {$table}");
        $this->db->query('SET @pretend = 1');
        $log = $this->db->stopPretending();

        // Assert
        $this->assertSame(1, (int) $read->fields['n'], 'a plain read runs and answers');
        $this->assertCount(4, $log, 'the four that might change something are recorded: ' . json_encode($log));
    }

    /**
     * The edges of the two entry points: a statement prepared before pretending and executed
     * during it is recorded by its SQL; bindings too few for the placeholders are shown
     * beside the statement rather than lost; a `WITH` that only reads runs, one that deletes
     * does not.
     */
    public function testPreparedStatementsBindingsAndWithClausesAreClassified(): void
    {
        // Arrange — prepared while not pretending, so it exists on the server
        $table    = $this->q($this->existing);
        $prepared = $this->db->prepare("UPDATE {$table} SET " . $this->q('name', false) . ' = %s WHERE ' . $this->q('id', false) . ' = %d');
        $this->db->startPretending();

        // Act
        $this->db->execute($prepared, 'changed', 1);
        $this->db->execute("DELETE FROM {$table} WHERE " . $this->q('id', false) . ' = %d AND ' . $this->q('name', false) . ' = %s', 1);
        $reads = $this->db->query("WITH x AS (SELECT COUNT(*) AS n FROM {$table}) SELECT n FROM x");
        $this->db->query("WITH x AS (DELETE FROM {$table} RETURNING 1) SELECT COUNT(*) FROM x");
        $log = $this->db->stopPretending();

        // Assert
        $this->assertCount(3, $log, json_encode($log));
        $this->assertStringContainsString('UPDATE', $log[0], 'the prepared statement is shown by its SQL');
        $this->assertStringContainsString('bindings', $log[1], 'mismatched bindings are shown beside it');
        $this->assertStringStartsWith('WITH', $log[2]);
        $this->assertSame(1, (int) $reads->fields['n'], 'a WITH that only reads runs');
        $this->assertSame('one', $this->db->queryBuilder()->table($this->existing)->where('id', 1)->first()->fields['name']);
    }

    /**
     * The runner's pretend(): each pending migration's statements, nothing run, nothing in the
     * ledger — and a migration's own read of the database sees it as it really is.
     */
    public function testTheRunnerPretendsEveryPendingMigrationAndRecordsNothing(): void
    {
        // Arrange
        $creates = $this->migration(function (): void {
            $this->db->query('CREATE TABLE ' . $this->q($this->created) . ' (' . $this->q('id', false) . ' INT)');
            $this->db->queryBuilder()->table($this->existing)->insert(['id' => 9, 'name' => 'nine']);
        });
        $reads = $this->migration(function (): void {
            // Decides by reading: true for the table that exists
            if ($this->db->schema()->hasTable($this->existing)) {
                $this->db->query('DROP TABLE ' . $this->q($this->existing));
            }
        });
        $runner = new MigrationRunner($this->db);

        // Act
        $result = $runner->pretend([$creates, $reads]);

        // Assert
        $this->assertSame([], $result['failed']);
        $this->assertCount(2, $result['planned'][$creates->getSlug()]);
        $this->assertSame(['DROP TABLE ' . $this->q($this->existing)], $result['planned'][$reads->getSlug()]);
        // Nothing ran, and the ledger knows nothing of either
        $this->assertFalse($this->db->schema()->hasTable($this->created));
        $this->assertTrue($this->db->schema()->hasTable($this->existing));
        $this->assertSame(1, (int) $this->db->queryBuilder()->table($this->existing)->count());
        $this->assertCount(2, $runner->getPending([$creates, $reads]), 'both are still pending');
    }

    /**
     * A migration that throws is reported as one that would fail, with the statements it had
     * reached; the next migration is still pretended.
     */
    public function testAMigrationThatThrowsIsReportedWithWhatItReached(): void
    {
        // Arrange
        $fails = $this->migration(function (): void {
            $this->db->query('CREATE TABLE ' . $this->q($this->created) . ' (' . $this->q('id', false) . ' INT)');
            throw new \RuntimeException('cannot go on');
        });
        $after = $this->migration(function (): void {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->q($this->created));
        });

        // Act
        $result = (new MigrationRunner($this->db))->pretend([$fails, $after]);

        // Assert
        $this->assertSame('cannot go on', $result['failed'][$fails->getSlug()]);
        $this->assertCount(1, $result['planned'][$fails->getSlug()]);
        $this->assertCount(1, $result['planned'][$after->getSlug()]);
        $this->assertFalse($this->db->schema()->hasTable($this->created));
    }

    /**
     * `migrate --pretend` prints each migration and its SQL, says nothing was run, and runs
     * nothing; with nothing pending it says so.
     */
    public function testTheCommandPrintsTheSqlAndRunsNothing(): void
    {
        // Arrange — one migration file in a directory of its own
        $dir  = sys_get_temp_dir() . '/pf-pretend-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $slug = 'pretend_probe_' . bin2hex(random_bytes(3));
        $sql  = 'CREATE TABLE ' . $this->q($this->created) . ' (' . $this->q('id', false) . ' INT)';
        file_put_contents($dir . '/2099_01_01_000001_' . $slug . '.php', '<?php
            return new class($app) extends \Pramnos\Database\Migration {
                public string $scope = "app";
                public string $feature = "core";
                public function up(): void { $this->DB()->query(' . var_export($sql, true) . '); }
                public function down(): void {}
            };');
        $tester = $this->commandTester();

        try {
            // Act
            $status = $tester->execute(['--path' => $dir, '--pretend' => true]);
            $display = $tester->getDisplay();
            $empty = $this->commandTester();
            $emptyDir = $dir . '/none';
            mkdir($emptyDir);
            $empty->execute(['--path' => $emptyDir, '--pretend' => true]);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }

        // Assert
        $this->assertSame(0, $status, $display);
        $this->assertStringContainsString('Pretending: nothing below was run.', $display);
        $this->assertStringContainsString($slug, $display);
        $this->assertStringContainsString($sql . ';', $display);
        $this->assertFalse($this->db->schema()->hasTable($this->created), 'the command ran the migration');
        $this->assertStringContainsString('No migrations found', $empty->getDisplay());
    }

    /**
     * A migration whose up() runs the given closure, with a slug of its own per test.
     *
     * @param \Closure(): void $up
     */
    private function migration(\Closure $up): Migration
    {
        return new class ($this->applicationFor(), $up) extends Migration {
            public string $feature = 'core';
            public string $scope   = 'app';
            public $description    = 'pretend probe';
            private string $slug;

            public function __construct($application, private \Closure $body)
            {
                parent::__construct($application);
                $this->slug = 'pretend_probe_' . bin2hex(random_bytes(4));
            }

            public function up(): void
            {
                ($this->body)();
            }

            public function down(): void
            {
            }

            public function getSlug(): string
            {
                return $this->slug;
            }
        };
    }

    /** An application carrying this test's connection, for `Migration::DB()`. */
    private function applicationFor(): Application
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $application->database = $this->db;

        return $application;
    }

    /** The migrate command, inside a console application whose internal app has this connection. */
    private function commandTester(): CommandTester
    {
        $consoleApp = new class ('Test', '0.0') extends \Pramnos\Console\Application {
            public function __construct(string $name, string $version)
            {
                \Symfony\Component\Console\Application::__construct($name, $version);
                $this->internalApplication = new class extends \Pramnos\Application\Application {
                    /** @var \Pramnos\Database\Database|null */
                    public $database = null;
                    public function __construct()
                    {
                    }
                };
            }
        };
        $consoleApp->internalApplication->database        = $this->db;
        $consoleApp->internalApplication->applicationInfo = [];
        $consoleApp->add(new Migrate());

        return new CommandTester($consoleApp->find('migrate'));
    }

    /** A quoted identifier; a table name gets the connection's prefix. */
    private function q(string $name, bool $table = true): string
    {
        $name = $table ? $this->db->prefix . $name : $name;

        return $this->db->type === 'postgresql' ? '"' . $name . '"' : '`' . $name . '`';
    }

    /** Remove both tables, whatever state a test left them in. */
    private function drop(): void
    {
        foreach ([$this->existing, $this->created] as $table) {
            if ($table !== '') {
                try {
                    $this->db->query('DROP TABLE IF EXISTS ' . $this->q($table));
                } catch (\Throwable) {
                    // Nothing to remove.
                }
            }
        }
    }
}
