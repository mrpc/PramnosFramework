<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\SpoolDrain;
use Pramnos\Database\WriteSpool;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `spool:drain` — 50 of 65 statements never executed, on a command the scheduler runs **every
 * minute**.
 *
 * What matters here is not the writing, which `WriteSpoolTest` covers. It is the **exit code**,
 * because that is what a scheduler records, and the distinction the code makes is one somebody
 * paid for already:
 *
 *   - a row **kept for the next run** is the retry budget being spent — the spool working — and
 *     is reported as a comment with exit **0**. Reported as a failure, the scheduler recorded a
 *     failure every minute until the budget ran out, and one deployment read *"3 errors in 200
 *     seconds"* as three tasks failing when it was one task failing three times;
 *   - a row **parked** is data set aside with no further attempt, so somebody has to look: exit
 *     **1**.
 *
 * And nothing buffered is exit 0 and **silent**, because a line a minute saying "nothing" is a
 * log nobody reads — which is also what makes the one line that does appear worth seeing.
 *
 * Runs on every backend: {@see SpoolDrainCommandPostgreSQLTest} re-runs it against
 * PostgreSQL/TimescaleDB. The failing write here is a table that does not exist, and what a
 * driver *does* with that is exactly what this framework has found differing before.
 */
#[CoversClass(SpoolDrain::class)]
class SpoolDrainCommandTest extends BaseTestCase
{
    private string $dir = '';

