<?php

declare(strict_types=1);

/**
 * Shadow getallheaders() inside the Pramnos\Http\Psr namespace so that
 * ServerRequestCreator::fromGlobals() can be exercised in a CLI context where
 * the real global getallheaders() returns false (no web-server SAPI).
 *
 * PHP resolves unqualified function calls by looking in the current namespace
 * first, then falling back to the global namespace. Declaring this function
 * here makes all calls to `getallheaders()` within Pramnos\Http\Psr return
 * a controlled set of headers for the lifetime of the test process.
 */
namespace Pramnos\Http\Psr {
    function getallheaders(): array
    {
        return ['X-Test-Header' => 'value', 'Content-Type' => 'application/json'];
    }
}

/**
 * The test class lives in its own namespace so the PHPUnit autoloader can
 * locate and register it normally.
 */
namespace Pramnos\Tests\Unit\Pramnos\Http\Psr {

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Psr\ServerRequestCreator;

/**
 * Unit tests for Pramnos\Http\Psr\ServerRequestCreator.
 *
 * ServerRequestCreator is the canonical entry point for PSR-7 request creation.
 * It wraps nyholm/psr7-server when available and falls back to a manual
 * construction path otherwise.  These tests exercise both entry points:
 *
 *  - fromGlobals() — reads superglobals, converts to PSR-7 ServerRequestInterface.
 *  - fromServerParams() — already covered by CharacterizationTest; retested here
 *    for completeness and to keep coverage under the #[CoversClass] attribute.
 *
 * The namespace-level getallheaders() stub above shadows the PHP built-in
 * inside Pramnos\Http\Psr so that fromGlobals() can be tested without a
 * web-server SAPI.
 */
#[CoversClass(ServerRequestCreator::class)]
class ServerRequestCreatorTest extends TestCase
{
    /** Original superglobal values saved before each test. */
    private array $savedCookie  = [];
    private array $savedGet     = [];
    private array $savedPost    = [];
    private array $savedFiles   = [];
    private array $savedServer  = [];

    protected function setUp(): void
    {
        // Snapshot superglobals so tearDown can restore them
        $this->savedCookie = $_COOKIE;
        $this->savedGet    = $_GET;
        $this->savedPost   = $_POST;
        $this->savedFiles  = $_FILES;
        $this->savedServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        // Restore superglobals to their original state
        $_COOKIE = $this->savedCookie;
        $_GET    = $this->savedGet;
        $_POST   = $this->savedPost;
        $_FILES  = $this->savedFiles;
        $_SERVER = $this->savedServer;
    }

    // =========================================================================
    // fromGlobals() — manual fallback path (nyholm/psr7-server not installed)
    // =========================================================================

    /**
     * fromGlobals() must return a PSR-7 ServerRequestInterface built from the
     * current PHP superglobals when the NyholmCreator helper is not installed.
     *
     * The manual fallback path (lines 47-68 of ServerRequestCreator.php) reads:
     *   $_SERVER  → method + URI
     *   getallheaders() → HTTP request headers (shadowed by namespace stub)
     *   $_COOKIE  → cookie params
     *   $_GET     → query params
     *   $_POST    → parsed body
     *   $_FILES   → uploaded files
     *
     * This test ensures every conditional branch inside the fallback is taken
     * by providing non-empty values in every superglobal.
     */
    public function testFromGlobalsReturnsPsr7RequestWithAllSuperglobals(): void
    {
        // Arrange — inject data into every superglobal that fromGlobals() reads
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_HOST']      = 'example.com';
        $_SERVER['REQUEST_URI']    = '/api/test?foo=bar';
        $_SERVER['HTTPS']          = 'on';

        $_COOKIE = ['session_id' => 'abc123'];
        $_GET    = ['foo' => 'bar', 'page' => '2'];
        $_POST   = ['field' => 'value', 'action' => 'save'];
        // $_FILES values must match the PSR-7 UploadedFile structure; for this
        // test we just need $_FILES to be non-empty so the branch is entered.
        // A malformed entry is tolerable because fromGlobals() passes the raw
        // array to withUploadedFiles() without further normalisation here.
        $_FILES = ['upload' => [
            'name'     => 'test.txt',
            'type'     => 'text/plain',
            'size'     => 4,
            'tmp_name' => '/tmp/test',
            'error'    => UPLOAD_ERR_OK,
        ]];

        // Act
        $request = ServerRequestCreator::fromGlobals();

        // Assert — the returned object must be a PSR-7 server request
        $this->assertInstanceOf(
            \Psr\Http\Message\ServerRequestInterface::class,
            $request,
            'fromGlobals() must return a Psr\Http\Message\ServerRequestInterface'
        );

        // Method is read from $_SERVER['REQUEST_METHOD']
        $this->assertSame('POST', $request->getMethod(),
            'fromGlobals() must use REQUEST_METHOD from $_SERVER');

        // URI reflects HTTPS + host + path
        $uri = $request->getUri();
        $this->assertSame('https', $uri->getScheme(),
            'fromGlobals() must detect HTTPS from $_SERVER[HTTPS]');
        $this->assertStringContainsString('example.com', (string) $uri,
            'fromGlobals() must include the host in the URI');

        // Cookie params were propagated
        $cookies = $request->getCookieParams();
        $this->assertArrayHasKey('session_id', $cookies,
            'fromGlobals() must propagate $_COOKIE into CookieParams');

        // Query params were propagated
        $query = $request->getQueryParams();
        $this->assertArrayHasKey('foo', $query,
            'fromGlobals() must propagate $_GET into QueryParams');

        // Parsed body was propagated
        $body = $request->getParsedBody();
        $this->assertIsArray($body);
        $this->assertArrayHasKey('field', $body,
            'fromGlobals() must propagate $_POST into ParsedBody');

        // Headers from the namespace-level getallheaders() stub are present
        $this->assertTrue(
            $request->hasHeader('X-Test-Header'),
            'fromGlobals() must apply headers returned by getallheaders()'
        );
    }

