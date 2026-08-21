<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\BroadcastableEvent;
use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Broadcasting\QueuedBroadcastableEvent;
use Pramnos\Broadcasting\Testing\FakeDriver;

/**
 * Self-describing events.
 *
 * The problem they solve is drift: every call site otherwise repeats which channel,
 * what the event is called and what the payload looks like. The channel name is the
 * dangerous one — one place builds `private-order.42`, another `private-order-42`,
 * and the subscriber that guessed wrong receives nothing, with no error anywhere.
 */
#[CoversClass(BroadcastingManager::class)]
class BroadcastableEventTest extends TestCase
{
    protected function tearDown(): void
    {
        FakeDriver::restore();
    }

    /** An event over two channels, counting how often its payload is resolved. */
    private function event(bool $queued = false): object
    {
        if ($queued) {
            return new class implements QueuedBroadcastableEvent {
                public int $payloadCalls = 0;

                public function broadcastOn(): array
                {
                    return ['private-order.42', 'ops'];
                }

                public function broadcastAs(): string
                {
                    return 'order.paid';
                }

                public function broadcastWith(): array
                {
                    $this->payloadCalls++;

                    return ['id' => 42];
                }
            };
        }

        return new class implements BroadcastableEvent {
            public int $payloadCalls = 0;

            public function broadcastOn(): array
            {
                return ['private-order.42', 'ops'];
            }

            public function broadcastAs(): string
            {
                return 'order.paid';
            }

            public function broadcastWith(): array
            {
                $this->payloadCalls++;

                return ['id' => 42];
            }
        };
    }

    private function manager(FakeDriver $fake): BroadcastingManager
    {
        return (new BroadcastingManager())->addDriver($fake)->setDefault('fake');
    }

    /**
     * An event publishes to every channel it names, under its own event name.
     */
    public function testPublishesToEveryChannelItNames(): void
    {
        // Arrange
        $fake    = new FakeDriver();
        $manager = $this->manager($fake);

        // Act
        $manager->event($this->event());

        // Assert
        $fake->assertBroadcast('private-order.42', 'order.paid');
        $fake->assertBroadcast('ops', 'order.paid');
        $fake->assertBroadcastCount(2);
        $this->assertSame([['id' => 42], ['id' => 42]], array_column($fake->recorded(), 'payload'));
    }

    /**
     * The payload is resolved once, not once per channel.
     *
     * `broadcastWith()` may be doing real work — loading relations, formatting — and
     * calling it per channel multiplies that by the size of the audience.
     */
    public function testPayloadIsResolvedOncePerDispatch(): void
    {
        // Arrange
        $fake    = new FakeDriver();
        $manager = $this->manager($fake);
        $event   = $this->event();

        // Act
        $manager->event($event);

        // Assert
        $this->assertSame(1, $event->payloadCalls, 'resolved once for two channels');
    }

    /**
     * except() applies to an event dispatch too.
     */
    public function testExclusionAppliesToAnEvent(): void
    {
        // Arrange
        $fake    = new FakeDriver();
        $manager = $this->manager($fake);

        // Act
        $manager->except('12.34')->event($this->event());

        // Assert
        $fake->assertBroadcastExcept('12.34', 'private-order.42', 'order.paid');
        $fake->assertBroadcastExcept('12.34', 'ops', 'order.paid');
    }

    /**
     * An event naming no channels publishes nothing rather than failing.
     *
     * A conditional audience — "notify the assignee, if there is one" — legitimately
     * resolves to an empty list, and that is not an error.
     */
    public function testEventWithNoChannelsPublishesNothing(): void
    {
        // Arrange
        $fake    = new FakeDriver();
        $manager = $this->manager($fake);

        $event = new class implements BroadcastableEvent {
            public function broadcastOn(): array
            {
                return [];
            }

            public function broadcastAs(): string
            {
                return 'nobody.listening';
            }

            public function broadcastWith(): array
            {
                return [];
            }
        };

        // Act
        $manager->event($event);

        // Assert
        $fake->assertNothingBroadcast();
    }

    // -------------------------------------------------------------------------
    // Queued events
    // -------------------------------------------------------------------------

