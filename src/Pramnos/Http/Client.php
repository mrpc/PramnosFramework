<?php

namespace Pramnos\Http;

/**
 * Fluent HTTP client built on ext-curl.
 *
 * Static usage (one-off requests):
 *
 *   $response = Client::get('https://api.example.com/users')
 *       ->bearerToken($token)
 *       ->timeout(10)
 *       ->retry(3, 500)
 *       ->send();
 *
 * Instance usage (shared base URL / auth across requests):
 *
 *   $api = (new Client('https://api.example.com'))->bearerToken($token);
 *   $users  = $api->get('/users')->send()->json();
 *   $orders = $api->get('/orders')->send()->json();
 *
 * Testing (no real network calls):
 *
 *   Client::fake([
 *       'https://api.example.com/users' => ClientResponse::make(['id' => 1], 200),
 *       'https://api.example.com/*'     => ClientResponse::make(['error' => 'not found'], 404),
 *   ]);
 *   // ... exercise code under test ...
 *   Client::resetFakes();
 *
 * Static factory methods (`Client::get()`, `Client::post()`, …) create a fresh
 * Client for one-off requests. For repeated calls that share a base URL, auth,
 * or default headers, use `$client->make(method, path)`:
 *
 *   $api = (new Client('https://api.example.com'))->bearerToken($token);
 *   $users  = $api->make('GET',  '/users')->send()->json();
 *   $orders = $api->make('POST', '/orders')->json($payload)->send()->json();
 *
 */
class Client
{
    // =========================================================================
    // Instance state (populated by the fluent builder)
    // =========================================================================

    private string  $method         = 'GET';
    private string  $url            = '';
    private string  $baseUrl        = '';

    /** @var array<string, string> */
    private array   $headers        = [];

    private ?string $body           = null;
    private string  $contentType    = '';
    private int     $timeout        = 30;
    private int     $connectTimeout = 10;
    private int     $retries        = 0;
    private int     $retryDelayMs   = 100;
    private bool    $verifySsl      = true;
    private bool    $throwOnError   = false;
    private string  $userAgent      = 'PramnosFramework/1.2 (+https://github.com/mrpc/PramnosFramework)';

    /** Stop reading once the final response headers have arrived. */
    private bool    $headersOnly    = false;

    /** Read at most this many body bytes, or null for no ceiling. */
    private ?int    $maxBytes       = null;

    // =========================================================================
    // Fake registry (used in tests to avoid real network calls)
    // =========================================================================

    /** @var array<string, ClientResponse|callable> */
    private static array $fakes = [];

    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * @param string $baseUrl Optional base URL prepended to all relative request paths.
     */
    public function __construct(string $baseUrl = '')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    // =========================================================================
    // Static factory methods — one-off requests (no shared config)
    // =========================================================================

    /** Create a one-off GET request. */
    public static function get(string $url): static
    {
        return (new static())->withMethod('GET')->withUrl($url);
    }

    /** Create a one-off POST request. */
    public static function post(string $url): static
    {
        return (new static())->withMethod('POST')->withUrl($url);
    }

    /** Create a one-off PUT request. */
    public static function put(string $url): static
    {
        return (new static())->withMethod('PUT')->withUrl($url);
    }

    /** Create a one-off PATCH request. */
    public static function patch(string $url): static
    {
        return (new static())->withMethod('PATCH')->withUrl($url);
    }

    /** Create a one-off DELETE request. */
    public static function delete(string $url): static
    {
        return (new static())->withMethod('DELETE')->withUrl($url);
    }

    /** Create a one-off HEAD request. */
    public static function head(string $url): static
    {
        return (new static())->withMethod('HEAD')->withUrl($url);
    }

    // =========================================================================
    // Instance factory — shared base URL / auth / default headers
    // =========================================================================

    /**
     * Create a new request from this instance, inheriting base URL, default
     * headers, auth, and timeouts.
     *
     * Use this when the same Client instance is reused across multiple requests:
     *
     *   $api = (new Client('https://api.example.com'))->bearerToken($token);
     *   $users  = $api->make('GET',  '/users')->send()->json();
     *   $orders = $api->make('POST', '/orders')->json($data)->send();
     *
     * @param string $method HTTP method (GET, POST, …).
     * @param string $path   Relative path (appended to base URL) or full URL.
     */
    public function make(string $method, string $path): static
    {
        return $this->newRequest($method, $path);
    }

    // =========================================================================
    // Fake system
    // =========================================================================

