<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Client;
use Pramnos\Http\ClientException;
use Pramnos\Http\ClientResponse;

/**
 * Integration tests for Client::headersOnly() and Client::maxResponseBytes()
 * against a real HTTP server.
 *
 * Why a real server. The behaviour under test is that the client *stops
 * reading* — it cannot be asserted against a fake, and it cannot be asserted
 * against a well-behaved endpoint either, because the interesting case is a
 * response that never ends. Each test forks a socket server that serves exactly
 * the pathology it needs and then keeps sending until the client hangs up.
 *
 * What was wrong before. Client read a response to completion or to its
 * timeout, and offered no way to say "the headers are all I need" or "stop
 * after N bytes". Against an endpoint that never stops sending — an internet
 * radio stream, an SSE feed, a `tail -f` over HTTP — those two are the same
 * thing, and neither is what the caller wanted:
 *
 *   - A live endpoint was reported unreachable. The server answered 200 with
 *     its content type in milliseconds, and all of it was thrown away, because
 *     the only way out of send() was a complete body or an exception.
 *   - A fast endpoint exhausted memory before the timeout ever fired. Three
 *     seconds of a fast stream measured a quarter of a gigabyte, which is not
 *     a recoverable failure: it takes the worker down.
 *
 * A consuming application dropped to raw cURL for both, and this file is the
 * evidence that it no longer has to.
 */
