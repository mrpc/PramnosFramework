<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\Inspector\DatabaseInspector;

/**
 * The inspector's PostgreSQL half, against PostgreSQL.
 *
 * Most of this class only exists on PostgreSQL — `pg_stat_activity`, `pg_stat_user_indexes`,
 * `pg_stat_statements`, `pg_terminate_backend` — and the rest of the suite runs on MySQL, where
 * every one of those methods returns its empty answer on the first line. Untested is not the
 * same as unreachable, and the difference is what this class is for.
 *
 * The DevPanel's database tab is made of these, so a reader that quietly answers nothing is a
 * section that has silently emptied rather than a section that is broken — the harder failure to
 * notice.
 */
#[CoversClass(DatabaseInspector::class)]
class DatabaseInspectorPostgreSQLTest extends TestCase
{
    private ?Database $db = null;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $settings = Settings::getSetting('postgresql');

        if (!$settings) {
            $this->markTestSkipped('No PostgreSQL settings in the test fixtures.');
        }

        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = $settings->hostname;
        $db->user     = $settings->user;
        $db->password = $settings->password;
        $db->database = $settings->database;
        $db->port     = $settings->port ?? 5432;

        if (!$db->connect(false)) {
            $this->markTestSkipped('PostgreSQL is not reachable.');
        }

        $this->db = $db;
    }

    /**
     * The process list describes the connections, and excludes this one.
     *
     * `pg_backend_pid()` is filtered out because a screen that could show — or kill — the
     * request rendering it would answer with a broken pipe.
     */
    public function testTheProcessListDescribesTheConnections(): void
    {
        // Act
        $processes = (new DatabaseInspector($this->db))->getProcessList();

        // Assert
        $this->assertIsArray($processes);

        $mine = (int) ($this->db->query('SELECT pg_backend_pid() AS pid')->fields['pid'] ?? 0);

        foreach ($processes as $process) {
            $this->assertArrayHasKey('state', $process);
            $this->assertArrayHasKey('query', $process);
            $this->assertNotSame($mine, (int) $process['pid'],
                'the connection rendering the screen is not on it');
        }
    }

    /**
     * Table sizes come back with the four numbers the screen shows, and no chunks.
     *
     * A hypertable's storage lives in `_timescaledb_internal` as one table per chunk, named
     * after nothing a person recognises. Listed, they crowd out the tables somebody was looking
     * for and double-count storage the hypertable already reports.
     */
    public function testTableSizesExcludeTimescaleChunks(): void
    {
        // Act
        $tables = (new DatabaseInspector($this->db))->getTableSizes();

        // Assert
        $this->assertIsArray($tables);

        foreach ($tables as $table) {
            $this->assertArrayHasKey('total_bytes', $table);
            $this->assertArrayHasKey('data_bytes', $table);
            $this->assertArrayHasKey('index_bytes', $table);
            $this->assertArrayHasKey('row_estimate', $table);
            $this->assertStringNotContainsString('_hyper_', (string) $table['table_name'],
                'a chunk is not a table anybody put anything in');
        }
    }

    /**
     * Index usage answers with both halves, and excludes primary keys from "unused".
     *
     * They are not there to be scanned — they are there to make a duplicate impossible — so
     * listing them as dead weight is telling somebody to drop the thing holding their data
     * together.
     */
    public function testIndexUsageAnswersWithBothHalves(): void
    {
        // Act
        $usage = (new DatabaseInspector($this->db))->getIndexUsage();

        // Assert
        $this->assertArrayHasKey('unused', $usage);
        $this->assertArrayHasKey('scanned', $usage);

        foreach ($usage['unused'] as $index) {
            $this->assertSame(0, (int) $index['scans']);
            $this->assertStringNotContainsString('_pkey', (string) $index['index_name'],
                'a primary key is not dead weight');
        }
    }

    /**
     * `pg_stat_statements` reports its own absence rather than an empty list.
     *
     * "Not installed" and "no slow queries" are different facts, and one answer for both tells
     * somebody their database is fine when it has never been asked.
     */
    public function testTheStatementsExtensionReportsItsOwnAbsence(): void
    {
        // Act
        $statements = (new DatabaseInspector($this->db))->getSlowStatements();

        // Assert
        $this->assertArrayHasKey('available', $statements);
        $this->assertIsBool($statements['available']);
        $this->assertIsArray($statements['rows']);
    }

    /**
     * Replication answers with nothing on a standalone instance, not with an error.
     */
    public function testReplicationAnswersOnAStandaloneInstance(): void
    {
        // Act & Assert
        $this->assertIsArray((new DatabaseInspector($this->db))->getReplicationStatus());
    }

    /**
     * The public views come back with a name and a definition.
     */
    public function testThePublicViewsCarryTheirDefinitions(): void
    {
        // Act
        $views = (new DatabaseInspector($this->db))->getPublicViews();

        // Assert
        $this->assertIsArray($views);

        foreach ($views as $view) {
            $this->assertArrayHasKey('view_name', $view);
            $this->assertArrayHasKey('view_definition', $view);
        }
    }

    /**
     * Killing this connection is refused.
     *
     * `pg_backend_pid()` is excluded from the list anyway, but the guard is what makes that a
     * decision rather than an accident — a screen that could end the request rendering it would
     * answer with a broken pipe.
     */
    public function testKillingThisConnectionIsRefused(): void
    {
        // Arrange
        $inspector = new DatabaseInspector($this->db);
        $mine = (int) ($this->db->query('SELECT pg_backend_pid() AS pid')->fields['pid'] ?? 0);

        // Act & Assert
        $this->assertFalse($inspector->killProcess($mine));
        $this->assertTrue($this->db->connected, 'and the connection survived being asked');
    }

    /**
     * A pid below one is refused before anything is asked of the database.
     *
     * `?pid=` with nothing after it, or a form posted without the hidden field. `KILL 0` on
     * MySQL is a real statement with a real error; refusing here is cheaper and clearer.
     */
    public function testAnImpossiblePidIsRefused(): void
    {
        // Act & Assert
        $this->assertFalse((new DatabaseInspector($this->db))->killProcess(0));
        $this->assertFalse((new DatabaseInspector($this->db))->killProcess(-1));
    }

    /**
     * A pid nothing is using answers false rather than raising.
     *
     * The ordinary case: the list was rendered a minute ago and the backend has finished since.
     * The button has to say "could not" rather than produce a 500.
     */
    public function testAPidThatIsNotThereAnswersFalse(): void
    {
        // Act & Assert
        $this->assertFalse((new DatabaseInspector($this->db))->killProcess(2147483600));
    }
}
