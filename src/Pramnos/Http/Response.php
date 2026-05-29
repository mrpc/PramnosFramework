<?php

namespace Pramnos\Http;

/**
 * Fluent HTTP response builder.
 *
 * Collects status code, headers, and body then sends them on send().
 * All mutators return a new instance (immutable-style) so intermediate
 * objects can be reused safely.
 *
 * BC: purely additive — existing controllers that call header()/echo/Document
 * are completely unaffected. Use this class when you want a cleaner API.
 *
 * Quick-start:
 *   return Response::make('Hello')->withStatus(200)->send();
 *   return Response::json(['ok' => true])->send();
 *   return Response::redirect('/login')->send();
 *
 */
class Response
{
    /** @var array<string, string[]> */
    private array $headers = [];

    private function __construct(
        private string $body       = '',
        private int    $statusCode = 200
    ) {}

    // ──────────────────────────────────────────────────────────────────────────
    // Static factories
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Create a plain-text / HTML response.
     */
    public static function make(string $body = '', int $status = 200): static
    {
        $res = new static($body, $status);
        return $res;
    }

    /**
     * Create an application/json response.
     *
     * @param mixed $data  Anything json_encode() accepts.
     * @param int   $flags json_encode() flags (e.g. JSON_PRETTY_PRINT).
     */
    public static function json(mixed $data, int $status = 200, int $flags = 0): static
    {
        $body = json_encode($data, $flags);
        if ($body === false) {
            $body = '{"error":"JSON encoding failed"}';
            $status = 500;
        }
        $res = new static($body, $status);
        $res->headers['Content-Type'] = ['application/json'];
        return $res;
    }

    /**
     * Create an HTTP redirect response.
     *
     * @param int $status 301, 302, 303, 307, or 308.
     */
    public static function redirect(string $url, int $status = 302): static
    {
        $res = new static('', $status);
        $res->headers['Location'] = [$url];
        return $res;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Fluent mutators (return cloned instance)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Return a new instance with the given HTTP status code.
     */
    public function withStatus(int $code): static
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        return $clone;
    }

    /**
     * Return a new instance with the given header added (does not replace).
     * Use withoutHeader() first if you need to replace.
     */
    public function withHeader(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->headers[$name][] = $value;
        return $clone;
    }

    /**
     * Return a new instance with the header replaced (any previous values removed).
     */
    public function withRawHeader(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->headers[$name] = [$value];
        return $clone;
    }

    /**
     * Return a new instance without the named header.
     */
    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        unset($clone->headers[$name]);
        return $clone;
    }

    /**
     * Return a new instance with the given body.
     */
    public function withBody(string $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Accessors (for testing / middleware inspection)
    // ──────────────────────────────────────────────────────────────────────────

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Returns all values for a header, or [] if not set.
     *
     * @return string[]
     */
    public function getHeader(string $name): array
    {
        return $this->headers[$name] ?? [];
    }

    /**
     * Returns the first value for a header, or null if not set.
     */
    public function getHeaderLine(string $name): ?string
    {
        $values = $this->headers[$name] ?? [];
        return $values ? implode(', ', $values) : null;
    }

    /**
     * Returns true when the named header is present.
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]) && $this->headers[$name] !== [];
    }

    /**
     * Returns all headers as name → first-value map (for simple inspection).
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        $out = [];
        foreach ($this->headers as $name => $values) {
            $out[$name] = implode(', ', $values);
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Emission
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Send status line, headers, and body to the client.
     *
     * Returns $this so it can be chained in a return statement, though in
     * normal usage the caller returns the result of send() directly.
     *
     * This method is intentionally thin — it delegates to header() and echo,
     * the same primitives existing controllers use, so behaviour is identical.
     *
     * @codeCoverageIgnore — pure I/O; logic tested via accessors.
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
        echo $this->body;
        return $this;
    }
}
