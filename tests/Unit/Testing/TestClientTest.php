<?php

declare(strict_types=1);

namespace Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Testing\TestClient;
use Pramnos\Testing\TestResponse;

/**
 * Unit tests for TestClient — the in-memory HTTP client.
 *
 * TestClient boots the full Application on first use, so these tests inject a
 * pre-initialised Application stub that skips the database init() call.
 * Without this guard, Application::init() throws and calls exit(), killing the
 * entire PHPUnit process.
 *
 * Full routing round-trips belong in tests/Feature/ where the container and
 * database are guaranteed to be available.
 *
 * Tests verify:
 *  - Constructor accepts a pre-initialised Application stub
 *  - get(), post(), put(), delete() all return TestResponse instances
 *  - Each HTTP method correctly sets $_SERVER['REQUEST_METHOD']
 */
#[CoversClass(TestClient::class)]
class TestClientTest extends TestCase
{
    private TestClient $client;

    protected function setUp(): void
    {
        // Create a stub Application with initialized = true so TestClient
        // never calls init() and never attempts a database connection.
        $stubApp = new class extends Application {
            public $initialized = true; // no type hint — must match parent declaration
        };

        $this->client = new TestClient($stubApp);
    }

    // ── Constructor ───────────────────────────────────────────────────────────

    /**
     * Constructor must succeed when given a pre-initialised Application and
     * return a usable TestClient ready to dispatch requests.
     */
    public function testConstructorAcceptsPreconfiguredApplication(): void
    {
        // Assert — if setUp() didn't throw, construction succeeded
        $this->assertInstanceOf(TestClient::class, $this->client,
            'TestClient constructor must succeed with a pre-initialised Application');
    }

    // ── get() ─────────────────────────────────────────────────────────────────

    /**
     * get() must return a TestResponse and set REQUEST_METHOD to GET.
     *
     * The route need not exist — the TestClient catches controller-not-found
     * exceptions and wraps the error response in a TestResponse, so the return
     * type is always TestResponse regardless of routing outcome.
     */
    public function testGetReturnsTestResponseAndSetsMethod(): void
    {
        // Act
        $response = $this->client->get('/nonexistent-route');

        // Assert
        $this->assertInstanceOf(TestResponse::class, $response,
            'TestClient::get() must return a TestResponse instance');
        $this->assertSame('GET', $_SERVER['REQUEST_METHOD'] ?? '',
            'TestClient::get() must set $_SERVER[REQUEST_METHOD] to "GET"');
    }

    // ── post() ───────────────────────────────────────────────────────────────

    /**
     * post() must return a TestResponse and set REQUEST_METHOD to POST.
     */
    public function testPostReturnsTestResponseAndSetsMethod(): void
    {
        // Act
        $response = $this->client->post('/nonexistent-route', ['foo' => 'bar']);

        // Assert
        $this->assertInstanceOf(TestResponse::class, $response,
            'TestClient::post() must return a TestResponse instance');
        $this->assertSame('POST', $_SERVER['REQUEST_METHOD'] ?? '',
            'TestClient::post() must set $_SERVER[REQUEST_METHOD] to "POST"');
    }

    // ── put() ────────────────────────────────────────────────────────────────

    /**
     * put() must return a TestResponse and set REQUEST_METHOD to PUT.
     */
    public function testPutReturnsTestResponseAndSetsMethod(): void
    {
        // Act
        $response = $this->client->put('/nonexistent-route', ['field' => 'v']);

        // Assert
        $this->assertInstanceOf(TestResponse::class, $response,
            'TestClient::put() must return a TestResponse instance');
        $this->assertSame('PUT', $_SERVER['REQUEST_METHOD'] ?? '',
            'TestClient::put() must set $_SERVER[REQUEST_METHOD] to "PUT"');
    }

    // ── delete() ─────────────────────────────────────────────────────────────

    /**
     * delete() must return a TestResponse and set REQUEST_METHOD to DELETE.
     */
    public function testDeleteReturnsTestResponseAndSetsMethod(): void
    {
        // Act
        $response = $this->client->delete('/nonexistent-route');

        // Assert
        $this->assertInstanceOf(TestResponse::class, $response,
            'TestClient::delete() must return a TestResponse instance');
        $this->assertSame('DELETE', $_SERVER['REQUEST_METHOD'] ?? '',
            'TestClient::delete() must set $_SERVER[REQUEST_METHOD] to "DELETE"');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }
}
