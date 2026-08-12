<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Drivers\SubscribableDriverInterface;
use Pramnos\Broadcasting\SubscriptionOptions;
use Pramnos\Http\Sse\SseWriter;
use Pramnos\Http\StreamedResponse;

/**
 * Fake subscribable backplane: subscribe() replays a scripted list of events to
 * the consumer callback and then returns (as if the loop ended), so SseWriter's
 * stream() can be tested without a live backplane or real blocking.
 */
class FakeSubscribableDriver implements SubscribableDriverInterface
{
    public ?SubscriptionOptions $lastOptions = null;

    /** @param list<array{0:string,1:string,2:array}> $events */
    public function __construct(private array $events = [])
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function broadcast(string $channel, string $event, array $payload): void
    {
    }

    /** How many idle ticks to fire before replaying the scripted events. */
    public int $idleTicks = 0;

    public function subscribe(array $channels, callable $onEvent, ?SubscriptionOptions $options = null): void
    {
        $this->lastOptions = $options;

        // The idle path is where an SSE consumer pings, checks whether the
        // client is still there, and runs onTick — none of which any event can
        // exercise, because it only happens when nothing arrived.
        for ($i = 0; $i < $this->idleTicks; $i++) {
            if ($options !== null && !$options->fireIdle()) {
                return;
            }
        }

        foreach ($this->events as $entry) {
            [$channel, $event, $payload] = $entry;
            // A driver with a durable log passes the event's id as a fourth
            // argument; one without passes null. Both shapes appear here.
            $id = $entry[3] ?? null;
            if ($onEvent($channel, $event, $payload, $id) === false) {
                return;
            }
        }
    }
}

/**
 * Unit tests for the SSE streaming primitives: StreamedResponse::sse() header
 * setup + producer wiring, and SseWriter frame formatting + the backplane pump.
 */