    /**
     * Register fake responses for testing — keyed by URL glob patterns.
     *
     * Values may be a ClientResponse or a callable(Client): ClientResponse.
     * Patterns are matched in declaration order; first match wins.
     * Use '*' as a wildcard: 'https://api.example.com/*'.
     *
     * @param array<string, ClientResponse|callable> $responses
     */
    public static function fake(array $responses = []): void
    {
        static::$fakes = $responses;
    }

    /** Remove all registered fakes. Call in tearDown() after each test. */
    public static function resetFakes(): void
    {
        static::$fakes = [];
    }

    /** True when at least one fake response is registered. */
    public static function hasFakes(): bool
    {
        return !empty(static::$fakes);
    }

    // =========================================================================
    // Fluent builder — request configuration
    // =========================================================================

    /** Set a single request header. */
    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Merge multiple headers at once.
     *
     * @param array<string, string> $headers
     */
    public function headers(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    /** Add an Authorization: Bearer header. */
    public function bearerToken(string $token): static
    {
        return $this->header('Authorization', 'Bearer ' . $token);
    }

    /** Add an Authorization: Basic header. */
    public function basicAuth(string $username, string $password): static
    {
        return $this->header('Authorization', 'Basic ' . base64_encode($username . ':' . $password));
    }

    /**
     * Set the request body as JSON.
     * Sets Content-Type: application/json automatically.
     *
     * @param array<mixed>|object $data
     */
    public function json(array|object $data): static
    {
        $this->body        = (string) json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->contentType = 'application/json';
        return $this;
    }

    /**
     * Set the request body as a URL-encoded form.
     * Sets Content-Type: application/x-www-form-urlencoded automatically.
     *
     * @param array<string, string|int|float> $data
     */
    public function form(array $data): static
    {
        $this->body        = http_build_query($data);
        $this->contentType = 'application/x-www-form-urlencoded';
        return $this;
    }

    /**
     * Set a raw request body with an explicit Content-Type.
     */
    public function body(string $body, string $contentType = 'application/octet-stream'): static
    {
        $this->body        = $body;
        $this->contentType = $contentType;
        return $this;
    }

    /** Total request timeout in seconds (default: 30). */
    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;
        return $this;
    }

    /** TCP connection timeout in seconds (default: 10). */
    public function connectTimeout(int $seconds): static
    {
        $this->connectTimeout = $seconds;
        return $this;
    }

    /**
     * Retry failed requests up to $times additional attempts.
     *
     * Retries occur on connection errors (curl errors) and 5xx responses.
     * 4xx responses are never retried — they indicate a client-side problem.
     * Delay between retries uses exponential backoff: $delayMs × 2^(attempt−1).
     *
     * @param int $times    Number of retry attempts after the first try (0 = no retry).
     * @param int $delayMs  Initial delay in milliseconds before the first retry.
     */
    public function retry(int $times, int $delayMs = 100): static
    {
        $this->retries      = max(0, $times);
        $this->retryDelayMs = max(0, $delayMs);
        return $this;
    }

    /**
     * Disable SSL certificate verification.
     * Use ONLY in development — never in production.
     */
    public function withoutSslVerification(): static
    {
        $this->verifySsl = false;
        return $this;
    }

    /** Override the default User-Agent header. */
    public function userAgent(string $agent): static
    {
        $this->userAgent = $agent;
        return $this;
    }

    /**
     * Throw a ClientException on 4xx/5xx responses instead of returning them.
     * Equivalent to calling $response->throw() after send().
     */
    public function throwOnError(): static
    {
        $this->throwOnError = true;
        return $this;
    }

    /**
     * Stop reading as soon as the final response headers have arrived.
     *
     * The response carries the status and the headers, an empty body, and
     * {@see ClientResponse::truncated()} true. Redirects are still followed —
     * "final" means the response that ends the chain.
     *
     * This is **not** {@see head()}, and the difference is the point.
     * `head()` sends a different request, and a great many servers answer a
     * HEAD with 404 or 405 on a path they serve happily over GET — measured at
     * 17 of 30 on one catalogue of streaming endpoints, so a prober built on
     * HEAD reports live services as dead. This sends the GET the server
     * expects and stops listening once it has what it came for.
     *
     * <code>
     * // Is this stream up, and what is it serving?
     * $r = Client::get($streamUrl)->connectTimeout(2)->timeout(3)
     *     ->headersOnly()->send();
     * $r->status();               // 200
     * $r->header('content-type'); // 'audio/mpeg'
     * </code>
     *
     * Without it, an endpoint that never stops sending — an internet radio
     * stream, an SSE feed, a `tail -f` over HTTP — has only two endings, and
     * neither is the one the caller wanted: the timeout, which throws away a
     * status that arrived in milliseconds, or memory exhaustion, which takes
     * the process down. Three seconds of a fast stream measured a quarter of a
     * gigabyte.
     *
     * Has no effect on a faked response, which never reaches the network.
     */
    public function headersOnly(): static
    {
        $this->headersOnly = true;
        return $this;
    }

