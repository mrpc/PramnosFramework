<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Pramnos\Application\Container;
use Pramnos\Application\ContainerException;
use Pramnos\Application\NotFoundException;

/**
 * Characterization tests for the PSR-11 Container implementation.
 *
 * These tests lock the contract of Container before any refactoring:
 * - PSR-11 type compliance (ContainerInterface).
 * - bind() produces a new instance on every get().
 * - singleton() produces the same instance across multiple get() calls.
 * - instance() always returns the pre-built object.
 * - Autowiring resolves constructor type-hints recursively.
 * - NotFoundException is thrown for unknown bindings.
 * - ContainerException wraps build failures.
 * - has() returns true for bound or instantiable identifiers.
 */
#[CoversClass(Container::class)]
#[CoversClass(NotFoundException::class)]
#[CoversClass(ContainerException::class)]
class ContainerCharacterizationTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        // Arrange — fresh container for every test
        $this->container = new Container();
    }

    // -------------------------------------------------------------------------

    /**
     * Container must implement Psr\Container\ContainerInterface so that
     * any PSR-11-aware library can accept it by type.
     */
    public function testImplementsPsrContainerInterface(): void
    {
        // Assert
        $this->assertInstanceOf(ContainerInterface::class, $this->container);
    }

    /**
     * bind() registers a transient factory: each call to get() / make()
     * must produce a new, distinct object instance.
     */
    public function testBindProducesNewInstanceOnEveryGet(): void
    {
        // Arrange
        $this->container->bind(
            ContainerFixtureService::class,
            fn() => new ContainerFixtureService('bound')
        );

        // Act
        $first  = $this->container->get(ContainerFixtureService::class);
        $second = $this->container->get(ContainerFixtureService::class);

        // Assert — transient: two distinct objects
        $this->assertNotSame($first, $second);
    }

    /**
     * singleton() ensures the factory is executed only once; subsequent calls
     * must return the same cached instance (object identity check).
     */
    public function testSingletonReturnsSameInstance(): void
    {
        // Arrange
        $this->container->singleton(
            ContainerFixtureService::class,
            fn() => new ContainerFixtureService('singleton')
        );

        // Act
        $first  = $this->container->get(ContainerFixtureService::class);
        $second = $this->container->get(ContainerFixtureService::class);

        // Assert — same object (=== comparison)
        $this->assertSame($first, $second);
    }

    /**
     * instance() registers a pre-built object; every get() must return
     * that exact object regardless of how many times it is called.
     */
    public function testInstanceAlwaysReturnsSamePrebuiltObject(): void
    {
        // Arrange
        $service = new ContainerFixtureService('pre-built');
        $this->container->instance(ContainerFixtureService::class, $service);

        // Act
        $resolved = $this->container->get(ContainerFixtureService::class);

        // Assert
        $this->assertSame($service, $resolved);
    }

    /**
     * Autowiring: when no explicit binding exists, the container should
     * instantiate a concrete class that has a no-argument constructor.
     */
    public function testAutowiringInstantiatesConcreteClassWithNoArgs(): void
    {
        // Act
        $service = $this->container->get(ContainerFixtureNoArgs::class);

        // Assert
        $this->assertInstanceOf(ContainerFixtureNoArgs::class, $service);
    }

    /**
     * Autowiring: constructor parameter type-hints to other concrete classes
     * must be recursively resolved without explicit bindings.
     */
    public function testAutowiringResolvesTypehintedDependencies(): void
    {
        // Act — ContainerFixtureDependent requires ContainerFixtureNoArgs
        $obj = $this->container->get(ContainerFixtureDependent::class);

        // Assert
        $this->assertInstanceOf(ContainerFixtureDependent::class, $obj);
        $this->assertInstanceOf(ContainerFixtureNoArgs::class, $obj->dep);
    }

    /**
     * get() must throw NotFoundException (implementing NotFoundExceptionInterface)
     * for an identifier that is not bound and not an instantiable class.
     * Per PSR-11 §3: "If the entry is not found, a NotFoundException MUST be thrown."
     */
    public function testGetThrowsNotFoundExceptionForUnknownId(): void
    {
        // Assert
        $this->expectException(NotFoundException::class);

        // Act
        $this->container->get('NonExistentClass\That\DoesNotExist');
    }

    /**
     * has() must return true when the identifier has an explicit binding,
     * so callers can probe the container without triggering exception paths.
     */
    public function testHasReturnsTrueForBoundId(): void
    {
        // Arrange
        $this->container->bind('my.service', fn() => new \stdClass());

        // Act / Assert
        $this->assertTrue($this->container->has('my.service'));
    }

    /**
     * has() must return false for an identifier that is neither bound
     * nor resolvable as a concrete class.
     */
    public function testHasReturnsFalseForUnknownId(): void
    {
        // Act / Assert
        $this->assertFalse($this->container->has('Totally\Unknown\Thing'));
    }

    /**
     * has() must return true for an instantiable class even without an explicit
     * binding, because the container can autowire it.
     */
    public function testHasReturnsTrueForInstantiableClassWithoutBinding(): void
    {
        // Act / Assert
        $this->assertTrue($this->container->has(ContainerFixtureNoArgs::class));
    }

    /**
     * make() with $parameters overrides must pass named arguments to the
     * constructor, bypassing autowiring for those positions.
     */
    public function testMakeWithParameterOverridesPassesArguments(): void
    {
        // Act
        $service = $this->container->make(ContainerFixtureService::class, ['name' => 'overridden']);

        // Assert
        $this->assertSame('overridden', $service->name);
    }

    /**
     * Binding a string class name (not a closure) as a factory must work:
     * the container resolves it as a FQCN and instantiates that class.
     */
    public function testBindWithClassNameStringResolves(): void
    {
        // Arrange
        $this->container->bind('fixture.noargs', ContainerFixtureNoArgs::class);

        // Act
        $result = $this->container->get('fixture.noargs');

        // Assert
        $this->assertInstanceOf(ContainerFixtureNoArgs::class, $result);
    }
}

// ---------------------------------------------------------------------------
// Inline fixture classes (not shipped into production src)
// ---------------------------------------------------------------------------

/** A simple service with a single string property — used to verify bind/make. */
class ContainerFixtureService
{
    public function __construct(public readonly string $name = 'default') {}
}

/** A no-arg concrete class — used to test pure autowiring. */
class ContainerFixtureNoArgs
{
    public function __construct() {}
}

/** Depends on ContainerFixtureNoArgs — tests recursive autowiring. */
class ContainerFixtureDependent
{
    public function __construct(public readonly ContainerFixtureNoArgs $dep) {}
}
