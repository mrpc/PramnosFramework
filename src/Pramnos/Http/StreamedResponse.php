<?php

declare(strict_types=1);

namespace Pramnos\Http;

/**
 * A Response whose body is produced by a callback and streamed incrementally.
 *
 * Where {@see Response} buffers a complete string and echoes it once,
 * StreamedResponse sends the status line and headers, then invokes a producer
 * callback that writes and flushes output over the lifetime of the request. This
 * is what makes Server-Sent Events (text/event-stream) and other long-lived
 * chunked responses possible on top of the normal HTTP/PHP request model.
 *
 * It mirrors Response's fluent header/status API so a dispatcher can treat both
 * uniformly — it just calls send().
 */
class StreamedResponse
{
    /** @var array<string, string[]> */
    private array $headers = [];

    /** @var callable */
    private $producer;

    private function __construct(callable $producer, int $statusCode = 200)
    {
        $this->producer   = $producer;
        $this->statusCode = $statusCode;
    }

    private int $statusCode = 200;

    /**
     * Create a streamed response from a producer callback.
     *
     * The callback receives no arguments and is expected to echo + flush output.
     */
    public static function create(callable $producer, int $status = 200): static
    {
        return new static($producer, $status);
    }

    /**
     * Create a Server-Sent Events response.
     *
     * Pre-sets the SSE headers and hands the producer a ready-to-use
     * {@see \Pramnos\Http\Sse\SseWriter}. Output buffering is disabled and the
     * writer is created inside send() so nothing is emitted until the response is
     * actually sent.
     *
     * @param callable $producer fn(\Pramnos\Http\Sse\SseWriter $sse): void
     */
    public static function sse(callable $producer): static
    {
        $response = new static(function () use ($producer): void {
            $producer(new \Pramnos\Http\Sse\SseWriter());
        });

        return $response
            ->withRawHeader('Content-Type', 'text/event-stream')
            ->withRawHeader('Cache-Control', 'no-cache')
            ->withRawHeader('Connection', 'keep-alive')
            ->withRawHeader('X-Accel-Buffering', 'no'); // disable nginx proxy buffering
    }

    // ── fluent mutators (return cloned instance, matching Response) ────────────

    public function withStatus(int $code): static
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        return $clone;
    }

    public function withHeader(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->headers[$name][] = $value;
        return $clone;
    }

    public function withRawHeader(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->headers[$name] = [$value];
        return $clone;
    }

    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        unset($clone->headers[$name]);
        return $clone;
    }

    // ── accessors (for tests / middleware inspection) ──────────────────────────

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return string[] */
    public function getHeader(string $name): array
    {
        return $this->headers[$name] ?? [];
    }

    public function getHeaderLine(string $name): ?string
    {
        $values = $this->headers[$name] ?? [];
        return $values ? implode(', ', $values) : null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]) && $this->headers[$name] !== [];
    }

    /** @return array<string,string> */
    public function getHeaders(): array
    {
        $out = [];
        foreach ($this->headers as $name => $values) {
            $out[$name] = implode(', ', $values);
        }
        return $out;
    }

    /**
     * Expose the producer so it can be invoked directly in tests without the
     * header/flush side effects of send().
     */
    public function getProducer(): callable
    {
        return $this->producer;
    }

    // ── emission ───────────────────────────────────────────────────────────────

    /**
     * Send status + headers, disable output buffering, then run the producer.
     *
     * @codeCoverageIgnore — pure I/O; producer + headers covered via accessors.
     */
    public function send(): static
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $values) {
                $first = true;
                foreach ($values as $value) {
                    header($name . ': ' . $value, $first);
                    $first = false;
                }
            }
        }

        // A stream runs for the life of the connection, so lift the execution
        // time limit — otherwise a web SAPI's default max_execution_time would
        // cut a long-lived SSE/stream short. Done in PHP so no vhost/.htaccess
        // php_value is required (shared-hosting-portable).
        @set_time_limit(0);

        // Tear down any output buffering so writes reach the client immediately.
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        ($this->producer)();
        return $this;
    }
}
