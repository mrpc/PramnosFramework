<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Client;
use Pramnos\Http\ClientException;
use Pramnos\Http\ClientResponse;

/**
 * Integration tests for Client::pool() — several requests in flight at once.
 *
 * Why it exists. Polling a catalogue one endpoint at a time is not a cadence,
 * it is a backlog. A consuming application measured 200 status endpoints at
 * ~1.1 s each: 218 seconds for one pass, so a poller promising a thirty-second
 * tier was reaching each station every four minutes while reporting otherwise.
 * Almost all of that second is spent waiting on somebody else's server, which
 * is exactly the wait that overlaps.
 *
 * The contract these tests pin, taken from what the filing asked for:
 *
 *   - keyed in, keyed out — the caller never re-derives whose answer is whose
 *   - a failure is a value, so one dead host does not abandon the batch
 *   - per-request options, by passing a configured Client
 *   - fakes work, so a test of a batching caller is not a live network test
 *   - retry() is honoured rather than silently ignored
 *
 * Each test forks its own servers; the concurrency assertions are made against
 * servers that deliberately sleep, because overlap is only observable in time.
 */
#[CoversClass(Client::class)]
#[\PHPUnit\Framework\Attributes\Group('integration')]
class ClientPoolTest extends TestCase
{
    /** @var int[] PIDs of servers forked by the current test. */
    private array $children = [];

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('These tests fork servers; ext-pcntl is required.');
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
        Client::resetFakes();
    }

    /**
     * Fork a server that answers $count connections in sequence.
     *
     * @param callable(int, string): string $respond Receives the zero-based
     *          connection number and the request text; returns a raw response.
     * @param int $count Connections to serve before exiting.
     * @return string Base URL.
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

    /** A URL that nothing is listening on. */
    private function deadUrl(): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) explode(
            ':', (string) stream_socket_get_name($server, false)
        )[1];
        fclose($server);

        return 'http://127.0.0.1:' . $port;
    }

    // ── The shape of the answer ─────────────────────────────────────────────

    /**
     * Responses come back under the keys the requests went in under.
     *
     * A positional array would make the caller re-derive which answer belongs
     * to which station, which is one more place to get it wrong — and the
     * requests do not finish in the order they were given.
     */
    public function testResponsesAreKeyedAsTheRequestsWere(): void
    {
        // Arrange — the second server is slower, so completion order differs
        // from input order.
        $fast = $this->serve(static fn(): string => self::response(200, 'FAST'));
        $slow = $this->serve(static function (): string {
            usleep(300_000);
            return self::response(200, 'SLOW');
        });

        // Act
        $responses = Client::pool(['quick' => $fast, 'lazy' => $slow]);

        // Assert
        $this->assertSame(['quick', 'lazy'], array_keys($responses));
        $this->assertSame('FAST', $responses['quick']->body());
        $this->assertSame('SLOW', $responses['lazy']->body());
    }

    /**
     * A plain string is shorthand for a GET with the defaults; a Client is used
     * as configured. Both forms may appear in one batch.
     */
    public function testStringsAndConfiguredClientsMayBeMixed(): void
    {
        // Arrange — the second server echoes the request so the configuration
        // can be seen to have arrived.
        $plain = $this->serve(static fn(): string => self::response(200, 'plain'));
        $echo  = $this->serve(
            static fn(int $i, string $req): string => self::response(200, $req)
        );

        // Act
        $responses = Client::pool([
            'a' => $plain,
            'b' => Client::get($echo)->header('X-Probe', 'yes')->timeout(5),
        ]);

        // Assert
        $this->assertSame('plain', $responses['a']->body());
        $this->assertStringContainsString('X-Probe: yes', $responses['b']->body());
    }

    /**
     * Per-request options really are per request: two entries with different
     * body ceilings each get their own.
     *
     * This is the reason the pool takes Clients rather than URLs plus one
     * options array — each station polled has its own timeout and its own
     * notion of how much of the body is worth reading.
     */
    public function testEachEntryKeepsItsOwnOptions(): void
    {
        // Arrange
        $body = str_repeat('y', 4096);
        $one  = $this->serve(static fn(): string => self::response(200, $body));
        $two  = $this->serve(static fn(): string => self::response(200, $body));

        // Act
        $responses = Client::pool([
            'small' => Client::get($one)->timeout(5)->maxResponseBytes(16),
            'whole' => Client::get($two)->timeout(5),
        ]);

        // Assert
        $this->assertSame(16, strlen($responses['small']->body()));
        $this->assertTrue($responses['small']->truncated());
        $this->assertSame(4096, strlen($responses['whole']->body()));
        $this->assertFalse($responses['whole']->truncated());
    }

    // ── Failure is a value ──────────────────────────────────────────────────

    /**
     * A dead host must not abandon the live ones. Its entry is a
     * ClientException *in the array*; the pool itself does not throw.
     *
     * Half of the endpoints this was written for are down at any moment, so
     * this is the ordinary case rather than the exceptional one.
     */
    public function testOneDeadHostDoesNotAbandonTheRest(): void
    {
        // Arrange
        $alive = $this->serve(static fn(): string => self::response(200, 'up'));
        $dead  = $this->deadUrl();

        // Act — no try/catch: the pool must not raise.
        $responses = Client::pool([
            'alive' => Client::get($alive)->timeout(5),
            'dead'  => Client::get($dead)->connectTimeout(2)->timeout(3),
        ]);

        // Assert
        $this->assertInstanceOf(ClientResponse::class, $responses['alive']);
        $this->assertSame('up', $responses['alive']->body());
        $this->assertInstanceOf(ClientException::class, $responses['dead']);
        $this->assertNotSame(0, $responses['dead']->getCurlErrno(),
            'the failure must carry curl\'s own error number');
    }

    /**
     * A 4xx or 5xx is an answer, not a transport failure, so it arrives as a
     * ClientResponse — the same distinction the single-request path makes.
     */
    public function testFailingStatusesArriveAsResponses(): void
    {
        // Arrange
        $notFound = $this->serve(static fn(): string => self::response(404, 'no'));
        $broken   = $this->serve(static fn(): string => self::response(500, 'bad'));

        // Act
        $responses = Client::pool(['a' => $notFound, 'b' => $broken]);

        // Assert
        $this->assertInstanceOf(ClientResponse::class, $responses['a']);
        $this->assertSame(404, $responses['a']->status());
        $this->assertInstanceOf(ClientResponse::class, $responses['b']);
        $this->assertSame(500, $responses['b']->status());
    }

    /**
     * throwOnError() on an entry is honoured as a *value* for that key, not by
     * ending the batch — the pool's own rule wins over the entry's.
     */
    public function testThrowOnErrorBecomesAnExceptionValueForThatEntry(): void
    {
        // Arrange
        $ok     = $this->serve(static fn(): string => self::response(200, 'fine'));
        $broken = $this->serve(static fn(): string => self::response(500, 'bad'));

        // Act
        $responses = Client::pool([
            'ok'     => Client::get($ok)->timeout(5),
            'broken' => Client::get($broken)->timeout(5)->throwOnError(),
        ]);

        // Assert — the batch completed, and the opted-in entry carries the throw.
        $this->assertSame('fine', $responses['ok']->body());
        $this->assertInstanceOf(ClientException::class, $responses['broken']);
    }

    // ── Concurrency ─────────────────────────────────────────────────────────

    /**
     * The requests really do overlap.
     *
     * Four servers that each sleep 400 ms take 1.6 s in sequence. Run together
     * they must finish in well under that — this is the entire point of the
     * method, and a pool that quietly ran them one after another would pass
     * every other test in this file.
     */
    public function testRequestsRunConcurrently(): void
    {
        // Arrange — four deliberately slow servers.
        $urls = [];
        for ($i = 0; $i < 4; $i++) {
            $urls['s' . $i] = $this->serve(static function () use ($i): string {
                usleep(400_000);
                return self::response(200, 'slow' . $i);
            });
        }

        // Act
        $started   = microtime(true);
        $responses = Client::pool($urls, concurrency: 4);
        $elapsed   = microtime(true) - $started;

        // Assert — all four answered …
        $this->assertCount(4, $responses);
        foreach ($responses as $key => $response) {
            $this->assertSame(200, $response->status(), "entry {$key}");
        }
        // … and in nothing like the 1.6 s a sequential loop would need.
        $this->assertLessThan(1.2, $elapsed,
            'four 400ms requests run together must not take the sequential time');
    }

    /**
     * The concurrency ceiling is respected: with a window of one, the same four
     * servers cannot overlap and the batch takes the sequential time.
     *
     * Asserted from the opposite direction to the test above, because "runs
     * concurrently" and "respects the limit" are different claims and a pool
     * that ignored $concurrency would satisfy only the first.
     */
    public function testConcurrencyOfOneSerialisesTheBatch(): void
    {
        // Arrange
        $urls = [];
        for ($i = 0; $i < 3; $i++) {
            $urls['s' . $i] = $this->serve(static function (): string {
                usleep(250_000);
                return self::response(200, 'x');
            });
        }

        // Act
        $started = microtime(true);
        $responses = Client::pool($urls, concurrency: 1);
        $elapsed = microtime(true) - $started;

        // Assert
        $this->assertCount(3, $responses);
        $this->assertGreaterThan(0.7, $elapsed,
            'a window of one cannot overlap three 250ms requests');
    }

    /**
     * More entries than the window means the rest are added as slots free up,
     * and every one of them is answered.
     */
    public function testMoreEntriesThanTheWindowAreAllAnswered(): void
    {
        // Arrange — eight servers, window of three.
        $urls = [];
        for ($i = 0; $i < 8; $i++) {
            $urls['s' . $i] = $this->serve(
                static fn(): string => self::response(200, 'ok' . $i)
            );
        }

        // Act
        $responses = Client::pool($urls, concurrency: 3);

        // Assert
        $this->assertCount(8, $responses);
        foreach (array_keys($urls) as $key) {
            $this->assertSame(200, $responses[$key]->status(), "entry {$key}");
        }
    }

    /**
     * A concurrency of zero or below is clamped to one rather than producing a
     * batch that never starts.
     */
    public function testConcurrencyIsClampedToAtLeastOne(): void
    {
        // Arrange
        $url = $this->serve(static fn(): string => self::response(200, 'ok'));

        // Act
        $responses = Client::pool(['a' => $url], concurrency: 0);

        // Assert
        $this->assertSame('ok', $responses['a']->body());
    }

    /** An empty batch is an empty result, not an error. */
    public function testAnEmptyBatchReturnsAnEmptyArray(): void
    {
        // Act / Assert
        $this->assertSame([], Client::pool([]));
    }

    // ── Retries ─────────────────────────────────────────────────────────────

    /**
     * retry() is honoured inside a pool. A 5xx on the first attempt is sent
     * again, and the success is the answer.
     *
     * Silently ignoring a configured retry would be the worse outcome: the
     * caller would believe the batch was as resilient as a single send() and it
     * would not be.
     */
    public function testAFailedEntryIsRetried(): void
    {
        // Arrange — fails once, then succeeds.
        $flaky = $this->serve(static function (int $i): string {
            return $i === 0
                ? self::response(503, 'busy')
                : self::response(200, 'recovered');
        }, 2);
        $steady = $this->serve(static fn(): string => self::response(200, 'fine'));

        // Act
        $responses = Client::pool([
            'flaky'  => Client::get($flaky)->timeout(5)->retry(2, 10),
            'steady' => Client::get($steady)->timeout(5),
        ]);

        // Assert
        $this->assertSame(200, $responses['flaky']->status());
        $this->assertSame('recovered', $responses['flaky']->body());
        $this->assertSame('fine', $responses['steady']->body());
    }

    /**
     * An entry with retries left is re-sent; one without is not. Entries retry
     * independently, so a neighbour's failure does not cost a re-send.
     */
    public function testAnEntryWithoutRetriesIsNotResent(): void
    {
        // Arrange — this server would answer 200 on a second connection, so a
        // re-send would be visible in the body.
        $url = $this->serve(static function (int $i): string {
            return $i === 0
                ? self::response(503, 'first')
                : self::response(200, 'second');
        }, 2);

        // Act — no retry configured.
        $responses = Client::pool(['a' => Client::get($url)->timeout(5)]);

        // Assert
        $this->assertSame(503, $responses['a']->status());
        $this->assertSame('first', $responses['a']->body());
    }

    /**
     * Retries are spent, not infinite: a server that never recovers yields the
     * last failure rather than looping.
     */
    public function testRetriesAreExhaustedAndTheLastFailureStands(): void
    {
        // Arrange — always 500, three connections available.
        $url = $this->serve(
            static fn(): string => self::response(500, 'always'), 3
        );

        // Act
        $responses = Client::pool(['a' => Client::get($url)->timeout(5)->retry(2, 5)]);

        // Assert
        $this->assertSame(500, $responses['a']->status());
    }

    // ── Fakes ───────────────────────────────────────────────────────────────

    /**
     * A key whose URL matches a fake is answered from it and never reaches the
     * network — otherwise every test of a batching caller becomes a live
     * network test.
     */
    public function testFakedEntriesNeverReachTheNetwork(): void
    {
        // Arrange — the URLs point at nothing; only the fakes can answer.
        Client::fake([
            'https://one.example/*' => ClientResponse::make(['n' => 1], 200),
            'https://two.example/*' => ClientResponse::make('nope', 404),
        ]);

        // Act
        $responses = Client::pool([
            'one' => 'https://one.example/status',
            'two' => 'https://two.example/status',
        ]);

        // Assert
        $this->assertSame(1, $responses['one']->json('n'));
        $this->assertSame(404, $responses['two']->status());
    }

    /**
     * Faked and live entries mix in one batch, so a test can fake the third
     * party and still exercise a real local endpoint.
     */
    public function testFakedAndLiveEntriesMixInOneBatch(): void
    {
        // Arrange
        $live = $this->serve(static fn(): string => self::response(200, 'real'));
        Client::fake(['https://faked.example/*' => ClientResponse::make('fake', 200)]);

        // Act
        $responses = Client::pool([
            'faked' => 'https://faked.example/thing',
            'live'  => $live,
        ]);

        // Assert
        $this->assertSame('fake', $responses['faked']->body());
        $this->assertSame('real', $responses['live']->body());
    }

    /**
     * A callable fake is invoked once per attempt, so a fake can simulate a
     * transient failure and the pool's retry policy can be seen to recover from
     * it without any network at all.
     */
    public function testACallableFakeIsInvokedOncePerAttempt(): void
    {
        // Arrange
        $calls = 0;
        Client::fake([
            'https://flaky.example/*' => function () use (&$calls): ClientResponse {
                $calls++;
                return $calls < 3
                    ? ClientResponse::make('busy', 503)
                    : ClientResponse::make('ok', 200);
            },
        ]);

        // Act
        $responses = Client::pool([
            'f' => Client::get('https://flaky.example/x')->retry(3, 1),
        ]);

        // Assert
        $this->assertSame(3, $calls, 'the fake must see each attempt');
        $this->assertSame(200, $responses['f']->status());
    }
}