    private mixed $savedDirectory = null;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        $app = Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $db        = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }
        if (!$db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }
        $app->database = $db;

        // A spool of this test's own, so a drain here cannot write another test's rows — or the
        // installation's, which is what `var/spool` holds.
        $this->dir = sys_get_temp_dir() . '/pf-spool-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0777, true);

        /*
         * Reset **before** pointing the spool here, not after.
         *
         * `WriteSpool::reset()` clears the driver, the attempt budget *and the directory* — so
         * setting the directory first and resetting second put it back to `var/spool`, and this
         * test drained the installation's own buffer. It found 648 undrained `tokenactions` rows
         * from other tests and tried to write them, which is the hazard this private directory
         * exists to avoid, arriving through the order of two lines.
         */
        $property = new \ReflectionProperty(WriteSpool::class, 'directory');
        $this->savedDirectory = $property->getValue();

        WriteSpool::reset();
        $property->setValue(null, $this->dir);
        WriteSpool::setDriver(WriteSpool::DRIVER_FILE);
        WriteSpool::setMaxAttempts(null);
    }

    protected function tearDown(): void
    {
        WriteSpool::reset();
        WriteSpool::setDriver(null);
        WriteSpool::setMaxAttempts(null);

        (new \ReflectionProperty(WriteSpool::class, 'directory'))
            ->setValue(null, $this->savedDirectory);

        foreach ((array) glob($this->dir . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    private function tester(): CommandTester
    {
        $console = new ConsoleApplication();
        $console->add(new SpoolDrain());

        return new CommandTester($console->find('spool:drain'));
    }

    // ── Nothing buffered ──────────────────────────────────────────────────────

    /**
     * An empty spool is a success, and says nothing.
     *
     * This runs every minute. A line per minute saying "nothing" is a log nobody reads — and a
     * log nobody reads is where the one line that mattered goes unnoticed.
     */
    public function testAnEmptySpoolIsSilentAndSucceeds(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(0, $code);
        $this->assertSame('', trim($tester->getDisplay()));
    }

    /** Unless somebody asked to be told. */
    public function testAnEmptySpoolSaysSoWhenAsked(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $tester->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

        // Assert
        $this->assertStringContainsString('Nothing was buffered', $tester->getDisplay());
    }

    // ── Status ────────────────────────────────────────────────────────────────

    /**
     * `--status` reports the driver and the depth, and writes nothing.
     *
     * The reason it exists is draining before a migration that will change the table — so it has
     * to be safe to run at the moment somebody is least able to afford a surprise write.
     */
    public function testStatusReportsWithoutWriting(): void
    {
        // Arrange
        WriteSpool::append('#PREFIX#no_such_spool_table', ['id' => 1]);
        $before = WriteSpool::pending();
        $this->assertGreaterThan(0, $before, 'precondition: something is buffered');

        // Act
        $tester = $this->tester();
        $code   = $tester->execute(['--status' => true]);
        $text   = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Driver:', $text);
        $this->assertStringContainsString('Waiting:', $text);
        $this->assertSame($before, WriteSpool::pending(), '--status wrote something');
    }

    // ── A row that cannot be written ──────────────────────────────────────────

    /**
     * A row kept for the next run is **exit 0**, reported as a comment.
     *
     * The distinction this command exists to get right. The retry budget being spent is the spool
     * working; reporting it as a task failure made the scheduler record one every minute until
     * the budget ran out, and *"3 errors in 200 seconds"* read as three tasks failing when it was
     * one task failing three times.
     */
    public function testAKeptRowIsNotAFailure(): void
    {
        // Arrange — a table that does not exist, so the write cannot succeed, with room to retry.
        WriteSpool::setMaxAttempts(0);
        WriteSpool::append('#PREFIX#no_such_spool_table', ['id' => 1]);

        // Act
        $tester = $this->tester();
        $code   = $tester->execute([]);
        $text   = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $code, 'a row inside its retry budget was reported as a failure');
        $this->assertStringContainsString('kept for the next run', $text);
    }

    /**
     * A row parked after its last attempt is **exit 1**.
     *
     * Data has been set aside and no further attempt will be made, so somebody has to look. This
     * is the signal a scheduler should raise on, and the reason the kept case must not.
     */
    public function testAParkedRowIsAFailure(): void
    {
        // Arrange — one attempt allowed, and a write that cannot succeed.
        WriteSpool::append('#PREFIX#no_such_spool_table', ['id' => 1]);

        // Act — the first drain spends the only attempt, so the row is parked.
        $tester = $this->tester();
        $code   = $tester->execute(['--max-attempts' => '1']);
        $text   = $tester->getDisplay();

        // Assert
        $this->assertSame(1, $code, 'a parked row was reported as a success');
        $this->assertStringContainsString('set aside', $text);
        $this->assertGreaterThan(0, WriteSpool::parked(), 'nothing was actually parked');
    }

    /**
     * `--status` then names the parked rows, and where to find them.
     *
     * A count with no pointer is a number somebody has to come back and ask about.
     */
    public function testStatusNamesParkedRowsAndWhereTheyAre(): void
    {
        // Arrange
        WriteSpool::append('#PREFIX#no_such_spool_table', ['id' => 1]);
        $this->tester()->execute(['--max-attempts' => '1']);
        $this->assertGreaterThan(0, WriteSpool::parked(), 'precondition: a row is parked');

        // Act
        $tester = $this->tester();
        $tester->execute(['--status' => true]);
        $text = $tester->getDisplay();

        // Assert
        $this->assertStringContainsString('Parked:', $text);
        $this->assertStringContainsString('spool.failed', $text);
    }

    /**
     * `--max-attempts` reaches the spool rather than being read and dropped.
     *
     * An option that parses and does nothing is worse than no option: the operator believes they
     * have changed the retry budget, and the behaviour they are trying to change continues.
     */
    public function testMaxAttemptsReachesTheSpool(): void
    {
        // Arrange
        $this->assertNotSame(7, WriteSpool::maxAttempts(), 'pick a value that is not the default');

        // Act
        $this->tester()->execute(['--max-attempts' => '7']);

        // Assert
        $this->assertSame(7, WriteSpool::maxAttempts());
    }

    // ── A row that can be written ─────────────────────────────────────────────

    /**
     * A row that writes is counted, per table, and the run reports how long it took.
     *
     * The per-table breakdown is what makes the line useful: "400 rows written" on an
     * installation spooling three kinds of row does not say which one is busy.
     */
    public function testWrittenRowsAreReportedPerTable(): void
    {
        // Arrange — a real table this test owns.
        $db    = Factory::getDatabase();
        $table = 'pf_spool_probe';
        $db->query('DROP TABLE IF EXISTS ' . $db->schema()->quoteTable($table));
        $db->schema()->createTable($table, function ($t) {
            $t->increments('id');
            $t->integer('value')->default(0);
        });

        try {
            WriteSpool::append($table, ['value' => 41]);
            WriteSpool::append($table, ['value' => 42]);

            // Act
            $tester = $this->tester();
            $code   = $tester->execute([]);
            $text   = $tester->getDisplay();

            // Assert
            $this->assertSame(0, $code, $text);
            $this->assertStringContainsString($table, $text, 'the table is not named');
            $this->assertStringContainsString('2 row(s) written', $text);
            $this->assertStringContainsString('ms.', $text, 'the run does not say how long it took');
            $this->assertSame(0, WriteSpool::pending(), 'the spool was not drained');
        } finally {
            $db->query('DROP TABLE IF EXISTS ' . $db->schema()->quoteTable($table));
        }
    }
}
