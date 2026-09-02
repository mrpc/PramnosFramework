<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;

/**
 * The middleware an application declares.
 *
 * Three statements, never executed, and they are what a `MiddlewarePipeline` is built from — so
 * whatever this returns is what wraps every dispatch.
 *
 * The type guard is the substance. `middleware` comes out of `app.php`, which is a file somebody
 * edits by hand, and a single entry written without brackets —
 *
 * ```php
 * 'middleware' => \App\Middleware\Cors::class,   // a string, not a list
 * ```
 *
 * — would reach a `foreach` as a string. Returning `[]` for anything that is not an array turns
 * that mistake into "no middleware", which is visible the moment somebody tests the thing they
 * just added; the alternative is a `foreach` over a string, and PHP's answer to that is a warning
 * printed into the response of every request.
 */
#[CoversClass(Application::class)]
class ApplicationMiddlewareListTest extends TestCase
{
    private mixed $saved = null;

    private bool $had = false;

    protected function setUp(): void
    {
        parent::setUp();
        $app = Application::getInstance();
        $this->had   = isset($app->applicationInfo['middleware']);
        $this->saved = $app->applicationInfo['middleware'] ?? null;
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();
        if ($this->had) {
            $app->applicationInfo['middleware'] = $this->saved;
        } else {
            unset($app->applicationInfo['middleware']);
        }
        parent::tearDown();
    }

    private function middlewareWith(mixed $configured): array
    {
        $app = Application::getInstance();

        if ($configured === '__unset__') {
            unset($app->applicationInfo['middleware']);
        } else {
            $app->applicationInfo['middleware'] = $configured;
        }

        return $app->getMiddleware();
    }

    /**
     * A declared list comes back as it is, in order.
     *
     * Order is the behaviour: middleware wraps in the order declared, so a list that came back
     * reordered would run an authentication check after the thing it was meant to guard.
     */
    public function testADeclaredListComesBackInOrder(): void
    {
        // Arrange + Act
        $middleware = $this->middlewareWith(['First', 'Second', 'Third']);

        // Assert
        $this->assertSame(['First', 'Second', 'Third'], $middleware);
    }

    /**
     * An application that declares none gets an empty list.
     *
     * The default, and it has to be a list rather than `null`: the pipeline iterates it.
     */
    public function testAnApplicationThatDeclaresNoneGetsAnEmptyList(): void
    {
        // Act + Assert
        $this->assertSame([], $this->middlewareWith('__unset__'));
    }

    /**
     * Anything that is not an array becomes an empty list.
     *
     * The guard. A single entry written without brackets is the mistake this catches, and turning
     * it into "no middleware" is the failure a developer sees immediately — a `foreach` over a
     * string is a warning printed into every response.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function notLists(): array
    {
        return [
            'a bare class name' => ['App\\Middleware\\Cors'],
            'null'              => [null],
            'a number'          => [1],
            'true'              => [true],
            'an object'         => [new \stdClass()],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notLists')]
    public function testAnythingThatIsNotAnArrayBecomesAnEmptyList(mixed $configured): void
    {
        // Act + Assert
        $this->assertSame(
            [],
            $this->middlewareWith($configured),
            var_export($configured, true) . ' should not reach a foreach'
        );
    }

    /**
     * An empty array stays an empty array.
     *
     * Declaring `'middleware' => []` is how an application says "none, deliberately", and it must
     * not be confused with a misconfiguration — both answer `[]`, which is the point: there is
     * nothing for the pipeline to do either way.
     */
    public function testAnEmptyDeclarationStaysEmpty(): void
    {
        // Act + Assert
        $this->assertSame([], $this->middlewareWith([]));
    }
}
