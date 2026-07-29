<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Queue;

use PHPUnit\Framework\TestCase;
use Pramnos\Queue\DelayedQueue;
use Pramnos\Queue\Drivers\RedisQueueDriver;
use Pramnos\Redis\ConnectionManager;

/**
 * Unit tests for the DelayedQueue::redis() factory — the accessor that builds a
 * Redis-backed delayed queue for a namespace from the shared ConnectionManager,
 * so an application obtains the queue capability without wiring the driver.
 */
class DelayedQueueTest extends TestCase
{
    protected function tearDown(): void
    {
        ConnectionManager::setInstance(null);
    }

    /**
     * redis($namespace) returns a DelayedQueue backed by a RedisQueueDriver for
     * that namespace, and its keyspace carries the ConnectionManager's per-install
     * prefix + namespace (<prefix><namespace>:data / :delayed) over the manager's
     * connection — proving the wiring without a hand-built driver.
     */
    public function testRedisFactoryBindsPrefixNamespaceAndConnection(): void
    {
        $fake = new FakeQueueConnection();
        ConnectionManager::setInstance(new ConnectionManager(
            ['prefix' => 'qx:'],
            static fn (): object => $fake
        ));

        $queue = DelayedQueue::redis('jobs');
        $this->assertInstanceOf(RedisQueueDriver::class, $queue->driver());
        $this->assertSame('jobs', $queue->driver()->getNamespace());

        $queue->push('sometype', ['a' => 1]);

        // push() writes the job hash then schedules it in the sorted set.
        $this->assertSame('qx:jobs:data', $fake->hSetKeys[0] ?? null);
        $this->assertSame('qx:jobs:delayed', $fake->zAddKeys[0] ?? null);
    }
}

/**
 * Minimal fake \Redis-like connection recording the queue writes push() makes.
 */
class FakeQueueConnection
{
    /** @var list<string> */
    public array $hSetKeys = [];
    /** @var list<string> */
    public array $zAddKeys = [];

    public function hSet(string $key, string $field, string $value): int
    {
        $this->hSetKeys[] = $key;
        return 1;
    }

    public function zAdd(string $key, float $score, string $member): int
    {
        $this->zAddKeys[] = $key;
        return 1;
    }
}
