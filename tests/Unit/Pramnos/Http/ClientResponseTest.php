<?php

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\ClientResponse;
use Pramnos\Http\ClientException;

/**
 * Unit tests for ClientResponse — the immutable value object wrapping HTTP responses.
 *
 * Tests verify status classification helpers, body/JSON access, header lookup,
 * the throw() helper, and the make() factory.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ClientResponse::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ClientException::class)]
class ClientResponseTest extends TestCase
{
    // =========================================================================
    // make() factory
    // =========================================================================

    /**
     * make() with an array body JSON-encodes it and adds Content-Type: application/json.
     *
     * This is the canonical way to create fake responses in tests — mirroring
     * what a real JSON API would return.
     */
    public function testMakeWithArrayEncodesJsonAndSetsContentType(): void
    {
        // Act
        $response = ClientResponse::make(['id' => 42, 'name' => 'Alice'], 200);

        // Assert
        $this->assertSame(200, $response->status());
        $this->assertSame('application/json', $response->header('content-type'));
        $this->assertSame(42, $response->json('id'));
        $this->assertSame('Alice', $response->json('name'));
    }

    /**
     * make() with a string body stores it verbatim.
     */
    public function testMakeWithStringBodyStoresVerbatim(): void
    {
        // Act
        $response = ClientResponse::make('plain text', 200);

        // Assert
        $this->assertSame('plain text', $response->body());
    }

    // =========================================================================
    // Status classification
    // =========================================================================

    /**
     * ok() / successful() return true for 2xx; failed() is false for 2xx.
     */
    public function testStatusClassificationFor2xx(): void
    {
        foreach ([200, 201, 204, 299] as $code) {
            $r = new ClientResponse($code, '');
            $this->assertTrue($r->ok(),         "ok() failed for $code");
            $this->assertTrue($r->successful(),  "successful() failed for $code");
            $this->assertFalse($r->failed(),     "failed() returned true for $code");
            $this->assertFalse($r->clientError(),"clientError() returned true for $code");
            $this->assertFalse($r->serverError(),"serverError() returned true for $code");
            $this->assertFalse($r->redirect(),   "redirect() returned true for $code");
        }
    }

    /**
     * redirect() returns true for 3xx; ok() and failed() are false.
     */
    public function testStatusClassificationFor3xx(): void
    {
        foreach ([301, 302, 307] as $code) {
            $r = new ClientResponse($code, '');
            $this->assertFalse($r->ok(),      "ok() returned true for $code");
            $this->assertFalse($r->failed(),  "failed() returned true for $code");
            $this->assertTrue($r->redirect(), "redirect() failed for $code");
        }
    }

    /**
     * clientError() and failed() are true for 4xx; ok() and serverError() are false.
     */
    public function testStatusClassificationFor4xx(): void
    {
        foreach ([400, 401, 403, 404, 422, 429] as $code) {
            $r = new ClientResponse($code, '');
            $this->assertFalse($r->ok(),          "ok() returned true for $code");
            $this->assertTrue($r->failed(),        "failed() returned false for $code");
            $this->assertTrue($r->clientError(),   "clientError() returned false for $code");
            $this->assertFalse($r->serverError(),  "serverError() returned true for $code");
        }
    }

    /**
     * serverError() and failed() are true for 5xx; ok() and clientError() are false.
     */
    public function testStatusClassificationFor5xx(): void
    {
        foreach ([500, 502, 503, 504] as $code) {
            $r = new ClientResponse($code, '');
            $this->assertFalse($r->ok(),          "ok() returned true for $code");
            $this->assertTrue($r->failed(),        "failed() returned false for $code");
            $this->assertFalse($r->clientError(),  "clientError() returned true for $code");
            $this->assertTrue($r->serverError(),   "serverError() returned false for $code");
        }
    }

    // =========================================================================
    // JSON decoding
    // =========================================================================

    /**
     * json() with no argument returns the full decoded array.
     */
    public function testJsonReturnsFullDecodedArray(): void
    {
        // Arrange
        $body = json_encode(['user' => ['id' => 1, 'email' => 'a@b.com']]);
        $r    = new ClientResponse(200, $body);

        // Act + Assert
        $data = $r->json();
        $this->assertIsArray($data);
        $this->assertSame(1, $data['user']['id']);
    }

    /**
     * json('key') plucks a top-level key from the decoded body.
     */
    public function testJsonWithKeyPlucksTopLevelValue(): void
    {
        // Arrange
        $r = ClientResponse::make(['status' => 'ok', 'count' => 5], 200);

        // Assert
        $this->assertSame('ok', $r->json('status'));
        $this->assertSame(5, $r->json('count'));
    }

    /**
     * json('a.b.c') uses dot notation to reach nested values.
     *
     * This avoids boilerplate array access ($response->json()['user']['address']['city'])
     * in application code.
     */
    public function testJsonDotNotationReachesNestedValues(): void
    {
        // Arrange
        $r = ClientResponse::make([
            'user' => ['address' => ['city' => 'Athens']],
        ], 200);

        // Assert
        $this->assertSame('Athens', $r->json('user.address.city'));
    }

    /**
     * json('missing.key') returns null rather than throwing.
     *
     * Callers use null-checks to handle optional fields — not try/catch.
     */
    public function testJsonMissingKeyReturnsNull(): void
    {
        // Arrange
        $r = ClientResponse::make(['id' => 1], 200);

        // Assert
        $this->assertNull($r->json('id.nonexistent'));
        $this->assertNull($r->json('completely_missing'));
    }

    /**
     * json() returns null when the body is not valid JSON.
     */
    public function testJsonReturnsNullForInvalidBody(): void
    {
        // Arrange
        $r = new ClientResponse(200, 'not-json');

        // Assert
        $this->assertNull($r->json());
    }

    // =========================================================================
    // Headers
    // =========================================================================

    /**
     * header() lookup is case-insensitive — HTTP headers are defined as
     * case-insensitive by RFC 7230.
     */
    public function testHeaderLookupIsCaseInsensitive(): void
    {
        // Arrange
        $r = new ClientResponse(200, '', ['content-type' => 'application/json']);

        // Assert: both casings return the same value
        $this->assertSame('application/json', $r->header('Content-Type'));
        $this->assertSame('application/json', $r->header('content-type'));
        $this->assertSame('application/json', $r->header('CONTENT-TYPE'));
    }

    /**
     * header() returns an empty string for absent headers — not null or false —
     * so callers can use string operations without null guards.
     */
    public function testMissingHeaderReturnsEmptyString(): void
    {
        // Arrange
        $r = new ClientResponse(200, '');

        // Assert
        $this->assertSame('', $r->header('X-Missing-Header'));
    }

    /**
     * headers() returns the full headers array with all registered entries.
     */
    public function testHeadersReturnsAllHeaders(): void
    {
        // Arrange
        $headers = ['content-type' => 'application/json', 'x-request-id' => 'abc123'];
        $r       = new ClientResponse(200, '', $headers);

        // Assert
        $this->assertSame($headers, $r->headers());
    }

    // =========================================================================
    // throw() helper
    // =========================================================================

    /**
     * throw() returns $this for 2xx responses so it can be chained:
     * $response->throw()->json().
     */
    public function testThrowReturnsSelfForSuccessfulResponse(): void
    {
        // Arrange
        $r = ClientResponse::make(['ok' => true], 200);

        // Act + Assert: no exception, returns same object
        $this->assertSame($r, $r->throw());
    }

    /**
     * throw() throws ClientException for 4xx responses.
     *
     * The exception message includes the status code so it can be logged
     * without needing to catch and inspect the response separately.
     */
    public function testThrowThrowsClientExceptionFor4xx(): void
    {
        // Arrange
        $r = ClientResponse::make(['error' => 'not found'], 404);

        // Act + Assert
        $this->expectException(ClientException::class);
        $this->expectExceptionMessageMatches('/404/');
        $r->throw();
    }

    /**
     * throw() throws ClientException for 5xx responses.
     */
    public function testThrowThrowsClientExceptionFor5xx(): void
    {
        // Arrange
        $r = ClientResponse::make(['error' => 'internal server error'], 500);

        // Act + Assert
        $this->expectException(ClientException::class);
        $r->throw();
    }

    // =========================================================================
    // ClientException
    // =========================================================================

    /**
     * ClientException stores the curl errno and exposes it via getCurlErrno().
     *
     * This allows error handling code to distinguish between e.g. CURLE_COULDNT_CONNECT
     * (errno 7) and CURLE_OPERATION_TIMEOUTED (errno 28) without string parsing.
     */
    public function testClientExceptionStoresCurlErrno(): void
    {
        // Arrange
        $e = new ClientException('connection refused', 7);

        // Assert
        $this->assertSame(7, $e->getCurlErrno());
        $this->assertSame('connection refused', $e->getMessage());
    }

    /**
     * getCurlErrno() returns 0 when no curl errno is provided —
     * the default for exceptions thrown from non-curl paths (e.g. throw()).
     */
    public function testClientExceptionDefaultCurlErnoIsZero(): void
    {
        // Arrange
        $e = new ClientException('bad response');

        // Assert
        $this->assertSame(0, $e->getCurlErrno());
    }
}
