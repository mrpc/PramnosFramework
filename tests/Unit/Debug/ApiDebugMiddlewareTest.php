<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Debug\ApiDebugMiddleware;
use Pramnos\Debug\Collectors\CollectorInterface;
use Pramnos\Debug\DebugBar;
use Pramnos\Http\Request;
use Pramnos\Http\Response;

/**
 * The middleware that puts `_debug` on a JSON response.
 *
 * Reported by an application that routes `#[Route]` attributes to controllers
 * returning `Response::json()`, with no `src/Api`: the payload rides along under
 * `_debug` and the design is right, but attaching it was private to
 * `Application\Api`. So the project wrote its own middleware — about thirty lines
 * that decode the body, refuse a top-level list, merge the key and set the header —
 * and every attribute-routed project would write the same file, each one
 * rediscovering from an empty panel that a JSON *array* has nowhere to put a key.
 *
 * What has to hold: it annotates what it can, leaves alone what it cannot, works
 * for both shapes a controller returns (a `Response` and a bare string), and costs
 * nothing in production.
 */
#[CoversClass(ApiDebugMiddleware::class)]
class ApiDebugMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        DebugBar::reset();
        \Pramnos\Debug\ApiDebugPayload::resetHeaderState();
    }

    protected function tearDown(): void
    {
        DebugBar::reset();
        \Pramnos\Debug\ApiDebugPayload::resetHeaderState();
        parent::tearDown();
    }

    /**
     * Register a collector, which is what "development" means to the payload.
     */
    private function collecting(): void
    {
        DebugBar::getInstance()->addCollector(new class implements CollectorInterface {
            /** The name this collector's data appears under. */
            public function name(): string
            {
                return 'demo';
            }

            /** Something recognisable in the payload. */
            public function collect(): array
            {
                return ['count' => 3];
            }
        });
    }

    /** A request object the middleware only passes along. */
    private function request(): Request
    {
        return $this->createMock(Request::class);
    }

    /**
     * A JSON object body gains the payload, and keeps everything it had.
     */
    public function testAJsonObjectResponseGainsTheDebugKey(): void
    {
        // Arrange
        $this->collecting();
        $middleware = new ApiDebugMiddleware();

        // Act
        $result = $middleware->handle($this->request(), fn() => '{"status":"ok"}');

        // Assert
        $decoded = json_decode((string) $result, true);
        $this->assertSame('ok', $decoded['status'], 'the response itself survives');
        $this->assertSame(3, $decoded['_debug']['demo']['count']);
    }

    /**
     * A `Response` object is annotated through its own body, and stays a Response.
     *
     * This is the shape an attribute-routed controller returns
     * (`Response::json(...)`), and returning a string instead would break every
     * later middleware and the status code with it.
     */
    public function testAResponseObjectIsAnnotatedAndStaysAResponse(): void
    {
        // Arrange
        $this->collecting();
        $middleware = new ApiDebugMiddleware();
        $response   = Response::make('{"id":7}');

        // Act
        $result = $middleware->handle($this->request(), fn() => $response);

        // Assert
        $this->assertInstanceOf(Response::class, $result);
        $decoded = json_decode($result->getBody(), true);
        $this->assertSame(7, $decoded['id']);
        $this->assertArrayHasKey('_debug', $decoded);
    }

    /**
     * A top-level JSON array is left exactly as it is.
     *
     * There is nowhere in `[1,2,3]` to put a key, and inventing a wrapper would
     * change the contract the client is coded against. This is the rule every
     * hand-written copy of this middleware had to rediscover.
     */
    public function testATopLevelJsonArrayIsUntouched(): void
    {
        // Arrange
        $this->collecting();
        $middleware = new ApiDebugMiddleware();

        // Act
        $result = $middleware->handle($this->request(), fn() => '[1,2,3]');

        // Assert
        $this->assertSame('[1,2,3]', $result);
    }

    /**
     * A non-JSON body is left alone.
     *
     * An HTML fragment from the same application, or a plain-text answer: both are
     * legitimate responses, and neither has room for a key.
     */
    public function testANonJsonBodyIsUntouched(): void
    {
        // Arrange
        $this->collecting();
        $middleware = new ApiDebugMiddleware();

        // Act
        $result = $middleware->handle($this->request(), fn() => '<p>hello</p>');

        // Assert
        $this->assertSame('<p>hello</p>', $result);
    }

    /**
     * A body that already carries `_debug` is not rebuilt.
     *
     * The API layer attaches its own, and a project can have both this middleware
     * and that layer in play. Rebuilding would double the work and could disagree
     * with itself about the same request.
     */
    public function testAnExistingDebugKeyIsLeftAsItIs(): void
    {
        // Arrange
        $this->collecting();
        $middleware = new ApiDebugMiddleware();
        $body       = '{"status":"ok","_debug":{"from":"the api layer"}}';

        // Act
        $result = $middleware->handle($this->request(), fn() => $body);

        // Assert
        $this->assertSame($body, $result);
    }

    /**
     * In production nothing is attached, and nothing is decoded either.
     *
     * With no collector registered the payload is disabled, which is what
     * "production" means here — the toolbar registers collectors only in debug
     * mode. The response comes back byte for byte.
     */
    public function testInProductionTheResponseIsUntouched(): void
    {
        // Arrange — no collector
        $middleware = new ApiDebugMiddleware();

        // Act
        $result = $middleware->handle($this->request(), fn() => '{"status":"ok"}');

        // Assert
        $this->assertSame('{"status":"ok"}', $result);
    }

    /**
     * A controller that returned nothing gets its headers and no complaint.
     *
     * A 204, a redirect, or a controller that echoed its own output and returned
     * null. The headers are the only channel those have, and they are sent before
     * the body is looked at for exactly that reason.
     */
    public function testAResponseWithNoBodyIsPassedThrough(): void
    {
        // Arrange
        $this->collecting();
        $middleware = new ApiDebugMiddleware();

        // Act
        $result = $middleware->handle($this->request(), fn() => null);

        // Assert
        $this->assertNull($result);
    }

    /**
     * An unchanged body does not produce a new Response object.
     *
     * Cheap to assert and worth keeping: a middleware that clones a response it
     * did not change makes every later `===` comparison in a pipeline lie.
     */
    public function testAnUnchangedResponseObjectIsTheSameInstance(): void
    {
        // Arrange — no collector, so there is nothing to attach
        $middleware = new ApiDebugMiddleware();
        $response   = Response::make('[1,2,3]');

        // Act
        $result = $middleware->handle($this->request(), fn() => $response);

        // Assert
        $this->assertSame($response, $result);
    }
}
