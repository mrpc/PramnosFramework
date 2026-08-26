<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;

/**
 * A controller action must accept what the dispatcher passes it.
 *
 * `Controller::exec()` calls every action the same way:
 *
 * ```php
 * fn() => $this->$action($args)
 * ```
 *
 * `$args` is the request's arguments **array**. So an action declaring a scalar
 * first parameter — `logs(string $name = '')` — is a guaranteed `TypeError` the
 * moment anything routes to it. Not a bug that shows under some input: the action
 * cannot be called at all.
 *
 * Five had it. Four were `ServicesController`'s stop, start, restart and logs,
 * which is every button on the services screen, and the fifth was
 * `LogController::clearFile()`. None of them had ever worked, and none of them
 * failed loudly enough to notice — a fatal on click reads as a broken page, and a
 * broken page in an admin screen nobody visits reads as nothing.
 *
 * The convention that works is `mixed $id = null` plus
 * `Request::staticGetOption()`, which is what the rest of the controllers do.
 *
 * This test is structural on purpose. Routing to every action of every controller
 * would need a fixture per screen; the declaration is what makes the action
 * callable or not, and the declaration can be read without a request.
 */
class ControllerActionSignatureTest extends TestCase
{
    /**
     * Types a first parameter may have and still take an array.
     */
    private const ACCEPTS_AN_ARRAY = ['mixed', 'array', 'iterable'];

    /**
     * Every controller class the framework ships.
     *
     * @return list<class-string>
     */
    private function controllerClasses(): array
    {
        $root = dirname(__DIR__, 3) . '/src/Pramnos';
        $classes = [];

        foreach (glob($root . '/*/Controllers/*.php') ?: [] as $file) {
            $relative = substr($file, strlen($root) + 1, -4);
            $class = 'Pramnos\\' . str_replace('/', '\\', $relative);
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * The controllers are found, or every assertion below is vacuous.
     */
    public function testTheControllersAreFound(): void
    {
        // Assert
        $this->assertGreaterThan(10, count($this->controllerClasses()),
            'the bundled controllers must be discoverable');
    }

    /**
     * No action declares a first parameter the dispatcher cannot fill.
     */
    public function testNoActionDeclaresAScalarFirstParameter(): void
    {
        // Act
        $offenders = [];
        foreach ($this->controllerClasses() as $class) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class
                    || $method->isStatic()
                    || $method->isConstructor()
                    || str_starts_with($method->getName(), '__')
                ) {
                    continue;
                }

                $parameters = $method->getParameters();
                if ($parameters === []) {
                    continue;
                }

                $type = $parameters[0]->getType();
                if (!$type instanceof \ReflectionNamedType) {
                    continue;
                }

                if (!in_array($type->getName(), self::ACCEPTS_AN_ARRAY, true)) {
                    $offenders[] = $class . '::' . $method->getName()
                        . '(' . $type->getName() . ' $' . $parameters[0]->getName() . ')';
                }
            }
        }

        // Assert
        $this->assertSame([], $offenders,
            "these actions cannot be called by the dispatcher, which passes an array:\n"
            . implode("\n", $offenders));
    }

    /**
     * The five that were broken now take the array.
     *
     * Named individually so a regression on one of them is reported as itself
     * rather than as a line in a list.
     */
    public function testThePreviouslyBrokenActionsTakeTheArray(): void
    {
        // Arrange
        $cases = [
            [\Pramnos\Application\Controllers\ServicesController::class, 'stop'],
            [\Pramnos\Application\Controllers\ServicesController::class, 'start'],
            [\Pramnos\Application\Controllers\ServicesController::class, 'restart'],
            [\Pramnos\Application\Controllers\ServicesController::class, 'logs'],
            [\Pramnos\Application\Controllers\LogController::class, 'clearFile'],
        ];

        // Act & Assert
        foreach ($cases as [$class, $action]) {
            $parameters = (new \ReflectionMethod($class, $action))->getParameters();
            $this->assertNotEmpty($parameters, "$class::$action must keep its parameter");
            $type = $parameters[0]->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $type);
            $this->assertContains(
                $type->getName(),
                self::ACCEPTS_AN_ARRAY,
                "$class::$action must accept the arguments array"
            );
        }
    }
}
