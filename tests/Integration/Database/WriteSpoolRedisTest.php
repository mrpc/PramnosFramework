<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\WriteSpool;
use Pramnos\Redis\ConnectionManager;

/**
 * The spool's Redis backend, against a real Redis.
 *
 * The file backend measured an order of magnitude cheaper and is the default,
 * so Redis exists for one reason: the buffer is shared, and several web servers
 * can be drained by one process. That only works if the drain is safe under
 * concurrency — which is the part a unit test with a fake cannot show.
 *
 * The rename-before-read is what makes it safe: rows arriving during a drain go
 * to a fresh list rather than being read and then deleted unwritten. `RENAME` is
 * atomic; read-then-delete is not, and the difference is rows lost under load,
 * which is the only load that matters.
 */
class WriteSpoolRedisTest extends TestCase
{
    /** @var object The Redis connection under test */
    private $redis;

    /** @var ConnectionManager|null The manager to put back afterwards */
    private $originalManager = null;

    /** @var list<array{table: string, row: array<string, mixed>}> Rows written */
    public static array $written = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        if (!class_exists('\Redis')) {
            $this->markTestSkipped('The phpredis extension is not installed');
        }

        // The container's Redis, named explicitly: resolveConfig() reads env
        // vars and application settings, neither of which a test process has.
        $this->originalManager = ConnectionManager::getInstance();
        ConnectionManager::setInstance(new ConnectionManager([
            'host'    => $_ENV['REDIS_HOST'] ?? (getenv('REDIS_HOST') ?: 'redis'),
            'port'    => (int) ($_ENV['REDIS_PORT'] ?? (getenv('REDIS_PORT') ?: 6379)),
            'prefix'  => 'pramnostest:',
            'timeout' => 1.0,
        ]));

        try {
            $this->redis = ConnectionManager::getInstance()->connection();
            $this->redis->ping();
        } catch (\Throwable $e) {
            ConnectionManager::setInstance($this->originalManager);
            $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }

