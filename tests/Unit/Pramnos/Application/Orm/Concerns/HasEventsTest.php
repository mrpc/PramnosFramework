<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Orm\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Orm\Concerns\HasEvents;

/**
 * Unit tests for Pramnos\Application\Orm\Concerns\HasEvents.
 *
 * HasEvents provides a lightweight observer/callback system keyed by model class.
 * All listener storage is static — tests must flush listeners after each test to
 * prevent cross-test pollution.
 *
 * Tests verify:
 *   - on() registers a callable for a named event.
 *   - observe() registers observer object methods that match event names.
 *   - fireEvent() invokes all listeners and returns true normally.
 *   - fireEvent() returns false when a listener explicitly returns false.
 *   - flushEventListeners() removes all listeners for the model class.
 */
#[CoversClass(HasEvents::class)]
class HasEventsTest extends TestCase
{
    // =========================================================================
    // Fixture — two independent model classes so they don't share listeners
    // =========================================================================

    /** @var class-string */
    private string $modelClass;

    protected function setUp(): void
    {
        // Anonymous class with HasEvents — each test gets a fresh class via
        // the stored class name.  We flush after every test.
        $this->modelClass = get_class(new class {
            use HasEvents;

            public function fire(string $event): bool
            {
                return $this->fireEvent($event);
            }
        });
    }

    protected function tearDown(): void
    {
        // Flush listeners so tests are independent
        $this->modelClass::flushEventListeners();
    }

    // =========================================================================
    // on() / fireEvent()
    // =========================================================================

    /**
     * on() registers a callback; fireEvent() invokes it and returns true when
     * the callback does not explicitly return false.
     */
    public function testOnRegistersCallbackAndFireEventInvokesIt(): void
    {
        // Arrange
        $called = false;
        $class  = $this->modelClass;
        $class::on('created', function () use (&$called) {
            $called = true;
        });
        $instance = new $class();

        // Act
        $result = $instance->fire('created');

        // Assert — listener fired, return value is true
        $this->assertTrue($called);
        $this->assertTrue($result);
    }

    /**
     * fireEvent() returns false when a listener explicitly returns false.
     * This allows "before" events (creating, updating, deleting) to cancel
     * the DB operation.
     */
    public function testFireEventReturnsFalseWhenListenerReturnsFalse(): void
    {
        // Arrange
        $class = $this->modelClass;
        $class::on('creating', fn() => false);
        $instance = new $class();

        // Act
        $result = $instance->fire('creating');

        // Assert — cancellation propagated
        $this->assertFalse($result);
    }

    /**
     * fireEvent() returns true when no listeners are registered for the event.
     */
    public function testFireEventReturnsTrueWhenNoListenersRegistered(): void
    {
        // Arrange — no listeners for 'deleted'
        $class    = $this->modelClass;
        $instance = new $class();

        // Act
        $result = $instance->fire('deleted');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Multiple listeners are invoked in registration order; all must run unless
     * one returns false.
     */
    public function testFireEventCallsAllListenersInOrder(): void
    {
        // Arrange
        $log   = [];
        $class = $this->modelClass;
        $class::on('updated', function () use (&$log) { $log[] = 'first'; });
        $class::on('updated', function () use (&$log) { $log[] = 'second'; });
        $instance = new $class();

        // Act
        $instance->fire('updated');

        // Assert — both fired in order
        $this->assertSame(['first', 'second'], $log);
    }

    // =========================================================================
    // observe()
    // =========================================================================

    /**
     * observe() registers the observer's methods for matching event names.
     * Methods whose names don't match event names are ignored.
     */
    public function testObserveRegistersMatchingObserverMethods(): void
    {
        // Arrange — anonymous observer that handles 'created' and 'deleting'
        $log      = [];
        $observer = new class($log) {
            private array $log;
            public function __construct(array &$log) { $this->log = &$log; }
            public function created(): void    { $this->log[] = 'created'; }
            public function deleting(): void   { $this->log[] = 'deleting'; }
            public function irrelevant(): void { $this->log[] = 'irrelevant'; }
        };

        $class = $this->modelClass;
        $class::observe($observer);
        $instance = new $class();

        // Act — fire 'created'
        $instance->fire('created');

        // Assert — only 'created' listener ran; 'irrelevant' was not registered
        $this->assertSame(['created'], $log);

        // Act — fire 'deleting'
        $instance->fire('deleting');

        // Assert
        $this->assertContains('deleting', $log);
        $this->assertNotContains('irrelevant', $log);
    }

    // =========================================================================
    // flushEventListeners()
    // =========================================================================

    /**
     * flushEventListeners() removes all listeners for the specific model class,
     * so subsequent fireEvent() calls return true without invoking any callback.
     */
    public function testFlushEventListenersRemovesAllListeners(): void
    {
        // Arrange — register a listener
        $called = false;
        $class  = $this->modelClass;
        $class::on('created', function () use (&$called) { $called = true; });

        // Act — flush, then fire
        $class::flushEventListeners();
        $instance = new $class();
        $result   = $instance->fire('created');

        // Assert — listener not called, fireEvent still returns true
        $this->assertFalse($called);
        $this->assertTrue($result);
    }
}
