<?php

namespace Pramnos\Http;

/**
 * Immutable value object wrapping an HTTP response received by Client.
 *
 */
class ClientResponse
{
    /**
     * @param array<string, string> $headers   Normalised lowercase-keyed headers.
     * @param bool                  $truncated Whether the client stopped reading
     *                                         the body before the server had
     *                                         finished sending it.
     * @param int|null              $bytes     Bytes that came over the wire, or
     *                                         null when nobody measured (a faked
     *                                         or hand-built response).
     * @param float|null            $elapsedMs Milliseconds the request took, or
     *                                         null for the same reason.
     */
    public function __construct(
        private readonly int    $statusCode,
        private readonly string $body,
        private readonly array  $headers = [],
        private readonly bool   $truncated = false,
        private readonly ?int   $bytes = null,
        private readonly ?float $elapsedMs = null
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

    // =========================================================================
    // What it cost
    // =========================================================================

    /**
     * Bytes that came over the wire, or null when nothing measured them.
     *
     * **Not `strlen(body())`.** They differ in the cases that matter:
     * {@see Client::maxResponseBytes()} stops the read, so the body length is
     * the ceiling rather than what the server sent; a compressed response costs
     * its compressed size on the wire while the body is the inflated one; and
     * this figure counts the **response headers too**, which a body length
     * cannot see. A caller measuring bandwidth wants the wire figure.
     *
     * Counting the headers is why a {@see Client::headersOnly()} probe reports
     * a real number rather than zero — measured at 161 bytes of headers against
     * 0 bytes of body on a local endpoint. A probe that reported no bandwidth
     * would zero the column of any ledger it fed, which is precisely the caller
     * this accessor exists for.
     *
     * **Populated on failure too.** A 404 with a page of HTML behind it is
     * bandwidth that was paid for, and a 500 with a stack trace is bandwidth
     * *and* a wrong address — a statistic only present on success would miss
     * exactly the requests worth finding.
     *
     * `null` means nobody measured, not zero: a faked response and a
     * hand-built one have no transfer to report, and reporting 0 for them
     * would quietly deflate any total they were added to.
     */
    public function transferredBytes(): ?int
    {
        return $this->bytes;
    }

    /**
     * Milliseconds this request took, or null when nothing measured it.
     *
     * curl's own total time for the transfer, so a **pooled** request reports
     * its own duration rather than a share of the batch's. Without it, a caller
     * keeping an outbound ledger had only the clock around the whole batch to
     * divide between its requests — which silently changes the column's meaning
     * from "how slow was that server" to "what share of our elapsed time did
     * this cost". Both are legitimate numbers; having to pick one because the
     * response would not say is not.
     *
     * Together with {@see transferredBytes()} this is what answers whether an
     * outbound cost is **payload** or **waiting**, and those have different
     * fixes: ask for less, against ask less often.
     */
    public function elapsedMs(): ?float
    {
        return $this->elapsedMs;
    }

    /**
     * Did the client stop reading before the server finished sending?
     *
     * True after {@see Client::headersOnly()} on a response that had a body, and
     * after {@see Client::maxResponseBytes()} when the ceiling was reached.
     * False otherwise, including for a body that happened to fit under the
     * ceiling — so this answers "is something missing", not "was a limit set".
     *
     * A truncated response is a normal outcome rather than a failure: the
     * status and headers are complete, and {@see body()} holds exactly the
     * bytes that were read. Check this before parsing a body you expected to be
     * whole.
     */
    public function truncated(): bool
    {
        return $this->truncated;
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
