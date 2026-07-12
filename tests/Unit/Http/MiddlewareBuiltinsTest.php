<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\RedirectException;
use Pramnos\Http\Middleware\AuthMiddleware;
use Pramnos\Http\Middleware\CorsMiddleware;
use Pramnos\Http\Middleware\ThrottleMiddleware;
use Pramnos\Http\Middleware\MaintenanceModeMiddleware;
use Pramnos\Http\Request;

#[CoversClass(RedirectException::class)]
#[CoversClass(AuthMiddleware::class)]
#[CoversClass(CorsMiddleware::class)]
#[CoversClass(ThrottleMiddleware::class)]
#[CoversClass(MaintenanceModeMiddleware::class)]
class MiddlewareBuiltinsTest extends TestCase
{
    // ─── helpers ─────────────────────────────────────────────────────────────

    private function makeRequest(string $uri = '/test', string $method = 'GET'): Request
    {
        return Request::create($uri, $method);
    }

    private function passThroughNext(string $returnValue = 'ok'): callable
    {
        return fn(Request $r) => $returnValue;
    }

    // =========================================================================
    // RedirectException
    // =========================================================================

    /**
     * RedirectException exposes the URL and status code it was constructed with.
     */
    public function testRedirectExceptionStoresUrlAndStatusCode(): void
    {
        // Arrange / Act
        $ex = new RedirectException('/login', 302);

        // Assert
        $this->assertSame('/login', $ex->getUrl());
        $this->assertSame(302, $ex->getStatusCode());
        $this->assertSame(302, $ex->getCode());
    }

    /**
     * The default status code is 302 (Found).
     */
    public function testRedirectExceptionDefaultsTo302(): void
    {
        // Arrange / Act
        $ex = new RedirectException('/somewhere');

        // Assert
        $this->assertSame(302, $ex->getStatusCode());
    }

    /**
     * RedirectException extends RuntimeException so it bubbles correctly
     * through catch (\RuntimeException) blocks.
     */
    public function testRedirectExceptionExtendsRuntimeException(): void
    {
        // Arrange / Assert
        $this->assertInstanceOf(\RuntimeException::class, new RedirectException('/x'));
    }

    // =========================================================================
    // AuthMiddleware
    // =========================================================================

    // Session::isLogged() checks $_SESSION['logged'] and $_SESSION['uid'] > 1.

    private function setLoggedIn(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = 42;
    }

    private function setLoggedOut(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['logged'] = false;
        $_SESSION['uid']    = 1;
    }

    /**
     * When the session is logged in, the middleware must call $next and return
     * its result unchanged.
     */
    public function testAuthMiddlewarePassesThroughWhenLoggedIn(): void
    {
        // Arrange
        $this->setLoggedIn();
        $mw = new AuthMiddleware();

        // Act
        $result = $mw->handle($this->makeRequest(), $this->passThroughNext('welcome'));

        // Assert
        $this->assertSame('welcome', $result);

        // Cleanup
        $this->setLoggedOut();
    }

    /**
     * When not logged in and no redirectTo, throw a 401 exception.
     */
    public function testAuthMiddlewareThrows401WhenNotLoggedIn(): void
    {
        // Arrange
        $this->setLoggedOut();
        $mw     = new AuthMiddleware();
        $called = false;

        // Act
        try {
            $mw->handle($this->makeRequest(), function () use (&$called) {
                $called = true;
                return 'should-not-reach';
            });
            $this->fail('Expected exception not thrown');
        } catch (\Exception $e) {
            // Assert
            $this->assertSame(401, $e->getCode());
            $this->assertFalse($called, '$next was called despite failed auth');
        }
    }

    /**
     * When not logged in and redirectTo is set, throw RedirectException
     * with the configured URL (instead of a bare exit).
     */
    public function testAuthMiddlewareThrowsRedirectExceptionWhenNotLoggedIn(): void
    {
        // Arrange
        $this->setLoggedOut();
        $mw     = new AuthMiddleware('/login');
        $called = false;

        // Act
        try {
            $mw->handle($this->makeRequest(), function () use (&$called) {
                $called = true;
                return 'nope';
            });
            $this->fail('Expected RedirectException not thrown');
        } catch (RedirectException $e) {
            // Assert
            $this->assertSame('/login', $e->getUrl());
            $this->assertSame(302, $e->getStatusCode());
            $this->assertFalse($called, '$next was called despite redirect');
        }
    }

    // =========================================================================
    // CorsMiddleware
    // =========================================================================

    /**
     * With allowedOrigins = ['*'], any origin is accepted and $next is called.
     */
    public function testCorsMiddlewareWildcardOriginCallsNext(): void
    {
        // Arrange
        $_SERVER['HTTP_ORIGIN'] = 'https://example.com';
        $mw     = new CorsMiddleware(['*']);
        $called = false;

        // Act
        $result = $mw->handle($this->makeRequest(), function (Request $r) use (&$called) {
            $called = true;
            return 'body';
        });

        // Assert
        $this->assertTrue($called);
        $this->assertSame('body', $result);

        // Cleanup
        unset($_SERVER['HTTP_ORIGIN']);
    }

