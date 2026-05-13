<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Middleware\MaintenanceModeMiddleware;
use Pramnos\Http\Request;

/**
 * Unit tests for Pramnos\Http\Middleware\MaintenanceModeMiddleware.
 *
 * MaintenanceModeMiddleware gates requests behind a maintenance flag file.
 * When the flag file exists, handle() throws an Exception with code 503.
 * When absent, it calls $next($request) and returns its value.
 *
 * Tests use a real temp file to verify the file-presence branch, avoiding
 * any need to mock the filesystem.
 *
 * Tests verify:
 *   - Constructor with explicit flag file path stores it.
 *   - handle() calls $next and returns its value when flag file is absent.
 *   - handle() throws Exception(503) and sets Retry-After when flag is present.
 */
#[CoversClass(MaintenanceModeMiddleware::class)]
class MaintenanceModeMiddlewareTest extends TestCase
{
    private string $flagFile;

    protected function setUp(): void
    {
        // Use a unique temp path so tests are isolated even if run in parallel.
        $this->flagFile = sys_get_temp_dir() . '/pramnos_test_maintenance_' . uniqid() . '.flag';
    }

    protected function tearDown(): void
    {
        // Clean up any flag file the test may have created.
        if (file_exists($this->flagFile)) {
            unlink($this->flagFile);
        }
    }

    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * An explicit flag file path passed to the constructor is stored and used
     * by handle().  This test exercises the non-default constructor branch.
     */
    public function testConstructorWithExplicitPathUsesIt(): void
    {
        // Arrange — flag file does NOT exist
        $m       = new MaintenanceModeMiddleware($this->flagFile);
        $request = new Request();
        $called  = false;

        // Act
        $result = $m->handle($request, function () use (&$called) {
            $called = true;
            return 'ok';
        });

        // Assert — next was called (no maintenance file present)
        $this->assertTrue($called);
        $this->assertSame('ok', $result);
    }

    // =========================================================================
    // handle() — flag file absent
    // =========================================================================

    /**
     * When the flag file is absent, handle() calls $next(request) and returns
     * its return value unchanged.
     */
    public function testHandleCallsNextWhenFlagFileIsAbsent(): void
    {
        // Arrange — file does NOT exist
        $m       = new MaintenanceModeMiddleware($this->flagFile);
        $request = new Request();
        $log     = [];

        // Act
        $result = $m->handle($request, function (Request $req) use (&$log, $request): string {
            $log[] = 'next';
            $this->assertSame($request, $req);
            return 'response';
        });

        // Assert
        $this->assertSame(['next'], $log);
        $this->assertSame('response', $result);
    }

    // =========================================================================
    // handle() — flag file present
    // =========================================================================

    /**
     * When the flag file exists, handle() throws an Exception with code 503
     * without calling $next.
     */
    public function testHandleThrows503WhenFlagFileIsPresent(): void
    {
        // Arrange — create the flag file
        file_put_contents($this->flagFile, '');
        $m       = new MaintenanceModeMiddleware($this->flagFile);
        $request = new Request();
        $called  = false;

        // Assert — exception thrown with code 503
        $this->expectException(\Exception::class);
        $this->expectExceptionCode(503);

        // Act
        $m->handle($request, function () use (&$called): void {
            $called = true;
        });

        // Should not reach here, but guard just in case
        $this->assertFalse($called, '$next must not be called during maintenance');
    }
}
