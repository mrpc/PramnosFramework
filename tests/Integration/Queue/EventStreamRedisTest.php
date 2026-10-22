<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Event\Event;
use Pramnos\Event\QueuedEvents;
use Pramnos\Event\Transport\RedisStream;
use Pramnos\Event\Transport\StreamConsumer;
use Pramnos\Event\Transport\StreamTransport;
use Pramnos\Event\Transport\Transports;
use Pramnos\Framework\Factory;
use Pramnos\Queue\QueueManager;

/**
 * The event stream on Redis Streams, with MySQL as the outbox: an event committed is appended to
 * the stream at once, one written in a transaction after it — and one a process died before
 * appending, by the next pass; each group reads with its own consumer group; a message read and
 * never acknowledged is claimed again; a failure past its attempts is dead in `event_deliveries`
 * and *Try the dead again* runs it; a Redis that evicts keys is refused for the queue.
 *
 * Requires the Docker Redis container (`REDIS_HOST`, `redis` by default).
 */
#[Group('redis')]
#[CoversClass(RedisStream::class)]
#[CoversClass(StreamTransport::class)]
#[CoversClass(StreamConsumer::class)]
class EventStreamRedisTest extends TestCase
{
    protected Database $db;

    /** A second Redis connection the test reads the stream with. */
    private object $redis;

    /** The queue and stream tables, empty; both listeners on a Redis stream of their own. */
    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');
        $config = ['host' => getenv('REDIS_HOST') ?: 'redis', 'port' => 6379, 'database' => 14, 'prefix' => 'pftest:' . getmypid() . ':'];
        try {
            $this->redis = \Pramnos\Redis\ConnectionManager::forConfig($config)->connection();
            $this->redis->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        $controller = $this->getMockBuilder(Controller::class)->disableOriginalConstructor()->getMock();
        $controller->application = $app;
        $this->dropTables();
        foreach (MigrationLoader::loadFromDirectory(dirname(__DIR__, 3) . '/database/migrations/framework/queue', $app) as $migration) {
            $migration->up();
        }
        QueueManager::forgetSchemaCache();
        Event::forget();
        QueuedEvents::reset();
        QueuedEvents::$queue = new QueueManager($controller);
        Transports::reset();
        StreamTransport::reset();
        RedisStream::reset();
        StreamConsumer::$backoffBase = 0;
        StreamListenerA::$runs = [];
        StreamListenerA::$fail = false;
        StreamListenerB::$runs = [];
        Application::getInstance()->applicationInfo['events'] = ['transport' => 'stream', 'stream' => 'redis', 'redis' => $config];
        $this->redis->del(RedisStream::key());
        Event::listen('account.changed', StreamListenerA::class);
        Event::listen('account.changed', StreamListenerB::class);
    }

