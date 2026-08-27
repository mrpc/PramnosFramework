<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;

/**
 * Covers the one place the raw request body is read.
 *
 * `php://input` is a stream. Reading it twice is not guaranteed to give the body
 * twice — for `multipart/form-data` it never does, and with
 * `enable_post_data_reading` off it does not either. Nine places in the
 * framework used to read it directly, so whichever of them ran second saw an
 * empty body and reported the payload as missing: a capabilities manifest
 * refused as "malformed or missing JSON", a token request refused as
 * `invalid_request`, both with every field present in the request.
 *
 * The second half is testability. `setRawInput()` is the framework's documented
 * way to supply a body, and a handler calling `file_get_contents('php://input')`
 * for itself cannot see it — so the body-reading branch of those handlers could
 * not be exercised by a test at all, which is why the bug above survived.
 */
class RequestRawBodyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Request::setRawInput(null);
    }

    protected function tearDown(): void
    {
        Request::resetInstance();
        parent::tearDown();
    }

    /**
     * A body supplied by a test is what the reader returns.
     *
     * This is the seam every handler now goes through, so it is what makes their
     * body-reading paths reachable from a test.
     */
    public function testTheSuppliedBodyIsWhatIsRead(): void
    {
        // Arrange
        Request::setRawInput('{"resources":[]}');

        // Act
        $body = Request::rawBody();

        // Assert
        $this->assertSame('{"resources":[]}', $body);
    }

    /**
     * The body reads the same the second time.
     *
     * The whole point: two handlers in one request both get the payload, in
     * whatever order they ask for it.
     */
    public function testTheBodyCanBeReadMoreThanOnce(): void
    {
        // Arrange
        Request::setRawInput('one=1&two=2');

        // Act
        $first = Request::rawBody();
        $second = Request::rawBody();

        // Assert
        $this->assertSame($first, $second);
        $this->assertSame('one=1&two=2', $second);
    }

    /**
     * With no body, the reader returns an empty string rather than false.
     *
     * `file_get_contents()` returns `false` on failure, and a handler comparing
     * `$raw === ''` to decide "no payload" treats `false` as a payload — then
     * hands it to `json_decode()`, which is where it becomes a 500 instead of a
     * 400.
     *
     * Under CLI there is no request body, so this is the real state, not a
     * simulated one.
     */
    public function testAnAbsentBodyIsAnEmptyString(): void
    {
        // Act
        $body = Request::rawBody();

        // Assert
        $this->assertSame('', $body, 'an absent body must be readable as a string');
    }

    /**
     * Resetting the request clears the remembered body.
     *
     * Caching is per request. A test — or a long-running worker handling several
     * requests in one process — must not have the previous request's body
     * answer for this one.
     */
    public function testResettingTheRequestForgetsTheBody(): void
    {
        // Arrange
        Request::setRawInput('stale=1');
        $this->assertSame('stale=1', Request::rawBody());

        // Act
        Request::resetInstance();

        // Assert
        $this->assertSame('', Request::rawBody(), 'the previous body must not survive a reset');
    }

    /**
     * The body reaches the request's own parsing, not only direct callers.
     *
     * `getBody()` decodes the raw body into an array; it is the path a
     * controller reading a PUT or DELETE payload goes through. If it stopped
     * sharing the read with `rawBody()`, the two would disagree about what
     * arrived — which is the bug this change exists to remove, reintroduced one
     * layer up.
     */
    public function testTheParsedBodyComesFromTheSameRead(): void
    {
        // Arrange — a PUT, because that is a method PHP leaves $_POST empty for
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        Request::setRawInput('{"client_id":"demo","scopes":["read"]}');

        // Act
        try {
            $parsed = Request::getInstance()->body();
        } finally {
            if ($method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $method;
            }
        }

        // Assert
        $this->assertSame('demo', $parsed['client_id'] ?? null);
        $this->assertSame(['read'], $parsed['scopes'] ?? null, 'nested values must decode as arrays');
    }
}
