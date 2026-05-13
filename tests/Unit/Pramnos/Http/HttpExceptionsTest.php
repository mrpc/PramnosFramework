<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\ClientException;
use Pramnos\Http\RedirectException;
use Pramnos\Http\MiddlewarePipeline;
use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Unit tests for HTTP infrastructure value objects and the middleware pipeline.
 *
 * Covers three classes:
 *
 * - ClientException: wraps a libcurl transport failure.  Verifies that the curl
 *   errno is stored and retrievable via getCurlErrno(), and that the exception
 *   chain is preserved through the $previous parameter.
 *
 * - RedirectException: signals an HTTP redirect inside middleware or a controller
 *   so that Application::exec() can issue header()/exit cleanly.  Verifies URL
 *   storage, status code default (302), and custom codes.
 *
 * - MiddlewarePipeline: builds a folded middleware stack and executes it.
 *   Tests verify registration order (first-in = outermost), fluent pipe() chaining,
 *   lazy FQCN instantiation, empty-pipeline pass-through, and short-circuit via
 *   a middleware that returns early without calling $next.
 */
#[CoversClass(ClientException::class)]
#[CoversClass(RedirectException::class)]
#[CoversClass(MiddlewarePipeline::class)]
class HttpExceptionsTest extends TestCase
{
    // =========================================================================
    // ClientException
    // =========================================================================

    /**
     * getCurlErrno() returns the errno passed to the constructor.
     */
    public function testClientExceptionStoresCurlErrno(): void
    {
        // Arrange / Act
        $e = new ClientException('Connection refused', 7);

        // Assert — CURLE_COULDNT_CONNECT = 7
        $this->assertSame(7, $e->getCurlErrno());
        $this->assertSame('Connection refused', $e->getMessage());
    }

    /**
     * getCurlErrno() returns 0 when not supplied (the "not a curl error" sentinel).
     */
    public function testClientExceptionDefaultCurlErrnoIsZero(): void
    {
        // Arrange / Act
        $e = new ClientException('Timeout');

        // Assert
        $this->assertSame(0, $e->getCurlErrno());
    }

    /**
     * ClientException accepts a $previous throwable to preserve the exception chain.
     */
    public function testClientExceptionPreservesPreviousException(): void
    {
        // Arrange
        $previous = new \RuntimeException('root cause');

        // Act
        $e = new ClientException('HTTP error', 0, $previous);

        // Assert — $previous is accessible via getPrevious()
        $this->assertSame($previous, $e->getPrevious());
    }

