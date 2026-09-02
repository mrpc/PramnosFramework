<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\StreamedResponse;

/**
 * Reading a streamed response's headers back, which is how middleware inspects one.
 *
 * A streamed response cannot be re-read once it has been sent, so these accessors are the only way
 * anything downstream can see what it will send. `getHeaders()` had no covered line.
 *
 * The shape is the substance: headers are stored as a list of values per name, because a name may
 * legitimately appear more than once, and `getHeaders()` flattens each list into the single line
 * HTTP would put on the wire.
 *
 * **The two setters are not the PSR-7 ones, and read as their opposites.** `withHeader()` *appends*
 * a value, and `withRawHeader()` replaces whatever was there. In PSR-7 it is `withHeader()` that
 * replaces and `withAddedHeader()` that appends — so somebody reaching for the familiar name to
 * overwrite a header gets two values joined with a comma instead. Pinned below, because the
 * difference is invisible until a response goes out with `Content-Type: text/html, application/json`.
 */
#[CoversClass(StreamedResponse::class)]
class StreamedResponseHeadersTest extends TestCase
{
    private function response(): StreamedResponse
    {
        // `create()`, not `new`: the constructor is private, because an SSE response and a plain
        // streamed one differ in the headers they start with and the named constructors are what
        // say which you asked for.
        return StreamedResponse::create(static function (): void {
            echo 'body';
        });
    }

    /**
     * Each header comes back as one line, keyed by name.
     */
    public function testEachHeaderComesBackAsOneLine(): void
    {
        // Arrange
        $response = $this->response()
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache');

        // Act
        $headers = $response->getHeaders();

        // Assert
        $this->assertSame('text/event-stream', $headers['Content-Type'] ?? null);
        $this->assertSame('no-cache', $headers['Cache-Control'] ?? null);
    }

    /**
     * A header with several values is joined with `, `.
     *
     * Which is what HTTP does for a list-valued header, and why the values are kept as a list
     * until this point rather than concatenated as they arrive: a caller adding a second value
     * must not have to know whether it is the first.
     *
     * Note which setter does this. `withHeader()` is the appending one here — see the note on the
     * class.
     */
    public function testAMultiValuedHeaderIsJoined(): void
    {
        // Arrange
        $response = $this->response()
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Cache-Control', 'no-store');

        // Act
        $headers = $response->getHeaders();

        // Assert
        $this->assertSame('no-cache, no-store', $headers['Cache-Control'] ?? null);
        $this->assertSame(
            ['no-cache', 'no-store'],
            $response->getHeader('Cache-Control'),
            'the values should still be a list underneath'
        );
    }

    /**
     * A response with no headers gives an empty array, not `null`.
     *
     * Middleware iterates this. `foreach (null)` is a warning printed into the middle of a stream,
     * where there is no page left to put an error on.
     */
    public function testAResponseWithNoHeadersGivesAnEmptyArray(): void
    {
        // Act
        $headers = $this->response()->getHeaders();

        // Assert
        $this->assertSame([], $headers);
    }

    /**
     * `getHeaders()` agrees with `getHeaderLine()`, name by name.
     *
     * Two ways of asking the same question, and middleware uses both. A disagreement would make
     * the answer depend on which accessor a piece of code happened to call.
     */
    public function testTheAccessorsAgree(): void
    {
        // Arrange
        $response = $this->response()
            ->withHeader('X-Accel-Buffering', 'no')
            ->withHeader('Vary', 'Accept')
            ->withHeader('Vary', 'Accept-Encoding');

        // Act
        $headers = $response->getHeaders();

        // Assert
        foreach ($headers as $name => $line) {
            $this->assertSame($line, $response->getHeaderLine($name), $name . ' disagrees');
        }
    }
    /**
     * `withRawHeader()` replaces; `withHeader()` appends.
     *
     * The pair, asserted together, because each is only surprising next to the other. Reaching for
     * `withHeader()` to correct a `Content-Type` leaves both values on the response and the client
     * sees `text/html, application/json` — a header no browser will use and no log will explain.
     */
    public function testRawReplacesWhereTheOtherAppends(): void
    {
        // Arrange
        $appended = $this->response()
            ->withHeader('Content-Type', 'text/html')
            ->withHeader('Content-Type', 'application/json');

        $replaced = $this->response()
            ->withHeader('Content-Type', 'text/html')
            ->withRawHeader('Content-Type', 'application/json');

        // Assert
        $this->assertSame(
            'text/html, application/json',
            $appended->getHeaders()['Content-Type'],
            'withHeader() should append, which is the trap'
        );
        $this->assertSame(
            'application/json',
            $replaced->getHeaders()['Content-Type'],
            'withRawHeader() should replace'
        );
    }

    /**
     * Every mutator returns a clone, so a response can be shared.
     *
     * The `with*` shape promises immutability, and middleware relies on it: a pipeline that
     * inspects a response and passes the original along must not find its own inspection has
     * changed it. A mutator that returned `$this` would make every earlier stage's reference point
     * at the final state.
     */
    public function testEveryMutatorReturnsACloneAndLeavesTheOriginalAlone(): void
    {
        // Arrange
        $original = $this->response()->withHeader('X-One', 'a');

        // Act
        $withStatus  = $original->withStatus(503);
        $withHeader  = $original->withHeader('X-Two', 'b');
        $withoutOne  = $original->withoutHeader('X-One');

        // Assert — three new objects
        $this->assertNotSame($original, $withStatus);
        $this->assertNotSame($original, $withHeader);
        $this->assertNotSame($original, $withoutOne);

        // ...and the original is untouched by any of them
        $this->assertSame(200, $original->getStatusCode());
        $this->assertSame(['X-One' => 'a'], $original->getHeaders());
    }

    /**
     * `withStatus()` carries the code through.
     *
     * A streamed response sends its status before the first byte of the body, so this is the last
     * moment it can be set — there is no changing it once the producer has run.
     */
    public function testWithStatusCarriesTheCodeThrough(): void
    {
        // Act
        $response = $this->response()->withStatus(503);

        // Assert
        $this->assertSame(503, $response->getStatusCode());
    }

    /**
     * `withoutHeader()` removes every value of a name, and is silent about one that is absent.
     *
     * Removing an unset header is the ordinary case for middleware that strips a header it may or
     * may not have been given — an error there would make the caller check first, every time.
     */
    public function testWithoutHeaderRemovesEveryValueAndIgnoresAnAbsentName(): void
    {
        // Arrange
        $response = $this->response()
            ->withHeader('Vary', 'Accept')
            ->withHeader('Vary', 'Accept-Encoding')
            ->withHeader('X-Keep', 'yes');

        // Act
        $stripped = $response->withoutHeader('Vary')->withoutHeader('X-Never-Set');

        // Assert
        $this->assertFalse($stripped->hasHeader('Vary'), 'a multi-valued header was only half removed');
        $this->assertSame([], $stripped->getHeader('Vary'));
        $this->assertSame('yes', $stripped->getHeaders()['X-Keep'] ?? null, 'an unrelated header went with it');
    }

}
