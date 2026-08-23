<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Client;
use Pramnos\Http\ClientException;
use Pramnos\Http\ClientResponse;

/**
 * Integration tests for Client::execute() — the curl call itself — against a
 * real socket server.
 *
 * This method used to carry @codeCoverageIgnore with the note "requires a live
 * network endpoint", which meant the only part of the client that actually
 * speaks HTTP was the only part nothing checked. A forked socket server *is* a
 * live network endpoint, so the note was excusing rather than explaining.
 *
 * What is pinned here is the wire behaviour the rest of the class assumes:
 * which curl option each HTTP verb takes, that a body reaches the server, that
 * headers set through the fluent builder arrive, and that a transport failure
 * becomes a ClientException rather than a false. The retry policy is asserted
 * against a server that really does fail first and succeed after.
 *
 * The response-body ceiling has its own file: ClientBodyCeilingTest.
 */
#[CoversClass(Client::class)]
#[CoversClass(ClientResponse::class)]
#[\PHPUnit\Framework\Attributes\Group('integration')]
class ClientTransportTest extends TestCase
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
        foreach ($this->children as $pid) {
            @posix_kill($pid, SIGKILL);
            @pcntl_waitpid($pid, $status);
        }
        $this->children = [];
    }

    /**
     * Fork a server that answers $count connections in sequence.
     *
     * More than one is needed for the retry tests, where the same URL must fail
     * and then succeed. The child is killed outright rather than returning,
     * because a forked PHPUnit child that unwinds normally runs the framework's
     * shutdown handlers and reports results of its own.
     *
     * @param callable(int, string): string $respond Receives the zero-based
     *          connection number and the request text; returns the raw response.
     * @param int $count How many connections to serve before exiting.
     * @return string Base URL of the server.
     */
    private function serve(callable $respond, int $count = 1): string
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
            for ($i = 0; $i < $count; $i++) {
                $conn = @stream_socket_accept($server, 10);
                if (!is_resource($conn)) {
                    break;
                }
                $request = '';
                while (!str_contains($request, "\r\n\r\n")) {
                    $line = fgets($conn, 8192);
                    if ($line === false) {
                        break;
                    }
                    $request .= $line;
                }
                // Read a declared body too, so the assertions below can see it.
                if (preg_match('/content-length:\s*(\d+)/i', $request, $m)
                    && (int) $m[1] > 0) {
                    $request .= (string) fread($conn, (int) $m[1]);
                }
                @fwrite($conn, $respond($i, $request));
                @fclose($conn);
            }
            @fclose($server);
            posix_kill(posix_getpid(), SIGKILL);
        }

        fclose($server);
        $this->children[] = $pid;

        return 'http://127.0.0.1:' . $port;
    }

    /**
     * Build a minimal, well-formed response.
     *
     * @param array<string,string> $headers
     */
    private static function response(
        int $status = 200, string $body = '', array $headers = []
    ): string {
        $head = "HTTP/1.1 {$status} X\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n";
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }

        return $head . "\r\n" . $body;
    }

    // ── Verbs ───────────────────────────────────────────────────────────────

    /**
     * Every verb the builder offers must reach the server as that verb.
     *
     * execute() maps them onto three different curl options — CURLOPT_HTTPGET,
     * CURLOPT_NOBODY and CURLOPT_CUSTOMREQUEST — and a mapping that is only
     * exercised through a fake proves nothing about what goes over the wire.
     *
     * @return array<string,array{string}>
     */
    public static function verbProvider(): array
    {
        return [
            'GET'    => ['GET'],
            'POST'   => ['POST'],
            'PUT'    => ['PUT'],
            'PATCH'  => ['PATCH'],
            'DELETE' => ['DELETE'],
        ];
    }

    /**
     * @param string $verb HTTP method under test.
     */
    #[DataProvider('verbProvider')]
    public function testTheVerbReachesTheServer(string $verb): void
    {
        // Arrange
        $seen = null;
        $url = $this->serve(static function (int $i, string $request): string {
            return self::response(200, explode(' ', $request, 2)[0]);
        });

        // Act
        $response = Client::{strtolower($verb)}($url)->timeout(5)->send();

        // Assert — the server echoed back the request line's first word.
        $this->assertSame(200, $response->status());
        $this->assertSame($verb, $response->body());
    }

    /**
     * HEAD takes the CURLOPT_NOBODY path: the request is sent, the status and
     * headers arrive, and there is no body to read.
     */
    public function testHeadReturnsHeadersWithoutABody(): void
    {
        // Arrange — a HEAD response carries Content-Length but no body.
        $url = $this->serve(static function (): string {
            return "HTTP/1.1 200 OK\r\nContent-Length: 42\r\n"
                . "Content-Type: text/plain\r\nConnection: close\r\n\r\n";
        });

        // Act
        $response = Client::head($url)->timeout(5)->send();

        // Assert
        $this->assertSame(200, $response->status());
        $this->assertSame('text/plain', $response->header('content-type'));
        $this->assertSame('', $response->body());
    }

    // ── What the builder puts on the wire ───────────────────────────────────

    /**
     * A JSON body and its Content-Type reach the server, and so does a header
     * set through the fluent builder.
     *
     * Asserted in one test because they travel together and the evidence is the
     * same request text.
     */
    public function testBodyContentTypeAndHeadersArrive(): void
    {
        // Arrange — the server hands the whole request back as the body.
        $url = $this->serve(static function (int $i, string $request): string {
            return self::response(200, $request);
        });

        // Act
        $response = Client::post($url)
            ->json(['station' => 'Aroma'])
            ->bearerToken('tok-123')
            ->header('X-Probe', 'yes')
            ->userAgent('PramnosTest/1.0')
            ->timeout(5)
            ->send();

        // Assert
        $echoed = $response->body();
        $this->assertStringContainsString('Content-Type: application/json', $echoed);
        $this->assertStringContainsString('Authorization: Bearer tok-123', $echoed);
        $this->assertStringContainsString('X-Probe: yes', $echoed);
        $this->assertStringContainsString('User-Agent: PramnosTest/1.0', $echoed);
        $this->assertStringContainsString('{"station":"Aroma"}', $echoed);
    }

    /**
     * A body on a CUSTOMREQUEST verb reaches the server too.
     *
     * POST takes the CURLOPT_POST path and PUT/PATCH/DELETE take
     * CURLOPT_CUSTOMREQUEST, and each attaches its body separately — so a body
     * that travels on a POST proves nothing about one on a PUT.
     */
    public function testABodyOnAPutReachesTheServer(): void
    {
        // Arrange
        $url = $this->serve(static function (int $i, string $request): string {
            return self::response(200, $request);
        });

        // Act
        $response = Client::put($url)
            ->json(['listeners' => 41])
            ->timeout(5)
            ->send();

        // Assert
        $echoed = $response->body();
        $this->assertStringStartsWith('PUT ', $echoed);
        $this->assertStringContainsString('{"listeners":41}', $echoed);
    }

    /**
     * A form body is URL-encoded and sent with the form content type.
     */
    public function testFormBodyIsUrlEncoded(): void
    {
        // Arrange
        $url = $this->serve(static function (int $i, string $request): string {
            return self::response(200, $request);
        });

        // Act
        $response = Client::post($url)
            ->form(['name' => 'Aroma FM', 'id' => 7])
            ->timeout(5)
            ->send();

        // Assert
        $echoed = $response->body();
        $this->assertStringContainsString(
            'Content-Type: application/x-www-form-urlencoded', $echoed
        );
        $this->assertStringContainsString('name=Aroma+FM&id=7', $echoed);
    }

    /**
     * Response headers are exposed lowercase-keyed, whatever case the server
     * used — the normalisation callers rely on when reading header().
     */
    public function testResponseHeadersAreNormalisedToLowercase(): void
    {
        // Arrange
        $url = $this->serve(static function (): string {
            return self::response(200, 'x', ['X-Weird-CASE' => 'kept']);
        });

        // Act
        $response = Client::get($url)->timeout(5)->send();

        // Assert
        $this->assertSame('kept', $response->header('x-weird-case'));
        $this->assertArrayHasKey('x-weird-case', $response->headers());
    }

    // ── Failure and retry ───────────────────────────────────────────────────

    /**
     * A connection that cannot be opened becomes a ClientException carrying
     * curl's own errno, not a silent false.
     */
    public function testAConnectionFailureThrows(): void
    {
        // Arrange — a port nothing listens on.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) explode(
            ':', (string) stream_socket_get_name($server, false)
        )[1];
        fclose($server);

        // Assert
        $this->expectException(ClientException::class);

        // Act
        Client::get('http://127.0.0.1:' . $port)
            ->connectTimeout(2)->timeout(3)->send();
    }

    /**
     * A 5xx is retried, and a later attempt that succeeds is the answer.
     *
     * The server fails the first connection and answers the second, so the
     * assertion is about the retry policy driving a real second request rather
     * than about a fake being called twice.
     */
    public function testAServerErrorIsRetriedAndTheSuccessIsReturned(): void
    {
        // Arrange
        $url = $this->serve(static function (int $i): string {
            return $i === 0
                ? self::response(503, 'busy')
                : self::response(200, 'ok');
        }, 2);

        // Act
        $response = Client::get($url)->timeout(5)->retry(2, 10)->send();

        // Assert
        $this->assertSame(200, $response->status());
        $this->assertSame('ok', $response->body());
    }

    /**
     * A 4xx is never retried: it describes the request, and sending it again
     * cannot change the answer. The server here would answer 200 on a second
     * connection, so a retry would be visible.
     */
    public function testAClientErrorIsNotRetried(): void
    {
        // Arrange
        $url = $this->serve(static function (int $i): string {
            return $i === 0
                ? self::response(404, 'nope')
                : self::response(200, 'would-be-retry');
        }, 2);

        // Act
        $response = Client::get($url)->timeout(5)->retry(3, 10)->send();

        // Assert — the first answer stands.
        $this->assertSame(404, $response->status());
        $this->assertSame('nope', $response->body());
    }

    /**
     * throwOnError() turns a failing status into a ClientException after the
     * retries are spent, rather than returning the response.
     */
    public function testThrowOnErrorRaisesOnAFailingStatus(): void
    {
        // Arrange
        $url = $this->serve(static function (): string {
            return self::response(500, 'boom');
        });

        // Assert
        $this->expectException(ClientException::class);

        // Act
        Client::get($url)->timeout(5)->throwOnError()->send();
    }

    /**
     * A redirect is followed and the final response is returned — the default
     * FOLLOWLOCATION behaviour, pinned because the header callback now resets
     * on each hop and a mistake there would be invisible otherwise.
     */
    public function testRedirectsAreFollowedToTheFinalResponse(): void
    {
        // Arrange
        $target = $this->serve(static function (): string {
            return self::response(200, 'arrived', ['X-Final' => 'yes']);
        });
        $start = $this->serve(static function () use ($target): string {
            return "HTTP/1.1 301 Moved Permanently\r\n"
                . "Location: {$target}/there\r\n"
                . "X-Hop: first\r\n"
                . "Content-Length: 0\r\n"
                . "Connection: close\r\n\r\n";
        });

        // Act
        $response = Client::get($start)->timeout(5)->send();

        // Assert — the destination answered, and the hop's header did not
        // survive into the final response.
        $this->assertSame(200, $response->status());
        $this->assertSame('arrived', $response->body());
        $this->assertSame('yes', $response->header('x-final'));
        $this->assertSame('', $response->header('x-hop'));
    }

    /**
     * A base URL and a relative path compose into the request that is actually
     * sent, which is the instance-usage pattern the class documents.
     */
    public function testBaseUrlAndRelativePathCompose(): void
    {
        // Arrange
        $url = $this->serve(static function (int $i, string $request): string {
            return self::response(200, explode(' ', $request, 3)[1]);
        });

        // Act
        $api = new Client($url);
        $response = $api->make('GET', '/stations/7')->timeout(5)->send();

        // Assert — the path the server saw.
        $this->assertSame('/stations/7', $response->body());
    }
}
