<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Queue\DelayedQueue;
use Pramnos\Queue\Drivers\RedisQueueDriver;
use Pramnos\Queue\ReservedJob;

/**
 * In-memory fake of the phpredis \Redis sorted-set + hash surface the
 * RedisQueueDriver uses.
 *
 * It models exactly the operations the driver issues — hSet/hGet/hDel for the
 * payload hash, zAdd/zRem/zCard/zRangeByScore/zRange for the delayed sorted set,
 * and del for flush — with real ordering-by-score semantics, so the driver's
 * claim/size/next/flush logic is exercised deterministically without a live
 * Redis server.
 */
class FakeQueueRedis
{
    /** @var array<string,array<string,string>> hash key => field => value */
    public array $hashes = [];
    /** @var array<string,array<string,int>> zset key => member => score */
    public array $zsets = [];

    public function hSet(string $key, string $field, string $value): int
    {
        $isNew = !isset($this->hashes[$key][$field]);
        $this->hashes[$key][$field] = $value;
        return $isNew ? 1 : 0;
    }

    public function hGet(string $key, string $field): string|false
    {
        return $this->hashes[$key][$field] ?? false;
    }

    public function hDel(string $key, string $field): int
    {
        if (isset($this->hashes[$key][$field])) {
            unset($this->hashes[$key][$field]);
            return 1;
        }
        return 0;
    }

    public function zAdd(string $key, int|float $score, string $member): int
    {
        $isNew = !isset($this->zsets[$key][$member]);
        $this->zsets[$key][$member] = (int) $score;
        return $isNew ? 1 : 0;
    }

    /**
     * @param array{limit?:array{0:int,1:int}} $options
     * @return list<string>
     */
    public function zRangeByScore(string $key, string $min, string $max, array $options = []): array
    {
        $members = $this->zsets[$key] ?? [];
        asort($members); // by score ascending
        $lo = $min === '-inf' ? PHP_INT_MIN : (int) $min;
        $hi = $max === '+inf' ? PHP_INT_MAX : (int) $max;

        $ids = [];
        foreach ($members as $member => $score) {
            if ($score >= $lo && $score <= $hi) {
                $ids[] = (string) $member;
            }
        }

        if (isset($options['limit'])) {
            [$offset, $count] = $options['limit'];
            $ids = array_slice($ids, $offset, $count);
        }

        return $ids;
    }

    public function zRem(string $key, string $member): int
    {
        if (isset($this->zsets[$key][$member])) {
            unset($this->zsets[$key][$member]);
            return 1;
        }
        return 0;
    }

    public function zCard(string $key): int
    {
        return count($this->zsets[$key] ?? []);
    }

    /**
     * Only the withscores=true, start=0, stop=0 form the driver uses is modelled:
     * returns [member => score] for the lowest-scored element.
     *
     * @return array<string,int>
     */
    public function zRange(string $key, int $start, int $stop, bool $withScores = false): array
    {
        $members = $this->zsets[$key] ?? [];
        asort($members);
        $sliced = array_slice($members, $start, ($stop - $start) + 1, true);
        return $withScores ? $sliced : array_map('strval', array_keys($sliced));
    }

    public function del(string $key): int
    {
        $existed = isset($this->hashes[$key]) || isset($this->zsets[$key]);
        unset($this->hashes[$key], $this->zsets[$key]);
        return $existed ? 1 : 0;
    }
}

/**
 * Unit tests for the Redis-backed delayed-queue driver.
 *
 * A FakeQueueRedis is injected through the driver's connection factory so the
 * push/claim/size/next/flush logic — including atomic claim-by-ZREM and the
 * exact prefixed key layout — is verified without a live Redis server.
 */
#[CoversClass(RedisQueueDriver::class)]
#[CoversClass(ReservedJob::class)]
#[CoversClass(DelayedQueue::class)]
class RedisQueueDriverTest extends TestCase
{
    private FakeQueueRedis $redis;

    protected function setUp(): void
    {
        $this->redis = new FakeQueueRedis();
    }

    private function driver(array $config = []): RedisQueueDriver
    {
        return new RedisQueueDriver(
            $config + ['prefix' => 'rcb_', 'namespace' => 'jobs'],
            fn (): object => $this->redis
        );
    }

