<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;

/**
 * Unit tests for Pramnos\Http\Response.
 *
 * Response is an immutable-style HTTP response builder.  Static factories
 * create instances; fluent mutators return cloned instances.  The send()
 * method is annotated @codeCoverageIgnore so it is excluded from coverage
 * targets — all logic is reachable through the accessor methods.
 *
 * Tests verify:
 *   - make() creates a plain response with body and status code.
 *   - json() serializes data and sets Content-Type; falls back on error.
 *   - redirect() sets Location header and default 302 status.
 *   - withStatus() returns a new instance with the updated code.
 *   - withHeader() appends values (does not replace); returns new instance.
 *   - withRawHeader() replaces all values; returns new instance.
 *   - withoutHeader() removes the header; returns new instance.
 *   - withBody() replaces the body; returns new instance.
 *   - Immutability: mutators do NOT modify the original instance.
 *   - getStatusCode() / getBody() / getHeader() / getHeaderLine() /
 *     hasHeader() / getHeaders() return accurate values.
 */
#[CoversClass(Response::class)]
class ResponseTest extends TestCase
{
    // =========================================================================
    // make()
    // =========================================================================

    /**
     * make() with defaults creates a 200 response with the given body.
     */
    public function testMakeCreatesResponseWithBodyAndDefaultStatus(): void
    {
        // Arrange / Act
        $r = Response::make('Hello');

        // Assert
        $this->assertSame('Hello', $r->getBody());
        $this->assertSame(200, $r->getStatusCode());
    }

    /**
     * make() with an explicit status code stores it correctly.
     */
    public function testMakeWithExplicitStatusCode(): void
    {
        // Arrange / Act
        $r = Response::make('Not Found', 404);

        // Assert
        $this->assertSame(404, $r->getStatusCode());
    }

    /**
     * make() with no arguments creates an empty 200 response.
     */
    public function testMakeWithNoArgumentsCreatesEmpty200(): void
    {
        // Arrange / Act
        $r = Response::make();

        // Assert
        $this->assertSame('', $r->getBody());
        $this->assertSame(200, $r->getStatusCode());
    }

    // =========================================================================
    // json()
    // =========================================================================

    /**
     * json() serializes the data and sets Content-Type: application/json.
     */
    public function testJsonSerializesDataAndSetsContentType(): void
    {
        // Arrange / Act
        $r = Response::json(['key' => 'value']);

        // Assert
        $this->assertSame('{"key":"value"}', $r->getBody());
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame(['application/json'], $r->getHeader('Content-Type'));
    }

    /**
     * json() accepts a custom status code.
     */
    public function testJsonWithCustomStatus(): void
    {
        // Arrange / Act
        $r = Response::json(['created' => true], 201);

        // Assert
        $this->assertSame(201, $r->getStatusCode());
    }

    /**
     * json() with JSON_PRETTY_PRINT flag formats the output.
     */
    public function testJsonWithPrettyPrintFlag(): void
    {
        // Arrange / Act
        $r = Response::json(['a' => 1], 200, JSON_PRETTY_PRINT);

        // Assert — pretty-printed output contains newlines
        $this->assertStringContainsString("\n", $r->getBody());
    }

    // =========================================================================
    // redirect()
    // =========================================================================

    /**
     * redirect() sets Location header and defaults to 302.
     */
    public function testRedirectSetsLocationHeaderAndDefaultStatus(): void
    {
        // Arrange / Act
        $r = Response::redirect('/login');

        // Assert
        $this->assertSame(302, $r->getStatusCode());
        $this->assertSame(['/login'], $r->getHeader('Location'));
        $this->assertSame('', $r->getBody());
    }

    /**
     * redirect() with explicit status stores it (e.g. 301 Moved Permanently).
     */
    public function testRedirectWithExplicitStatus(): void
    {
        // Arrange / Act
        $r = Response::redirect('/new-url', 301);

        // Assert
        $this->assertSame(301, $r->getStatusCode());
    }

    // =========================================================================
    // withStatus()
    // =========================================================================

    /**
     * withStatus() returns a new instance with the updated status code.
     * The original is unchanged (immutability).
     */
    public function testWithStatusReturnsNewInstanceWithUpdatedCode(): void
    {
        // Arrange
        $original = Response::make('body', 200);

        // Act
        $updated = $original->withStatus(404);

        // Assert — new instance has new code
        $this->assertSame(404, $updated->getStatusCode());

        // Assert — original unchanged
        $this->assertSame(200, $original->getStatusCode());

        // Assert — different instances
        $this->assertNotSame($original, $updated);
    }