#[CoversClass(StreamedResponse::class)]
#[CoversClass(SseWriter::class)]
class SseStreamTest extends TestCase
{
    /**
     * StreamedResponse::sse() presets the event-stream headers and a 200 status,
     * and its producer is handed a real SseWriter.
     */
    public function testSseFactorySetsHeadersAndPassesWriter(): void
    {
        $response = StreamedResponse::sse(function ($sse): void {
            $this->assertInstanceOf(SseWriter::class, $sse);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no', $response->getHeaderLine('X-Accel-Buffering'));

        // Invoke the producer directly (asserts run inside it).
        ($response->getProducer())();
    }

    /**
     * event() formats "event: <name>" + one "data:" line + terminating blank line,
     * JSON-encoding non-string payloads.
     */
    public function testEventFormatsNamedEventWithJsonData(): void
    {
        $sse = new SseWriter();
        ob_start();
        $sse->event('message.created', ['body' => 'hi']);
        $out = ob_get_clean();

        $this->assertSame("event: message.created\ndata: {\"body\":\"hi\"}\n\n", $out);
    }

    /**
     * A string payload is written verbatim (already-encoded JSON is not re-encoded).
     */
    public function testEventWritesStringPayloadVerbatim(): void
    {
        $sse = new SseWriter();
        ob_start();
        $sse->event('users', '{"count":3}');
        $out = ob_get_clean();

        $this->assertSame("event: users\ndata: {\"count\":3}\n\n", $out);
    }

    /**
     * comment(), ping() and retry() produce their SSE line forms.
     */
    public function testCommentPingRetryFormatting(): void
    {
        $sse = new SseWriter();

        ob_start();
        $sse->comment('SSE connection established');
        $this->assertSame(": SSE connection established\n\n", ob_get_clean());

        ob_start();
        $sse->retry(3000);
        $this->assertSame("retry: 3000\n\n", ob_get_clean());

        ob_start();
        $sse->ping();
        $this->assertMatchesRegularExpression("/^: ping \\d+\n\n$/", ob_get_clean());
    }

    /**
     * Multi-line data payloads are split into multiple data: lines per spec.
     */
    public function testMultilineDataSplitsIntoDataLines(): void
    {
        $sse = new SseWriter();
        ob_start();
        $sse->data("line1\nline2");
        $out = ob_get_clean();

        $this->assertSame("data: line1\ndata: line2\n\n", $out);
    }

    /**
     * stream() forwards each backplane event to the app callback, applies the
     * ping-interval / max-runtime to the subscription, and emits a reconnect event
     * when the loop ends under a runtime ceiling.
     */
    public function testStreamPumpsBackplaneAndEmitsReconnect(): void
    {
        $driver = new FakeSubscribableDriver([
            ['chat.updates', 'message', ['body' => 'hi']],
            ['chat.updates', 'users', ['count' => 2]],
        ]);

        $sse = new SseWriter();
        ob_start();
        $sse->stream(
            $driver,
            ['chat.updates'],
            fn (string $c, string $e, array $p, SseWriter $w) => $w->event($e, $p),
            maxRuntime: 95,
            pingInterval: 20,
        );
        $out = ob_get_clean();

        $this->assertStringContainsString("event: message\ndata: {\"body\":\"hi\"}\n\n", $out);
        $this->assertStringContainsString("event: users\ndata: {\"count\":2}\n\n", $out);
        $this->assertStringContainsString('event: reconnect', $out);

        $this->assertSame(20, $driver->lastOptions->readTimeout);
        $this->assertSame(95, $driver->lastOptions->maxRuntime);
    }

    /**
     * An event carrying a backplane id is written with an `id:` frame.
     *
     * This is the whole mechanism: `EventSource` remembers the last id it saw
     * and sends it back as `Last-Event-ID` when it reconnects, which is how the
     * server knows what to replay. Without the frame there is nothing to
     * remember, and everything published during a reconnect is lost — and
     * `maxRuntime` makes that reconnect happen on a schedule.
     */
    public function testAnEventWithAnIdWritesAnIdFrame(): void
    {
        // Arrange — the application callback does not touch the id at all
        $driver = new FakeSubscribableDriver([
            ['chat', 'message.created', ['n' => 1], '42'],
        ]);

        // Act
        $output = $this->capture(function () use ($driver) {
            (new SseWriter())->stream(
                driver: $driver,
                channels: ['chat'],
                onEvent: function (string $c, string $e, array $p, SseWriter $sse): void {
                    $sse->event($e, $p);
                },
            );
        });

        // Assert — the id precedes its event, per the SSE spec
        $this->assertStringContainsString("id: 42\nevent: message.created\n", $output);
    }

    /**
     * A driver with no ids produces no `id:` frames — rather than an empty one,
     * which the client would remember as its resume point and send back.
     */
    public function testAnEventWithoutAnIdWritesNoIdFrame(): void
    {
        // Arrange
        $driver = new FakeSubscribableDriver([['chat', 'ping', []]]);

        // Act
        $output = $this->capture(function () use ($driver) {
            (new SseWriter())->stream(
                driver: $driver,
                channels: ['chat'],
                onEvent: function (string $c, string $e, array $p, SseWriter $sse): void {
                    $sse->event($e, $p);
                },
            );
        });

        // Assert
        $this->assertStringNotContainsString('id:', $output);
    }

    /**
     * `Last-Event-ID` becomes the resume point, with no application code.
     *
     * The browser sends this header by itself on every reconnect. Reading it
     * here is what makes replay the default rather than something each endpoint
     * has to remember to implement.
     */
    public function testLastEventIdHeaderBecomesTheResumePoint(): void
    {
        // Arrange
        $_SERVER['HTTP_LAST_EVENT_ID'] = '1699-7';
        $driver = new FakeSubscribableDriver();

        // Act
        $this->capture(function () use ($driver) {
            (new SseWriter())->stream(driver: $driver, channels: ['chat'], onEvent: fn () => null);
        });

        // Assert
        $this->assertSame('1699-7', $driver->lastOptions->sinceId);

        // Cleanup
        unset($_SERVER['HTTP_LAST_EVENT_ID']);
    }

    /**
     * `?since=` works for clients that keep their own cursor — a native app, a
     * polyfill, or a page that already has data on screen from a first render.
     */
    public function testSinceQueryParameterIsUsedWhenTheHeaderIsAbsent(): void
    {
        // Arrange
        $_GET['since'] = '128';
        $driver = new FakeSubscribableDriver();

        // Act
        $this->capture(function () use ($driver) {
            (new SseWriter())->stream(driver: $driver, channels: ['chat'], onEvent: fn () => null);
        });

        // Assert
        $this->assertSame('128', $driver->lastOptions->sinceId);

        // Cleanup
        unset($_GET['since']);
    }

    /**
     * A first connection says nothing, and gets the live stream — not a replay
     * of everything the backplane happens to be holding.
     */
    public function testAFirstConnectionStartsLive(): void
    {
        // Arrange — no header, no query parameter
        $driver = new FakeSubscribableDriver();

        // Act
        $this->capture(function () use ($driver) {
            (new SseWriter())->stream(driver: $driver, channels: ['chat'], onEvent: fn () => null);
        });

        // Assert
        $this->assertNull($driver->lastOptions->sinceId);
    }

    /**
     * An explicit $sinceId beats whatever the request said.
     *
     * An endpoint that derives the resume point itself — from a database cursor
     * belonging to the signed-in user, say — must not be overruled by a header
     * the client controls.
     */
    public function testAnExplicitSinceIdOverridesTheRequest(): void
    {
        // Arrange
        $_SERVER['HTTP_LAST_EVENT_ID'] = '999';
        $driver = new FakeSubscribableDriver();

        // Act
        $this->capture(function () use ($driver) {
            (new SseWriter())->stream(
                driver: $driver,
                channels: ['chat'],
                onEvent: fn () => null,
                sinceId: '5',
            );
        });

        // Assert
        $this->assertSame('5', $driver->lastOptions->sinceId);

        // Cleanup
        unset($_SERVER['HTTP_LAST_EVENT_ID']);
    }

    /**
     * Passing an empty string opts out: start live, whatever the client asked
     * for. The escape hatch for an endpoint whose events are not replayable.
     */
    public function testAnEmptySinceIdOptsOutOfReplay(): void
    {
        // Arrange
        $_SERVER['HTTP_LAST_EVENT_ID'] = '999';
        $driver = new FakeSubscribableDriver();

        // Act
        $this->capture(function () use ($driver) {
            (new SseWriter())->stream(
                driver: $driver,
                channels: ['chat'],
                onEvent: fn () => null,
                sinceId: '',
            );
        });

        // Assert
        $this->assertNull($driver->lastOptions->sinceId);

        // Cleanup
        unset($_SERVER['HTTP_LAST_EVENT_ID']);
    }


    /**
     * An idle tick sends a keep-alive ping.
     *
     * A proxy closes a connection that has been silent for a minute, so the
     * ping is what keeps a quiet stream alive. It only happens when *nothing*
     * arrived, so no event can exercise it.
     */
    public function testAnIdleTickPings(): void
    {
        // Arrange
        $driver = new FakeSubscribableDriver();
        $driver->idleTicks = 2;

        // Act
        $output = $this->capture(function () use ($driver) {
            (new SseWriter())->stream(driver: $driver, channels: ['chat'], onEvent: fn () => null);
        });

        // Assert — two comment-form pings, which EventSource ignores
        $this->assertSame(2, substr_count($output, ': ping'));
    }

    /**
     * onTick runs on every idle tick, and can end the stream.
     *
     * The canonical use is server-driven presence: the live connection is itself
     * proof the user is online, so it is refreshed each tick rather than trusted
     * from a client heartbeat.
     */
    public function testOnTickRunsAndCanEndTheStream(): void
    {
        // Arrange
        $driver = new FakeSubscribableDriver();
        $driver->idleTicks = 5;
        $ticks = 0;

        // Act
        $output = $this->capture(function () use ($driver, &$ticks) {
            (new SseWriter())->stream(
                driver: $driver,
                channels: ['chat'],
                onEvent: fn () => null,
                pingInterval: 20,
                onTick: function () use (&$ticks): bool {
                    $ticks++;
                    return $ticks < 3;   // stop on the third
                },
            );
        });

        // Assert — it stopped when asked, before the five ticks ran out
        $this->assertSame(3, $ticks);
        $this->assertSame(2, substr_count($output, ': ping'), 'the tick that stopped it does not ping');
    }

    /**
     * Capture what the writer echoes.
     */
    private function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $output = (string) ob_get_clean();
        }
        return $output;
    }
}
