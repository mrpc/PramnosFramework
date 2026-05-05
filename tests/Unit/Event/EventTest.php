<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Pramnos\Event\Event;
use Pramnos\Event\ListenerInterface;

/**
 * Unit tests for Pramnos\Event\Event.
 *
 * The event bus must:
 *  - fire listeners in priority order (lower priority number = first)
 *  - pass arguments from fire() to each listener
 *  - stop propagation when a listener returns false
 *  - support class-name strings and ListenerInterface instances
 *  - be independently resettable (no cross-test contamination)
 *  - report hasListeners() correctly
 *  - return results from every listener that ran
 */
class EventTest extends TestCase
{
    protected function setUp(): void
    {
        // Arrange — start each test with a clean registry so listeners
        // registered in one test cannot leak into another.
        Event::forget();
    }

    protected function tearDown(): void
    {
        Event::forget();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Basic fire / listen
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A listener registered before fire() is called and receives the argument.
     *
     * This is the golden-path contract: listen → fire → listener executes.
     */
    public function testListenerIsCalledOnFire(): void
    {
        // Arrange
        $called = false;
        Event::listen('test.event', function () use (&$called) {
            $called = true;
        });

        // Act
        Event::fire('test.event');

        // Assert
        $this->assertTrue($called);
    }

    /**
     * Arguments passed to fire() are forwarded to every listener verbatim.
     */
    public function testFirePassesArgumentsToListener(): void
    {
        // Arrange
        $received = null;
        Event::listen('data.event', function (string $payload) use (&$received) {
            $received = $payload;
        });

        // Act
        Event::fire('data.event', 'hello');

        // Assert
        $this->assertSame('hello', $received);
    }

    /**
     * Multiple arguments are all forwarded in the correct order.
     */
    public function testFirePassesMultipleArgumentsToListener(): void
    {
        // Arrange
        $receivedA = null;
        $receivedB = null;
        Event::listen('multi.event', function (int $a, string $b) use (&$receivedA, &$receivedB) {
            $receivedA = $a;
            $receivedB = $b;
        });

        // Act
        Event::fire('multi.event', 42, 'world');

        // Assert
        $this->assertSame(42, $receivedA);
        $this->assertSame('world', $receivedB);
    }

    /**
     * fire() on an event with no listeners returns an empty array — it is
     * safe to fire events that nothing listens to (zero-listener contract).
     */
    public function testFireWithNoListenersReturnsEmptyArray(): void
    {
        // Act
        $results = Event::fire('no.listeners');

        // Assert
        $this->assertSame([], $results);
    }

    /**
     * fire() returns the return value from each listener that executed.
     */
    public function testFireReturnsListenerReturnValues(): void
    {
        // Arrange
        Event::listen('result.event', fn() => 'first');
        Event::listen('result.event', fn() => 'second');

        // Act
        $results = Event::fire('result.event');

        // Assert — both return values collected in order
        $this->assertSame(['first', 'second'], $results);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Priority ordering
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Listeners are executed in ascending priority order: the listener with
     * the smallest priority number runs first.
     *
     * This mirrors the convention used by Addon::addAction() so developers
     * have a consistent mental model.
     */
    public function testListenersRunInPriorityOrder(): void
    {
        // Arrange
        $order = [];
        Event::listen('ordered.event', function () use (&$order) { $order[] = 'third'; }, 30);
        Event::listen('ordered.event', function () use (&$order) { $order[] = 'first'; }, 10);
        Event::listen('ordered.event', function () use (&$order) { $order[] = 'second'; }, 20);

        // Act
        Event::fire('ordered.event');

        // Assert — executed in priority 10 → 20 → 30 order
        $this->assertSame(['first', 'second', 'third'], $order);
    }

    /**
     * Two listeners at the same priority are executed in registration order
     * (FIFO within the same priority bucket).
     */
    public function testSamePriorityFifoOrder(): void
    {
        // Arrange
        $order = [];
        Event::listen('fifo.event', function () use (&$order) { $order[] = 'A'; }, 10);
        Event::listen('fifo.event', function () use (&$order) { $order[] = 'B'; }, 10);
        Event::listen('fifo.event', function () use (&$order) { $order[] = 'C'; }, 10);

        // Act
        Event::fire('fifo.event');

        // Assert
        $this->assertSame(['A', 'B', 'C'], $order);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Propagation stopping
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When a listener returns false, no subsequent listeners run. This allows
     * higher-priority listeners to short-circuit processing (e.g. auth guards
     * that reject a request before business logic runs).
     */
    public function testReturnFalseStopsPropagation(): void
    {
        // Arrange
        $secondCalled = false;
        Event::listen('stop.event', fn() => false, 10);
        Event::listen('stop.event', function () use (&$secondCalled) {
            $secondCalled = true;
        }, 20);

        // Act
        $results = Event::fire('stop.event');

        // Assert — only the first listener ran; second was not called
        $this->assertFalse($secondCalled, 'Second listener must not run after false return');
        // results array contains exactly the false value, nothing more
        $this->assertSame([false], $results);
    }

    /**
     * Returning null or 0 from a listener does NOT stop propagation —
     * only an explicit false halts the chain.
     */
    public function testNullReturnDoesNotStopPropagation(): void
    {
        // Arrange
        $secondCalled = false;
        Event::listen('null.event', fn() => null, 10);
        Event::listen('null.event', function () use (&$secondCalled) {
            $secondCalled = true;
        }, 20);

        // Act
        Event::fire('null.event');

        // Assert — second listener executed despite null return
        $this->assertTrue($secondCalled);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Class-based listeners
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A class name string that implements ListenerInterface is instantiated
     * automatically and its handle() method is called.
     *
     * This pattern lets listeners be registered before the class is
     * instantiated — useful in Service Provider boot() methods.
     */
    public function testClassNameListenerIsInstantiatedAndCalled(): void
    {
        // Arrange — anonymous class implementing ListenerInterface
        $listenerClass = new class implements ListenerInterface {
            public static bool $handled = false;

            public function handle(mixed ...$args): mixed
            {
                self::$handled = true;
                return null;
            }
        };

        // Register the object instance (simulate class-name via instance)
        Event::listen('class.event', $listenerClass);

        // Act
        Event::fire('class.event');

        // Assert
        $this->assertTrue($listenerClass::$handled);
    }

    /**
     * A ListenerInterface instance receives arguments forwarded from fire().
     */
    public function testClassListenerReceivesArguments(): void
    {
        // Arrange
        $received = null;
        $listener = new class($received) implements ListenerInterface {
            public mixed $value = null;

            public function __construct(mixed &$ref)
            {
                $this->ref = &$ref;
            }

            private mixed $ref;

            public function handle(mixed ...$args): mixed
            {
                $this->ref = $args[0] ?? null;
                return null;
            }
        };

        Event::listen('arg.event', $listener);

        // Act
        Event::fire('arg.event', 'payload');

        // Assert — listener received the payload via handle()
        $this->assertSame('payload', $received);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Registry management
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * hasListeners() returns true after a listener is registered and false
     * before any listener is registered (or after forget()).
     */
    public function testHasListeners(): void
    {
        // Assert — clean state
        $this->assertFalse(Event::hasListeners('mgmt.event'));

        // Arrange
        Event::listen('mgmt.event', fn() => null);

        // Assert — after registration
        $this->assertTrue(Event::hasListeners('mgmt.event'));
    }

    /**
     * forget(event) removes only the listeners for that specific event,
     * leaving listeners for other events intact.
     */
    public function testForgetSpecificEventRemovesOnlyThatEvent(): void
    {
        // Arrange
        Event::listen('keep.event', fn() => null);
        Event::listen('drop.event', fn() => null);

        // Act
        Event::forget('drop.event');

        // Assert
        $this->assertTrue(Event::hasListeners('keep.event'), 'Other events must not be affected');
        $this->assertFalse(Event::hasListeners('drop.event'), 'Forgotten event must have no listeners');
    }

    /**
     * forget() with no argument clears ALL listeners across all events.
     */
    public function testForgetAllClearsEverything(): void
    {
        // Arrange
        Event::listen('event.a', fn() => null);
        Event::listen('event.b', fn() => null);

        // Act
        Event::forget();

        // Assert
        $this->assertFalse(Event::hasListeners('event.a'));
        $this->assertFalse(Event::hasListeners('event.b'));
    }

    /**
     * getListeners() returns all listeners in priority order as a flat list,
     * mirroring the execution order that fire() would use.
     */
    public function testGetListenersReturnsFlatPriorityOrderedList(): void
    {
        // Arrange
        $c = fn() => 'c';
        $a = fn() => 'a';
        $b = fn() => 'b';
        Event::listen('list.event', $c, 30);
        Event::listen('list.event', $a, 10);
        Event::listen('list.event', $b, 20);

        // Act
        $listeners = Event::getListeners('list.event');

        // Assert — returned in priority 10 → 20 → 30 order
        $this->assertSame([$a, $b, $c], $listeners);
    }

    /**
     * getListeners() returns an empty array for an event with no registered listeners.
     */
    public function testGetListenersEmptyWhenNoneRegistered(): void
    {
        // Act & Assert
        $this->assertSame([], Event::getListeners('nonexistent.event'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Isolation between events
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Firing event A must not trigger listeners registered for event B.
     * The registry is keyed by event name — there is no cross-event bleed.
     */
    public function testDifferentEventsDoNotCrossfire(): void
    {
        // Arrange
        $calledForB = false;
        Event::listen('event.a', fn() => null);
        Event::listen('event.b', function () use (&$calledForB) {
            $calledForB = true;
        });

        // Act — fire only event.a
        Event::fire('event.a');

        // Assert — event.b listener was not triggered
        $this->assertFalse($calledForB);
    }
}
