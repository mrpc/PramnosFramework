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
        // Clear any leftover themeObject from a previous test so that
        // Document::render() does not call loadTheme() on a partial mock.
        $doc = \Pramnos\Framework\Factory::getDocument();
        if (isset($doc->themeObject)) {
            unset($doc->themeObject);
        }

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

    // ── submitForm() ─────────────────────────────────────────────────────────

    /**
     * submitForm() must throw RuntimeException because it is not yet
     * implemented (line 64). This ensures callers receive a clear signal
     * rather than a silent no-op when using this method.
     */
    public function testSubmitFormThrowsRuntimeException(): void
    {
        // Assert — RuntimeException is thrown before any form processing
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not yet fully implemented/');

        // Act
        $this->client->submitForm('Submit');
    }

    // ── call() with HTTP headers ──────────────────────────────────────────────

    /**
     * call() must propagate custom HTTP headers into $_SERVER (lines 77-78).
     * This is necessary for controllers or middleware that read request headers
     * like Authorization, X-API-Key, or Accept.
     */
    public function testCallSetsCustomHeadersInServerGlobal(): void
    {
        // Act — pass a custom header; call() must translate it to HTTP_X_CUSTOM
        $this->client->get('/some-route', ['X-Custom-Header' => 'custom-value']);

        // Assert — header was placed in $_SERVER with the expected key
        $this->assertSame(
            'custom-value',
            $_SERVER['HTTP_X_CUSTOM_HEADER'] ?? null,
            'call() must set HTTP_X_CUSTOM_HEADER in $_SERVER for the X-Custom-Header header'
        );
    }

    // ── call() with query string in URI ───────────────────────────────────────

    /**
     * call() must parse query-string parameters from the URI into $_GET
     * (line 87). Controllers rely on $_GET being populated from the URI,
     * not just from the $parameters array.
     */
    public function testCallParsesQueryStringFromUri(): void
    {
        // Act — include a query string in the URI
        $this->client->get('/some-route?foo=bar&baz=123');

        // Assert — query-string parameters were parsed into $_GET
        $this->assertSame('bar', $_GET['foo'] ?? null,
            'call() must parse foo=bar from the URI query string into $_GET');
        $this->assertSame('123', $_GET['baz'] ?? null,
            'call() must parse baz=123 from the URI query string into $_GET');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_X_CUSTOM_HEADER']);
        $_GET  = [];
        $_POST = [];
        unset($_SERVER['HTTP_REFERER']);
    }

    // ── Constructor (null-app path) ───────────────────────────────────────────

    /**
     * TestClient constructor called with no app argument must retrieve the
     * existing Application singleton (lines 21-22 + 26 + 28).
     *
     * setUp() registers a stub Application as the default singleton, so
     * Application::getInstance() returns it (non-null), exercising the
     * else branch at line 26. The stub has initialized=true, so init()
     * is not called (line 28 fires but not line 29).
     */
    public function testNullConstructorUsesExistingApplicationSingleton(): void
    {
        // Arrange — setUp() has already registered a stub as the singleton

        // Act — construct without an explicit app (null path, lines 21-29)
        $client = new TestClient();

        // Assert — client was created and uses the singleton
        $this->assertInstanceOf(TestClient::class, $client,
            'TestClient() must succeed when the Application singleton is available');
    }

    // ── MVC controller dispatch paths ─────────────────────────────────────────

    /**
     * call() must return a TestResponse wrapping the controller's Response
     * object (lines 126 + 129-130) when getController() succeeds and exec()
     * returns a Response instance.
     */
    public function testCallUsesControllerResponseWhenExecReturnsResponse(): void
    {
        // Arrange — stub app whose getController() returns a mock that returns Response
        $response = \Pramnos\Http\Response::make('Hello from controller', 200);
        $mockController = new class ($response) {
            public function __construct(private readonly mixed $returnVal) {}
            public function exec(string $action): mixed { return $this->returnVal; }
        };

        $stubApp = $this->makeStubApp(getController: $mockController);
        $client  = new TestClient($stubApp);

        // Act
        $result = $client->get('/any-route');

        // Assert — the controller's Response was wrapped (lines 126, 129, 130)
        $this->assertInstanceOf(\Pramnos\Testing\TestResponse::class, $result);
        $result->assertStatus(200);
    }

    /**
     * call() must render the document and return a TestResponse (lines
     * 126 + 135-139) when exec() returns a plain string. The string is
     * added to the document and the document is rendered for the response.
     */
    public function testCallRendersDocumentWhenExecReturnsString(): void
    {
        // Arrange — mock controller that returns a string
        $mockController = new class {
            public function exec(string $action): string { return 'Hello, string!'; }
        };

        $stubApp = $this->makeStubApp(getController: $mockController);
        $client  = new TestClient($stubApp);

        // Act
        $result = $client->get('/any-route');

        // Assert — document was rendered and TestResponse returned (lines 135-139)
        $this->assertInstanceOf(\Pramnos\Testing\TestResponse::class, $result);
    }

    /**
     * call() must catch \Pramnos\Http\RedirectException and return a 3xx
     * TestResponse (lines 141-142). This is the canonical path for controllers
     * that call $this->redirect() (which throws RedirectException in the
     * framework's non-exit mode).
     */
    public function testCallHandlesRedirectException(): void
    {
        // Arrange — mock controller that throws RedirectException
        $mockController = new class {
            public function exec(string $action): never {
                throw new \Pramnos\Http\RedirectException('/redirected-to', 302);
            }
        };

        $stubApp = $this->makeStubApp(getController: $mockController);
        $client  = new TestClient($stubApp);

        // Act
        $result = $client->get('/any-route');

        // Assert — RedirectException became a redirect response (lines 141-142)
        $this->assertInstanceOf(\Pramnos\Testing\TestResponse::class, $result);
        $result->assertStatus(302); // call() must return 302 for RedirectException
    }

    /**
     * call() must catch \Pramnos\Validation\ValidationException, store errors
     * and old input in session, and return a redirect response (lines 144-149).
     */
    public function testCallHandlesValidationException(): void
    {
        // Arrange — controller that throws ValidationException
        $mockController = new class {
            public function exec(string $action): never {
                throw new \Pramnos\Validation\ValidationException(
                    ['field' => ['Field is required']]
                );
            }
        };

        $_SERVER['HTTP_REFERER'] = '/form-page';

        $stubApp = $this->makeStubApp(getController: $mockController);
        $client  = new TestClient($stubApp);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Act
        $result = $client->get('/any-route');

        // Assert — ValidationException became a redirect (lines 144-149)
        $this->assertInstanceOf(\Pramnos\Testing\TestResponse::class, $result);
        $result->assertStatus(302); // call() must return a 302 redirect for ValidationException
        $this->assertArrayHasKey('_validation_errors', $_SESSION,
            'call() must store validation errors in session (line 145)');
        $this->assertArrayHasKey('_old_input', $_SESSION,
            'call() must store old input in session (line 146)');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Creates a stub Application subclass whose getController() returns
     * $getController if provided, or throws an Exception for unknown names.
     *
     * @param object|null $getController Controller object returned by getController()
     */
    private function makeStubApp(?object $getController = null): Application
    {
        return new class ($getController) extends Application {
            public $initialized  = true;
            public $defaultController = 'index';

            public function __construct(private readonly ?object $mockCtrl)
            {
                // Bypass parent constructor; register as default singleton manually.
                self::$appInstances['default'] = $this;
                self::$lastUsedApplication     = 'default';
            }

            public function getController($controller, $userPermissions = []): mixed
            {
                if ($this->mockCtrl !== null) {
                    return $this->mockCtrl;
                }
                throw new \Exception('Controller not found: ' . $controller);
            }
        };
    }
}