    /**
     * Read at most $bytes of the response body, then stop.
     *
     * **Reaching the ceiling is a normal outcome, not an error.** The response
     * arrives carrying what was read, and {@see ClientResponse::truncated()}
     * says so. That is what turns "the process died" into a value the caller
     * can act on.
     *
     * <code>
     * // Read the first 16 kB of an endless stream — enough for the ICY
     * // metadata block — and stop.
     * $r = Client::get($url)->header('Icy-MetaData', '1')
     *     ->maxResponseBytes(16 * 1024)->send();
     * $r->truncated();  // true — there was more, we did not want it
     * </code>
     *
     * Reading a bounded prefix is its own use, not an approximation of
     * {@see headersOnly()}: a caller that needs the headers *and* the first N
     * bytes has no other way to say so. If both are set, headersOnly wins and
     * no body is read.
     *
     * **There is no default ceiling**, and that is deliberate rather than an
     * oversight. A default would silently truncate every existing caller that
     * legitimately downloads something large, and a response that quietly
     * loses its tail is worse than one that fails loudly. Set the ceiling where
     * you know what the body should be.
     *
     * Has no effect on a faked response, which never reaches the network.
     *
     * @param int $bytes Maximum body bytes to keep. Negative is treated as 0.
     */
    public function maxResponseBytes(int $bytes): static
    {
        $this->maxBytes = max(0, $bytes);
        return $this;
    }

    // =========================================================================
    // Send
    // =========================================================================

    /**
     * Execute the request and return the response.
     *
     * @throws ClientException On connection/transport error, or on 4xx/5xx
     *                         when throwOnError() was set.
     */
    public function send(): ClientResponse
    {
        return $this->executeWithRetry($this->resolveUrl());
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function withMethod(string $method): static
    {
        $this->method = strtoupper($method);
        return $this;
    }

    private function withUrl(string $url): static
    {
        $this->url = $url;
        return $this;
    }

    /** Clone $this and set method + URL for a new request, preserving all other config. */
    private function newRequest(string $method, string $url): static
    {
        $clone              = clone $this;
        $clone->method      = strtoupper($method);
        $clone->url         = $url;
        $clone->body        = null;
        $clone->contentType = '';
        return $clone;
    }

    private function resolveUrl(): string
    {
        if ($this->baseUrl === '') {
            return $this->url;
        }
        // Absolute URL overrides base URL
        if (str_starts_with($this->url, 'http://') || str_starts_with($this->url, 'https://')) {
            return $this->url;
        }
        if ($this->url === '') {
            return $this->baseUrl;
        }
        return $this->baseUrl . '/' . ltrim($this->url, '/');
    }

    /** Match a URL against a glob pattern ('*' wildcard supported). */
    private function matchesPattern(string $url, string $pattern): bool
    {
        return $pattern === '*' || $pattern === $url || fnmatch($pattern, $url);
    }

    /**
     * Dispatch one request attempt — checks fakes first, then falls through to curl.
     *
     * Keeping fake resolution inside the retry loop ensures that callable fakes
     * are invoked once per attempt, which is necessary for tests that simulate
     * transient failures (e.g. two 500s then a 200).
     *
     * @throws ClientException On curl error.
     */
    private function dispatch(string $url): ClientResponse
    {
        if (!empty(static::$fakes)) {
            foreach (static::$fakes as $pattern => $fake) {
                if ($this->matchesPattern($url, $pattern)) {
                    return is_callable($fake) ? $fake($this) : $fake;
                }
            }
        }
        return $this->execute($url);
    }

    /**
     * Execute the request with the configured retry policy.
     *
     * - Connection errors (ClientException from curl) → retry
     * - 5xx responses → retry up to $retries times
     * - 4xx responses → returned immediately, no retry
     */
    private function executeWithRetry(string $url): ClientResponse
    {
        $lastException = null;
        $response      = null;

        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            if ($attempt > 0 && $this->retryDelayMs > 0) {
                // Exponential backoff: delayMs × 2^(attempt−1) converted to microseconds
                usleep((int) ($this->retryDelayMs * (2 ** ($attempt - 1)) * 1000));
            }

            try {
                $response = $this->dispatch($url);

                if ($response->serverError() && $attempt < $this->retries) {
                    continue; // retry on 5xx
                }

                if ($this->throwOnError) {
                    $response->throw();
                }
                return $response;

            } catch (ClientException $e) {
                $lastException = $e;
                // Connection error — continue to next attempt
            }
        }

        // All retries exhausted
        if ($lastException !== null) {
            throw $lastException;
        }

        // @codeCoverageIgnore — unreachable: throwOnError causes response->throw() inside the
        // loop which is caught by the catch block, setting $lastException (handled above).
        if ($this->throwOnError && $response !== null) { // @codeCoverageIgnore
            $response->throw(); // @codeCoverageIgnore
        } // @codeCoverageIgnore
        return $response; // @codeCoverageIgnore
    }