    /** A queue that records what it was pushed. */
    private function queue(): object
    {
        return new class extends \Pramnos\Queue\DelayedQueue {
            /** @var list<array{type:string,payload:array<string,mixed>}> */
            public array $pushed = [];

            public function __construct()
            {
                // No driver needed: push() is overridden.
            }

            public function push(string $type, array $payload, int $delaySeconds = 0): string
            {
                $this->pushed[] = ['type' => $type, 'payload' => $payload];

                return 'job-1';
            }
        };
    }

    /**
     * A queued event goes to the queue and is not published inline.
     */
    public function testQueuedEventGoesToTheQueue(): void
    {
        // Arrange
        $fake    = new FakeDriver();
        $queue   = $this->queue();
        $manager = $this->manager($fake)->useQueue($queue);

        // Act
        $manager->event($this->event(queued: true));

        // Assert
        $fake->assertNothingBroadcast();
        $this->assertCount(1, $queue->pushed);
        $this->assertSame(BroadcastingManager::QUEUED_EVENT_JOB, $queue->pushed[0]['type']);
    }

    /**
     * The queued job carries the resolved channels, name and payload — not the event
     * object.
     *
     * Deliberate: an event holding a model cannot arrive at a worker after the row
     * was deleted, cannot rebuild a stale copy of it, and cannot fail to unserialise
     * because a class moved. The cost is the mirror image, and is documented: the
     * payload describes the state at *dispatch* time.
     */
    public function testQueuedJobCarriesTheResolvedPayloadAndNotTheObject(): void
    {
        // Arrange
        $queue   = $this->queue();
        $manager = $this->manager(new FakeDriver())->useQueue($queue);

        // Act
        $manager->event($this->event(queued: true));

        // Assert
        $payload = $queue->pushed[0]['payload'];
        $this->assertSame(['private-order.42', 'ops'], $payload['channels']);
        $this->assertSame('order.paid', $payload['event']);
        $this->assertSame(['id' => 42], $payload['payload']);
        $this->assertSame('fake', $payload['driver'], 'the worker publishes on the same driver');

        // Nothing in the job may be an object, or it would need to unserialise.
        array_walk_recursive($payload, function ($value): void {
            $this->assertIsNotObject($value, 'a queued broadcast must carry no objects');
        });
    }

    /**
     * A queued event carries the exclusion, because the worker never sees the
     * request that caused it.
     */
    public function testQueuedJobCarriesTheExclusion(): void
    {
        // Arrange
        $queue   = $this->queue();
        $manager = $this->manager(new FakeDriver())->useQueue($queue);

        // Act
        $manager->except('12.34')->event($this->event(queued: true));

        // Assert
        $this->assertSame('12.34', $queue->pushed[0]['payload']['except']);
    }

    /**
     * With no exclusion, the field is null rather than absent, so a worker reads one
     * shape.
     */
    public function testQueuedJobHasANullExclusionWhenThereIsNone(): void
    {
        // Arrange
        $queue   = $this->queue();
        $manager = $this->manager(new FakeDriver())->useQueue($queue);

        // Act
        $manager->event($this->event(queued: true));

        // Assert
        $this->assertArrayHasKey('except', $queue->pushed[0]['payload']);
        $this->assertNull($queue->pushed[0]['payload']['except']);
    }

    /**
     * useQueue() is fluent and per-manager, so the exclusion clone keeps the queue.
     *
     * Without this, `except(...)->event(...)` on a queued event would resolve a fresh
     * Redis queue instead of the configured one — and in a test, would need Redis.
     */
    public function testTheExclusionCloneKeepsTheQueue(): void
    {
        // Arrange
        $queue   = $this->queue();
        $manager = $this->manager(new FakeDriver())->useQueue($queue);

        // Act
        $manager->except('1.2')->event($this->event(queued: true));

        // Assert
        $this->assertCount(1, $queue->pushed, 'the clone used the configured queue');
    }

    /**
     * A non-queued event ignores the queue entirely.
     */
    public function testPlainEventDoesNotTouchTheQueue(): void
    {
        // Arrange
        $fake    = new FakeDriver();
        $queue   = $this->queue();
        $manager = $this->manager($fake)->useQueue($queue);

        // Act
        $manager->event($this->event());

        // Assert
        $this->assertSame([], $queue->pushed);
        $fake->assertBroadcastCount(2);
    }
}
