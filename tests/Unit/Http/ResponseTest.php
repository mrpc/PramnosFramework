<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\Response;

#[CoversClass(Response::class)]
class ResponseTest extends TestCase
{
    // =========================================================================
    // make() factory
    // =========================================================================

    /**
     * make() with no arguments produces a 200 response with an empty body.
     * Default status must be 200 — any other would be a surprise for callers
     * who just want a simple text response.
     */
    public function testMakeDefaultsTo200AndEmptyBody(): void
    {
        // Arrange / Act
        $response = Response::make();

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getBody());
    }

    /**
     * make() with explicit body and status stores both values.
     */
    public function testMakeStoresBodyAndStatus(): void
    {
        // Arrange / Act
        $response = Response::make('Hello World', 201);

        // Assert
        $this->assertSame('Hello World', $response->getBody());
        $this->assertSame(201, $response->getStatusCode());
    }

    /**
     * make() produces a Response instance, ensuring type-safety for callers
     * that type-hint on Response.
     */
    public function testMakeReturnsResponseInstance(): void
    {
        // Arrange / Act / Assert
        $this->assertInstanceOf(Response::class, Response::make());
    }

    // =========================================================================
    // json() factory
    // =========================================================================

    /**
     * json() encodes the payload and sets Content-Type: application/json.
     * The Content-Type header is mandatory so browsers and API clients
     * parse the body correctly.
     */
    public function testJsonEncodesPayloadAndSetsContentType(): void
    {
        // Arrange
        $data = ['status' => 'ok', 'count' => 3];

        // Act
        $response = Response::json($data);

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(json_encode($data), $response->getBody());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    /**
     * json() respects the explicit status code parameter (e.g. 201 Created
     * for resource-creation endpoints).
     */
    public function testJsonUsesExplicitStatusCode(): void
    {
        // Arrange / Act
        $response = Response::json(['id' => 99], 201);

        // Assert
        $this->assertSame(201, $response->getStatusCode());
    }

    /**
     * json() with JSON_PRETTY_PRINT flag produces indented output.
     * Useful for debug endpoints or export APIs.
     */
    public function testJsonRespectsEncodeFlags(): void
    {
        // Arrange / Act
        $response = Response::json(['x' => 1], 200, JSON_PRETTY_PRINT);

        // Assert — pretty-printed JSON always contains a newline
        $this->assertStringContainsString("\n", $response->getBody());
    }

    /**
     * When json_encode() fails (e.g. malformed UTF-8 with no fallback flag),
     * json() returns a 500 response with a safe error payload rather than
     * throwing or producing a broken body.
     */
    public function testJsonHandlesEncodingFailureGracefully(): void
    {
        // Arrange — INF cannot be encoded by default
        $data = INF;

        // Act
        $response = Response::json($data);

        // Assert
        $this->assertSame(500, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $decoded);
    }

    // =========================================================================
    // redirect() factory
    // =========================================================================

    /**
     * redirect() sets the Location header and defaults to 302.
     * 302 is the standard "Found" redirect for temporary redirects.
     */
    public function testRedirectSetsLocationAndDefaultsTo302(): void
    {
        // Arrange / Act
        $response = Response::redirect('/login');

        // Assert
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertSame('', $response->getBody());
    }

    /**
     * redirect() with explicit status 301 produces a permanent redirect.
     */
    public function testRedirectUsesExplicitStatusCode(): void
    {
        // Arrange / Act
        $response = Response::redirect('/new-url', 301);

        // Assert
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new-url', $response->getHeaderLine('Location'));
    }

    // =========================================================================
    // withStatus()
    // =========================================================================

    /**
     * withStatus() returns a NEW instance (clone) — the original is unchanged.
     * This is the immutable-style contract: mutators never alter the receiver.
     */
    public function testWithStatusReturnsNewInstanceAndDoesNotMutateOriginal(): void
    {
        // Arrange
        $original = Response::make('body', 200);

        // Act
        $modified = $original->withStatus(404);

        // Assert
        $this->assertNotSame($original, $modified);  // different object
        $this->assertSame(200, $original->getStatusCode());  // original unchanged
        $this->assertSame(404, $modified->getStatusCode());
    }

    /**
     * withStatus() preserves the existing body and headers on the clone.
     */
    public function testWithStatusPreservesBodyAndHeaders(): void
    {
        // Arrange
        $original = Response::make('hello')->withHeader('X-Foo', 'bar');

        // Act
        $modified = $original->withStatus(500);

        // Assert
        $this->assertSame('hello', $modified->getBody());
        $this->assertSame('bar', $modified->getHeaderLine('X-Foo'));
    }

    // =========================================================================
    // withHeader() / withRawHeader() / withoutHeader()
    // =========================================================================

    /**
     * withHeader() accumulates values for the same header name.
     * This is consistent with HTTP semantics where a header may appear
     * multiple times (e.g. Set-Cookie).
     */
    public function testWithHeaderAccumulatesMultipleValues(): void
    {
        // Arrange
        $response = Response::make()
            ->withHeader('Set-Cookie', 'a=1')
            ->withHeader('Set-Cookie', 'b=2');

        // Act
        $values = $response->getHeader('Set-Cookie');

        // Assert
        $this->assertSame(['a=1', 'b=2'], $values);
    }

    /**
     * withHeader() returns a new instance — the original is unchanged.
     */
    public function testWithHeaderDoesNotMutateOriginal(): void
    {
        // Arrange
        $original = Response::make();

        // Act
        $modified = $original->withHeader('X-Custom', 'value');

        // Assert
        $this->assertNotSame($original, $modified);
        $this->assertFalse($original->hasHeader('X-Custom'));
        $this->assertTrue($modified->hasHeader('X-Custom'));
    }

    /**
     * withRawHeader() replaces all existing values for a header with a single
     * new value. Use this when you need exactly one value (e.g. Content-Type).
     */
    public function testWithRawHeaderReplacesExistingValues(): void
    {
        // Arrange — start with two Set-Cookie values
        $response = Response::make()
            ->withHeader('Content-Type', 'text/html')
            ->withHeader('Content-Type', 'text/plain');

        // Act — override both with a single value
        $replaced = $response->withRawHeader('Content-Type', 'application/json');

        // Assert
        $this->assertSame(['application/json'], $replaced->getHeader('Content-Type'));
    }

    /**
     * withoutHeader() removes the named header entirely.
     */
    public function testWithoutHeaderRemovesHeader(): void
    {
        // Arrange
        $response = Response::make()->withHeader('X-Remove-Me', 'gone');

        // Act
        $stripped = $response->withoutHeader('X-Remove-Me');

        // Assert
        $this->assertFalse($stripped->hasHeader('X-Remove-Me'));
    }

    /**
     * withoutHeader() on a header that doesn't exist is a no-op (does not throw).
     */
    public function testWithoutHeaderOnMissingHeaderIsNoop(): void
    {
        // Arrange
        $response = Response::make();

        // Act / Assert — must not throw
        $result = $response->withoutHeader('X-Does-Not-Exist');
        $this->assertFalse($result->hasHeader('X-Does-Not-Exist'));
    }

    // =========================================================================
    // withBody()
    // =========================================================================

    /**
     * withBody() returns a new instance with the given body while preserving
     * status and headers from the original.
     */
    public function testWithBodyReturnsNewInstanceWithUpdatedBody(): void
    {
        // Arrange
        $original = Response::make('old body', 201)->withHeader('X-Api', 'v1');

        // Act
        $modified = $original->withBody('new body');

        // Assert
        $this->assertNotSame($original, $modified);
        $this->assertSame('old body', $original->getBody());   // original unchanged
        $this->assertSame('new body', $modified->getBody());
        $this->assertSame(201, $modified->getStatusCode());    // status preserved
        $this->assertSame('v1', $modified->getHeaderLine('X-Api'));  // headers preserved
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * getHeader() returns an empty array for a header that was never set,
     * making it safe to iterate without null-checks.
     */
    public function testGetHeaderReturnsEmptyArrayForMissingHeader(): void
    {
        // Arrange / Act
        $values = Response::make()->getHeader('X-Missing');

        // Assert
        $this->assertSame([], $values);
    }

    /**
     * getHeaderLine() returns null when the header is absent.
     */
    public function testGetHeaderLineReturnsNullForMissingHeader(): void
    {
        // Arrange / Act
        $line = Response::make()->getHeaderLine('X-Missing');

        // Assert
        $this->assertNull($line);
    }

    /**
     * getHeaderLine() joins multiple values with ", " — the standard HTTP
     * multi-value separator.
     */
    public function testGetHeaderLineJoinsMultipleValuesWithComma(): void
    {
        // Arrange
        $response = Response::make()
            ->withHeader('Accept', 'text/html')
            ->withHeader('Accept', 'application/json');

        // Act
        $line = $response->getHeaderLine('Accept');

        // Assert
        $this->assertSame('text/html, application/json', $line);
    }

    /**
     * hasHeader() returns false when no header by that name has been set,
     * and true when it has been set with at least one value.
     */
    public function testHasHeaderReturnsTrueOnlyWhenHeaderIsSet(): void
    {
        // Arrange
        $response = Response::make()->withHeader('X-Present', 'yes');

        // Assert
        $this->assertTrue($response->hasHeader('X-Present'));
        $this->assertFalse($response->hasHeader('X-Absent'));
    }

    /**
     * getHeaders() returns a flat name→value map with multiple values joined
     * by ", ". Useful for iterating all headers without dealing with arrays.
     */
    public function testGetHeadersReturnsFlatMap(): void
    {
        // Arrange
        $response = Response::make()
            ->withHeader('X-A', 'one')
            ->withHeader('X-A', 'two')
            ->withHeader('X-B', 'three');

        // Act
        $headers = $response->getHeaders();

        // Assert
        $this->assertSame('one, two', $headers['X-A']);
        $this->assertSame('three', $headers['X-B']);
    }

    // =========================================================================
    // Chaining
    // =========================================================================

    /**
     * All fluent mutators can be chained arbitrarily — the final object
     * holds the cumulative state. This proves the clone-chain doesn't drop
     * state across multiple calls.
     */
    public function testFluentChainingProducesCorrectFinalState(): void
    {
        // Arrange / Act
        $response = Response::make('initial')
            ->withStatus(201)
            ->withHeader('X-Trace', 'abc')
            ->withBody('final body')
            ->withHeader('X-Version', '1');

        // Assert
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('final body', $response->getBody());
        $this->assertTrue($response->hasHeader('X-Trace'));
        $this->assertTrue($response->hasHeader('X-Version'));
    }
}
