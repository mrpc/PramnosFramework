<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Event\Event;
use Pramnos\Event\EventListenerTask;
use Pramnos\Event\QueuedEvents;
use Pramnos\Event\QueuedListener;
use Pramnos\Queue\QueueManager;

/**
 * A queued listener records what it was given, so a test can see whether — and how — it ran.
 */
final class RecordingQueuedListener extends QueuedListener
{
    /** @var list<list<mixed>> The arguments of every call */
    public static array $calls = [];

    /** @var list<array<string, mixed>> What it fires itself, as `[event, args]` */
    public static array $fires = [];

    /** Record the call, and fire whatever it was told to. */
    public function handle(mixed ...$args): mixed
    {
        self::$calls[] = $args;
        foreach (self::$fires as [$event, $with]) {
            Event::fire($event, ...$with);
        }

        return true;
    }

    /** Folded per first argument. */
    public function debounceKey(mixed ...$args): ?string
    {
        return 'rec:' . ($args[0] ?? '');
    }
}

/**
 * A queued listener that fails.
 */
final class FailingQueuedListener extends QueuedListener
{
    /** Always throws. */
    public function handle(mixed ...$args): mixed
    {
        throw new \RuntimeException('provider down');
    }
}

/**
 * Event::fire() with a {@see QueuedListener}: written to the queue, never called in the request.
 *
 * The queue is a stub here — what is written is the point; the database side (transactions,
 * folding, idempotency) is proved against MySQL and PostgreSQL in the integration tests.
 */
#[CoversClass(Event::class)]
#[CoversClass(QueuedEvents::class)]
#[CoversClass(EventListenerTask::class)]
final class QueuedListenerTest extends TestCase
{
    /** @var list<array<string, mixed>> Every addKeyed() call the stub queue received */
    private array $written = [];

    /** A stub queue that records what it is given and answers with ids from 100. */
    protected function setUp(): void
    {
        Event::forget();
        QueuedEvents::reset();
        RecordingQueuedListener::$calls = [];
        RecordingQueuedListener::$fires = [];
        $this->written = [];

        $queue = $this->getMockBuilder(QueueManager::class)->disableOriginalConstructor()
            ->onlyMethods(['addKeyed', 'hasEventColumns'])->getMock();
        $queue->method('hasEventColumns')->willReturn(true);
        $queue->method('addKeyed')->willReturnCallback(function (string $type, mixed $data, ?string $key, int $delay, int $ceiling): int {
            $this->written[] = ['type' => $type, 'data' => $data, 'key' => $key, 'delay' => $delay, 'ceiling' => $ceiling];

            return 99 + count($this->written);
        });
        QueuedEvents::$queue = $queue;
    }

    /** Leave nothing behind for the next test. */
    protected function tearDown(): void
    {
        Event::forget();
        QueuedEvents::reset();
    }

    /**
     * fire() queues a queued listener and does not call it; its place in the results is the
     * task id, and a synchronous listener after it still runs — the queued one has not run, so
     * it cannot stop anything.
     */
    public function testFireQueuesTheListenerInsteadOfCallingIt(): void
    {
        // Arrange
        $after = 0;
        Event::listen('user.saved', RecordingQueuedListener::class);
        Event::listen('user.saved', function () use (&$after) { $after++; }, 20);

        // Act
        $results = Event::fire('user.saved', 42, ['email' => 'a@example.test']);

        // Assert
        $this->assertSame([], RecordingQueuedListener::$calls, 'the request ran the queued listener');
        $this->assertSame(1, $after, 'the synchronous listener after it did not run');
        $this->assertSame(100, $results[0]);
        $this->assertCount(1, $this->written);
        $task = $this->written[0];
        $this->assertSame(EventListenerTask::TYPE, $task['type']);
        $this->assertSame(RecordingQueuedListener::class, $task['data']['listener']);
        $this->assertSame([42, ['email' => 'a@example.test']], $task['data']['args']);
        // Folded by the listener's key, with the default window and ceiling.
        $this->assertSame(['rec:42', 60, 600], [$task['key'], $task['delay'], $task['ceiling']]);
    }

    /**
     * Each task says who fired its event: fireAs() names the account, or nobody — the system —
     * and under a test runner the source is the console.
     */
    public function testTheTaskSaysWhoFiredItAndFromWhere(): void
    {
        // Arrange
        Event::listen('subscription.expired', RecordingQueuedListener::class);

        // Act
        Event::fireAs(7, 'subscription.expired', 7);
        Event::fireAs(null, 'subscription.expired', 8);
        Event::fire('subscription.expired', 9);

        // Assert
        [$asUser, $asSystem, $plain] = array_map(fn ($w) => $w['data']['meta'], $this->written);
        $this->assertSame(7, $asUser['userid']);
        $this->assertNull($asSystem['userid'], 'fireAs(null) is the system');
        $this->assertSame('cli', $plain['source']);
        $this->assertNull($plain['ip'], 'only a web request has a client address');
        // A first event is its own root, with no cause.
        $this->assertNull($plain['causedBy']);
        $this->assertSame($plain['eventId'], $plain['rootId']);
        $this->assertSame(0, $plain['depth']);
        $this->assertSame(32, strlen($plain['eventId']));
    }