    /**
     * Make a single curl request and return the parsed response.
     *
     * Covered by integration tests, which fork a real socket server — see
     * tests/Integration/Http/ClientBodyCeilingTest.php and
     * ClientTransportTest.php. It carried @codeCoverageIgnore until the body
     * ceiling was added, on the grounds that it needed a live network endpoint;
     * a forked server is a live network endpoint, and the reasoning had been
     * excusing the least-tested method in the class.
     *
     * @throws ClientException On curl error.
     */
    private function execute(string $url): ClientResponse
    {
        $ch = curl_init();

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION,
            function ($ch, string $header) use (&$responseHeaders): int {
                $len  = strlen($header);
                $line = trim($header);
                if (str_starts_with($line, 'HTTP/')) {
                    // A new status line means a new response. With
                    // FOLLOWLOCATION on, curl reports the headers of every hop
                    // through this callback, and they used to accumulate — so a
                    // redirected request answered with its redirect's Location
                    // and Content-Type mixed into the final response's headers.
                    // Only the last response is the response.
                    $responseHeaders = [];
                    return $len;
                }
                if ($line === '') {
                    return $len;
                }
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return $len;
            }
        );

        // Body ceiling / early abort. cURL's documented way to stop a transfer
        // is a write callback that returns a short count, and CURLE_WRITE_ERROR
        // is therefore the *success* path here — see the errno handling below.
        $received  = '';
        $truncated = false;
        if ($this->headersOnly || $this->maxBytes !== null) {
            $limit      = $this->headersOnly ? 0 : (int) $this->maxBytes;
            $headersOnly = $this->headersOnly;
            $writer = function ($ch, string $chunk) use (
                &$received, &$truncated, $limit, $headersOnly
            ): int {
                $length = strlen($chunk);

                // A redirect's own body is not the response body. Swallow it so
                // curl can follow the Location rather than aborting on the first
                // byte of "301 Moved Permanently".
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($status >= 300 && $status < 400) {
                    return $length;
                }

                if ($headersOnly) {
                    $truncated = true;
                    return 0;
                }

                $remaining = $limit - strlen($received);
                if ($remaining <= 0) {
                    $truncated = true;
                    return 0;
                }
                if ($length > $remaining) {
                    $received .= substr($chunk, 0, $remaining);
                    $truncated = true;
                    return 0;
                }

                $received .= $chunk;
                return $length;
            };
        }

        $curlHeaders = ['User-Agent: ' . $this->userAgent];
        if ($this->contentType !== '') {
            $curlHeaders[] = 'Content-Type: ' . $this->contentType;
        }
        foreach ($this->headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
        ];

        if (isset($writer)) {
            $options[CURLOPT_WRITEFUNCTION] = $writer;
        }

        switch ($this->method) {
            case 'GET':
                $options[CURLOPT_HTTPGET] = true;
                break;
            case 'HEAD':
                $options[CURLOPT_NOBODY] = true;
                break;
            case 'POST':
                $options[CURLOPT_POST] = true;
                if ($this->body !== null) {
                    $options[CURLOPT_POSTFIELDS] = $this->body;
                }
                break;
            default: // PUT, PATCH, DELETE
                $options[CURLOPT_CUSTOMREQUEST] = $this->method;
                if ($this->body !== null) {
                    $options[CURLOPT_POSTFIELDS] = $this->body;
                }
                break;
        }

        curl_setopt_array($ch, $options);

        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // We stopped on purpose. curl reports our own short write as
        // CURLE_WRITE_ERROR, and $truncated is set only where this code returned
        // the short count — so a genuine write failure, which never sets it,
        // still raises.
        if ($truncated && $errno === CURLE_WRITE_ERROR) {
            return new ClientResponse($status, $received, $responseHeaders, true);
        }

        if ($errno !== 0 || $body === false) {
            throw new ClientException($error ?: 'curl request failed', $errno);
        }

        // With a write callback installed curl_exec() answers true rather than
        // the body, and the bytes are in $received.
        if (isset($writer)) {
            return new ClientResponse($status, $received, $responseHeaders, $truncated);
        }

        return new ClientResponse($status, (string) $body, $responseHeaders);
    }
}