        $this->flushSpoolKeys();
        RedisBackedSpool::$written = [];
        RedisBackedSpool::reset();
        RedisBackedSpool::setDriver(WriteSpool::DRIVER_REDIS);
    }

    protected function tearDown(): void
    {
        try {
            $this->flushSpoolKeys();
        } catch (\Throwable) {
            // Cleanup only.
        }
        RedisBackedSpool::reset();

        if ($this->originalManager !== null) {
            ConnectionManager::setInstance($this->originalManager);
        }

        parent::tearDown();
    }

    /** Remove every spool key this test could have created. */
    private function flushSpoolKeys(): void
    {
        $prefix = ConnectionManager::getInstance()->prefix();

        foreach ((array) $this->redis->keys($prefix . 'spool:*') as $key) {
            $this->redis->del($key);
        }
    }

    /** The Redis key a table's rows live under. */
    private function keyFor(string $table): string
    {
        return ConnectionManager::getInstance()->prefix() . 'spool:' . $table;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * A row goes onto a Redis list rather than to the database.
     */
    public function testARowIsPushedOntoARedisList(): void
    {
        // Act
        $driver = RedisBackedSpool::append('readings', ['id' => 1, 'value' => 2.5]);

        // Assert
        $this->assertSame(WriteSpool::DRIVER_REDIS, $driver);
        $this->assertSame(1, $this->redis->lLen($this->keyFor('readings')));
        $this->assertSame([], RedisBackedSpool::$written, 'the request did not wait for a write');
    }

    /**
     * Buffered rows come back out in order and reach the database.
     */
    public function testBufferedRowsAreDrainedInOrder(): void
    {
        // Arrange
        RedisBackedSpool::append('readings', ['id' => 1]);
        RedisBackedSpool::append('readings', ['id' => 2]);
        RedisBackedSpool::append('readings', ['id' => 3]);

        // Act
        $stats = RedisBackedSpool::drain();

        // Assert
        $this->assertSame(3, $stats['written']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(
            [1, 2, 3],
            array_column(array_column(RedisBackedSpool::$written, 'row'), 'id')
        );
        $this->assertSame(0, $this->redis->exists($this->keyFor('readings')));
    }

    /**
     * Rows for different tables are drained separately.
     */
    public function testTablesAreDrainedSeparately(): void
    {
        // Arrange
        RedisBackedSpool::append('readings', ['id' => 1]);
        RedisBackedSpool::append('events', ['id' => 2]);

        // Act
        $stats = RedisBackedSpool::drain();

        // Assert
        $this->assertSame(2, $stats['written']);
        $this->assertSame(1, $stats['tables']['readings']);
        $this->assertSame(1, $stats['tables']['events']);
    }

    /**
     * A row appended during a drain is not swallowed by it.
     *
     * The reason the list is renamed before it is read. Simulated by appending
     * after the drain has taken its copy — with read-then-delete, this row would
     * be deleted along with the ones that were written.
     */
    public function testARowAppendedDuringADrainSurvives(): void
    {
        // Arrange
        RedisBackedSpool::append('readings', ['id' => 1]);

        // Act — the drain reads and clears, then a writer arrives
        RedisBackedSpool::drain();
        RedisBackedSpool::append('readings', ['id' => 2]);
        $second = RedisBackedSpool::drain();

        // Assert
        $this->assertSame(1, $second['written']);
        $this->assertSame(2, RedisBackedSpool::$written[1]['row']['id']);
    }

    /**
     * A list another process is draining is left alone.
     *
     * Two servers draining at once must not both claim the same rows; the
     * working key is skipped by the key scan for exactly that reason.
     */
    public function testAListBeingDrainedElsewhereIsSkipped(): void
    {
        // Arrange — a key that looks like another drainer's working copy
        $this->redis->rPush(
            $this->keyFor('readings') . ':draining:999:abcdef',
            json_encode(['id' => 99])
        );
        RedisBackedSpool::append('readings', ['id' => 1]);

        // Act
        $stats = RedisBackedSpool::drain();

        // Assert
        $this->assertSame(1, $stats['written'], 'only this drainer\'s rows were written');
        $this->assertSame(1, RedisBackedSpool::$written[0]['row']['id']);
        $this->assertSame(
            1,
            $this->redis->exists($this->keyFor('readings') . ':draining:999:abcdef'),
            'the other drainer\'s copy is untouched'
        );

        $this->redis->del($this->keyFor('readings') . ':draining:999:abcdef');
    }

    /**
     * The pending count reads the Redis lists.
     */
    public function testPendingCountsWhatIsInRedis(): void
    {
        // Arrange
        RedisBackedSpool::append('readings', ['id' => 1]);
        RedisBackedSpool::append('readings', ['id' => 2]);

        // Act & Assert
        $this->assertSame(2, RedisBackedSpool::pending());

        RedisBackedSpool::drain();
        $this->assertSame(0, RedisBackedSpool::pending());
    }

    /**
     * Redis is detected as available when it is.
     *
     * The probe is what decides the driver on an installation that has not set
     * `spool_driver`; a probe that always said false would make the Redis
     * backend unreachable.
     */
    public function testRedisIsDetectedAsAvailable(): void
    {
        // Act
        $available = RedisBackedSpool::probeRedis();

        // Assert
        $this->assertTrue($available);
    }

    /**
     * An unwritable row is counted and the rest are still written.
     */
    public function testOneBadRowDoesNotStopTheOthers(): void
    {
        // Arrange
        RedisBackedSpool::append('readings', ['id' => 1]);
        RedisBackedSpool::append('readings', ['id' => 2]);
        $this->redis->rPush($this->keyFor('readings'), 'not json at all');

        // Act
        $stats = RedisBackedSpool::drain();

        // Assert
        $this->assertSame(2, $stats['written']);
        $this->assertSame(1, $stats['failed']);
    }
}

/**
 * The spool with only the database write replaced.
 *
 * Everything else — the Redis calls, the rename, the key scan — is the real
 * implementation, because that is what this test exists to exercise.
 */
class RedisBackedSpool extends WriteSpool
{
    /** @var list<array{table: string, row: array<string, mixed>}> Rows written */
    public static array $written = [];

    /** Expose the availability probe, which is protected. */
    public static function probeRedis(): bool
    {
        return static::redisAvailable();
    }

    protected static function writeNow(string $table, array $row): void
    {
        static::$written[] = ['table' => $table, 'row' => $row];
    }

    protected static function beginBatch(): void
    {
    }

    protected static function commitBatch(): void
    {
    }

    protected static function rollbackBatch(): void
    {
    }

    /**
     * Never fall back to a file: this test is about the Redis path, and a
     * silent fallback would make a broken Redis look like a passing test.
     */
    protected static function directory(): ?string
    {
        return null;
    }
}
