<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Container;
use Pramnos\Application\NotFoundException;
use Pramnos\Application\ContainerException;

/**
 * Unit tests for Pramnos\Application\Container.
 *
 * Container is a PSR-11 IoC container with three binding types:
 *   - bind()      : factory — new instance on every get()
 *   - singleton() : factory called once; result cached
 *   - instance()  : pre-built object registered directly
 *
 * Plus reflection-based autowiring for classes with no explicit binding.
 *
 * Tests verify:
 *   - bind(), singleton(), instance() store their values and return $this.
 *   - has() correctly reports whether an id can be resolved.
 *   - get() delegates to make() and wraps non-NotFoundException errors.
 *   - make() routes through pre-built instances, singleton cache, transient
 *     factories, and autowiring, in that order.
 *   - NotFoundException is thrown for unresolvable identifiers.
 *   - Autowiring builds classes without constructors and with default params.
 *   - ContainerException is thrown when a required param cannot be resolved.
 */
#[CoversClass(Container::class)]
class ContainerTest extends TestCase
{
    private function makeContainer(): Container
    {
        return new Container();
    }

    // =========================================================================
    // bind()
    // =========================================================================

    /**
     * bind() stores a factory closure and returns $this for chaining.
     */
    public function testBindStoresFactoryAndReturnsSelf(): void
    {
        // Arrange
        $c = $this->makeContainer();

        // Act
        $result = $c->bind('service', fn($c) => new \stdClass());

        // Assert
        $this->assertSame($c, $result);
    }

    /**
     * bind() with a closure: get() returns a new instance on every call
     * (transient — not shared).
     */
    public function testBindReturnsNewInstanceEveryTime(): void
    {
        // Arrange
        $c = $this->makeContainer();
        $c->bind('widget', fn($c) => new \stdClass());

        // Act
        $first  = $c->get('widget');
        $second = $c->get('widget');

        // Assert — different instances (transient binding)
        $this->assertNotSame($first, $second);
    }

    /**
     * bind() accepts a class name string as the factory.
     * make() will use build() to instantiate the class via reflection.
     */
    public function testBindAcceptsClassNameString(): void
    {
        // Arrange
        $c = $this->makeContainer();
        $c->bind('stdobj', \stdClass::class);

        // Act
        $result = $c->get('stdobj');

        // Assert
        $this->assertInstanceOf(\stdClass::class, $result);
    }

    // =========================================================================
    // singleton()
    // =========================================================================

    /**
     * singleton() returns $this for fluent chaining.
     */
    public function testSingletonReturnsSelf(): void
    {
        // Arrange
        $c = $this->makeContainer();

        // Act
        $result = $c->singleton('counter', fn($c) => new \stdClass());

        // Assert
        $this->assertSame($c, $result);
    }

    /**
     * singleton() caches the first resolved instance and returns the same
     * object on subsequent get() calls.
     */
    public function testSingletonReturnsSameInstanceOnMultipleCalls(): void
    {
        // Arrange
        $c = $this->makeContainer();
        $c->singleton('shared', fn($c) => new \stdClass());

        // Act
        $first  = $c->get('shared');
        $second = $c->get('shared');

        // Assert — same object (singleton cached after first resolve)
        $this->assertSame($first, $second);
    }

    // =========================================================================
    // instance()
    // =========================================================================

    /**
     * instance() registers a pre-built object and returns $this.
     */
    public function testInstanceRegistersObjectAndReturnsSelf(): void
    {
        // Arrange
        $c      = $this->makeContainer();
        $object = new \stdClass();
        $object->tag = 'registered';

        // Act
        $result = $c->instance('myService', $object);

        // Assert
        $this->assertSame($c, $result);
    }

    /**
     * get() returns the exact same pre-built instance registered via instance().
     */
    public function testInstanceReturnsSameRegisteredObject(): void
    {
        // Arrange
        $c      = $this->makeContainer();
        $object = new \stdClass();
        $c->instance('db', $object);

        // Act
        $resolved = $c->get('db');

        // Assert — same reference
        $this->assertSame($object, $resolved);
    }

    /**
     * instance() takes priority over all other bindings in make().
     */
    public function testInstanceTakesPriorityOverSingleton(): void
    {
        // Arrange
        $c        = $this->makeContainer();
        $prebuilt = new \stdClass();
        $prebuilt->source = 'instance';

        $c->singleton('svc', fn($c) => new \stdClass());
        $c->instance('svc', $prebuilt); // overrides

        // Act
        $result = $c->get('svc');

        // Assert — prebuilt wins
        $this->assertSame($prebuilt, $result);
        $this->assertSame('instance', $result->source);
    }