#[CoversClass(Client::class)]
#[CoversClass(ClientResponse::class)]
#[\PHPUnit\Framework\Attributes\Group('integration')]
class ClientBodyCeilingTest extends TestCase
{
    /** @var int[] PIDs of servers forked by the current test. */
    private array $children = [];

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('These tests fork a server; ext-pcntl is required.');
        }
        Client::resetFakes();
    }

    protected function tearDown(): void
    {
        // A server that outlived its test would hold a port and, worse, a
        // never-ending write loop.
        foreach ($this->children as $pid) {
            @posix_kill($pid, SIGKILL);
            @pcntl_waitpid($pid, $status);
        }
        $this->children = [];
    }

    /**
     * Fork a one-shot HTTP server and return the URL that reaches it.
     *
     * The child accepts a single connection, reads the request headers, hands
     * the socket to $respond, and is killed outright rather than returning —
     * a forked PHPUnit child that unwinds normally would run the framework's
     * shutdown handlers and report its own results.
     *
     * @param callable(resource, string): void $respond Receives the connection
     *                                                  and the request text.
     * @return string Base URL of the server, with no trailing slash.
     */
    private function serve(callable $respond): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse(
            $server, "test needs a listening socket: {$errstr} ({$errno})"
        );
        $port = (int) explode(
            ':', (string) stream_socket_get_name($server, false)
        )[1];

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            $conn = @stream_socket_accept($server, 10);
            if (is_resource($conn)) {
                $request = '';
                while (!str_contains($request, "\r\n\r\n")) {
                    $line = fgets($conn, 8192);
                    if ($line === false) {
                        break;
                    }
                    $request .= $line;
                }
                $respond($conn, $request);
                @fclose($conn);
            }
            @fclose($server);
            posix_kill(posix_getpid(), SIGKILL);
        }

        // The parent does not accept; the child owns the listening socket.
        fclose($server);
        $this->children[] = $pid;

        return 'http://127.0.0.1:' . $port;
    }

    /**
     * A response whose body never ends — the shape of an Icecast mount, an SSE
     * feed, or anything else that streams.
     *
     * @param string $contentType Content-Type to advertise.
     * @return callable(resource, string): void
     */
    private function endlessBody(string $contentType = 'audio/mpeg'): callable
    {
        return static function ($conn, string $request) use ($contentType): void {
            fwrite($conn,
                "HTTP/1.1 200 OK\r\n"
                . "Content-Type: {$contentType}\r\n"
                . "icy-metaint: 8192\r\n"
                . "Connection: close\r\n"
                . "\r\n"
            );
            $chunk = str_repeat('A', 8192);
            // Bounded only so a bug in the test cannot spin for ever; the client
            // is expected to hang up long before this runs out.
            for ($i = 0; $i < 20000; $i++) {
                if (@fwrite($conn, $chunk) === false) {
                    return;
                }
            }
        };
    }

    // ── headersOnly ─────────────────────────────────────────────────────────

    /**
     * The first reported failure: an endpoint that answers 200 immediately and
     * then streams for ever must be readable.
     *
     * Before, this call ended in a ClientException — "Operation timed out after
     * 3003 milliseconds with 102200 bytes received" — and the status and
     * content type that had arrived in the first few milliseconds were thrown
     * away with it.
     */
    public function testHeadersOnlyReturnsTheHeadersOfAnEndlessResponse(): void
    {
        // Arrange
        $url = $this->serve($this->endlessBody());

        // Act
        $response = Client::get($url)
            ->connectTimeout(2)->timeout(10)
            ->headersOnly()
            ->send();

        // Assert — everything the caller came for, and none of the stream.
        $this->assertSame(200, $response->status());
        $this->assertSame('audio/mpeg', $response->header('content-type'));
        $this->assertSame('8192', $response->header('icy-metaint'));
        $this->assertSame('', $response->body());
        $this->assertTrue($response->truncated(),
            'a body that was cut short must say so');
    }

    /**
     * headersOnly() must not be slower than the headers. The old failure took
     * the full timeout to produce nothing; this must return well inside it.
     */
    public function testHeadersOnlyReturnsWithoutWaitingForTheTimeout(): void
    {
        // Arrange
        $url = $this->serve($this->endlessBody());

        // Act
        $started = microtime(true);
        Client::get($url)->connectTimeout(2)->timeout(10)->headersOnly()->send();
        $elapsed = microtime(true) - $started;

        // Assert — generous, because CI is not a stopwatch; the point is that
        // it is nothing like the 10-second timeout it used to burn.
        $this->assertLessThan(5.0, $elapsed,
            'headersOnly() must stop at the headers, not run to the timeout');
    }

    /**
     * A response with no body at all is not truncated: nothing was cut short.
     *
     * This is what makes truncated() worth reading — it answers "is something
     * missing", not "did you set a limit".
     */
    public function testHeadersOnlyOnAnEmptyBodyIsNotTruncated(): void
    {
        // Arrange — 204, which by definition carries no body.
        $url = $this->serve(static function ($conn): void {
            fwrite($conn, "HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n");
        });

        // Act
        $response = Client::get($url)->timeout(5)->headersOnly()->send();

        // Assert
        $this->assertSame(204, $response->status());
        $this->assertSame('', $response->body());
        $this->assertFalse($response->truncated(),
            'nothing was cut short, so nothing should claim it was');
    }

    /**
     * Redirects are still followed, and "final" means the response that ends
     * the chain — not the first one that arrives.
     *
     * The write callback swallows a redirect's own body rather than aborting on
     * its first byte, which is what lets curl follow the Location at all.
     */
    public function testHeadersOnlyFollowsRedirectsAndReportsTheFinalResponse(): void
    {
        // Arrange — a 302 with a body of its own, then the real endpoint.
        $second = $this->serve($this->endlessBody('audio/aacp'));
        $first  = $this->serve(static function ($conn) use ($second): void {
            $filler = str_repeat('x', 512);
            fwrite($conn,
                "HTTP/1.1 302 Found\r\n"
                . "Location: {$second}/live\r\n"
                . "Content-Type: text/html\r\n"
                . 'Content-Length: ' . strlen($filler) . "\r\n"
                . "Connection: close\r\n"
                . "\r\n" . $filler
            );
        });

        // Act
        $response = Client::get($first)->timeout(10)->headersOnly()->send();

        // Assert — the destination's headers, not the redirect's.
        $this->assertSame(200, $response->status());
        $this->assertSame('audio/aacp', $response->header('content-type'));
        $this->assertSame('', $response->header('location'),
            'the redirect\'s own headers must not survive into the final response');
    }

    // ── maxResponseBytes ────────────────────────────────────────────────────

    /**
     * The second caller's need, which is not the same as the first: the headers
     * *and* a bounded prefix of the body.
     *
     * Reading ICY metadata means sending `Icy-MetaData: 1`, then reading
     * `icy-metaint` bytes of audio and the metadata block after it — around
     * 16 kB. There is no way to express that with headersOnly(), and no way to
     * express it at all without a ceiling.
     */
    public function testMaxResponseBytesReadsAPrefixOfAnEndlessBody(): void
    {
        // Arrange
        $url = $this->serve($this->endlessBody());

        // Act
        $response = Client::get($url)
            ->header('Icy-MetaData', '1')
            ->timeout(10)
            ->maxResponseBytes(16 * 1024)
            ->send();

        // Assert — exactly the ceiling, and an honest flag.
        $this->assertSame(200, $response->status());
        $this->assertSame(16 * 1024, strlen($response->body()));
        $this->assertTrue($response->truncated());
    }

    /**
     * Reaching the ceiling is a normal outcome. It must not throw, and the
     * status and headers must be intact — that is the whole difference between
     * a value the caller can act on and a process that died.
     */
    public function testReachingTheCeilingIsNotAnError(): void
    {
        // Arrange
        $url = $this->serve($this->endlessBody('text/event-stream'));

        // Act — no expectException; reaching the ceiling is the happy path.
        $response = Client::get($url)->timeout(10)->maxResponseBytes(64)->send();

        // Assert
        $this->assertSame(200, $response->status());
        $this->assertSame('text/event-stream', $response->header('content-type'));
        $this->assertSame(64, strlen($response->body()));
        $this->assertTrue($response->truncated());
    }

    /**
     * A body that fits under the ceiling arrives whole and is not marked
     * truncated — the ceiling is a limit, not a target.
     */
    public function testABodyUnderTheCeilingArrivesWholeAndUntruncated(): void
    {
        // Arrange
        $payload = json_encode(['station' => 'Aroma', 'listeners' => 41]);
        $url = $this->serve(static function ($conn) use ($payload): void {
            fwrite($conn,
                "HTTP/1.1 200 OK\r\n"
                . "Content-Type: application/json\r\n"
                . 'Content-Length: ' . strlen($payload) . "\r\n"
                . "Connection: close\r\n"
                . "\r\n" . $payload
            );
        });

        // Act
        $response = Client::get($url)->timeout(5)->maxResponseBytes(8192)->send();

        // Assert — and json() still works, which is the point of not truncating.
        $this->assertFalse($response->truncated());
        $this->assertSame($payload, $response->body());
        $this->assertSame('Aroma', $response->json('station'));
    }

    /**
     * A body exactly the size of the ceiling is not truncated: every byte the
     * server sent was kept.
     *
     * The boundary is worth pinning because an off-by-one here would report a
     * complete response as incomplete, and a caller checking truncated() before
     * parsing would discard a perfectly good body.
     */
    public function testABodyExactlyAtTheCeilingIsNotTruncated(): void
    {
        // Arrange
        $payload = str_repeat('z', 100);
        $url = $this->serve(static function ($conn) use ($payload): void {
            fwrite($conn,
                "HTTP/1.1 200 OK\r\n"
                . 'Content-Length: ' . strlen($payload) . "\r\n"
                . "Connection: close\r\n"
                . "\r\n" . $payload
            );
        });

        // Act
        $response = Client::get($url)->timeout(5)->maxResponseBytes(100)->send();

        // Assert
        $this->assertSame(100, strlen($response->body()));
        $this->assertFalse($response->truncated(),
            'the server sent 100 bytes and we kept 100 bytes — nothing is missing');
    }

    /**
     * A ceiling of zero reads no body at all, and a negative one is clamped to
     * zero rather than becoming an enormous positive remaining-byte count.
     */
    public function testAZeroOrNegativeCeilingReadsNothing(): void
    {
        // Arrange
        $urlA = $this->serve($this->endlessBody());
        $urlB = $this->serve($this->endlessBody());

        // Act
        $zero     = Client::get($urlA)->timeout(10)->maxResponseBytes(0)->send();
        $negative = Client::get($urlB)->timeout(10)->maxResponseBytes(-5)->send();

        // Assert
        $this->assertSame('', $zero->body());
        $this->assertTrue($zero->truncated());
        $this->assertSame('', $negative->body());
        $this->assertTrue($negative->truncated());
    }

    /**
     * With both set, headersOnly() wins and no body is read — the documented
     * resolution, asserted rather than left to the reader.
     */
    public function testHeadersOnlyWinsOverAByteCeiling(): void
    {
        // Arrange
        $url = $this->serve($this->endlessBody());

        // Act
        $response = Client::get($url)->timeout(10)
            ->maxResponseBytes(4096)
            ->headersOnly()
            ->send();

        // Assert
        $this->assertSame('', $response->body());
        $this->assertTrue($response->truncated());
    }

    // ── Nothing else changed ────────────────────────────────────────────────

    /**
     * A request with neither option set reads the whole body and reports it as
     * complete, exactly as before. The write callback is only installed when
     * one of the two is asked for.
     */
    public function testAnOrdinaryRequestIsUnaffected(): void
    {
        // Arrange
        $payload = str_repeat('payload', 1000);
        $url = $this->serve(static function ($conn) use ($payload): void {
            fwrite($conn,
                "HTTP/1.1 200 OK\r\n"
                . "Content-Type: text/plain\r\n"
                . 'Content-Length: ' . strlen($payload) . "\r\n"
                . "Connection: close\r\n"
                . "\r\n" . $payload
            );
        });

        // Act
        $response = Client::get($url)->timeout(5)->send();

        // Assert
        $this->assertSame($payload, $response->body());
        $this->assertFalse($response->truncated());
    }

    /**
     * A real transport failure must still throw while a ceiling is set.
     *
     * cURL reports our own deliberate stop as CURLE_WRITE_ERROR, so the success
     * path is an error code — and the guard that tells the two apart is the flag
     * this code sets when it does the stopping. A connection that never opens
     * never sets it, and must raise as it always did.
     */
    public function testAConnectionFailureStillThrowsWithACeilingSet(): void
    {
        // Arrange — a port nothing is listening on.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) explode(
            ':', (string) stream_socket_get_name($server, false)
        )[1];
        fclose($server);

        // Assert
        $this->expectException(ClientException::class);

        // Act
        Client::get('http://127.0.0.1:' . $port)
            ->connectTimeout(2)->timeout(3)
            ->maxResponseBytes(1024)
            ->send();
    }

    /**
     * A server that hangs up mid-body, below the ceiling, is a transport error
     * and not a truncation — the client did not choose to stop.
     *
     * Reporting this as a clean truncated response would hide a broken transfer
     * behind a flag that means something else.
     */
    public function testAServerHangingUpBelowTheCeilingStillThrows(): void
    {
        // Arrange — promise 1000 bytes, send 10, close.
        $url = $this->serve(static function ($conn): void {
            fwrite($conn,
                "HTTP/1.1 200 OK\r\n"
                . "Content-Length: 1000\r\n"
                . "\r\n" . str_repeat('q', 10)
            );
        });

        // Assert
        $this->expectException(ClientException::class);

        // Act
        Client::get($url)->timeout(5)->maxResponseBytes(4096)->send();
    }

    /**
     * The options are inert for a faked response, which never reaches the
     * network. Documented, and asserted so the documentation cannot drift.
     */
    public function testAFakedResponseIgnoresTheCeiling(): void
    {
        // Arrange
        Client::fake([
            '*' => ClientResponse::make(str_repeat('f', 500), 200),
        ]);

        // Act
        $response = Client::get('https://example.test/anything')
            ->maxResponseBytes(10)
            ->send();

        // Assert
        $this->assertSame(500, strlen($response->body()));
        $this->assertFalse($response->truncated());

        // Cleanup
        Client::resetFakes();
    }
}
