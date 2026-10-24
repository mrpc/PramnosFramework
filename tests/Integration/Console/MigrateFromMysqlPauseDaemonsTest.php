<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\DatabaseMigrateFromMysql;
use Pramnos\Console\Commands\DatabaseResumeDaemons;
use Pramnos\Console\DaemonControls;
use Pramnos\Database\Database;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/** The migrate command, with its own pools file and fast polling for a test. */
final class MigrateFromMysqlPauseDaemonsOver extends DatabaseMigrateFromMysql
{
    public function __construct(private \Pramnos\Application\Application $app, private string $poolsFile)
    {
        parent::__construct();
    }

    protected function application(): ?\Pramnos\Application\Application
    {
        return $this->app;
    }

    protected function poolsFilePath(): string
    {
        return $this->poolsFile;
    }

    public int $pollInterval = 0;

    protected function pollIntervalSeconds(): int
    {
        return $this->pollInterval;
    }

    protected function schedulerGraceSeconds(): int
    {
        return 0;
    }
}

/** The resume command, over a given application. */
final class ResumeDaemonsOver extends DatabaseResumeDaemons
{
    public function __construct(private \Pramnos\Application\Application $app)
    {
        parent::__construct();
    }

    protected function application(): ?\Pramnos\Application\Application
    {
        return $this->app;
    }
}

/**
 * `db:migrate-from-mysql --pause-daemons` and `db:resume-daemons` (FW-286).
 *
 * What has to hold: an enabled pool and the scheduler are stopped, marked `'migration'`, before
 * `operational` runs; the command waits for the orchestrator's own pools report to confirm the
 * drain, at a timestamp at or after the stop, not a stale one; a report that never arrives
 * refuses the phase rather than guessing; the control tables missing refuses outright; and
 * `db:resume-daemons` brings back only what was marked `'migration'`, leaving an operator's own
 * stop exactly as it was.
 */
#[CoversClass(DatabaseMigrateFromMysql::class)]
#[CoversClass(DatabaseResumeDaemons::class)]
final class MigrateFromMysqlPauseDaemonsTest extends TestCase
{
    private ?Database $mysql = null;

    private ?Database $postgres = null;

    private ?Database $source = null;

    private ?Database $target = null;

    private string $name = '';

    private string $poolsFile = '';

    private ?MigrateFromMysqlPauseDaemonsOver $command = null;

    protected function tearDown(): void
    {
        $this->source?->close();
        if (isset($this->target) && $this->target instanceof Database) {
            $this->target->schema()->dropTableIfExists(DaemonControls::POOLS_TABLE);
            $this->target->schema()->dropTableIfExists(DaemonControls::STOPPED_TABLE);
        }
        $this->target?->close();
        if ($this->name !== '') {
            $this->mysql?->query('DROP DATABASE IF EXISTS `' . $this->name . '`');
            $this->postgres?->query('DROP DATABASE IF EXISTS "' . $this->name . '" WITH (FORCE)');
        }
        if ($this->poolsFile !== '' && is_file($this->poolsFile)) {
            @unlink($this->poolsFile);
        }
    }

