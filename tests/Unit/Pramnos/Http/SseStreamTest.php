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

    public function subscribe(array $channels, callable $onEvent, ?SubscriptionOptions $options = null): void
    {
        $this->lastOptions = $options;
        foreach ($this->events as [$channel, $event, $payload]) {
            if ($onEvent($channel, $event, $payload) === false) {
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
}
