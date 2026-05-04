<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\MiddlewarePipeline;
use Pramnos\Http\Request;
use Pramnos\Routing\Route;
use Pramnos\Routing\Router;

#[CoversClass(MiddlewarePipeline::class)]
class MiddlewarePipelineTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers — inline middleware stubs
    // -------------------------------------------------------------------------

    /** Returns a MiddlewareInterface that appends a marker to a log array and calls $next. */
    private function tracingMiddleware(array &$log, string $marker): MiddlewareInterface
    {
        return new class($log, $marker) implements MiddlewareInterface {
            public function __construct(private array &$log, private string $marker) {}
            public function handle(Request $request, callable $next): mixed
            {
                $this->log[] = 'before:' . $this->marker;
                $result = $next($request);
                $this->log[] = 'after:' . $this->marker;
                return $result;
            }
        };
    }

    /** Returns a MiddlewareInterface that short-circuits, returning $value immediately. */
    private function shortCircuitMiddleware(mixed $value): MiddlewareInterface
    {
        return new class($value) implements MiddlewareInterface {
            public function __construct(private mixed $value) {}
            public function handle(Request $request, callable $next): mixed
            {
                return $this->value;
            }
        };
    }

    /** FQCN helper — a real class stored in a temp file for lazy-instantiation tests. */
    private function makeFqcnMiddleware(array &$log, string $marker): string
    {
        // Define an anonymous class via eval so we have a real FQCN to pass as string.
        // Each call uses a unique class name to avoid re-definition errors.
        static $counter = 0;
        $counter++;
        $className = 'FqcnTracer' . $counter;
        $markerEsc = addslashes($marker);
        eval(<<<PHP
            class {$className} implements \Pramnos\Http\MiddlewareInterface {
                public function handle(\Pramnos\Http\Request \$request, callable \$next): mixed {
                    return \$next(\$request);
                }
            }
        PHP);
        return $className;
    }

    private function makeRequest(): Request
    {
        return Request::create('/test', 'GET');
    }

    // -------------------------------------------------------------------------
    // MiddlewarePipeline — core mechanics
    // -------------------------------------------------------------------------

    /**
     * With no middleware registered, the pipeline must call the destination
     * directly and return its result unchanged.
     */
    public function testEmptyPipelineCallsDestinationDirectly(): void
    {
        // Arrange
        $pipeline = new MiddlewarePipeline();
        $called   = false;

        // Act
        $result = $pipeline->run($this->makeRequest(), function (Request $r) use (&$called): string {
            $called = true;
            return 'ok';
        });

        // Assert
        $this->assertTrue($called, 'Destination was not called');
        $this->assertSame('ok', $result);
    }

    /**
     * A single middleware must receive the request, call $next, and have its
     * return value propagated back to the caller.
     */
    public function testSingleMiddlewareWrapsDestination(): void
    {
        // Arrange
        $log      = [];
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe($this->tracingMiddleware($log, 'A'));

        // Act
        $result = $pipeline->run($this->makeRequest(), function (Request $r) use (&$log): string {
            $log[] = 'action';
            return 'result';
        });

        // Assert
        $this->assertSame(['before:A', 'action', 'after:A'], $log);
        $this->assertSame('result', $result);
    }

    /**
     * Multiple middlewares must run in registration order (first pipe()d = outermost),
     * which means: A-before → B-before → action → B-after → A-after.
     */
    public function testMultipleMiddlewaresRunInRegistrationOrder(): void
    {
        // Arrange
        $log      = [];
        $pipeline = (new MiddlewarePipeline())
            ->pipe($this->tracingMiddleware($log, 'A'))
            ->pipe($this->tracingMiddleware($log, 'B'))
            ->pipe($this->tracingMiddleware($log, 'C'));

        // Act
        $pipeline->run($this->makeRequest(), function (Request $r) use (&$log): void {
            $log[] = 'action';
        });

        // Assert
        $this->assertSame(
            ['before:A', 'before:B', 'before:C', 'action', 'after:C', 'after:B', 'after:A'],
            $log,
            'Middleware onion order violated'
        );
    }

    /**
     * A middleware that does NOT call $next short-circuits the pipeline: the
     * destination and any subsequent middlewares never run.
     */
    public function testShortCircuitPreventsDestinationFromRunning(): void
    {
        // Arrange
        $log      = [];
        $pipeline = (new MiddlewarePipeline())
            ->pipe($this->tracingMiddleware($log, 'outer'))
            ->pipe($this->shortCircuitMiddleware('blocked'))
            ->pipe($this->tracingMiddleware($log, 'inner'));

        // Act
        $result = $pipeline->run($this->makeRequest(), function (Request $r) use (&$log): string {
            $log[] = 'action';
            return 'should-not-reach';
        });

        // Assert
        $this->assertSame('blocked', $result, 'Short-circuit value not returned');
        $this->assertNotContains('action', $log, 'Destination ran after short-circuit');
        $this->assertNotContains('before:inner', $log, 'Inner middleware ran after short-circuit');
        // Outer middleware still sees the return (after hook runs normally)
        $this->assertContains('after:outer', $log);
    }

    /**
     * Passing a FQCN string instead of an instance triggers lazy instantiation
     * inside the pipeline. The middleware class must have a no-arg constructor.
     */
    public function testFqcnStringTriggersLazyInstantiation(): void
    {
        // Arrange — define a real named class via eval
        $className = 'LazyTestMiddleware_' . uniqid();
        eval(<<<PHP
            class {$className} implements \Pramnos\Http\MiddlewareInterface {
                public function handle(\Pramnos\Http\Request \$request, callable \$next): mixed {
                    return 'lazy:' . \$next(\$request);
                }
            }
        PHP);

        $pipeline = (new MiddlewarePipeline())->pipe($className);

        // Act
        $result = $pipeline->run($this->makeRequest(), fn(Request $r) => 'done');

        // Assert
        $this->assertSame('lazy:done', $result);
    }

    /**
     * pipe() returns the pipeline instance for fluent chaining.
     */
    public function testPipeReturnsSelf(): void
    {
        // Arrange
        $pipeline = new MiddlewarePipeline();
        $mw       = $this->shortCircuitMiddleware(null);

        // Act / Assert
        $this->assertSame($pipeline, $pipeline->pipe($mw));
    }

    /**
     * A middleware may modify the result returned by the inner chain.
     */
    public function testMiddlewareCanTransformResult(): void
    {
        // Arrange
        $wrapper = new class implements MiddlewareInterface {
            public function handle(Request $request, callable $next): mixed
            {
                return strtoupper($next($request));
            }
        };

        $pipeline = (new MiddlewarePipeline())->pipe($wrapper);

        // Act
        $result = $pipeline->run($this->makeRequest(), fn(Request $r) => 'hello');

        // Assert
        $this->assertSame('HELLO', $result);
    }

    // -------------------------------------------------------------------------
    // Route — middleware attachment
    // -------------------------------------------------------------------------

    /**
     * A freshly created route has no middleware.
     */
    public function testRouteHasNoMiddlewareByDefault(): void
    {
        // Arrange / Act
        $route = new Route('/test', 'GET', fn() => null);

        // Assert
        $this->assertFalse($route->hasMiddleware());
        $this->assertSame([], $route->getMiddleware());
    }

    /**
     * middleware() accumulates instances and returns $this for chaining.
     */
    public function testRouteMiddlewareAccumulatesAndChainsFlux(): void
    {
        // Arrange
        $mw1   = $this->shortCircuitMiddleware(null);
        $mw2   = $this->shortCircuitMiddleware(null);
        $route = new Route('/test', 'GET', fn() => null);

        // Act
        $return = $route->middleware($mw1, $mw2);

        // Assert
        $this->assertSame($route, $return, 'middleware() must return $this');
        $this->assertTrue($route->hasMiddleware());
        $this->assertSame([$mw1, $mw2], $route->getMiddleware());
    }

    /**
     * middleware() called twice appends — it does not replace.
     */
    public function testRouteMiddlewareCallsAppend(): void
    {
        // Arrange
        $mw1   = $this->shortCircuitMiddleware('a');
        $mw2   = $this->shortCircuitMiddleware('b');
        $route = new Route('/test', 'GET', fn() => null);

        // Act
        $route->middleware($mw1);
        $route->middleware($mw2);

        // Assert
        $this->assertCount(2, $route->getMiddleware());
    }

    // -------------------------------------------------------------------------
    // Router — global middleware + dispatch integration
    // -------------------------------------------------------------------------

    /**
     * addGlobalMiddleware() returns $this for fluent chaining.
     */
    public function testRouterAddGlobalMiddlewareReturnsSelf(): void
    {
        // Arrange
        $router = new Router(null);
        $mw     = $this->shortCircuitMiddleware(null);

        // Act / Assert
        $this->assertSame($router, $router->addGlobalMiddleware($mw));
    }

    /**
     * When no middleware exists, dispatch() behaves exactly as before
     * (the action closure is called and its return value propagated).
     */
    public function testDispatchWithNoMiddlewareCallsActionDirectly(): void
    {
        // Arrange
        $called = false;
        $router = new Router(null);
        $router->get('/test', function () use (&$called): string {
            $called = true;
            return 'direct';
        });
        $request = Request::create('/test', 'GET');

        // Act
        $result = $router->dispatch($request);

        // Assert
        $this->assertTrue($called);
        $this->assertSame('direct', $result);
    }

    /**
     * Global middleware runs before the action; route middleware also runs.
     * Order: global → route-specific → action.
     */
    public function testDispatchRunsGlobalThenRouteMiddleware(): void
    {
        // Arrange
        $log    = [];
        $router = new Router(null);
        $router->addGlobalMiddleware($this->tracingMiddleware($log, 'global'));

        $router->get('/test', function () use (&$log): string {
            $log[] = 'action';
            return 'ok';
        })->middleware($this->tracingMiddleware($log, 'route'));

        $request = Request::create('/test', 'GET');

        // Act
        $router->dispatch($request);

        // Assert
        $this->assertSame(
            ['before:global', 'before:route', 'action', 'after:route', 'after:global'],
            $log
        );
    }

    /**
     * A global short-circuit middleware prevents any route action from running,
     * even for routes that have no route-specific middleware.
     */
    public function testGlobalShortCircuitBlocksAction(): void
    {
        // Arrange
        $actionRan = false;
        $router    = new Router(null);
        $router->addGlobalMiddleware($this->shortCircuitMiddleware('blocked'));
        $router->get('/test', function () use (&$actionRan): string {
            $actionRan = true;
            return 'should-not-run';
        });
        $request = Request::create('/test', 'GET');

        // Act
        $result = $router->dispatch($request);

        // Assert
        $this->assertFalse($actionRan, 'Action ran despite global short-circuit');
        $this->assertSame('blocked', $result);
    }

    // -------------------------------------------------------------------------
    // Controller — addMiddleware() + exec() integration
    // -------------------------------------------------------------------------

    /**
     * Controller::exec() without any middleware registered runs the action
     * directly (identical to pre-middleware behaviour).
     */
    public function testControllerExecWithNoMiddlewareRunsActionDirectly(): void
    {
        // Arrange
        $ctrl = new class extends \Pramnos\Application\Controller {
            public bool $ran = false;
            public function __construct() {
                $this->addaction('greet');
                parent::__construct();
            }
            public function greet(): string {
                $this->ran = true;
                return 'hello';
            }
        };

        // Act
        $result = $ctrl->exec('greet');

        // Assert
        $this->assertTrue($ctrl->ran);
        $this->assertSame('hello', $result);
    }

    /**
     * Action-specific middleware runs only for that action.
     */
    public function testControllerActionSpecificMiddlewareRunsForTargetAction(): void
    {
        // Arrange
        $log  = [];
        $ctrl = new class($log) extends \Pramnos\Application\Controller {
            public function __construct(private array &$log) {
                $this->addaction('edit');
                $this->addaction('view');
                parent::__construct();
            }
            public function edit(): string {
                $this->log[] = 'edit-action';
                return 'edited';
            }
            public function view(): string {
                $this->log[] = 'view-action';
                return 'viewed';
            }
        };

        $mw = new class($log) implements MiddlewareInterface {
            public function __construct(private array &$log) {}
            public function handle(Request $request, callable $next): mixed {
                $this->log[] = 'mw-before';
                $r = $next($request);
                $this->log[] = 'mw-after';
                return $r;
            }
        };
        $ctrl->addMiddleware('edit', $mw);

        // Act — middleware-protected action
        $ctrl->exec('edit');
        // Act — unprotected action
        $ctrl->exec('view');

        // Assert
        $this->assertSame(
            ['mw-before', 'edit-action', 'mw-after', 'view-action'],
            $log,
            'Middleware ran on wrong action'
        );
    }

    /**
     * Wildcard '*' middleware runs for every action in the controller.
     */
    public function testControllerWildcardMiddlewareRunsForEveryAction(): void
    {
        // Arrange
        $log  = [];
        $ctrl = new class($log) extends \Pramnos\Application\Controller {
            public function __construct(private array &$log) {
                $this->addaction('foo');
                $this->addaction('bar');
                parent::__construct();
            }
            public function foo(): void { $this->log[] = 'foo'; }
            public function bar(): void { $this->log[] = 'bar'; }
        };

        $mw = new class($log) implements MiddlewareInterface {
            public function __construct(private array &$log) {}
            public function handle(Request $request, callable $next): mixed {
                $this->log[] = 'mw';
                return $next($request);
            }
        };
        $ctrl->addMiddleware('*', $mw);

        // Act
        $ctrl->exec('foo');
        $ctrl->exec('bar');

        // Assert
        $this->assertSame(['mw', 'foo', 'mw', 'bar'], $log);
    }

    /**
     * addMiddleware() accepts an array of action names — each gets the middleware.
     */
    public function testControllerAddMiddlewareAcceptsActionArray(): void
    {
        // Arrange
        $log  = [];
        $ctrl = new class($log) extends \Pramnos\Application\Controller {
            public function __construct(private array &$log) {
                $this->addaction('a');
                $this->addaction('b');
                $this->addaction('c');
                parent::__construct();
            }
            public function a(): void { $this->log[] = 'a'; }
            public function b(): void { $this->log[] = 'b'; }
            public function c(): void { $this->log[] = 'c'; }
        };

        $mw = new class($log) implements MiddlewareInterface {
            public function __construct(private array &$log) {}
            public function handle(Request $request, callable $next): mixed {
                $this->log[] = 'mw';
                return $next($request);
            }
        };
        $ctrl->addMiddleware(['a', 'b'], $mw); // 'c' is NOT protected

        // Act
        $ctrl->exec('a');
        $ctrl->exec('b');
        $ctrl->exec('c');

        // Assert — middleware ran for a and b, not c
        $this->assertSame(['mw', 'a', 'mw', 'b', 'c'], $log);
    }

    /**
     * addMiddleware() returns $this for fluent chaining.
     */
    public function testControllerAddMiddlewareReturnsSelf(): void
    {
        // Arrange
        $ctrl = new class extends \Pramnos\Application\Controller {
            public function __construct() { parent::__construct(); }
        };
        $mw = $this->shortCircuitMiddleware(null);

        // Act / Assert
        $this->assertSame($ctrl, $ctrl->addMiddleware('*', $mw));
    }

    /**
     * A short-circuit middleware on a controller action prevents the action from running.
     */
    public function testControllerShortCircuitPreventsActionExecution(): void
    {
        // Arrange
        $ctrl = new class extends \Pramnos\Application\Controller {
            public bool $ran = false;
            public function __construct() {
                $this->addaction('sensitive');
                parent::__construct();
            }
            public function sensitive(): string {
                $this->ran = true;
                return 'secret';
            }
        };
        $ctrl->addMiddleware('sensitive', $this->shortCircuitMiddleware('denied'));

        // Act
        $result = $ctrl->exec('sensitive');

        // Assert
        $this->assertFalse($ctrl->ran);
        $this->assertSame('denied', $result);
    }
}