    /**
     * With a specific origin list, a matching Origin header is accepted.
     */
    public function testCorsMiddlewareSpecificOriginMatchCallsNext(): void
    {
        // Arrange
        $_SERVER['HTTP_ORIGIN'] = 'https://app.example.com';
        $mw     = new CorsMiddleware(['https://app.example.com']);
        $called = false;

        // Act
        $result = $mw->handle($this->makeRequest(), function (Request $r) use (&$called) {
            $called = true;
            return 'ok';
        });

        // Assert
        $this->assertTrue($called);
        $this->assertSame('ok', $result);

        // Cleanup
        unset($_SERVER['HTTP_ORIGIN']);
    }

    /**
     * With a specific origin list, a non-matching Origin still calls $next
     * (CORS doesn't block the request server-side; the browser enforces it).
     */
    public function testCorsMiddlewareNonMatchingOriginStillCallsNext(): void
    {
        // Arrange
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example.com';
        $mw     = new CorsMiddleware(['https://app.example.com']);
        $called = false;

        // Act
        $mw->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return 'ok';
        });

        // Assert
        $this->assertTrue($called);

        // Cleanup
        unset($_SERVER['HTTP_ORIGIN']);
    }

    /**
     * No HTTP_ORIGIN header: the middleware still calls $next.
     */
    public function testCorsMiddlewareNoOriginHeaderCallsNext(): void
    {
        // Arrange
        unset($_SERVER['HTTP_ORIGIN']);
        $mw = new CorsMiddleware(['https://app.example.com']);

        // Act
        $called = false;
        $mw->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return 'ok';
        });

        // Assert
        $this->assertTrue($called);
    }

    /**
     * An OPTIONS preflight request must short-circuit and return '' without
     * calling $next.
     */
    public function testCorsMiddlewarePreflightShortCircuits(): void
    {
        // Arrange
        $mw     = new CorsMiddleware();
        $called = false;
        $req    = $this->makeRequest('/api', 'OPTIONS');

        // Act
        $result = $mw->handle($req, function () use (&$called) {
            $called = true;
            return 'action';
        });

        // Assert
        $this->assertFalse($called, '$next was called on OPTIONS preflight');
        $this->assertSame('', $result);
    }

    /**
     * allowCredentials: true path executes without error (header is set).
     */
    public function testCorsMiddlewareAllowCredentialsPathExecutes(): void
    {
        // Arrange
        $mw     = new CorsMiddleware(['*'], allowCredentials: true);
        $called = false;

        // Act — no exception means the credentials header was set
        $mw->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return 'ok';
        });

        // Assert
        $this->assertTrue($called);
    }

    // =========================================================================
    // ThrottleMiddleware — via in-memory stub
    // =========================================================================

    /**
     * Build a ThrottleMiddleware subclass that uses an in-memory array as the
     * counter store, so APCu is not required and tests are deterministic.
     *
     * @param array &$store  Shared counter map (key → count).
     * @param int   $maxRequests
     * @param int   $perSeconds
     */
    private function makeInMemoryThrottle(
        array       &$store,
        int         $maxRequests = 3,
        int         $perSeconds  = 60,
    ): ThrottleMiddleware {
        return new class($store, $maxRequests, $perSeconds) extends ThrottleMiddleware {
            public function __construct(
                private array &$store,
                int $maxRequests,
                int $perSeconds,
            ) {
                parent::__construct($maxRequests, $perSeconds);
            }

            protected function fetchCount(string $key): int|false
            {
                return array_key_exists($key, $this->store) ? $this->store[$key] : false;
            }

            protected function storeCount(string $key, int $value, int $ttl): void
            {
                $this->store[$key] = $value;
            }

            protected function incrementCount(string $key): void
            {
                $this->store[$key] = ($this->store[$key] ?? 0) + 1;
            }
        };
    }

    /**
     * The first request for an IP stores count = 1 and calls $next.
     */
    public function testThrottleFirstRequestStoresCountAndCallsNext(): void
    {
        // Arrange
        $store = [];
        $mw    = $this->makeInMemoryThrottle($store, maxRequests: 3);
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $called = false;

        // Act
        $result = $mw->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return 'first';
        });

        // Assert
        $this->assertTrue($called);
        $this->assertSame('first', $result);
        // Count was stored (key exists and equals 1)
        $this->assertSame(1, array_values($store)[0]);
    }

    /**
     * Subsequent requests below the limit increment the counter and call $next.
     */
    public function testThrottleSubsequentRequestBelowLimitCallsNext(): void
    {
        // Arrange — pre-seed the counter at 1 (previous request)
        $key   = 'throttle:' . md5('1.2.3.4');
        $store = [$key => 1];
        $mw    = $this->makeInMemoryThrottle($store, maxRequests: 3);
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $called = false;

        // Act
        $mw->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return 'second';
        });

        // Assert
        $this->assertTrue($called);
        // Counter incremented to 2
        $this->assertSame(2, $store[$key]);
    }

    /**
     * When the counter is at maxRequests, the middleware throws a 429 exception
     * and does NOT call $next.
     */
    public function testThrottleAtLimitThrows429(): void
    {
        // Arrange — pre-seed counter at the limit
        $key   = 'throttle:' . md5('1.2.3.4');
        $store = [$key => 3];
        $mw    = $this->makeInMemoryThrottle($store, maxRequests: 3);
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
        $called = false;

        // Act
        try {
            $mw->handle($this->makeRequest(), function () use (&$called) {
                $called = true;
                return 'should-not-reach';
            });
            $this->fail('Expected 429 exception not thrown');
        } catch (\Exception $e) {
            // Assert
            $this->assertSame(429, $e->getCode());
            $this->assertFalse($called);
        }
    }

    /**
     * When APCu is unavailable, fetchCount returns false → the middleware
     * calls storeCount(key, 1, ttl) but still calls $next (graceful degradation).
     * This test covers the no-APCu code path in the real class directly by
     * confirming the real fetchCount() returns false when apcu_fetch() is absent.
     */
    public function testThrottleRealFetchCountReturnsFalseWhenApcuAbsent(): void
    {
        // Arrange
        $mw = new ThrottleMiddleware(60, 60);
        $reflection = new \ReflectionMethod($mw, 'fetchCount');

        // Act — call the real protected method
        $result = $reflection->invoke($mw, 'any-key');

        if (function_exists('apcu_fetch')) {
            // APCu available: result is int|false (depends on whether key exists)
            $this->assertThat($result, $this->logicalOr(
                $this->isType('int'),
                $this->identicalTo(false)
            ));
        } else {
            // APCu absent: must return false (graceful degradation)
            $this->assertFalse($result);
        }
    }

    /**
     * storeCount() and incrementCount() are silent no-ops when APCu is absent.
     */
    public function testThrottleStoreAndIncrementAreNoOpsWithoutApcu(): void
    {
        // Arrange
        $mw = new ThrottleMiddleware(60, 60);
        $storeRef = new \ReflectionMethod($mw, 'storeCount');
        $incRef   = new \ReflectionMethod($mw, 'incrementCount');

        // Act — must not throw
        $storeRef->invoke($mw, 'key', 1, 60);
        $incRef->invoke($mw, 'key');

        // Assert — if we got here without exception, the no-op path worked
        $this->assertTrue(true);
    }

    // =========================================================================
    // MaintenanceModeMiddleware
    // =========================================================================

    /**
     * When the flag file does NOT exist, the middleware calls $next normally.
     */
    public function testMaintenanceModePassesThroughWhenFlagAbsent(): void
    {
        // Arrange — use a path that certainly doesn't exist
        $flagFile = sys_get_temp_dir() . '/pramnos_maintenance_' . uniqid() . '.flag';
        $mw       = new MaintenanceModeMiddleware($flagFile);
        $called   = false;

        // Act
        $result = $mw->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return 'live';
        });

        // Assert
        $this->assertTrue($called);
        $this->assertSame('live', $result);
    }

    /**
     * When the flag file EXISTS, the middleware throws a 503 exception and
     * does NOT call $next.
     */
    public function testMaintenanceModeThrows503WhenFlagPresent(): void
    {
        // Arrange
        $flagFile = sys_get_temp_dir() . '/pramnos_maintenance_' . uniqid() . '.flag';
        touch($flagFile);
        $mw     = new MaintenanceModeMiddleware($flagFile);
        $called = false;

        try {
            // Act
            $mw->handle($this->makeRequest(), function () use (&$called) {
                $called = true;
                return 'should-not-run';
            });
            $this->fail('Expected 503 exception not thrown');
        } catch (\Exception $e) {
            // Assert
            $this->assertSame(503, $e->getCode());
            $this->assertFalse($called, '$next was called during maintenance');
        } finally {
            unlink($flagFile);
        }
    }

    /**
     * Constructor with an explicit path stores and uses that path.
     */
    public function testMaintenanceModeConstructorWithCustomPath(): void
    {
        // Arrange
        $flagFile = sys_get_temp_dir() . '/pramnos_test_' . uniqid() . '.flag';
        $mw       = new MaintenanceModeMiddleware($flagFile);

        // Act — no flag file → passes through
        $called = false;
        $mw->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return 'ok';
        });

        // Assert
        $this->assertTrue($called, 'Middleware blocked even without flag file');
    }

    /**
     * Constructor with empty string resolves to a sensible default path
     * (ROOT or getcwd()) and does not throw during construction.
     */
    public function testMaintenanceModeConstructorDefaultPath(): void
    {
        // Arrange / Act — must not throw
        $mw = new MaintenanceModeMiddleware();

        // Assert — flag doesn't exist at default path → passes through
        $called = false;
        $mw->handle($this->makeRequest(), function () use (&$called) {
            $called = true;
            return 'ok';
        });
        $this->assertTrue($called);
    }
}
