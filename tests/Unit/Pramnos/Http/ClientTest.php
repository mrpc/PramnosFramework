<?php

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;
use Pramnos\Http\ClientException;

/**
 * Unit tests for the fluent HTTP client.
 *
 * All tests use Client::fake() so no real network calls are made.
 * The fake system is the canonical way to test code that uses the HTTP client;
 * tests here also verify that the fake system itself is correct.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(Client::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ClientResponse::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ClientException::class)]
class ClientTest extends TestCase
{
    protected function tearDown(): void
    {
        // Always reset fakes so tests are isolated
        Client::resetFakes();
    }

    // =========================================================================
    // Fake system
    // =========================================================================

    /**
     * Client::fake() intercepts requests matching the registered URL patterns
     * and returns the configured response without making a real HTTP call.
     *
     * This is the primary testing mechanism — code that uses the HTTP client
     * can be tested without network access or a running server.
     */
    public function testFakeReturnsRegisteredResponseForExactUrl(): void
    {
        // Arrange
        Client::fake([
            'https://api.example.com/users' => ClientResponse::make(['id' => 1, 'name' => 'John'], 200),
        ]);

        // Act
        $response = Client::get('https://api.example.com/users')->send();

        // Assert
        $this->assertTrue($response->ok());
        $this->assertSame(200, $response->status());
        $this->assertSame(1, $response->json('id'));
        $this->assertSame('John', $response->json('name'));
    }

    /**
     * Glob wildcards in fake keys allow matching a whole API path family.
     *
     * 'https://api.example.com/*' matches any URL under that domain,
     * useful for catching unexpected requests in tests.
     */
    public function testFakeMatchesGlobPattern(): void
    {
        // Arrange: catch-all wildcard for the domain
        Client::fake([
            'https://api.example.com/*' => ClientResponse::make([], 404),
        ]);

        // Act: any URL under the domain gets the 404
        $r1 = Client::get('https://api.example.com/users/42')->send();
        $r2 = Client::get('https://api.example.com/orders')->send();

        // Assert
        $this->assertSame(404, $r1->status());
        $this->assertSame(404, $r2->status());
    }

    /**
     * When multiple patterns are registered, the first matching one wins.
     *
     * More specific patterns should be registered before catch-alls.
     */
    public function testFakeFirstMatchWins(): void
    {
        // Arrange: specific rule before catch-all
        Client::fake([
            'https://api.example.com/users/1' => ClientResponse::make(['id' => 1], 200),
            'https://api.example.com/*'       => ClientResponse::make([], 404),
        ]);

        // Assert: specific URL gets 200, other paths get 404
        $this->assertSame(200, Client::get('https://api.example.com/users/1')->send()->status());
        $this->assertSame(404, Client::get('https://api.example.com/users/2')->send()->status());
    }

    /**
     * Fake values may also be callables, which receive the Client instance.
     * This lets tests inspect what headers/body were sent with the request.
     */
    public function testFakeCallableReceivesClientInstance(): void
    {
        // Arrange: callable captures the request
        $capturedClient = null;
        Client::fake([
            'https://api.example.com/echo' => function (Client $client) use (&$capturedClient): ClientResponse {
                $capturedClient = $client;
                return ClientResponse::make(['ok' => true], 200);
            },
        ]);

        // Act
        Client::post('https://api.example.com/echo')
            ->json(['msg' => 'hello'])
            ->send();

        // Assert: the callable received the Client instance
        $this->assertInstanceOf(Client::class, $capturedClient);
    }

    /**
     * resetFakes() clears all registered fakes.
     * After reset, send() would attempt a real network call — we cannot test
     * that here, but we can verify hasFakes() reflects the cleared state.
     */
    public function testResetFakesClearsRegistry(): void
    {
        // Arrange
        Client::fake(['https://example.com' => ClientResponse::make('', 200)]);
        $this->assertTrue(Client::hasFakes());

        // Act
        Client::resetFakes();

        // Assert
        $this->assertFalse(Client::hasFakes());
    }

    // =========================================================================
    // HTTP methods
    // =========================================================================

    /**
     * GET, POST, PUT, PATCH, DELETE, HEAD static factories each set the correct
     * HTTP method. The fake intercept returns regardless of method — the method
     * is verified by checking that no exception is thrown and the URL is matched.
     *
     * A callable fake that checks the instance method is the authoritative way
     * to assert method selection when building tests for production code.
     */
    public function testAllHttpMethodFactoriesReachFake(): void
    {
        // Arrange: one catch-all fake
        Client::fake(['*' => ClientResponse::make('', 200)]);

        // Assert: all methods hit the fake without error
        $this->assertSame(200, Client::get('https://x.com/a')->send()->status());
        $this->assertSame(200, Client::post('https://x.com/a')->send()->status());
        $this->assertSame(200, Client::put('https://x.com/a')->send()->status());
        $this->assertSame(200, Client::patch('https://x.com/a')->send()->status());
        $this->assertSame(200, Client::delete('https://x.com/a')->send()->status());
        $this->assertSame(200, Client::head('https://x.com/a')->send()->status());
    }

    // =========================================================================
    // Builder — headers and auth
    // =========================================================================

    /**
     * bearerToken() sets the Authorization header in the format expected by
     * RFC 6750 token-based APIs. Verifying this through a callable fake ensures
     * the header is actually built before send() is called.
     */
    public function testBearerTokenSetsAuthorizationHeader(): void
    {
        // Arrange
        $sentHeaders = [];
        Client::fake([
            'https://api.example.com/me' => function (Client $c) use (&$sentHeaders): ClientResponse {
                // Expose internal headers via reflection for assertion
                $ref = new \ReflectionProperty($c, 'headers');
                $ref->setAccessible(true);
                $sentHeaders = $ref->getValue($c);
                return ClientResponse::make([], 200);
            },
        ]);

        // Act
        Client::get('https://api.example.com/me')->bearerToken('my-secret-token')->send();

        // Assert
        $this->assertSame('Bearer my-secret-token', $sentHeaders['Authorization']);
    }

    /**
     * basicAuth() sets Authorization: Basic <base64(user:pass)>.
     */
    public function testBasicAuthSetsCorrectHeader(): void
    {
        // Arrange
        $sentHeaders = [];
        Client::fake([
            '*' => function (Client $c) use (&$sentHeaders): ClientResponse {
                $ref = new \ReflectionProperty($c, 'headers');
                $ref->setAccessible(true);
                $sentHeaders = $ref->getValue($c);
                return ClientResponse::make([], 200);
            },
        ]);

        // Act
        Client::get('https://api.example.com/')->basicAuth('user', 'pass')->send();

        // Assert
        $this->assertSame('Basic ' . base64_encode('user:pass'), $sentHeaders['Authorization']);
    }

    /**
     * header() and headers() allow setting arbitrary request headers.
     * Multiple calls to header() accumulate; headers() merges an associative array.
     */
    public function testCustomHeadersAreMerged(): void
    {
        // Arrange
        $sentHeaders = [];
        Client::fake([
            '*' => function (Client $c) use (&$sentHeaders): ClientResponse {
                $ref = new \ReflectionProperty($c, 'headers');
                $ref->setAccessible(true);
                $sentHeaders = $ref->getValue($c);
                return ClientResponse::make([], 200);
            },
        ]);

        // Act
        Client::get('https://example.com')
            ->header('X-Tenant', 'acme')
            ->headers(['X-Version' => '2', 'Accept' => 'application/json'])
            ->send();

        // Assert
        $this->assertSame('acme', $sentHeaders['X-Tenant']);
        $this->assertSame('2', $sentHeaders['X-Version']);
        $this->assertSame('application/json', $sentHeaders['Accept']);
    }

    // =========================================================================
    // Builder — body
    // =========================================================================

    /**
     * json() encodes the array as JSON and sets the content type to application/json.
     * The encoded body is stored on the Client instance before send() is called.
     */
    public function testJsonBodyIsEncodedAndContentTypeSet(): void
    {
        // Arrange
        $capturedBody        = null;
        $capturedContentType = null;
        Client::fake([
            '*' => function (Client $c) use (&$capturedBody, &$capturedContentType): ClientResponse {
                $bodyRef = new \ReflectionProperty($c, 'body');
                $bodyRef->setAccessible(true);
                $capturedBody = $bodyRef->getValue($c);

                $ctRef = new \ReflectionProperty($c, 'contentType');
                $ctRef->setAccessible(true);
                $capturedContentType = $ctRef->getValue($c);

                return ClientResponse::make([], 201);
            },
        ]);

        // Act
        Client::post('https://api.example.com/items')
            ->json(['name' => 'Widget', 'price' => 9.99])
            ->send();

        // Assert
        $this->assertSame('application/json', $capturedContentType);
        $decoded = json_decode($capturedBody, true);
        $this->assertSame('Widget', $decoded['name']);
        $this->assertSame(9.99, $decoded['price']);
    }

    /**
     * form() encodes the data as application/x-www-form-urlencoded.
     */
    public function testFormBodyIsUrlEncoded(): void
    {
        // Arrange
        $capturedBody        = null;
        $capturedContentType = null;
        Client::fake([
            '*' => function (Client $c) use (&$capturedBody, &$capturedContentType): ClientResponse {
                $bodyRef = new \ReflectionProperty($c, 'body');
                $bodyRef->setAccessible(true);
                $capturedBody = $bodyRef->getValue($c);

                $ctRef = new \ReflectionProperty($c, 'contentType');
                $ctRef->setAccessible(true);
                $capturedContentType = $ctRef->getValue($c);

                return ClientResponse::make([], 200);
            },
        ]);

        // Act
        Client::post('https://example.com/login')
            ->form(['username' => 'john', 'password' => 'secret'])
            ->send();

        // Assert
        $this->assertSame('application/x-www-form-urlencoded', $capturedContentType);
        parse_str($capturedBody, $parsed);
        $this->assertSame('john', $parsed['username']);
        $this->assertSame('secret', $parsed['password']);
    }

    /**
     * body() sets a raw body with an explicit content type.
     */
    public function testRawBodyWithExplicitContentType(): void
    {
        // Arrange
        $capturedBody        = null;
        $capturedContentType = null;
        Client::fake([
            '*' => function (Client $c) use (&$capturedBody, &$capturedContentType): ClientResponse {
                $bodyRef = new \ReflectionProperty($c, 'body');
                $bodyRef->setAccessible(true);
                $capturedBody = $bodyRef->getValue($c);

                $ctRef = new \ReflectionProperty($c, 'contentType');
                $ctRef->setAccessible(true);
                $capturedContentType = $ctRef->getValue($c);

                return ClientResponse::make([], 200);
            },
        ]);

        // Act
        Client::post('https://example.com/data')
            ->body('<root><item/></root>', 'application/xml')
            ->send();

        // Assert
        $this->assertSame('application/xml', $capturedContentType);
        $this->assertSame('<root><item/></root>', $capturedBody);
    }

    // =========================================================================
    // Base URL resolution
    // =========================================================================

    /**
     * make() on a Client instance with a base URL prepends the base to relative paths.
     *
     * This is the pattern for sharing auth and base URL across multiple requests
     * without repeating the full URL every time. Static Client::get() etc. are for
     * one-off requests; make() is for instance-based reuse.
     */
    public function testBaseUrlIsPrependedToRelativePathsViaMake(): void
    {
        // Arrange: capture the resolved URL from inside send()
        $resolvedUrls = [];
        Client::fake([
            '*' => function (Client $c) use (&$resolvedUrls): ClientResponse {
                $ref = new \ReflectionMethod($c, 'resolveUrl');
                $ref->setAccessible(true);
                $resolvedUrls[] = $ref->invoke($c);
                return ClientResponse::make([], 200);
            },
        ]);

        // Act: instance client with base URL, two relative paths
        $client = new Client('https://api.example.com');
        $client->make('GET', '/users')->send();
        $client->make('GET', 'orders')->send();

        // Assert: base URL correctly prepended to each relative path
        $this->assertSame('https://api.example.com/users',  $resolvedUrls[0]);
        $this->assertSame('https://api.example.com/orders', $resolvedUrls[1]);
    }

    /**
     * make() inherits default headers and auth from the parent instance so shared
     * config (token, tenant header) is set once and applied to every request.
     */
    public function testMakeInheritsDefaultHeadersFromParentInstance(): void
    {
        // Arrange
        $capturedHeaders = [];
        Client::fake([
            '*' => function (Client $c) use (&$capturedHeaders): ClientResponse {
                $ref = new \ReflectionProperty($c, 'headers');
                $ref->setAccessible(true);
                $capturedHeaders = $ref->getValue($c);
                return ClientResponse::make([], 200);
            },
        ]);

        // Act: configure once, reuse via make()
        $api = (new Client('https://api.example.com'))
            ->bearerToken('shared-token')
            ->header('X-Tenant', 'acme');

        $api->make('GET', '/users')->send();

        // Assert: inherited headers are present on the cloned request
        $this->assertSame('Bearer shared-token', $capturedHeaders['Authorization']);
        $this->assertSame('acme', $capturedHeaders['X-Tenant']);
    }

    // =========================================================================
    // throwOnError()
    // =========================================================================

    /**
     * throwOnError() causes send() to throw a ClientException on 4xx/5xx
     * responses, instead of returning them silently.
     *
     * This is useful when the caller treats any non-2xx as a hard failure
     * and does not want to check $response->ok() explicitly.
     */
    public function testThrowOnErrorThrowsForClientError(): void
    {
        // Arrange
        Client::fake(['*' => ClientResponse::make(['error' => 'Not Found'], 404)]);

        // Act + Assert
        $this->expectException(ClientException::class);
        Client::get('https://api.example.com/missing')->throwOnError()->send();
    }

    /**
     * throwOnError() does NOT throw for 2xx responses — it is a no-op when
     * the request succeeds.
     */
    public function testThrowOnErrorDoesNotThrowForSuccessResponse(): void
    {
        // Arrange
        Client::fake(['*' => ClientResponse::make(['id' => 1], 200)]);

        // Act + Assert: no exception
        $response = Client::get('https://api.example.com/users/1')->throwOnError()->send();
        $this->assertTrue($response->ok());
    }

    // =========================================================================
    // Retry logic
    // =========================================================================

    /**
     * retry(n) retries up to n times on 5xx responses.
     *
     * The fake callable counts invocations; after two 500s, the third call
     * returns 200. With retry(2) the client must succeed on the third attempt.
     */
    public function testRetrySucceedsAfterTransientServerErrors(): void
    {
        // Arrange: first two calls return 500, third returns 200
        $callCount = 0;
        Client::fake([
            '*' => function () use (&$callCount): ClientResponse {
                $callCount++;
                if ($callCount < 3) {
                    return ClientResponse::make(['error' => 'server error'], 500);
                }
                return ClientResponse::make(['id' => 1], 200);
            },
        ]);

        // Act: retry up to 2 times (3 total attempts)
        $response = Client::get('https://api.example.com/users')
            ->retry(2, 0) // 0ms delay to keep the test fast
            ->send();

        // Assert: eventually got 200, made exactly 3 calls
        $this->assertTrue($response->ok());
        $this->assertSame(3, $callCount);
    }

    /**
     * After exhausting all retries, the last 5xx response is returned
     * (not thrown) unless throwOnError() was also set.
     */
    public function testRetryExhaustedReturnsLastResponse(): void
    {
        // Arrange: always 503
        Client::fake(['*' => ClientResponse::make(['error' => 'unavailable'], 503)]);

        // Act
        $response = Client::get('https://api.example.com/flaky')
            ->retry(2, 0)
            ->send();

        // Assert: last response returned, not thrown
        $this->assertSame(503, $response->status());
        $this->assertTrue($response->serverError());
    }

    /**
     * retry() with a non-zero delay exercises the exponential backoff usleep path.
     * A single retry with 1ms delay is enough to cover the code path without
     * making the test perceptibly slow.
     */
    public function testRetryWithNonZeroDelayCoversUsleepPath(): void
    {
        // Arrange: first call 500, second call 200
        $callCount = 0;
        Client::fake([
            '*' => function () use (&$callCount): ClientResponse {
                $callCount++;
                return $callCount < 2
                    ? ClientResponse::make([], 500)
                    : ClientResponse::make(['ok' => true], 200);
            },
        ]);

        // Act: retry(1, 1) — 1ms initial delay, covers the usleep branch
        $response = Client::get('https://api.example.com/flaky')
            ->retry(1, 1)
            ->send();

        // Assert: succeeded on second attempt
        $this->assertTrue($response->ok());
        $this->assertSame(2, $callCount);
    }

    /**
     * When a callable fake throws ClientException (simulating a connection error),
     * the retry loop catches it and retries. If all retries are exhausted,
     * the last ClientException is re-thrown.
     */
    public function testRetryExhaustedOnConnectionErrorThrowsClientException(): void
    {
        // Arrange: fake always throws a connection error
        $callCount = 0;
        Client::fake([
            '*' => function () use (&$callCount): ClientResponse {
                $callCount++;
                throw new ClientException('connection refused', 7);
            },
        ]);

        // Act + Assert: exception propagates after all retries are used
        try {
            Client::get('https://api.example.com/unreachable')
                ->retry(2, 0)
                ->send();
            $this->fail('Expected ClientException to be thrown');
        } catch (ClientException $e) {
            $this->assertSame('connection refused', $e->getMessage());
            $this->assertSame(7, $e->getCurlErrno());
            // retry(2) means 3 total attempts (1 + 2 retries)
            $this->assertSame(3, $callCount);
        }
    }

    /**
     * throwOnError() + retry exhausted on 5xx: the ClientException from response->throw()
     * is caught by the retry loop's catch block, setting lastException, which is
     * then re-thrown after all attempts are used.
     */
    public function testThrowOnErrorWithExhaustedRetriesThrowsOnLastResponse(): void
    {
        // Arrange: always 503
        Client::fake(['*' => ClientResponse::make(['error' => 'unavailable'], 503)]);

        // Act + Assert
        $this->expectException(ClientException::class);
        Client::get('https://api.example.com/down')
            ->retry(1, 0)
            ->throwOnError()
            ->send();
    }

    // =========================================================================
    // Builder property coverage
    // =========================================================================

    /**
     * timeout(), connectTimeout(), withoutSslVerification(), and userAgent()
     * set the corresponding instance properties used by execute().
     * Verified via reflection since these only affect the curl call.
     */
    public function testBuilderPropertiesAreSetCorrectly(): void
    {
        // Arrange: build a request with every property set
        Client::fake(['*' => ClientResponse::make([], 200)]);

        $client = Client::get('https://example.com')
            ->timeout(45)
            ->connectTimeout(5)
            ->withoutSslVerification()
            ->userAgent('TestAgent/1.0');

        // Act: send to trigger fake (we care about the built state, not response)
        $client->send();

        // Assert via reflection
        $get = fn(string $prop) => (new \ReflectionProperty($client, $prop))->getValue($client);

        $this->assertSame(45, $get('timeout'));
        $this->assertSame(5, $get('connectTimeout'));
        $this->assertFalse($get('verifySsl'));
        $this->assertSame('TestAgent/1.0', $get('userAgent'));
    }

    // =========================================================================
    // resolveUrl() edge cases
    // =========================================================================

    /**
     * When baseUrl is set but the path is an absolute URL, the absolute URL
     * takes precedence — base URL is ignored.
     */
    public function testAbsoluteUrlOverridesBaseUrl(): void
    {
        // Arrange
        $resolvedUrl = null;
        Client::fake([
            '*' => function (Client $c) use (&$resolvedUrl): ClientResponse {
                $ref = new \ReflectionMethod($c, 'resolveUrl');
                $ref->setAccessible(true);
                $resolvedUrl = $ref->invoke($c);
                return ClientResponse::make([], 200);
            },
        ]);

        // Act: make() with an absolute URL overrides the base URL
        $client = new Client('https://api.example.com');
        $client->make('GET', 'https://other.example.com/path')->send();

        // Assert
        $this->assertSame('https://other.example.com/path', $resolvedUrl);
    }

    /**
     * When baseUrl is set and the path is empty, send() uses the base URL alone.
     */
    public function testEmptyPathWithBaseUrlUsesBaseUrlAlone(): void
    {
        // Arrange
        $resolvedUrl = null;
        Client::fake([
            '*' => function (Client $c) use (&$resolvedUrl): ClientResponse {
                $ref = new \ReflectionMethod($c, 'resolveUrl');
                $ref->setAccessible(true);
                $resolvedUrl = $ref->invoke($c);
                return ClientResponse::make([], 200);
            },
        ]);

        // Act: Client with baseUrl, no path set (empty url)
        $client = new Client('https://api.example.com');
        $client->make('GET', '')->send();

        // Assert
        $this->assertSame('https://api.example.com', $resolvedUrl);
    }

    /**
     * 4xx responses are NOT retried — client errors indicate a request problem
     * that will not resolve on its own (wrong URL, missing auth, etc.).
     */
    public function testRetryDoesNotRetryClientErrors(): void
    {
        // Arrange
        $callCount = 0;
        Client::fake([
            '*' => function () use (&$callCount): ClientResponse {
                $callCount++;
                return ClientResponse::make(['error' => 'forbidden'], 403);
            },
        ]);

        // Act
        $response = Client::get('https://api.example.com/admin')
            ->retry(3, 0)
            ->send();

        // Assert: called exactly once despite retry(3)
        $this->assertSame(1, $callCount);
        $this->assertSame(403, $response->status());
    }
}
