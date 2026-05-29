<?php

namespace Pramnos\Http;

/**
 * Immutable value object wrapping an HTTP response received by Client.
 *
 */
class ClientResponse
{
    /** @param array<string, string> $headers Normalised lowercase-keyed headers. */
    public function __construct(
        private readonly int    $statusCode,
        private readonly string $body,
        private readonly array  $headers = []
    ) {}

    // =========================================================================
    // Factory helpers (used by Client::fake() and tests)
    // =========================================================================

    /**
     * Create a response from a string or array body.
     *
     * If $body is an array it is JSON-encoded and Content-Type is set to
     * application/json automatically.
     *
     * @param string|array<mixed> $body
     * @param array<string,string> $headers
     */
    public static function make(string|array $body, int $status = 200, array $headers = []): static
    {
        if (is_array($body)) {
            $body = (string) json_encode($body);
            $headers = array_merge(['content-type' => 'application/json'], $headers);
        }
        return new static($status, $body, $headers);
    }

    // =========================================================================
    // Status
    // =========================================================================

    /** HTTP status code (e.g. 200, 404, 500). */
    public function status(): int
    {
        return $this->statusCode;
    }

    /** True for 2xx responses. */
    public function ok(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /** Alias for ok(). */
    public function successful(): bool
    {
        return $this->ok();
    }

    /** True for 4xx or 5xx responses. */
    public function failed(): bool
    {
        return $this->clientError() || $this->serverError();
    }

    /** True for 4xx responses. */
    public function clientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /** True for 5xx responses. */
    public function serverError(): bool
    {
        return $this->statusCode >= 500;
    }

    /** True for 3xx responses. */
    public function redirect(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    // =========================================================================
    // Body
    // =========================================================================

    /** Raw response body as a string. */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Decode the response body as JSON.
     *
     * @param string|null $key  Optional dot-notation key to pluck from the decoded object.
     * @return mixed            Decoded array, or the value at $key, or null on decode failure.
     */
    public function json(?string $key = null): mixed
    {
        $data = json_decode($this->body, true);
        if ($key === null) {
            return $data;
        }
        // Dot-notation support: "user.email"
        $parts  = explode('.', $key);
        $cursor = $data;
        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return null;
            }
            $cursor = $cursor[$part];
        }
        return $cursor;
    }

    // =========================================================================
    // Headers
    // =========================================================================

    /**
     * Return a response header value (case-insensitive).
     * Returns an empty string if the header is absent.
     */
    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /** Return all response headers (lowercase keys). */
    public function headers(): array
    {
        return $this->headers;
    }

    // =========================================================================
    // Throw helper
    // =========================================================================

    /**
     * Throw a ClientException if the response is a failure (4xx or 5xx).
     * Returns $this so it can be chained: $response->throw()->json().
     *
     * @throws ClientException
     */
    public function throw(): static
    {
        if ($this->failed()) {
            throw new ClientException(
                "HTTP {$this->statusCode} response: " . substr($this->body, 0, 200)
            );
        }
        return $this;
    }
}
