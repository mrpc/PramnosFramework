<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Inspector\DatabaseInspector;

/**
 * The inspector's MySQL answers, which are mostly "not here".
 *
 * Three of these methods read `pg_stat_*` and have no MySQL equivalent worth pretending about,
 * so they return their empty answer on the first line. That line is shipped code nobody had
 * run: an early return that answered the *wrong* empty shape — a list where a caller expects
 * `['unused' => …, 'scanned' => …]` — is a fatal on the screen that reads it, and it would only
 * ever appear on a MySQL installation.
 */
#[CoversClass(DatabaseInspector::class)]
class DatabaseInspectorMySQLTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $this->db = \Pramnos\Framework\Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect();
        }

        if ($this->db->type !== 'mysql') {
            $this->markTestSkipped('This asserts the MySQL branches.');
        }
    }

    /**
     * Index usage answers with both keys, empty.
     *
     * The shape matters more than the emptiness: the screen reads `$usage['unused']` and
     * `$usage['scanned']` without checking, because an inspector that answered a bare list on
     * one driver and a map on the other would be a fatal on exactly one kind of installation.
     */
    public function testIndexUsageAnswersWithBothKeys(): void
    {
        // Act
        $usage = (new DatabaseInspector($this->db))->getIndexUsage();

        // Assert
        $this->assertSame(['unused' => [], 'scanned' => []], $usage);
    }

    /**
     * The statements reader reports itself unavailable rather than empty.
     *
     * `pg_stat_statements` has no MySQL equivalent, and "not available" is a different answer
     * from "no slow queries" — one of them tells somebody to install something, the other tells
     * them their database is fine.
     */
    public function testTheStatementsReaderReportsItselfUnavailable(): void
    {
        // Act
        $statements = (new DatabaseInspector($this->db))->getSlowStatements();

        // Assert
        $this->assertSame(['available' => false, 'rows' => []], $statements);
    }

    /**
     * Replication is empty on MySQL rather than an error.
     */
    public function testReplicationIsEmptyOnMysql(): void
    {
        // Act & Assert
        $this->assertSame([], (new DatabaseInspector($this->db))->getReplicationStatus());
    }

    /**
     * And so are the public views — the concept is PostgreSQL's.
     */
    public function testThePublicViewsAreEmptyOnMysql(): void
    {
        // Act & Assert
        $this->assertSame([], (new DatabaseInspector($this->db))->getPublicViews());
    }

    /**
     * The process list is `SHOW PROCESSLIST`, and it describes this connection among others.
     */
    public function testTheProcessListIsTheMysqlOne(): void
    {
        // Act
        $processes = (new DatabaseInspector($this->db))->getProcessList();

        // Assert
        $this->assertNotSame([], $processes, 'this connection is at least one of them');
        $this->assertArrayHasKey('Id', $processes[0]);
        $this->assertArrayHasKey('Command', $processes[0]);
    }

    /**
     * Killing a connection that is not there answers false rather than raising.
     *
     * `KILL 2147483600` is a real statement with a real error on MySQL, and the ordinary case
     * for this button is a backend that has finished since the list was rendered.
     */
    public function testKillingAConnectionThatIsNotThereAnswersFalse(): void
    {
        // Act & Assert
        $this->assertFalse((new DatabaseInspector($this->db))->killProcess(2147483600));
    }

    /**
     * And a pid below one is refused before anything is asked of the database.
     */
    public function testAnImpossiblePidIsRefused(): void
    {
        // Act & Assert
        $this->assertFalse((new DatabaseInspector($this->db))->killProcess(0));
    }

    /**
     * Table sizes come back with the four numbers the screen shows.
     */
    public function testTableSizesCarryTheFourNumbers(): void
    {
        // Act
        $tables = (new DatabaseInspector($this->db))->getTableSizes();

        // Assert
        $this->assertNotSame([], $tables);
        $this->assertArrayHasKey('total_bytes', $tables[0]);
        $this->assertArrayHasKey('data_bytes', $tables[0]);
        $this->assertArrayHasKey('index_bytes', $tables[0]);
        $this->assertArrayHasKey('row_estimate', $tables[0]);
    }
}