    // =========================================================================
    // withHeader() / withRawHeader() / withoutHeader()
    // =========================================================================

    /**
     * withHeader() appends a value to the named header and returns a new instance.
     */
    public function testWithHeaderAppendsValueAndReturnsNewInstance(): void
    {
        // Arrange
        $r = Response::make();

        // Act
        $r1 = $r->withHeader('X-Custom', 'first');
        $r2 = $r1->withHeader('X-Custom', 'second');

        // Assert — r2 has both values
        $this->assertSame(['first', 'second'], $r2->getHeader('X-Custom'));

        // Assert — r1 unchanged (immutable)
        $this->assertSame(['first'], $r1->getHeader('X-Custom'));
    }

    /**
     * withRawHeader() replaces all existing values for a header.
     */
    public function testWithRawHeaderReplacesAllValues(): void
    {
        // Arrange — start with a header that has two values
        $r = Response::make()
            ->withHeader('X-Tag', 'old1')
            ->withHeader('X-Tag', 'old2');

        // Act — replace with a single new value
        $replaced = $r->withRawHeader('X-Tag', 'new');

        // Assert — only the new value remains
        $this->assertSame(['new'], $replaced->getHeader('X-Tag'));

        // Assert — original still has both
        $this->assertSame(['old1', 'old2'], $r->getHeader('X-Tag'));
    }

    /**
     * withoutHeader() removes the named header from the new instance.
     */
    public function testWithoutHeaderRemovesNamedHeader(): void
    {
        // Arrange
        $r = Response::make()->withHeader('X-Debug', 'true');

        // Act
        $stripped = $r->withoutHeader('X-Debug');

        // Assert — header removed from clone
        $this->assertFalse($stripped->hasHeader('X-Debug'));

        // Assert — original still has it
        $this->assertTrue($r->hasHeader('X-Debug'));
    }

    // =========================================================================
    // withBody()
    // =========================================================================

    /**
     * withBody() returns a new instance with the given body; original unchanged.
     */
    public function testWithBodyReturnsNewInstanceWithNewBody(): void
    {
        // Arrange
        $r = Response::make('original');

        // Act
        $updated = $r->withBody('replaced');

        // Assert
        $this->assertSame('replaced', $updated->getBody());
        $this->assertSame('original', $r->getBody());
        $this->assertNotSame($r, $updated);
    }

    // =========================================================================
    // Header accessors
    // =========================================================================

    /**
     * getHeader() returns an empty array when the header is not set.
     */
    public function testGetHeaderReturnsEmptyArrayWhenNotSet(): void
    {
        // Arrange
        $r = Response::make();

        // Assert
        $this->assertSame([], $r->getHeader('X-Missing'));
    }

    /**
     * getHeaderLine() returns a comma-joined string for multi-value headers.
     */
    public function testGetHeaderLineJoinsMultipleValues(): void
    {
        // Arrange
        $r = Response::make()
            ->withHeader('Accept', 'text/html')
            ->withHeader('Accept', 'application/json');

        // Act
        $line = $r->getHeaderLine('Accept');

        // Assert
        $this->assertSame('text/html, application/json', $line);
    }

    /**
     * getHeaderLine() returns null when the header is absent.
     */
    public function testGetHeaderLineReturnsNullWhenHeaderAbsent(): void
    {
        // Arrange
        $r = Response::make();

        // Assert
        $this->assertNull($r->getHeaderLine('X-Missing'));
    }

    /**
     * hasHeader() returns true only when the named header is present.
     */
    public function testHasHeaderReturnsCorrectBoolean(): void
    {
        // Arrange
        $r = Response::make()->withHeader('X-Auth', 'Bearer token');

        // Assert — present
        $this->assertTrue($r->hasHeader('X-Auth'));

        // Assert — absent
        $this->assertFalse($r->hasHeader('X-Other'));
    }

    /**
     * getHeaders() returns a name→first-value map for all headers.
     */
    public function testGetHeadersReturnsAllHeadersAsMap(): void
    {
        // Arrange
        $r = Response::make()
            ->withRawHeader('Content-Type', 'text/html')
            ->withRawHeader('X-Frame-Options', 'DENY');

        // Act
        $headers = $r->getHeaders();

        // Assert
        $this->assertSame('text/html', $headers['Content-Type']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
    }
}