    /** Nothing left for the next: the tables, the stream, the settings. */
    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->del(RedisStream::key());
        }
        Event::forget();
        QueuedEvents::reset();
        QueuedEvents::$queue = null;
        Transports::reset();
        StreamTransport::reset();
        RedisStream::reset();
        StreamConsumer::$backoffBase = 30;
        unset(Application::getInstance()->applicationInfo['events']);
        if (isset($this->db)) {
            $this->dropTables();
        }
        QueueManager::forgetSchemaCache();
    }

    /** Drop the tables this class builds. */
    private function dropTables(): void
    {
        foreach (['event_deliveries', 'event_consumers', 'event_log', 'queueitems', 'queuestats', 'queue_idempotency'] as $table) {
            $this->db->schema()->dropTableIfExists($table);
        }
    }

    /**
     * Committed, it is on the stream at once; in a transaction, not before the commit; and an
     * entry no process appended — it died between the two — is appended by the next pass.
     */
    public function testWhatIsCommittedReachesTheStreamAndNothingIsLostInBetween(): void
    {
        // Arrange
        Event::fire('account.changed', 1, 'plain');
        $atOnce = $this->redis->xLen(RedisStream::key());

        // Act
        $this->db->startTransaction();
        Event::fire('account.changed', 2, 'plain');
        $inside = $this->redis->xLen(RedisStream::key());
        $this->db->commitTransaction();
        $relayed = (new StreamConsumer('one'))->consume(StreamListenerA::class);

        // Assert
        $this->assertSame(1, $atOnce);
        $this->assertSame(1, $inside, 'not before the commit');
        $this->assertSame(2, $this->redis->xLen(RedisStream::key()), 'the next pass appended it');
        $this->assertSame(2, $relayed['ran']);
        $this->assertSame([[1, 'plain'], [2, 'plain']], StreamListenerA::$runs);
        $this->assertSame([], StreamListenerB::$runs, 'the other group reads on its own');
    }

    /**
     * A message read and never acknowledged — its consumer died — is claimed by the next one; a
     * failure is tried again and, past the listener's attempts, is dead in event_deliveries,
     * shown in the status, and run when tried again from the screen.
     */
    public function testAnUnacknowledgedMessageIsClaimedAndAFailureDies(): void
    {
        // Arrange
        Event::fire('account.changed', 1, 'plain');
        try {
            $this->redis->xGroup('CREATE', RedisStream::key(), StreamListenerA::class, '0', true);
        } catch (\Throwable) {
        }
        $this->redis->xReadGroup(StreamListenerA::class, 'died', [RedisStream::key() => '>'], 10);

        // Act
        $claimed = (new StreamConsumer('two'))->consume(StreamListenerA::class);
        Event::fire('account.changed', 2, 'plain');
        StreamListenerA::$fail = true;
        $consumer = new StreamConsumer('two');
        $first    = $consumer->consume(StreamListenerA::class);
        $second   = $consumer->consume(StreamListenerA::class);
        $status   = array_column(StreamConsumer::status(), null, 'group')[StreamListenerA::class];
        StreamListenerA::$fail = false;
        $again = StreamConsumer::redrive(StreamListenerA::class);
        $rerun = $consumer->consume(StreamListenerA::class);

        // Assert
        $this->assertSame(1, $claimed['retried'], 'the dead consumer\'s message was claimed');
        $this->assertSame([1, 'plain'], StreamListenerA::$runs[0]);
        $this->assertSame(1, $first['failed']);
        $this->assertSame(1, $second['dead'], 'its second delivery was its last');
        $this->assertSame([1, 0], [$status['dead'], $status['pending']], 'acknowledged on Redis, dead in the table');
        $this->assertSame(1, $again);
        $this->assertSame(1, $rerun['retried']);
        $this->assertSame([2, 'plain'], StreamListenerA::$runs[1], 'tried again from the screen');
        $this->assertSame([], $this->db->queryBuilder()->table('event_deliveries')->getAll());
    }

    /**
     * Pruning keeps what the relay has not appended yet — an entry written while a process died
     * before appending it — and deletes what it has; the groups' figures come from Redis.
     */
    public function testPruningFollowsTheRelayAndTheStatusReadsRedis(): void
    {
        // Arrange
        Event::fire('account.changed', 1, 'plain');
        $this->db->queryBuilder()->table('event_log')->insert(['event' => 'account.changed', 'eventid' => 'x', 'payload' => '{}',
            'groups' => '[]', 'createdat' => time()]);
        $this->db->queryBuilder()->table('event_log')->where('entryid', '>', 0)->update(['createdat' => time() - 40 * 86400]);

        // Act
        $status = array_column(StreamConsumer::status(), null, 'group');
        $pruned = StreamConsumer::prune(30);
        $left   = count($this->db->queryBuilder()->table('event_log')->getAll());

        // Assert
        $this->assertSame(1, $pruned, 'the appended one');
        $this->assertSame(1, $left, 'the one never appended stays for the relay');
        $this->assertSame(0, $status[StreamListenerB::class]['lag'], 'no group on Redis yet: nothing said to wait');
    }

    /**
     * A Redis that evicts keys under memory pressure is refused: the stream is passed over for the
     * queue, and what is fired becomes queue tasks.
     */
    public function testARedisThatEvictsIsRefusedForTheQueue(): void
    {
        // Arrange
        $before = (string) (((array) $this->redis->config('GET', 'maxmemory-policy'))['maxmemory-policy'] ?? 'noeviction');
        $this->redis->config('SET', 'maxmemory-policy', 'allkeys-lru');

        // Act
        try {
            StreamTransport::reset();
            $refusal = RedisStream::refusal();
            $results = Event::fire('account.changed', 9, 'plain');
        } finally {
            $this->redis->config('SET', 'maxmemory-policy', $before);
        }

        // Assert
        $this->assertStringContainsString('allkeys-lru', (string) $refusal);
        $this->assertSame('queue', Transports::for(StreamListenerA::class)->name());
        $this->assertCount(2, $this->db->queryBuilder()->table('queueitems')->getAll(), 'two queue tasks instead');
        $this->assertIsInt($results[0]);
    }

    /**
     * The edges: a Redis that forbids CONFIG is asked through INFO; one that cannot be reached is
     * passed over for the queue; the relay waits for a transaction to end; a message for other
     * groups is acknowledged and left alone.
     */
    public function testTheEdgesOfRedis(): void
    {
        // Arrange
        $managed = new class () {
            /** CONFIG is disabled, as on a managed Redis. */
            public function config(string $op, string $what): array
            {
                throw new \RuntimeException('unknown command CONFIG');
            }

            /** INFO says the policy. @return array<string, string> */
            public function info(string $section): array
            {
                return ['maxmemory_policy' => 'allkeys-lfu'];
            }
        };
        RedisStream::$connect = static fn (): object => $managed;
        $managedRefusal = RedisStream::refusal();
        RedisStream::reset();
        Application::getInstance()->applicationInfo['events']['redis']['port'] = 1;
        StreamTransport::reset();
        $unreachable = (new StreamTransport())->available();
        Application::getInstance()->applicationInfo['events']['redis']['port'] = 6379;
        \Pramnos\Redis\ConnectionManager::resetPool();
        StreamTransport::reset();
        $this->db->startTransaction();

        // Act
        $inTransaction = RedisStream::relay();
        $this->db->commitTransaction();
        $this->redis->xAdd(RedisStream::key(), '*', ['entry' => '0', 'groups' => '[]']);
        $other = (new StreamConsumer('one'))->consume(StreamListenerA::class);

        // Assert
        $this->assertStringContainsString('allkeys-lfu', (string) $managedRefusal, 'read through INFO');
        $this->assertFalse($unreachable, 'unreachable: the queue instead');
        $this->assertSame(0, $inTransaction, 'not before the commit');
        $this->assertSame(['ran' => 0, 'failed' => 0], ['ran' => $other['ran'], 'failed' => $other['failed']]);
        $this->assertSame(0, (int) ($this->redis->xPending(RedisStream::key(), StreamListenerA::class)[0] ?? 0), 'acknowledged, left alone');
    }
}