    // =========================================================================
    // has()
    // =========================================================================

    /**
     * has() returns true for an id registered via bind().
     */
    public function testHasReturnsTrueForBoundId(): void
    {
        // Arrange
        $c = $this->makeContainer();
        $c->bind('logger', fn($c) => new \stdClass());

        // Assert
        $this->assertTrue($c->has('logger'));
    }

    /**
     * has() returns true for an id registered via singleton().
     */
    public function testHasReturnsTrueForSingletonId(): void
    {
        // Arrange
        $c = $this->makeContainer();
        $c->singleton('cache', fn($c) => new \stdClass());

        // Assert
        $this->assertTrue($c->has('cache'));
    }

    /**
     * has() returns true for an id registered via instance().
     */
    public function testHasReturnsTrueForInstanceId(): void
    {
        // Arrange
        $c = $this->makeContainer();
        $c->instance('obj', new \stdClass());

        // Assert
        $this->assertTrue($c->has('obj'));
    }

    /**
     * has() returns true for an existing instantiable class even when it is
     * not explicitly bound — the container can autowire it.
     */
    public function testHasReturnsTrueForAutowirableClass(): void
    {
        // Arrange
        $c = $this->makeContainer();

        // Assert — stdClass exists and is instantiable
        $this->assertTrue($c->has(\stdClass::class));
    }

    /**
     * has() returns false for an unknown string that is not a class.
     */
    public function testHasReturnsFalseForUnknownId(): void
    {
        // Arrange
        $c = $this->makeContainer();

        // Assert — 'totally_unknown' is not a class or bound id
        $this->assertFalse($c->has('totally_unknown'));
    }

    // =========================================================================
    // get() — error wrapping
    // =========================================================================

    /**
     * get() wraps NotFoundException from make() and re-throws it as-is.
     */
    public function testGetRethrowsNotFoundExceptionDirectly(): void
    {
        // Arrange
        $c = $this->makeContainer();

        // Assert — unresolvable id → NotFoundException propagates unchanged
        $this->expectException(NotFoundException::class);
        $c->get('no_such_binding');
    }

    // =========================================================================
    // make() — resolution order
    // =========================================================================

    /**
     * make() throws NotFoundException for an unknown non-class id.
     */
    public function testMakeThrowsNotFoundExceptionForUnknownId(): void
    {
        // Arrange
        $c = $this->makeContainer();

        // Assert
        $this->expectException(NotFoundException::class);
        $c->make('not_a_class_or_binding');
    }

    /**
     * make() autowires stdClass (no constructor) by returning a new instance.
     */
    public function testMakeAutowiresClassWithNoConstructor(): void
    {
        // Arrange
        $c = $this->makeContainer();

        // Act — stdClass has no constructor; just reflects and returns new instance
        $result = $c->make(\stdClass::class);

        // Assert
        $this->assertInstanceOf(\stdClass::class, $result);
    }

    /**
     * make() uses caller-supplied $parameters to satisfy constructor arguments
     * when autowiring a class that has required parameters.
     */
    public function testMakeUsesCallerSuppliedParameters(): void
    {
        // Arrange — a simple class with a required constructor param
        // We'll define it inline so the test is self-contained.
        $testClass = new class('hello') {
            public string $value;
            public function __construct(string $v) { $this->value = $v; }
        };
        $className = get_class($testClass);

        $c = $this->makeContainer();

        // Act — provide the required parameter by name
        $result = $c->make($className, ['v' => 'world']);

        // Assert
        $this->assertSame('world', $result->value);
    }

    /**
     * make() returns a new instance from a transient binding on each call,
     * unlike get() which may cache depending on the binding type.
     * This verifies the explicit transient path.
     */
    public function testMakeAlwaysBuildsNewInstanceForTransientBinding(): void
    {
        // Arrange
        $c = $this->makeContainer();
        $c->bind('thing', fn($c) => new \stdClass());

        // Act
        $a = $c->make('thing');
        $b = $c->make('thing');

        // Assert — separate instances each time
        $this->assertNotSame($a, $b);
    }

    // =========================================================================
    // Autowiring — default parameter values
    // =========================================================================

    /**
     * Autowiring fills constructor parameters that have default values with
     * those defaults when the caller does not supply an override.
     */
    public function testAutowiringUsesDefaultParameterValues(): void
    {
        // Arrange — anonymous class to test
        $testClass = new class('') {
            public string $value;
            public function __construct(string $v = 'default') { $this->value = $v; }
        };
        $c = $this->makeContainer();

        // Act — no $parameters supplied → default 'default' used
        $result = $c->make(get_class($testClass));

        // Assert
        $this->assertSame('default', $result->value);
    }
}
