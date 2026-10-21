<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Event\Event;
use Pramnos\Event\QueueableEvent;
use Pramnos\Event\QueuedEvents;
use Pramnos\Event\QueuedListener;
use Pramnos\Queue\QueueManager;

/**
 * An event as a class: a post that was created, carrying the post object itself.
 */
final class PostCreated implements QueueableEvent
{
    public const EVENT = 'post.created';

    /** @param \stdClass $post The post, as the request holds it */
    public function __construct(public readonly \stdClass $post)
    {
    }

    /** The post's id is all a task carries. */
    public function jsonSerialize(): array
    {
        return ['id' => $this->post->id];
    }

    /** The post again, as the worker would load it. */
    public static function fromQueue(array $data): static
    {
        $post             = new \stdClass();
        $post->id         = (int) $data['id'];
        $post->fromQueue  = true;

        return new self($post);
    }
}

/**
 * An event class with no `EVENT` constant, and nothing to queue.
 */
final class UnnamedThing
{
}

/**
 * A request listener typed with the event: changes the caller's post.
 */
final class MarkPostSeen
{
    /** Marks the post the caller holds. */
    public function handle(PostCreated $event): mixed
    {
        $event->post->seen = true;

        return null;
    }
}

/**
 * A queued listener typed with the event, folded and limited by the event's post.
 */
final class IndexPost extends QueuedListener
{
    /** @var list<PostCreated> The events it ran with */
    public static array $events = [];

    /** Record the event. */
    public function handle(PostCreated $event): mixed
    {
        self::$events[] = $event;

        return true;
    }

    /** Folded per post. */
    public function debounceKey(mixed ...$args): ?string
    {
        return 'index:' . $args[0]->post->id;
    }

    /** Once per post. */
    public function idempotencyKey(mixed ...$args): ?string
    {
        return 'indexed:' . $args[0]->post->id;
    }
}

/**
 * Domain events as classes: {@see Event::dispatch()} hands a request listener the caller's own
 * object, and a {@see QueuedListener} the same class built again in the worker.
 */
#[CoversClass(Event::class)]
#[CoversClass(QueuedEvents::class)]
#[CoversClass(QueuedListener::class)]
final class DomainEventTest extends TestCase
{
    /** @var list<array<string, mixed>> Every addKeyed() call the stub queue received */
    private array $written = [];

    /** A stub queue that records what it is given. */
    protected function setUp(): void
    {
        Event::forget();
        QueuedEvents::reset();
        IndexPost::$events = [];
        $this->written     = [];

        $queue = $this->getMockBuilder(QueueManager::class)->disableOriginalConstructor()
            ->onlyMethods(['addKeyed', 'hasEventColumns'])->getMock();
        $queue->method('hasEventColumns')->willReturn(true);
        $queue->method('addKeyed')->willReturnCallback(function (string $type, mixed $data, ?string $key): int {
            $this->written[] = ['data' => $data, 'key' => $key];

            return 99 + count($this->written);
        });
        QueuedEvents::$queue = $queue;
        // No idempotency table to ask: the key is what is under test, not its storage.
        (new \ReflectionProperty(QueuedEvents::class, 'hasIdempotency'))->setValue(null, false);
    }

    /** Leave nothing behind for the next test. */
    protected function tearDown(): void
    {
        Event::forget();
        QueuedEvents::reset();
    }

    /** A post the request holds. */
    private static function post(int $id): \stdClass
    {
        $post     = new \stdClass();
        $post->id = $id;

        return $post;
    }

    /**
     * (a) A request listener receives the dispatched instance itself — what it changes on the
     * caller's objects is what the caller saves next, so a listener that reloaded and saved its
     * own copy cannot be overwritten by the caller's stale one. Both a closure and a class
     * registered by name, whose handle() is typed with the event, are called.
     */
    public function testARequestListenerReceivesTheSameInstance(): void
    {
        // Arrange
        $received = null;
        Event::listen(PostCreated::EVENT, function (PostCreated $event) use (&$received): void {
            $received = $event;
        });
        Event::listen(PostCreated::EVENT, MarkPostSeen::class);
        $event = new PostCreated(self::post(7));

        // Act
        $results = Event::dispatch($event);

        // Assert
        $this->assertSame($event, $received);
        $this->assertTrue($event->post->seen ?? false, 'the typed listener did not change the caller\'s post');
        $this->assertSame([null, null], $results);
    }

