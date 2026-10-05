<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Console\DaemonControls;
use Pramnos\Database\Database;

/**
 * The services screen's controls, written to and read from the real tables.
 *
 * What an operator decides has to survive the supervisor's next cycle, which a sentinel file
 * could not do, so these are read back from the database rather than from the object. Run
 * against MySQL (prefixed tables) and TimescaleDB (the `pramnos` schema), because the same
 * logical names resolve to different physical tables on each.
 */
#[CoversClass(DaemonControls::class)]
class DaemonControlsTest extends TestCase
{
    private Database $db;

    private DaemonControls $controls;

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'       => ['mysql', 'db', 3306],
            'timescaledb' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    /**
     * Connects to one database and builds the two tables from their migration.
     */
    private function connect(string $type, string $host, int $port): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $this->db           = new Database();
        $this->db->type     = $type;
        $this->db->server   = $host;
        $this->db->port     = $port;
        $this->db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $this->db->password = 'secret';
        $this->db->database = TEST_DATABASE;

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        require_once dirname(__DIR__, 3) . '/database/migrations/framework/core/2026_10_02_000003_create_worker_control_tables.php';
        $migration = new \Pramnos\Framework\Migrations\Core\CreateWorkerControlTables($app);
        $migration->down();
        $migration->up();

        $this->controls = new DaemonControls($this->db);
    }

    /**
     * Drops the tables.
     */
    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->connected) {
            $this->db->schema()->dropTableIfExists(DaemonControls::POOLS_TABLE);
            $this->db->schema()->dropTableIfExists(DaemonControls::STOPPED_TABLE);
        }
    }

    /**
     * A saved pool reads back with its limits, and what was left blank reads as null.
     *
     * Null is "as declared" for a code pool, so blank fields must not become zeros.
     */
    #[DataProvider('databases')]
    public function testASavedPoolReadsBackWithBlanksAsNull(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);

        // Act
        $this->controls->savePool('passes', [
            'types' => ' pass_unit , mail ', 'floor' => '1', 'ceiling' => 8,
            'grow_above' => 50, 'shrink_below' => '', 'load_percent' => 70,
        ], 'alice');
        $pool = $this->controls->pools()['passes'];

        // Assert
        $this->assertSame('pass_unit,mail', $pool['types']);
        $this->assertSame([1, 8, 50, null, 70, null], [
            $pool['floor'], $pool['ceiling'], $pool['grow_above'], $pool['shrink_below'], $pool['load_percent'], $pool['cooldown'],
        ]);
        $this->assertTrue($pool['enabled']);
        $this->assertSame('alice', $pool['updated_by']);
        $this->assertGreaterThan(time() - 10, $pool['updated_at']);

        // As queuePool() configuration: only what the row sets, the percentage as a ratio
        $this->assertSame(
            ['types' => 'pass_unit,mail', 'floor' => 1, 'ceiling' => 8, 'grow_above' => 50, 'load_ceiling' => 0.7],
            DaemonControls::asPoolConfig($pool)
        );
    }

    /**
     * Saving again replaces the row rather than adding one.
     */
    #[DataProvider('databases')]
    public function testSavingAgainReplacesThePool(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $this->controls->savePool('passes', ['ceiling' => 4]);

        // Act
        $this->controls->savePool('passes', ['ceiling' => 6, 'enabled' => false]);

        // Assert
        $pools = $this->controls->pools();
        $this->assertCount(1, $pools);
        $this->assertSame(6, $pools['passes']['ceiling']);
        $this->assertFalse($pools['passes']['enabled']);
    }

    /**
     * Stopping a pool that has no row yet records only the flag, so its declared limits stand.
     */
    #[DataProvider('databases')]
    public function testStoppingACodePoolRecordsOnlyTheFlag(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);

        // Act
        $this->controls->setPoolEnabled('mail', false, 'bob');

        // Assert — every limit is null, so nothing about the declaration is overridden
        $pool = $this->controls->pools()['mail'];
        $this->assertFalse($pool['enabled']);
        $this->assertSame([], DaemonControls::asPoolConfig($pool));

        // Act & Assert — started again, still nothing overridden
        $this->controls->setPoolEnabled('mail', true);
        $this->assertTrue($this->controls->pools()['mail']['enabled']);
    }

    /**
     * Deleting a pool row removes it.
     */
    #[DataProvider('databases')]
    public function testDeletingAPoolRemovesIt(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $this->controls->savePool('passes', ['ceiling' => 4]);

        // Act
        $this->controls->deletePool('passes');

        // Assert
        $this->assertSame([], $this->controls->pools());
    }

    /**
     * A stopped service stays listed, with who stopped it, until it is started.
     */
    #[DataProvider('databases')]
    public function testAStoppedServiceIsListedUntilStarted(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);

        // Act
        $this->controls->stopService('schedule', 'alice');
        $this->controls->stopService('schedule', 'alice'); // twice is still one row

        // Assert
        $stopped = $this->controls->stoppedServices();
        $this->assertSame(['schedule'], array_keys($stopped));
        $this->assertSame('alice', $stopped['schedule']['stopped_by']);

        // Act & Assert
        $this->controls->startService('schedule');
        $this->assertSame([], $this->controls->stoppedServices());
    }

    /**
     * Values outside their ranges, and names that could become arguments, are refused.
     *
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function invalidPools(): array
    {
        return [
            'bad name'           => ['passes; rm -rf /', [], 'pool name'],
            'ceiling over 50'    => ['p', ['ceiling' => 51], 'ceiling must be'],
            'fraction'           => ['p', ['floor' => '1.5'], 'floor must be'],
            'ceiling below floor'=> ['p', ['floor' => 4, 'ceiling' => 2], 'below floor'],
            'no threshold gap'   => ['p', ['grow_above' => 10, 'shrink_below' => 10], 'less than grow above'],
            'type with a space'  => ['p', ['types' => 'pass unit'], 'not a task type'],
            'load over 100'      => ['p', ['load_percent' => 101], 'load percent'],
        ];
    }

    /**
     * Each refusal names the field, in words an operator can act on, and writes nothing.
     *
     * @param array<string, mixed> $fields
     */
    #[DataProvider('invalidPools')]
    public function testInvalidPoolsAreRefused(string $name, array $fields, string $message): void
    {
        // Arrange
        $this->connect('mysql', 'db', 3306);

        // Act
        $error = null;
        try {
            $this->controls->savePool($name, $fields);
        } catch (\InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }

        // Assert
        $this->assertNotNull($error, 'the pool was accepted');
        $this->assertStringContainsString($message, $error);
        $this->assertSame([], $this->controls->pools());
    }

    /**
     * With no tables — an installation that has not migrated — there is nothing, not an error.
     */
    public function testWithoutTheTablesThereAreNoControls(): void
    {
        // Arrange
        $this->connect('mysql', 'db', 3306);
        $this->db->schema()->dropTableIfExists(DaemonControls::POOLS_TABLE);
        $this->db->schema()->dropTableIfExists(DaemonControls::STOPPED_TABLE);

        // Act & Assert
        $this->assertSame([], $this->controls->pools());
        $this->assertSame([], $this->controls->stoppedServices());
    }

    /**
     * An empty service id is refused rather than stored as a stop for nothing.
     */
    public function testAnEmptyServiceIdIsRefused(): void
    {
        // Arrange
        $this->connect('mysql', 'db', 3306);

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        $this->controls->stopService('  ');
    }
}
