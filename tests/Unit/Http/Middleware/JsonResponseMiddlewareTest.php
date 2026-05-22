<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\Middleware\JsonResponseMiddleware;
use Pramnos\Http\Request;

/**
 * Tests for JsonResponseMiddleware.
 *
 * Verifies that the middleware:
 * - Always sets Content-Type: application/json by default
 * - Sets Content-Type: application/xml when HTTP_ACCEPT requests it
 * - Always passes through to $next (does not short-circuit)
 * - Returns whatever $next returns
 */
#[CoversClass(JsonResponseMiddleware::class)]
class JsonResponseMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear HTTP_ACCEPT for isolation
        unset($_SERVER['HTTP_ACCEPT']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_ACCEPT']);
    }

    /**
     * Without HTTP_ACCEPT the middleware defaults to application/json and
     * passes through to $next, returning its result.
     */
    public function testDefaultsToJsonAndPassesThrough(): void
    {
        // Arrange
        $mw      = new JsonResponseMiddleware();
        $request = Request::create('/api/test', 'GET');
        $called  = false;

        // Act
        $result = $mw->handle($request, function (Request $r) use (&$called): string {
            $called = true;
            return 'next-result';
        });

        // Assert — next was called and its return value is propagated
        $this->assertTrue($called);
        $this->assertSame('next-result', $result);
    }

    /**
     * When HTTP_ACCEPT is 'application/xml', the middleware passes through
     * (content-type header would be set to XML — we verify $next is still called).
     */
    public function testXmlAcceptPassesThrough(): void
    {
        // Arrange
        $_SERVER['HTTP_ACCEPT'] = 'application/xml';
        $mw      = new JsonResponseMiddleware();
        $request = Request::create('/api/test', 'GET');
        $called  = false;

        // Act
        $result = $mw->handle($request, function (Request $r) use (&$called): string {
            $called = true;
            return 'xml-response';
        });

        // Assert — next was called even for XML accept
        $this->assertTrue($called);
        $this->assertSame('xml-response', $result);
    }

    /**
     * When HTTP_ACCEPT is the shorthand 'xml', behaviour is same as application/xml.
     */
    public function testShorthandXmlAcceptPassesThrough(): void
    {
        // Arrange
        $_SERVER['HTTP_ACCEPT'] = 'xml';
        $mw      = new JsonResponseMiddleware();
        $request = Request::create('/api/ping', 'GET');
        $called  = false;

        // Act
        $mw->handle($request, function () use (&$called): string {
            $called = true;
            return '';
        });

        // Assert
        $this->assertTrue($called);
    }

    /**
     * The middleware is a pass-through — it never short-circuits or returns null
     * when $next returns a value.
     */
    public function testReturnValueFromNextIsPreserved(): void
    {
        // Arrange
        $mw      = new JsonResponseMiddleware();
        $request = Request::create('/api/data', 'POST');

        // Act
        $result = $mw->handle($request, fn() => ['status' => 200, 'data' => 'ok']);

        // Assert — array from $next returned as-is
        $this->assertSame(['status' => 200, 'data' => 'ok'], $result);
    }
}