    /**
     * An object whose class has no `EVENT` constant is fired under its class name.
     */
    public function testAnEventWithoutANameIsFiredUnderItsClass(): void
    {
        // Arrange
        $received = null;
        Event::listen(UnnamedThing::class, function (UnnamedThing $event) use (&$received): void {
            $received = $event;
        });
        $event = new UnnamedThing();

        // Act
        Event::dispatch($event);

        // Assert
        $this->assertSame($event, $received);
    }

    /**
     * (b) and (c) A queued listener: the key methods are asked with the event object at
     * dispatch, the task carries the event's data and class, and the worker hands handle() an
     * object of the same class built by fromQueue() — not the request's instance, not an array.
     */
    public function testAQueuedListenerReceivesTheEventBuiltAgain(): void
    {
        // Arrange
        Event::listen(PostCreated::EVENT, IndexPost::class);

        // Act
        $results = Event::dispatch(new PostCreated(self::post(7)));
        $job     = json_decode((string) json_encode($this->written[0]['data']), true);
        $message = QueuedEvents::run($job, 100);

        // Assert
        $this->assertSame([100], $results);
        $this->assertSame('index:7', $this->written[0]['key'], 'debounceKey() did not get the event');
        $this->assertSame('indexed:7', $this->written[0]['data']['idempotency']['key']);
        $this->assertSame([['id' => 7]], $job['args']);
        $this->assertSame([PostCreated::class], $job['events']);
        $this->assertCount(1, IndexPost::$events);
        $this->assertInstanceOf(PostCreated::class, IndexPost::$events[0]);
        // Built in the worker from the id, so it reads the current state.
        $this->assertTrue(IndexPost::$events[0]->post->fromQueue);
        $this->assertStringContainsString('Ran ' . IndexPost::class, $message);
    }

    /**
     * (d) An object that cannot travel — not a QueueableEvent, not even JsonSerializable — is
     * refused for a queued listener as any such argument is: it throws under test.
     */
    public function testAnEventThatCannotTravelIsRefusedForAQueuedListener(): void
    {
        // Arrange
        Event::listen(UnnamedThing::class, IndexPost::class);

        // Act
        try {
            Event::dispatch(new UnnamedThing());
            $thrown = null;
        } catch (\InvalidArgumentException $refused) {
            $thrown = $refused->getMessage();
        }

        // Assert
        $this->assertStringContainsString('an object of ' . UnnamedThing::class, (string) $thrown);
        $this->assertSame([], $this->written);
    }

    /**
     * A task naming as its event a class that is not a QueueableEvent is refused rather than
     * having a static method called on whatever the payload says.
     */
    public function testATaskNamingAnotherClassAsItsEventIsRefused(): void
    {
        // Arrange
        $job = ['listener' => IndexPost::class, 'event' => 'post.created', 'args' => [['id' => 1]],
            'events' => [UnnamedThing::class], 'meta' => ['eventId' => 'a', 'rootId' => 'a', 'depth' => 0]];

        // Assert — declared before the act that throws
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not a queueable event');

        // Act
        QueuedEvents::run($job, 1);
    }

    /**
     * A queued listener called directly runs now — calling is what that means — and being
     * callable is what lets an instance be registered; fire() still queues the instance. An
     * equal instance registered twice is one listener.
     */
    public function testAQueuedListenerInstanceIsRegisteredQueuedAndRunsWhenCalled(): void
    {
        // Arrange
        Event::listen(PostCreated::EVENT, new IndexPost());
        Event::listen(PostCreated::EVENT, new IndexPost());
        $event = new PostCreated(self::post(3));

        // Act
        $results = Event::dispatch($event);
        $direct  = (new IndexPost())($event);

        // Assert
        $this->assertSame([100], $results, 'the instance was not queued, or was registered twice');
        $this->assertTrue($direct);
        $this->assertSame([$event], IndexPost::$events);
    }
}
