<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Pramnos\Http\Psr\Pipeline;
use Pramnos\Http\Psr\ServerRequestCreator;

/**
 * Characterization tests for the PSR-7 / PSR-15 HTTP layer.
 *
 * Covers:
 * - ServerRequestCreator::fromServerParams() produces a valid PSR-7 request.
 * - Pipeline implements MiddlewareInterface (PSR-15 compliance).
 * - Pipeline executes middleware in FIFO order.
 * - Pipeline delegates to the final handler when the stack is empty.
 * - pipe() is immutable — original pipeline is unmodified.
 * - Middleware can short-circuit (return early without calling handler).
 * - Pipelines can be nested (Pipeline inside Pipeline).
 *
 * These tests do not touch superglobals and rely only on Nyholm PSR-7
 * objects, which are already in the vendor directory.
 */
#[CoversClass(Pipeline::class)]
#[CoversClass(ServerRequestCreator::class)]
class PsrHttpCharacterizationTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    // -------------------------------------------------------------------------
    // ServerRequestCreator
    // -------------------------------------------------------------------------

    /**
     * fromServerParams() must return a Psr\Http\Message\ServerRequestInterface
     * built from the supplied server params — no superglobals involved.
     */
    public function testFromServerParamsReturnsServerRequestInterface(): void
    {
        // Arrange
        $params = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/test',
            'HTTP_HOST'      => 'example.com',
        ];

        // Act
        $request = ServerRequestCreator::fromServerParams($params);

        // Assert
        $this->assertInstanceOf(ServerRequestInterface::class, $request);
    }

    /**
     * fromServerParams() must map REQUEST_METHOD correctly onto the
     * returned request object.
     */
    public function testFromServerParamsMapsHttpMethod(): void
    {
        // Arrange
        $params = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI'    => '/submit',
            'HTTP_HOST'      => 'example.com',
        ];

        // Act
        $request = ServerRequestCreator::fromServerParams($params);

        // Assert
        $this->assertSame('POST', $request->getMethod());
    }

    /**
     * fromServerParams() must reconstruct the URI from HTTP_HOST and
     * REQUEST_URI entries in the server params.
     */
    public function testFromServerParamsBuildsCorrectUri(): void
    {
        // Arrange
        $params = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/path?query=1',
            'HTTP_HOST'      => 'example.com',
        ];

        // Act
        $request = ServerRequestCreator::fromServerParams($params);

        // Assert
        $uri = $request->getUri();
        $this->assertSame('example.com', $uri->getHost());
        $this->assertStringStartsWith('/path', $uri->getPath());
    }

    /**
     * https scheme must be detected when HTTPS=on is in the server params.
     */
    public function testFromServerParamsDetectsHttpsScheme(): void
    {
        // Arrange
        $params = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/',
            'HTTP_HOST'      => 'secure.example.com',
            'HTTPS'          => 'on',
        ];

        // Act
        $request = ServerRequestCreator::fromServerParams($params);

        // Assert
        $this->assertSame('https', $request->getUri()->getScheme());
    }

    // -------------------------------------------------------------------------
    // Pipeline — PSR-15 compliance
    // -------------------------------------------------------------------------

    /**
     * Pipeline must implement Psr\Http\Server\MiddlewareInterface so it can
     * be used as a middleware inside another pipeline (composition).
     */
    public function testPipelineImplementsMiddlewareInterface(): void
    {
        // Assert
        $this->assertInstanceOf(MiddlewareInterface::class, new Pipeline());
    }

    /**
     * An empty pipeline must delegate directly to the final handler without
     * modification — the request passes through unchanged.
     */
    public function testEmptyPipelineDelegatesToFinalHandler(): void
    {
        // Arrange
        $pipeline = new Pipeline();
        $request  = $this->buildRequest('GET', '/');
        $handler  = $this->buildHandler(200, 'direct');

        // Act
        $response = $pipeline->process($request, $handler);

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('direct', (string) $response->getBody());
    }

    /**
     * Middleware added via pipe() must execute before the final handler.
     * The response body is built by appending strings so the execution
     * order is observable.
     */
    public function testMiddlewareExecutesBeforeFinalHandler(): void
    {
        // Arrange
        $pipeline = (new Pipeline())->pipe(
            $this->buildMiddleware(fn(string $body) => 'middleware:' . $body)
        );
        $request = $this->buildRequest('GET', '/');
        $handler = $this->buildHandler(200, 'handler');

        // Act
        $response = $pipeline->process($request, $handler);

        // Assert — middleware wraps the handler body
        $this->assertSame('middleware:handler', (string) $response->getBody());
    }

    /**
     * Multiple middleware must execute in FIFO (first-piped = outermost) order.
     * The assertion string encodes the expected call sequence.
     */
    public function testMultipleMiddlewareExecuteInFifoOrder(): void
    {
        // Arrange — two middleware, each wraps the downstream body
        $pipeline = (new Pipeline())
            ->pipe($this->buildMiddleware(fn(string $body) => 'first:' . $body))
            ->pipe($this->buildMiddleware(fn(string $body) => 'second:' . $body));

        $request = $this->buildRequest('GET', '/');
        $handler = $this->buildHandler(200, 'end');

        // Act
        $response = $pipeline->process($request, $handler);

        // Assert — outer wraps inner: "first" sees the result of "second" + "end"
        $this->assertSame('first:second:end', (string) $response->getBody());
    }

    /**
     * pipe() must be immutable: the original pipeline must not gain new
     * middleware after a chained call.  This prevents accidental sharing of
     * state when pipelines are reused across requests.
     */
    public function testPipeIsImmutable(): void
    {
        // Arrange
        $original = new Pipeline();
        $extended = $original->pipe(
            $this->buildMiddleware(fn(string $body) => 'added:' . $body)
        );

        $request     = $this->buildRequest('GET', '/');
        $handler     = $this->buildHandler(200, 'resp');

        // Act
        $originalResponse = $original->process($request, $handler);
        $extendedResponse = $extended->process($request, $handler);

        // Assert — original is unaffected, extended has the middleware
        $this->assertSame('resp',       (string) $originalResponse->getBody());
        $this->assertSame('added:resp', (string) $extendedResponse->getBody());
    }

    /**
     * A middleware that short-circuits (returns early) must prevent the
     * final handler from being called at all.
     */
    public function testShortCircuitMiddlewareBlocksFinalHandler(): void
    {
        // Arrange — middleware ignores $handler and returns its own response
        $shortCircuit = new class ($this->factory) implements MiddlewareInterface {
            public function __construct(private readonly Psr17Factory $factory) {}
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                return $this->factory->createResponse(401)
                    ->withBody($this->factory->createStream('blocked'));
            }
        };

        $handler = $this->buildHandler(200, 'reached');

        $pipeline = (new Pipeline())->pipe($shortCircuit);
        $request  = $this->buildRequest('GET', '/protected');

        // Act
        $response = $pipeline->process($request, $handler);

        // Assert — short-circuit response returned (not the 200 from final handler)
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('blocked', (string) $response->getBody());
    }

    /**
     * A Pipeline can be used as a middleware inside another Pipeline.
     * This tests the MiddlewareInterface::process() implementation on Pipeline.
     */
    public function testNestedPipelines(): void
    {
        // Arrange — inner pipeline wraps with "inner:", outer wraps with "outer:"
        $inner = (new Pipeline())->pipe(
            $this->buildMiddleware(fn(string $body) => 'inner:' . $body)
        );

        $outer = (new Pipeline())->pipe(
            $this->buildMiddleware(fn(string $body) => 'outer:' . $body)
        )->pipe($inner);

        $request = $this->buildRequest('GET', '/');
        $handler = $this->buildHandler(200, 'core');

        // Act
        $response = $outer->process($request, $handler);

        // Assert
        $this->assertSame('outer:inner:core', (string) $response->getBody());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildRequest(string $method, string $path): ServerRequestInterface
    {
        return $this->factory->createServerRequest($method, $path);
    }

    private function buildHandler(int $status, string $body): RequestHandlerInterface
    {
        $factory = $this->factory;
        return new class ($factory, $status, $body) implements RequestHandlerInterface {
            public function __construct(
                private readonly Psr17Factory $factory,
                private readonly int $status,
                private readonly string $body
            ) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse($this->status)
                    ->withBody($this->factory->createStream($this->body));
            }
        };
    }

    /**
     * Build a simple middleware that transforms the downstream body via $transform.
     *
     * @param  callable(string): string $transform
     */
    private function buildMiddleware(callable $transform): MiddlewareInterface
    {
        $factory = $this->factory;
        return new class ($factory, $transform) implements MiddlewareInterface {
            public function __construct(
                private readonly Psr17Factory $factory,
                private readonly \Closure $transform
            ) {}
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler
            ): ResponseInterface {
                $downstream = $handler->handle($request);
                $newBody    = ($this->transform)((string) $downstream->getBody());
                return $downstream->withBody($this->factory->createStream($newBody));
            }
        };
    }
}