    private function server(string $type): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $type === 'mysql' ? 'db' : 'timescaledb';
        $db->port     = $type === 'mysql' ? 3306 : 5432;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                self::markTestSkipped($db->server . ' not reachable');
            }
        } catch (\Throwable $e) {
            self::markTestSkipped($db->server . ' not reachable: ' . $e->getMessage());
        }

        return $db;
    }

    /** A trivial MySQL source and a PostgreSQL target carrying the worker control tables. */
    private function databases(): CommandTester
    {
        $this->mysql    = $this->server('mysql');
        $this->postgres = $this->server('postgresql');
        $this->name     = 'pfpause_' . bin2hex(random_bytes(3));
        $this->mysql->query('CREATE DATABASE `' . $this->name . '` CHARACTER SET utf8mb4');
        $this->postgres->query('CREATE DATABASE "' . $this->name . '"');

        $this->source = $this->server('mysql');
        $this->source->query('USE `' . $this->name . '`');
        $this->source->database = $this->name;
        $this->source->query('CREATE TABLE widgets (id INT PRIMARY KEY) ENGINE=InnoDB');

        $this->target = $this->server('postgresql');
        $this->target->close();
        $this->target->database = $this->name;
        $this->target->connect(false);
        $this->target->query('CREATE TABLE widgets (id INT PRIMARY KEY)');

        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->target;
        require_once dirname(__DIR__, 3) . '/database/migrations/framework/core/2026_10_02_000003_create_worker_control_tables.php';
        (new \Pramnos\Framework\Migrations\Core\CreateWorkerControlTables($app))->up();

        $this->poolsFile = sys_get_temp_dir() . '/pfpause_pools_' . bin2hex(random_bytes(3)) . '.json';
        $console = new ConsoleApplication();
        $this->command = new MigrateFromMysqlPauseDaemonsOver($app, $this->poolsFile);
        $console->add($this->command);

        return new CommandTester($console->find('db:migrate-from-mysql'));
    }

    private function dsn(): string
    {
        return 'mysql://root:secret@db:3306/' . $this->name;
    }

    private function controls(): DaemonControls
    {
        return new DaemonControls($this->target);
    }

    private function writeDrainedReport(int $at): void
    {
        file_put_contents($this->poolsFile, json_encode([
            'at'    => $at,
            'pools' => [['name' => 'invoices', 'size' => 0]],
        ]));
    }

    /**
     * The pool and the scheduler are stopped before operational, marked 'migration'; a report
     * written after the stop, showing the pool drained, lets the phase proceed.
     */
    public function testPauseDaemonsStopsAndWaitsForTheOrchestratorsOwnReport(): void
    {
        // Arrange — a stale report already on disk when polling starts: a report written
        // before the pool was asked to stop proves nothing about what happened after, and a
        // naive "any report saying size 0" check would accept it on the very first poll.
        $tester = $this->databases();
        $this->controls()->savePool('invoices', [], 'alice');
        $this->writeDrainedReport(time() - 3600);
        $this->command->pollInterval = 1;

        // The fresh report lands only after the command has had to poll at least once —
        // a separate process, because the command under test is about to block on sleep().
        exec('(sleep 2; echo \'' . json_encode(['at' => time() + 60, 'pools' => [['name' => 'invoices', 'size' => 0]]])
            . '\' > ' . escapeshellarg($this->poolsFile) . ') > /dev/null 2>&1 &');

        // Act
        $started = microtime(true);
        $code    = $tester->execute(['--source' => $this->dsn(), '--phase' => 'operational', '--pause-daemons' => true, '--pause-timeout' => 10]);
        $elapsed = microtime(true) - $started;

        // Assert — the mutations below happen whether or not the stale report was wrongly
        // accepted, so the one proof that it was not is that this could not have returned on
        // the very first poll: the fresh report lands no sooner than the 2s sleep above.
        $this->assertGreaterThanOrEqual(1.5, $elapsed, 'returned too fast to have waited past the stale report');
        $this->assertSame(0, $code, $tester->getDisplay());
        $pool = $this->controls()->pools()['invoices'];
        $this->assertFalse($pool['enabled'], 'the pool was stopped');
        $this->assertSame('migration', $pool['updated_by']);
        $scheduler = $this->controls()->stoppedServices()['schedule'] ?? null;
        $this->assertNotNull($scheduler, 'the scheduler was stopped too');
        $this->assertSame('migration', $scheduler['stopped_by']);
    }

    /**
     * A report that never shows the pool drained refuses the phase — the migration never runs,
     * and the pool stays exactly as `--pause-daemons` left it (stopped, for the operator to
     * read on the Services screen).
     */
    public function testPauseDaemonsRefusesThePhaseWhenNothingEverDrains(): void
    {
        // Arrange — no report is ever written
        $tester = $this->databases();
        $this->controls()->savePool('invoices', [], 'alice');

        // Act
        $code = $tester->execute(['--source' => $this->dsn(), '--phase' => 'operational', '--pause-daemons' => true, '--pause-timeout' => 1]);

        // Assert
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Timed out', $tester->getDisplay());
        $this->assertFalse($this->controls()->pools()['invoices']['enabled'], 'still stopped, not resumed on failure');
    }

    /** No control tables at all: refused outright, operational never runs. */
    public function testPauseDaemonsRefusesWhenTheControlTablesAreMissing(): void
    {
        // Arrange
        $tester = $this->databases();
        $this->target->schema()->dropTableIfExists(DaemonControls::POOLS_TABLE);
        $this->target->schema()->dropTableIfExists(DaemonControls::STOPPED_TABLE);

        // Act
        $code = $tester->execute(['--source' => $this->dsn(), '--phase' => 'operational', '--pause-daemons' => true]);

        // Assert
        $this->assertSame(1, $code);
        $this->assertStringContainsString('neither is here', $tester->getDisplay());
    }

    /**
     * `db:resume-daemons` brings back only what `'migration'` stopped — a pool an operator
     * stopped themselves, before or during the run, is left exactly as it was.
     */
    public function testResumeDaemonsTouchesOnlyWhatMigrationPaused(): void
    {
        // Arrange
        $this->databases();
        $controls = $this->controls();
        $controls->savePool('invoices', [], 'alice');
        $controls->setPoolEnabled('invoices', false, 'migration');
        $controls->savePool('exports', [], 'alice');
        $controls->setPoolEnabled('exports', false, 'alice');
        $controls->stopService('schedule', 'migration');

        $app     = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->target;
        $console = new ConsoleApplication();
        $console->add(new ResumeDaemonsOver($app));
        $tester  = new CommandTester($console->find('db:resume-daemons'));

        // Act
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(0, $code, $tester->getDisplay());
        $this->assertTrue($controls->pools()['invoices']['enabled'], 'resumed: migration stopped it');
        $this->assertFalse($controls->pools()['exports']['enabled'], 'left alone: alice stopped it, not migration');
        $this->assertArrayNotHasKey('schedule', $controls->stoppedServices(), 'the scheduler was resumed');
    }
}