    /**
     * ClientException is a RuntimeException (unchecked; callers are not forced to
     * declare it in their signatures).
     */
    public function testClientExceptionExtendsRuntimeException(): void
    {
        // Arrange / Act
        $e = new ClientException('boom');

        // Assert
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    // =========================================================================
    // RedirectException
    // =========================================================================

    /**
     * getUrl() returns the destination URL passed to the constructor.
     */
    public function testRedirectExceptionStoresUrl(): void
    {
        // Arrange / Act
        $e = new RedirectException('https://example.com/login');

        // Assert
        $this->assertSame('https://example.com/login', $e->getUrl());
    }

    /**
     * Default status code is 302 (Found) — the most common redirect type.
     */
    public function testRedirectExceptionDefaultStatusCodeIs302(): void
    {
        // Arrange / Act
        $e = new RedirectException('/dashboard');

        // Assert
        $this->assertSame(302, $e->getStatusCode());
    }

    /**
     * A custom status code is stored and returned by getStatusCode().
     */
    public function testRedirectExceptionAcceptsCustomStatusCode(): void
    {
        // Arrange / Act — 301 = Moved Permanently
        $e = new RedirectException('https://new.example.com/', 301);

        // Assert
        $this->assertSame(301, $e->getStatusCode());
        $this->assertSame('https://new.example.com/', $e->getUrl());
    }

    /**
     * getMessage() includes the redirect URL so stack traces are informative.
     */
    public function testRedirectExceptionMessageContainsUrl(): void
    {
        // Arrange / Act
        $e = new RedirectException('/home', 303);

        // Assert
        $this->assertStringContainsString('/home', $e->getMessage());
    }

    /**
     * RedirectException is a RuntimeException so uncaught redirects bubble up
     * to the top-level handler cleanly.
     */
    public function testRedirectExceptionExtendsRuntimeException(): void
    {
        // Arrange / Act
        $e = new RedirectException('/');

        // Assert
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    // =========================================================================
    // MiddlewarePipeline
    // =========================================================================

    /**
     * An empty pipeline passes the request directly to the destination callable.
     */
    public function testEmptyPipelineCallsDestination(): void
    {
        // Arrange
        $request  = $this->createMock(Request::class);
        $pipeline = new MiddlewarePipeline();
        $called   = false;

        // Act
        $result = $pipeline->run($request, function (Request $req) use (&$called, $request): string {
            $called = true;
            // Assert — the destination receives the original request object
            $this->assertSame($request, $req);
            return 'done';
        });

        // Assert
        $this->assertTrue($called);
        $this->assertSame('done', $result);
    }

    /**
     * pipe() returns $this for fluent chaining, allowing multiple calls to be
     * chained without a temporary variable.
     */
    public function testPipeReturnsSelfForFluentChaining(): void
    {
        // Arrange
        $pipeline = new MiddlewarePipeline();

        // Act — chain two pipe() calls
        $returned = $pipeline->pipe(
            new class implements MiddlewareInterface {
                public function handle(Request $req, callable $next): mixed { return $next($req); }
            }
        );

        // Assert — same instance
        $this->assertSame($pipeline, $returned);
    }

    /**
     * Middleware is executed in registration order: the first pipe()d middleware
     * is the outermost and runs first.  The destination runs last.
     */
    public function testMiddlewareExecutionOrderIsRegistrationOrder(): void
    {
        // Arrange
        $request = $this->createMock(Request::class);
        $log     = [];

        $first = new class($log) implements MiddlewareInterface {
            public function __construct(private array &$log) {}
            public function handle(Request $req, callable $next): mixed {
                $this->log[] = 'first-before';
                $result = $next($req);
                $this->log[] = 'first-after';
                return $result;
            }
        };

        $second = new class($log) implements MiddlewareInterface {
            public function __construct(private array &$log) {}
            public function handle(Request $req, callable $next): mixed {
                $this->log[] = 'second-before';
                $result = $next($req);
                $this->log[] = 'second-after';
                return $result;
            }
        };

        // Act
        (new MiddlewarePipeline())
            ->pipe($first)
            ->pipe($second)
            ->run($request, function () use (&$log): string {
                $log[] = 'destination';
                return 'response';
            });

        // Assert — first is outermost, runs before second; both wrap the destination
        $this->assertSame(
            ['first-before', 'second-before', 'destination', 'second-after', 'first-after'],
            $log
        );
    }

    /**
     * A middleware can short-circuit the pipeline by returning early without
     * calling $next.  Subsequent middleware and the destination are never invoked.
     */
    public function testShortCircuitMiddlewareSkipsRemainder(): void
    {
        // Arrange
        $request     = $this->createMock(Request::class);
        $destCalled  = false;

        $guard = new class implements MiddlewareInterface {
            public function handle(Request $req, callable $next): mixed {
                // Short-circuit: never calls $next
                return 'blocked';
            }
        };

        // Act
        $result = (new MiddlewarePipeline())
            ->pipe($guard)
            ->run($request, function () use (&$destCalled): string {
                $destCalled = true;
                return 'reached';
            });

        // Assert — pipeline was short-circuited
        $this->assertSame('blocked', $result);
        $this->assertFalse($destCalled, 'Destination should not be called after short-circuit');
    }

    /**
     * Middleware registered as a FQCN string is instantiated lazily when the
     * pipeline runs.  The concrete class must have a no-args constructor.
     */
    public function testFqcnMiddlewareIsInstantiatedLazily(): void
    {
        // Arrange — a globally defined test middleware class registered by name
        $request = $this->createMock(Request::class);
        $fqcn    = HttpExceptionsTestPassthroughMiddleware::class;

        // Act
        $result = (new MiddlewarePipeline())
            ->pipe($fqcn)
            ->run($request, fn(): string => 'from-destination');

        // Assert — the lazy-instantiated middleware passed the request through
        $this->assertSame('from-destination', $result);
    }

    /**
     * The pipeline return value is whatever the destination callable returns;
     * middleware wrappers can also transform it on the way back out.
     */
    public function testPipelineReturnsDestinationReturnValue(): void
    {
        // Arrange
        $request = $this->createMock(Request::class);

        // Act
        $result = (new MiddlewarePipeline())
            ->run($request, fn(): int => 42);

        // Assert
        $this->assertSame(42, $result);
    }
}

/**
 * Minimal concrete middleware used to test lazy FQCN instantiation.
 * Must be at file scope so class_exists() can locate it by string name.
 */
class HttpExceptionsTestPassthroughMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        return $next($request);
    }
}
