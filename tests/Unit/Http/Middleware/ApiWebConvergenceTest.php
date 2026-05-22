<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\Middleware\ApiAuthMiddleware;
use Pramnos\Http\Middleware\CorsMiddleware;
use Pramnos\Http\Middleware\JsonResponseMiddleware;
use Pramnos\Http\MiddlewarePipeline;
use Pramnos\Http\Request;

/**
 * Phase 15 integration test — API pipeline vs web pipeline convergence.
 *
 * Verifies that the three-layer API pipeline (CorsMiddleware →
 * JsonResponseMiddleware → ApiAuthMiddleware) and a plain web pipeline
 * (no auth middleware) can coexist and produce the correct behaviour:
 *
 *  - API requests without a key are rejected with a JSON 403 envelope.
 *  - API requests with a valid key reach the controller.
 *  - Web requests pass straight through without any auth requirement.
 *
 * This is the composition test for Phase 15: the middleware classes exist and
 * work correctly in isolation (individual unit tests) — here we verify they
 * chain together as designed.
 */
#[CoversClass(ApiAuthMiddleware::class)]
#[CoversClass(CorsMiddleware::class)]
#[CoversClass(JsonResponseMiddleware::class)]
#[CoversClass(MiddlewarePipeline::class)]
class ApiWebConvergenceTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SERVER['HTTP_APIKEY'], $_SERVER['HTTP_ACCESSTOKEN']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_APIKEY'], $_SERVER['HTTP_ACCESSTOKEN']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the standard API pipeline:
     *   CorsMiddleware(['*']) → JsonResponseMiddleware → ApiAuthMiddleware($checker)
     *
     * This mirrors what Api::exec() wires up, allowing us to test the composition
     * in isolation without booting the full application.
     */
    private function makeApiPipeline(callable $apiKeyChecker): MiddlewarePipeline
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new CorsMiddleware(['*']));
        $pipeline->pipe(new JsonResponseMiddleware());
        $pipeline->pipe(new ApiAuthMiddleware($apiKeyChecker));
        return $pipeline;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // API pipeline — authentication enforcement
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When HTTP_APIKEY is absent, the full API pipeline must short-circuit
     * before reaching the controller and return a JSON 403 envelope.
     *
     * This verifies that CorsMiddleware (which always passes through for
     * non-OPTIONS) does not interfere with ApiAuthMiddleware's key check.
     */
    public function testApiPipelineShortCircuitsWithJson403WhenKeyAbsent(): void
    {
        // Arrange — no HTTP_APIKEY in $_SERVER
        $controllerCalled = false;
        $pipeline = $this->makeApiPipeline(fn() => true);

        // Act
        $response = $pipeline->run(
            Request::create('/api/v1/users', 'GET'),
            function () use (&$controllerCalled): string {
                $controllerCalled = true;
                return '<html>controller</html>';
            }
        );

        // Assert — controller never reached
        $this->assertFalse($controllerCalled,
            'Controller must not be called when API key is missing');

        // Assert — response is a JSON error envelope (not HTML)
        $decoded = json_decode($response, true);
        $this->assertIsArray($decoded, 'Pipeline response must be valid JSON');
        $this->assertSame(403, $decoded['status']);
        $this->assertSame('APIKeyMissing', $decoded['error']);
    }

    /**
     * When the API key checker returns false (invalid key), the pipeline must
     * short-circuit and return a JSON 401 envelope — not the controller response.
     */
    public function testApiPipelineReturns401JsonWhenKeyIsInvalid(): void
    {
        // Arrange — checker rejects all keys
        $_SERVER['HTTP_APIKEY'] = 'wrong-key';
        $pipeline = $this->makeApiPipeline(fn(string $k) => false);

        // Act
        $response = $pipeline->run(
            Request::create('/api/v1/users', 'GET'),
            fn() => '<html>should not reach</html>'
        );

        // Assert
        $decoded = json_decode($response, true);
        $this->assertIsArray($decoded, 'Rejected key response must be valid JSON');
        $this->assertSame(401, $decoded['status']);
        $this->assertSame('APIKeyInvalid', $decoded['error']);
    }

    /**
     * When the API key is valid, the pipeline must call $next and return whatever
     * the controller returns — the middleware stack is transparent on success.
     */
    public function testApiPipelinePassesToControllerWithValidKey(): void
    {
        // Arrange — valid key
        $_SERVER['HTTP_APIKEY'] = 'correct-key';
        $pipeline = $this->makeApiPipeline(fn(string $k) => $k === 'correct-key');

        // Act
        $response = $pipeline->run(
            Request::create('/api/v1/users', 'GET'),
            fn() => '{"users":[]}'
        );

        // Assert — controller response passed through unchanged
        $this->assertSame('{"users":[]}', $response);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Web pipeline — no auth requirement
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A plain web pipeline (MiddlewarePipeline with no ApiAuthMiddleware) must
     * pass every request to $next without any authentication check.
     *
     * This confirms that the two pipeline styles can coexist: API routes use the
     * 3-middleware stack while web routes use a plain pipeline, and the same
     * MiddlewarePipeline class supports both patterns.
     */
    public function testWebPipelinePassesDirectlyWithoutAuth(): void
    {
        // Arrange — no API key, plain pipeline (no auth middleware)
        $pipeline = new MiddlewarePipeline();
        // No middleware added — $next is the only handler

        // Act
        $response = $pipeline->run(
            Request::create('/home', 'GET'),
            fn() => '<html><body>Welcome</body></html>'
        );

        // Assert — web controller reached and returned HTML
        $this->assertSame('<html><body>Welcome</body></html>', $response);
    }

    /**
     * Demonstrates the convergence: the same request (without an API key) is
     * handled differently by an API pipeline vs a web pipeline.
     *
     *  - API pipeline → JSON 403 (auth enforced)
     *  - Web pipeline → HTML response (no auth)
     *
     * This is the core claim of Phase 15: both route styles can coexist in the
     * same application by choosing the appropriate pipeline per route group.
     */
    public function testApiAndWebPipelinesHandleSameRequestDifferently(): void
    {
        // Arrange — no API key in scope
        $request   = Request::create('/resource', 'GET');
        $controller = fn() => '<h1>Hello</h1>';

        $apiPipeline = $this->makeApiPipeline(fn() => true);
        $webPipeline = new MiddlewarePipeline();

        // Act
        $apiResponse = $apiPipeline->run($request, $controller);
        $webResponse = $webPipeline->run($request, $controller);

        // Assert — API pipeline enforces auth, web pipeline does not
        $apiDecoded = json_decode($apiResponse, true);
        $this->assertIsArray($apiDecoded,
            'API pipeline response must be a JSON error envelope when key is absent');
        $this->assertSame(403, $apiDecoded['status']);

        $this->assertSame('<h1>Hello</h1>', $webResponse,
            'Web pipeline must pass through to controller unconditionally');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // JSON error envelope structure
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every JSON error envelope produced by the API pipeline must contain the
     * four required keys: 'status', 'statusmessage', 'message', 'error'.
     *
     * This contract is consumed by API clients and must remain stable.
     */
    public function testApiPipelineErrorEnvelopeHasAllRequiredKeys(): void
    {
        // Arrange — trigger 403 via missing key
        $pipeline = $this->makeApiPipeline(fn() => true);

        // Act
        $response = $pipeline->run(Request::create('/api/x', 'GET'), fn() => null);
        $decoded  = json_decode($response, true);

        // Assert — all four required keys present
        $this->assertArrayHasKey('status',        $decoded);
        $this->assertArrayHasKey('statusmessage', $decoded);
        $this->assertArrayHasKey('message',       $decoded);
        $this->assertArrayHasKey('error',         $decoded);
    }
}
