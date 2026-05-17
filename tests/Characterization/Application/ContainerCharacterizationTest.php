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
     * has() must return true when the same identifier is registered in all
     * three internal stores (instances, singletons, bindings) simultaneously.
     *
     * This covers the triple-isset early return in Container::has() which short-
     * circuits when the ID exists in all stores at once (unusual but valid).
     */
    public function testHasReturnsTrueWhenIdInAllThreeStores(): void
    {
        // Arrange — register the same ID in all three internal stores
        $this->container->instance('triple.id', new \stdClass());
        $this->container->singleton('triple.id', fn() => new \stdClass());
        $this->container->bind('triple.id', fn() => new \stdClass());

        // Act / Assert — triple-isset path returns true
        $this->assertTrue($this->container->has('triple.id'));
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

    /**
     * get() must wrap any non-NotFoundException thrown during resolution in a
     * ContainerException (PSR-11 §3 ContainerExceptionInterface requirement).
     *
     * This covers the `catch (\Throwable $e) { throw new ContainerException ... }`
     * branch in Container::get().
     */
    public function testGetWrapsNonNotFoundExceptionAsContainerException(): void
    {
        // Arrange — factory that throws a plain RuntimeException
        $this->container->bind('bad.service', fn() => throw new \RuntimeException('build failed'));

        // Assert
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/build failed/');

        // Act
        $this->container->get('bad.service');
    }

    /**
     * When bind() is given a class-name string that does not exist, get() must
     * throw NotFoundException because the resolve→build path catches the
     * ReflectionException and converts it.
     *
     * This covers the ReflectionException catch block in Container::build().
     */
    public function testBuildThrowsNotFoundForNonExistentClassBinding(): void
    {
        // Arrange
        $this->container->bind('ghost', 'NoSuch\\Class\\Anywhere');

        // Assert
        $this->expectException(NotFoundException::class);

        // Act
        $this->container->get('ghost');
    }

    /**
     * Attempting to instantiate an abstract class or interface must throw
     * NotFoundException because the container cannot call `new` on it.
     *
     * This covers the `!$ref->isInstantiable()` check in Container::build().
     */
    public function testBuildThrowsNotFoundForAbstractClass(): void
    {
        // Arrange — ContainerFixtureAbstract is abstract, defined below
        // Assert
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessageMatches('/not instantiable/');

        // Act
        $this->container->make(ContainerFixtureAbstract::class);
    }

    /**
     * make() with a positional (integer-keyed) override must pass that value
     * as the constructor argument at that position, bypassing autowiring.
     *
     * This covers the `array_key_exists($i, $overrides)` branch in
     * Container::resolveParameters().
     */
    public function testMakeWithPositionalParameterOverride(): void
    {
        // Act — 0 is the position of $name in ContainerFixtureService::__construct
        $service = $this->container->make(ContainerFixtureService::class, [0 => 'positional']);

        // Assert
        $this->assertSame('positional', $service->name);
    }

    /**
     * When an unresolvable required type-hinted constructor parameter exists,
     * make() must throw ContainerException (not NotFoundException), because the
     * class itself exists but cannot be constructed.
     *
     * This covers the ContainerException throw in Container::resolveParameters()
     * for the case where the dependency is an unresolvable type-hint.
     */
    public function testMakeThrowsContainerExceptionForUnresolvableRequiredDependency(): void
    {
        // Arrange — ContainerFixtureRequiresAbstract requires ContainerFixtureAbstract,
        //           which is abstract and cannot be instantiated.
        // Assert
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/Cannot resolve parameter/');

        // Act
        $this->container->make(ContainerFixtureRequiresAbstract::class);
    }

    /**
     * Instantiating a class with no constructor at all (not even an empty one)
     * must succeed via ReflectionClass::newInstance(), the zero-arg path in build().
     *
     * This covers the `if ($constructor === null) { return $ref->newInstance(); }`
     * branch in Container::build().
     */
    public function testMakeInstantiatesClassWithNoExplicitConstructor(): void
    {
        // Act — ContainerFixtureNoConstructor has no constructor declaration at all
        $obj = $this->container->make(ContainerFixtureNoConstructor::class);

        // Assert
        $this->assertInstanceOf(ContainerFixtureNoConstructor::class, $obj);
    }

    /**
     * When make() is called without overrides and a scalar constructor parameter
     * has a default value, the default must be used automatically.
     *
     * This covers the `if ($param->isDefaultValueAvailable())` branch in
     * Container::resolveParameters() (built-in type, no override supplied).
     */
    public function testMakeWithNoOverridesUsesParameterDefaultValue(): void
    {
        // Act — ContainerFixtureService has `string $name = 'default'`; no overrides
        $service = $this->container->make(ContainerFixtureService::class);

        // Assert — default value was used
        $this->assertSame('default', $service->name);
    }

    /**
     * A nullable scalar parameter with no default value must resolve to null
     * when make() is called without overrides.
     *
     * This covers the `if ($param->allowsNull()) { $args[] = null; }` branch
     * in Container::resolveParameters().
     */
    public function testMakeResolvesNullableParameterAsNull(): void
    {
        // Act — ContainerFixtureNullableRequired has `?string $name` (nullable, no default)
        $obj = $this->container->make(ContainerFixtureNullableRequired::class);

        // Assert — nullable parameter with no default receives null
        $this->assertNull($obj->name);
    }

    /**
     * A required scalar parameter with no type-hint default or nullable marker
     * must cause ContainerException when make() cannot resolve it.
     *
     * This covers the final `throw new ContainerException("Cannot resolve required...")` in
     * Container::resolveParameters() when the parameter is non-optional, non-nullable,
     * and has no default value.
     */
    public function testMakeThrowsContainerExceptionForRequiredScalarWithoutDefault(): void
    {
        // Assert
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/Cannot resolve required parameter/');

        // Act — ContainerFixtureRequiredScalar has `string $required` with no default
        $this->container->make(ContainerFixtureRequiredScalar::class);
    }

    /**
     * An optional type-hinted parameter (abstract class with `= null` default)
     * must silently take its default value when the dependency cannot be resolved.
     *
     * This covers the `if ($param->isOptional()) { $args[] = $param->getDefaultValue(); }`
     * branch in Container::resolveParameters() after a NotFoundException.
     */
    public function testMakeResolvesOptionalUnresolvableDependencyWithDefault(): void
    {
        // Act — ContainerFixtureOptionalAbstractDep has `?ContainerFixtureAbstract $dep = null`
        //       The container cannot instantiate ContainerFixtureAbstract (abstract),
        //       so it falls back to the default value (null).
        $obj = $this->container->make(ContainerFixtureOptionalAbstractDep::class);

        // Assert — default null was used instead of throwing
        $this->assertNull($obj->dep);
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

/** Abstract class — cannot be instantiated; used to test NotFoundException on non-instantiable. */
abstract class ContainerFixtureAbstract
{
    abstract public function doSomething(): void;
}

/** Requires ContainerFixtureAbstract — cannot be autowired since the dep is abstract. */
class ContainerFixtureRequiresAbstract
{
    public function __construct(public readonly ContainerFixtureAbstract $dep) {}
}

/** No constructor declaration at all — tests ReflectionClass::newInstance() path. */
class ContainerFixtureNoConstructor
{
    public string $value = 'no-constructor';
}

/** Nullable required parameter (no default) — tests $param->allowsNull() path. */
class ContainerFixtureNullableRequired
{
    public function __construct(public readonly ?string $name) {}
}

/** Required scalar parameter with no default — tests final ContainerException path. */
class ContainerFixtureRequiredScalar
{
    public function __construct(public readonly string $required) {}
}

/** Optional abstract dependency with null default — tests optional type-hint fallback. */
class ContainerFixtureOptionalAbstractDep
{
    public function __construct(public readonly ?ContainerFixtureAbstract $dep = null) {}
}
