<?php

declare(strict_types=1);

namespace Pramnos\Application;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

/**
 * PSR-11 compliant IoC container with constructor-injection autowiring.
 *
 * ## Binding types
 *
 * | Method        | Behaviour                                                    |
 * |---------------|--------------------------------------------------------------|
 * | `bind()`      | Factory — new instance on every `get()` / `make()`          |
 * | `singleton()` | Factory called once; result cached for the container lifetime|
 * | `instance()`  | Register a pre-built object as a shared instance             |
 *
 * ## Autowiring
 *
 * When `make($id)` is called for a class that has no explicit binding, the
 * container attempts to satisfy its constructor parameters automatically:
 *
 * - Type-hinted class/interface params are resolved recursively via `make()`.
 * - Parameters with a default value use the default when not otherwise resolvable.
 * - Unresolvable required parameters cause a `ContainerException`.
 *
 * ## Usage
 *
 * ```php
 * $c = new Container();
 *
 * // Bind an interface to a concrete class
 * $c->bind(LoggerInterface::class, FileLogger::class);
 *
 * // Singleton
 * $c->singleton(Database::class, fn() => new Database($config));
 *
 * // Pre-built instance
 * $c->instance('config', $configObject);
 *
 * // Resolve
 * $db = $c->get(Database::class);
 * ```
 *
 * @see         https://www.php-fig.org/psr/psr-11/
 */
class Container implements ContainerInterface
{
    /** @var array<string, callable|string> Factory closures or class names. */
    private array $bindings = [];

    /** @var array<string, callable|string> Factories whose result is cached. */
    private array $singletons = [];

    /** @var array<string, mixed> Resolved singleton + pre-built instances. */
    private array $instances = [];

    // -------------------------------------------------------------------------
    // Registration API
    // -------------------------------------------------------------------------

    /**
     * Bind an abstract identifier to a factory (new instance on every resolve).
     *
     * @param  string          $id       Interface FQCN, class FQCN, or arbitrary key.
     * @param  callable|string $factory  Closure or concrete FQCN.
     * @return static
     */
    public function bind(string $id, callable|string $factory): static
    {
        $this->bindings[$id] = $factory;
        return $this;
    }

    /**
     * Bind an abstract identifier as a singleton (factory called once, result cached).
     *
     * @param  string          $id       Interface / class FQCN or arbitrary key.
     * @param  callable|string $factory  Closure or concrete FQCN.
     * @return static
     */
    public function singleton(string $id, callable|string $factory): static
    {
        $this->singletons[$id] = $factory;
        return $this;
    }

    /**
     * Register an already-instantiated object as a shared instance.
     * Equivalent to `singleton()` with a factory that always returns $concrete.
     *
     * @param  string $id
     * @param  mixed  $concrete
     * @return static
     */
    public function instance(string $id, mixed $concrete): static
    {
        $this->instances[$id] = $concrete;
        return $this;
    }

    // -------------------------------------------------------------------------
    // PSR-11 ContainerInterface
    // -------------------------------------------------------------------------

    /**
     * Resolve and return an entry by identifier.
     *
     * @throws NotFoundException            If the identifier is not bound and cannot be autowired.
     * @throws ContainerException           If resolution fails for any other reason.
     */
    public function get(string $id): mixed
    {
        try {
            return $this->make($id);
        } catch (NotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ContainerException(
                "Error resolving '{$id}': " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Return true when the container has an explicit binding or a pre-built
     * instance for $id, OR when $id is an instantiable class.
     */
    public function has(string $id): bool
    {
        if (isset($this->instances[$id], $this->singletons[$id], $this->bindings[$id])) {
            return true;
        }
        if (isset($this->instances[$id]) || isset($this->singletons[$id]) || isset($this->bindings[$id])) {
            return true;
        }
        return class_exists($id) && (new \ReflectionClass($id))->isInstantiable();
    }

    // -------------------------------------------------------------------------
    // Extended make() API
    // -------------------------------------------------------------------------

    /**
     * Resolve $id and return the concrete, optionally overriding constructor
     * parameters via $parameters (keyed by parameter name or position).
     *
     * Unlike `get()`, `make()` always builds a new instance even for singletons,
     * unless a pre-built `instance()` is registered.
     *
     * @param  string  $id
     * @param  mixed[] $parameters  Optional overrides for constructor arguments.
     * @return mixed
     * @throws NotFoundException   If $id cannot be resolved.
     * @throws ContainerException  If construction fails.
     */
    public function make(string $id, array $parameters = []): mixed
    {
        // 1. Pre-built instance — always returned as-is
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // 2. Singleton binding — resolve once and cache
        if (isset($this->singletons[$id])) {
            $this->instances[$id] = $this->resolve($this->singletons[$id], $parameters);
            return $this->instances[$id];
        }

        // 3. Transient binding — new instance every time
        if (isset($this->bindings[$id])) {
            return $this->resolve($this->bindings[$id], $parameters);
        }

        // 4. Autowire: if $id is an instantiable class, build it via reflection
        if (class_exists($id)) {
            return $this->build($id, $parameters);
        }

        throw new NotFoundException("No binding found for '{$id}'");
    }

    // -------------------------------------------------------------------------
    // Internal resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve a factory (callable or FQCN string) to a concrete value.
     */
    private function resolve(callable|string $factory, array $parameters): mixed
    {
        if (is_callable($factory)) {
            return $factory($this, $parameters);
        }

        // String: treat as a class name
        return $this->build($factory, $parameters);
    }

    /**
     * Instantiate $class using reflection-based constructor injection.
     *
     * @throws NotFoundException   If $class doesn't exist or is not instantiable.
     * @throws ContainerException  If a required constructor parameter cannot be resolved.
     */
    private function build(string $class, array $parameters): mixed
    {
        try {
            $ref = new \ReflectionClass($class);
        } catch (\ReflectionException $e) {
            throw new NotFoundException("Class '{$class}' does not exist", 0, $e);
        }

        if (!$ref->isInstantiable()) {
            throw new NotFoundException("'{$class}' is not instantiable (abstract/interface)");
        }

        $constructor = $ref->getConstructor();

        if ($constructor === null) {
            return $ref->newInstance();
        }

        $args = $this->resolveParameters($constructor->getParameters(), $parameters, $class);
        return $ref->newInstanceArgs($args);
    }

    /**
     * Resolve an array of ReflectionParameters to concrete values.
     *
     * @param  \ReflectionParameter[] $refParams
     * @param  mixed[]                $overrides   Caller-supplied overrides (by name or position).
     * @param  string                 $class       Only used for error messages.
     * @return mixed[]
     * @throws ContainerException
     */
    private function resolveParameters(array $refParams, array $overrides, string $class): array
    {
        $args = [];

        foreach ($refParams as $i => $param) {
            $name = $param->getName();

            // Caller-supplied override by name or position
            if (array_key_exists($name, $overrides)) {
                $args[] = $overrides[$name];
                continue;
            }
            if (array_key_exists($i, $overrides)) {
                $args[] = $overrides[$i];
                continue;
            }

            // Type-hinted class/interface — recurse into make()
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                try {
                    $args[] = $this->make($typeName);
                    continue;
                } catch (NotFoundException $e) {
                    if ($param->isOptional()) {
                        $args[] = $param->getDefaultValue();
                        continue;
                    }
                    throw new ContainerException(
                        "Cannot resolve parameter \${$name} of type {$typeName} "
                        . "in constructor of {$class}: " . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }

            // Default value available
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // Nullable without default → null
            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new ContainerException(
                "Cannot resolve required parameter \${$name} of {$class}"
            );
        }

        return $args;
    }
}