    /**
     * fromGlobals() must still return a valid PSR-7 request when all
     * superglobals are empty — every conditional branch (cookies, GET, POST,
     * FILES) evaluates to false and the code skips those withXxx() calls.
     *
     * This covers the happy-path where the request arrives without any body,
     * query string, cookies, or uploaded files.
     */
    public function testFromGlobalsWithEmptySuperglobals(): void
    {
        // Arrange — clear every optional superglobal
        $_COOKIE = [];
        $_GET    = [];
        $_POST   = [];
        $_FILES  = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST']      = 'localhost';
        $_SERVER['REQUEST_URI']    = '/';
        unset($_SERVER['HTTPS']);

        // Act
        $request = ServerRequestCreator::fromGlobals();

        // Assert — basic structure is still valid
        $this->assertInstanceOf(
            \Psr\Http\Message\ServerRequestInterface::class,
            $request,
            'fromGlobals() must return a PSR-7 ServerRequestInterface even with empty superglobals'
        );
        $this->assertSame('GET', $request->getMethod(),
            'fromGlobals() must default to GET when REQUEST_METHOD is GET');
        $this->assertSame('http', $request->getUri()->getScheme(),
            'fromGlobals() must use http scheme when HTTPS is absent');
    }

    // =========================================================================
    // fromServerParams() — direct construction (already covered by
    // CharacterizationTest; retested here for completeness)
    // =========================================================================

    /**
     * fromServerParams() must return a PSR-7 request whose method and URI
     * are derived exclusively from the supplied $serverParams array, ignoring
     * the real $_SERVER superglobal entirely.
     *
     * This makes it suitable for use in tests where superglobals cannot be
     * safely mutated (e.g. concurrent test workers, complex test suites).
     */
    public function testFromServerParamsBuildsRequestFromGivenArray(): void
    {
        // Arrange — a minimal server-params array (no $_SERVER dependency)
        $params = [
            'REQUEST_METHOD' => 'PUT',
            'HTTP_HOST'      => 'api.example.com',
            'REQUEST_URI'    => '/v2/resource/42',
            'HTTPS'          => 'on',
        ];

        // Act
        $request = ServerRequestCreator::fromServerParams($params);

        // Assert — method and URI reflect the supplied params
        $this->assertSame('PUT', $request->getMethod(),
            'fromServerParams() must use REQUEST_METHOD from the given array');
        $this->assertStringContainsString('api.example.com', (string) $request->getUri(),
            'fromServerParams() must incorporate HTTP_HOST into the URI');
        $this->assertSame('https', $request->getUri()->getScheme(),
            'fromServerParams() must detect HTTPS from the HTTPS param');
    }
}

} // end namespace Pramnos\Tests\Unit\Pramnos\Http\Psr
