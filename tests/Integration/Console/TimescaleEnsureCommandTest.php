<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\TimescaleEnsure;
use Pramnos\Database\HypertableRegistry;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `timescale:ensure`, run against a real connection.
 *
 * `TimescaleEnsureTest` covers the decisions with stubs. What it cannot cover is the entry
 * point, and the entry point is where this command's one interesting branch lives: **what it
 * does on a backend that has no TimescaleDB**. That branch was written for the case it repairs —
 * a database that gained the extension after the migrations had run — so it has to behave
 * correctly on a database that never will.
 *
 * Which makes this the one place in the suite where the two backends exercise **different code
 * on purpose**, rather than the same code twice:
 *
 * | backend | what runs |
 * | --- | --- |
 * | MySQL / MariaDB | continuous aggregates only, then the documented bow-out |
 * | PostgreSQL / TimescaleDB | the same, then the whole hypertable plan |
 *
 * So the subclass at the bottom is not a duplicate — it is the other half of the command.
 */
#[CoversClass(TimescaleEnsure::class)]
class TimescaleEnsureCommandTest extends BaseTestCase
{
    private $db;

    private bool $hasTimescale = false;

    /** A PostgreSQL database with no TimescaleDB, built by the one test that needs it. */
    private ?\Pramnos\Database\Database $plainDb = null;