    /**
     * push() writes the payload to the "<prefix><namespace>:data" hash and the
     * run-at score to the "<prefix><namespace>:delayed" sorted set under keys
     * the driver prefixes itself (no reliance on OPT_PREFIX), and returns a job id.
     */
    public function testPushStoresJobUnderPrefixedKeysAndReturnsId(): void
    {
        $driver = $this->driver();

        $id = $driver->push('reply', ['message' => 'hi'], 0);

        $this->assertNotSame('', $id);
        $this->assertArrayHasKey('rcb_jobs:data', $this->redis->hashes);
        $this->assertArrayHasKey($id, $this->redis->hashes['rcb_jobs:data']);
        $this->assertArrayHasKey('rcb_jobs:delayed', $this->redis->zsets);
        $this->assertArrayHasKey($id, $this->redis->zsets['rcb_jobs:delayed']);

        $stored = json_decode($this->redis->hashes['rcb_jobs:data'][$id], true);
        $this->assertSame('reply', $stored['type']);
        $this->assertSame(['message' => 'hi'], $stored['payload']);
        $this->assertSame(0, $stored['attempts']);
    }

    /**
     * claimDue() returns only jobs whose run-at has passed, as ReservedJob value
     * objects, and removes each claimed job from both the sorted set and the hash
     * (claim-and-remove), leaving not-yet-due jobs untouched.
     */
    public function testClaimDueReturnsOnlyDueJobsAndRemovesThem(): void
    {
        $driver = $this->driver();

        $dueId    = $driver->push('reply', ['x' => 1], 0);
        $futureId = $driver->push('reply', ['x' => 2], 3600);

        $claimed = $driver->claimDue();

        $this->assertCount(1, $claimed);
        $this->assertInstanceOf(ReservedJob::class, $claimed[0]);
        $this->assertSame($dueId, $claimed[0]->id);
        $this->assertSame(['x' => 1], $claimed[0]->payload);

        // Due job gone from both structures; future job still present.
        $this->assertArrayNotHasKey($dueId, $this->redis->zsets['rcb_jobs:delayed']);
        $this->assertArrayNotHasKey($dueId, $this->redis->hashes['rcb_jobs:data']);
        $this->assertArrayHasKey($futureId, $this->redis->zsets['rcb_jobs:delayed']);
    }

    /**
     * A second claimer never re-processes a job already claimed: once claimDue
     * has removed the job, a subsequent claimDue returns nothing for it.
     */
    public function testClaimIsAtomicAcrossCalls(): void
    {
        $driver = $this->driver();
        $driver->push('reply', [], 0);

        $first  = $driver->claimDue();
        $second = $driver->claimDue();

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
    }

    /**
     * size() counts scheduled jobs (due and not-yet-due) and secondsUntilNext()
     * reports 0 when work is already due, the remaining seconds when the soonest
     * job is in the future, and null when the queue is empty.
     */
    public function testSizeAndSecondsUntilNext(): void
    {
        $driver = $this->driver();

        $this->assertSame(0, $driver->size());
        $this->assertNull($driver->secondsUntilNext());

        $driver->push('reply', [], 0);
        $driver->push('reply', [], 120);

        $this->assertSame(2, $driver->size());
        $this->assertSame(0, $driver->secondsUntilNext()); // a due job exists
    }

    /**
     * secondsUntilNext() returns the positive remaining seconds when every
     * scheduled job is still in the future.
     */
    public function testSecondsUntilNextWhenAllFuture(): void
    {
        $driver = $this->driver();
        $driver->push('reply', [], 300);

        $next = $driver->secondsUntilNext();
        $this->assertNotNull($next);
        $this->assertGreaterThan(0, $next);
        $this->assertLessThanOrEqual(300, $next);
    }

    /**
     * flush() removes every scheduled job and returns how many were removed.
     */
    public function testFlushClearsQueue(): void
    {
        $driver = $this->driver();
        $driver->push('reply', [], 0);
        $driver->push('reply', [], 10);

        $removed = $driver->flush();

        $this->assertSame(2, $removed);
        $this->assertSame(0, $driver->size());
    }

    /**
     * The DelayedQueue facade delegates to its driver and applies linear-backoff
     * retry: a job under the attempt ceiling is re-pushed with an incremented
     * attempt count and a delay, while a job at the ceiling is dropped (null).
     */
    public function testDelayedQueueRetryBackoffAndDrop(): void
    {
        $queue = new DelayedQueue($this->driver());

        // attempts 1 -> re-scheduled (1 < 3)
        $job1  = new ReservedJob('a', 'reply', ['k' => 'v'], 1, time());
        $newId = $queue->retry($job1, 3, 10);
        $this->assertNotNull($newId);
        $requeued = json_decode($this->redis->hashes['rcb_jobs:data'][$newId], true);
        $this->assertSame(2, $requeued['attempts']);
        $this->assertSame(['k' => 'v'], $requeued['payload']);

        // attempts 2 -> at ceiling (2 + 1 = 3 >= 3) -> dropped
        $job2 = new ReservedJob('b', 'reply', [], 2, time());
        $this->assertNull($queue->retry($job2, 3, 10));
    }
}