    /**
     * The worker runs the listener in its event's context: an event the listener fires is
     * caused by that one and shares its root, one level deeper — and the context is gone again
     * once the listener has returned.
     */
    public function testEventsFiredByAQueuedListenerCarryTheirCause(): void
    {
        // Arrange
        Event::listen('user.registered', RecordingQueuedListener::class);
        Event::listen('welcome.sent', RecordingQueuedListener::class);
        Event::fire('user.registered', 1);
        $parent = $this->written[0]['data'];
        RecordingQueuedListener::$fires = [['welcome.sent', [1]]];

        // Act
        $message = QueuedEvents::run($parent, 100);
        Event::fire('welcome.sent', 2);

        // Assert
        $this->assertSame([[1]], RecordingQueuedListener::$calls);
        $this->assertStringContainsString('Ran ' . RecordingQueuedListener::class, $message);
        $child = $this->written[1]['data']['meta'];
        $this->assertSame($parent['meta']['eventId'], $child['causedBy']);
        $this->assertSame($parent['meta']['rootId'], $child['rootId']);
        $this->assertSame(1, $child['depth']);
        $this->assertSame('queue', $child['source']);
        // After the run, an event is a first event again.
        $this->assertNull($this->written[2]['data']['meta']['causedBy']);
    }

    /**
     * A chain deeper than MAX_DEPTH is refused — a rule that triggers itself would otherwise
     * fill the queue for ever — and the context is restored even when the listener throws.
     */
    public function testAChainTooDeepIsRefusedAndAFailureRestoresTheContext(): void
    {
        // Arrange — a task at the deepest allowed level, whose listener fires again
        Event::listen('loop', RecordingQueuedListener::class);
        RecordingQueuedListener::$fires = [['loop', [1]]];
        $job = ['listener' => RecordingQueuedListener::class, 'event' => 'loop', 'args' => [1],
            'meta' => ['eventId' => 'e10', 'rootId' => 'e0', 'depth' => QueuedEvents::MAX_DEPTH, 'userid' => null]];

        // Act
        QueuedEvents::run($job, 1);
        $results = RecordingQueuedListener::$calls;
        $thrown = null;
        try {
            QueuedEvents::run(['listener' => FailingQueuedListener::class, 'event' => 'x', 'args' => [], 'meta' => $job['meta']], 2);
        } catch (\RuntimeException $failure) {
            $thrown = $failure->getMessage();
        }

        // Assert
        $this->assertCount(1, $results);
        $this->assertSame([], $this->written, 'an event 11 deep was queued');
        $this->assertSame('provider down', $thrown);
        $this->assertSame('cli', QueuedEvents::source(), 'the failed run left its context behind');
    }

    /**
     * Arguments travel as JSON. One that cannot — a model, a resource — throws under test and in
     * development; in production it is logged, that task skipped, and fire() goes on to the
     * other listeners, because an event fired in a payment's path must not lose the payment.
     */
    public function testAnArgumentThatCannotTravelThrowsInDevelopmentAndIsSkippedInProduction(): void
    {
        // Arrange
        $after = 0;
        Event::listen('order.paid', RecordingQueuedListener::class);
        Event::listen('order.paid', function () use (&$after) { $after++; }, 20);
        $model = new \stdClass();

        // Act
        try {
            Event::fire('order.paid', $model);
            $thrown = null;
        } catch (\InvalidArgumentException $refused) {
            $thrown = $refused->getMessage();
        }
        QueuedEvents::$strict = false;
        $results = Event::fire('order.paid', $model);

        // Assert
        $this->assertStringContainsString('an object of stdClass', (string) $thrown);
        $this->assertSame([null, null], $results, 'the production path did not skip the task');
        $this->assertSame([], $this->written);
        $this->assertSame(1, $after, 'fire() stopped at the listener it could not queue');
    }

    /**
     * The worker's task: it decodes the payload, runs the listener and reports what ran; a
     * class that is not a queued listener is refused rather than instantiated.
     */
    public function testTheTaskRunsTheListenerFromItsPayload(): void
    {
        // Arrange
        $task = new EventListenerTask(null);
        $item = new class (null) extends \Pramnos\Queue\QueueItem {
            /** No model behind it: the task reads two fields. */
            public function __construct($controller)
            {
            }
        };
        $item->taskid  = 5;
        $item->payload = (string) json_encode(['listener' => RecordingQueuedListener::class, 'event' => 'user.saved', 'args' => [3], 'meta' => ['eventId' => 'abc', 'rootId' => 'abc', 'depth' => 0]]);

        // Act
        $result      = $task->execute($item);
        $description = $task->getDescription($item);

        // Assert
        $this->assertSame([[3]], RecordingQueuedListener::$calls);
        $this->assertSame('Ran ' . RecordingQueuedListener::class . ' for user.saved (abc)', $result['message']);
        $this->assertSame('user.saved → ' . RecordingQueuedListener::class, $description);
        $this->expectException(\RuntimeException::class);
        QueuedEvents::run(['listener' => \stdClass::class], 6);
    }
}