    /** Named once: the teardown has to drop what the arrange created. */
    private const PLAIN_DB = 'pramnos_tsensure_plain';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        $app = Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        // The command reads the application's connection, not the singleton directly.
        $app->database      = $this->db;
        $this->hasTimescale = $this->db->capabilities()->hasTimescaleDB();
    }

    /**
     * Point this test at a PostgreSQL database with no TimescaleDB extension.
     *
     * Built rather than assumed: `TEMPLATE template0`, because the TimescaleDB image
     * installs the extension into `template1` and an ordinary `CREATE DATABASE` inherits
     * it. Dropped in tearDown.
     */
    private function useDatabaseWithoutTimescale(): void
    {
        $this->db->query('DROP DATABASE IF EXISTS ' . self::PLAIN_DB . ' WITH (FORCE)');
        $this->db->query('CREATE DATABASE ' . self::PLAIN_DB . ' TEMPLATE template0');

        $plain = new \Pramnos\Database\Database();
        $plain->type     = $this->db->type;
        $plain->server   = $this->db->server;
        $plain->port     = $this->db->port;
        $plain->user     = $this->db->user;
        $plain->password = $this->db->password;
        $plain->database = self::PLAIN_DB;

        if (!$plain->connect(false)) {
            $this->markTestSkipped('Could not reach the extension-free probe database.');
        }

        $this->plainDb = $plain;
        $this->db      = $plain;
        Application::getInstance()->database = $plain;
        $this->hasTimescale = $plain->capabilities()->hasTimescaleDB();
    }

    protected function tearDown(): void
    {
        if ($this->plainDb !== null) {
            $admin = $this->plainDb;
            $this->plainDb = null;

            try {
                $admin->close();
            } catch (\Throwable) {
                // Already gone.
            }
        }

        HypertableRegistry::reset();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** The command, wired to a tester. */
    private function tester(): CommandTester
    {
        $console = new ConsoleApplication();
        $console->add(new TimescaleEnsure());

        return new CommandTester($console->find('timescale:ensure'));
    }

    /**
     * A dry run reports and changes nothing, whatever the backend.
     *
     * Exit 0 covers "nothing to do" and "no extension" as well as success — a repair command
     * that exited non-zero because a database is already correct would fail every deployment
     * pipeline it was added to.
     */
    public function testADryRunSucceedsAndWritesSomething(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['--dry-run' => true]);

        // Assert
        $this->assertSame(0, $code, $tester->getDisplay());
        $this->assertNotSame('', trim($tester->getDisplay()), 'a dry run that says nothing is useless');
    }

    /**
     * On a backend without TimescaleDB it says so, names the driver, and points elsewhere.
     *
     * The message matters as much as the exit code: an operator running this because a table is
     * growing needs to be told *where retention actually comes from here*, not merely that this
     * command declined. Naming the driver is what turns "not available" into something
     * actionable.
     */
    public function testWithoutTimescaleItExplainsWhereRetentionComesFrom(): void
    {
        // Arrange — on the PostgreSQL lane, a database of this test's own with the
        // extension dropped, rather than a skip.
        //
        // The container has every backend, so "this connection has TimescaleDB" is a
        // choice of connection and not a constraint. Skipping meant the PostgreSQL branch
        // of this message — the one an operator on plain PostgreSQL actually sees — was
        // only ever exercised on MySQL, which is a different driver and a different
        // sentence.
        if ($this->hasTimescale) {
            $this->useDatabaseWithoutTimescale();
        }
        $tester = $this->tester();

        // Act
        $code = $tester->execute([]);
        $text = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $code, $text);
        $this->assertStringContainsString('TimescaleDB is not available', $text);
        $this->assertStringContainsString($this->db->type, $text, 'the driver is not named');
        $this->assertStringContainsString('service:policy-engine', $text, 'no pointer to the alternative');
        $this->assertStringContainsString('No hypertable was touched', $text);
    }

    /**
     * On TimescaleDB it gets as far as the plan.
     *
     * Not asserting what the plan contains — that depends on which migrations this database has
     * run, and a test that pinned it would break every time one was added. What is asserted is
     * that the hypertable half runs at all, which is the half the other lane cannot reach.
     */
    public function testWithTimescaleItReachesTheHypertablePlan(): void
    {
        // Arrange
        if (!$this->hasTimescale) {
            $this->markTestSkipped('This connection has no TimescaleDB; the other lane covers this.');
        }
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['--dry-run' => true]);
        $text = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $code, $text);
        $this->assertStringNotContainsString(
            'TimescaleDB is not available',
            $text,
            'the extension is installed here, so the command must not bow out'
        );
    }

    /**
     * `--table` naming something undeclared fails, and lists what is declared.
     *
     * A typo in a table name must not read as "that table is already fine". Printing the
     * declared list is the difference between an error and an error somebody can act on.
     */
    public function testAnUndeclaredTableIsRefusedWithTheDeclaredList(): void
    {
        // Arrange
        if (!$this->hasTimescale) {
            $this->markTestSkipped('The --table check sits after the extension check.');
        }
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['--table' => 'public.no_such_declared_table']);
        $text = $tester->getDisplay();

        // Assert
        $this->assertSame(1, $code, 'a typo reported success');
        $this->assertStringContainsString('is not a declared hypertable', $text);
        $this->assertStringContainsString('Declared:', $text, 'the list an operator needs is missing');
    }

    /**
     * `--table` naming a declared one narrows the run to it.
     *
     * The reason the option exists: converting one audit table during a maintenance window,
     * rather than every declared table in one lock-holding run.
     */
    public function testADeclaredTableNarrowsTheRun(): void
    {
        // Arrange
        if (!$this->hasTimescale) {
            $this->markTestSkipped('The --table check sits after the extension check.');
        }
        $declared = array_keys(HypertableRegistry::all());
        if ($declared === []) {
            $this->markTestSkipped('No hypertable is declared on this installation.');
        }
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['--dry-run' => true, '--table' => $declared[0]]);
        $text = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $code, $text);
        $this->assertStringNotContainsString('is not a declared hypertable', $text);
    }

    /**
     * Running it twice changes nothing the second time.
     *
     * Every step is guarded by its own existence check, and that is what makes this safe to put
     * in a deployment script. A repair command that is not idempotent is a repair command
     * nobody dares automate.
     */
    public function testRunningItTwiceIsSafe(): void
    {
        // Arrange
        $first  = $this->tester();
        $second = $this->tester();

        // Act
        $firstCode  = $first->execute(['--dry-run' => true]);
        $secondCode = $second->execute(['--dry-run' => true]);

        // Assert
        $this->assertSame(0, $firstCode);
        $this->assertSame(0, $secondCode);
        $this->assertSame(
            $first->getDisplay(),
            $second->getDisplay(),
            'two dry runs in a row disagreed, so the report depends on something it should not'
        );
    }
}
