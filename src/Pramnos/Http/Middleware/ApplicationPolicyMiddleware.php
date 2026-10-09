<?php

declare(strict_types=1);

namespace Pramnos\Http\Middleware;

use Pramnos\Auth\ApplicationSettings;
use Pramnos\Http\Request;
use Pramnos\Http\TokenBucket;

/**
 * The limits an administrator set for the application calling the API.
 *
 * Runs after {@see ApiAuthMiddleware}, once the API key has named the application, and applies
 * its {@see ApplicationSettings}, in this order:
 *
 * 1. `require_https` — a plain-HTTP request is refused, `403 HTTPSRequired`. A request from
 *    the machine itself (loopback) is exempt, which is a developer's own server.
 * 2. The IP lock — a blocked address, or one outside the allowed list while the lock is on,
 *    is refused, `403 AddressNotAllowed`.
 * 3. CORS — with `cors_enabled`, a browser request whose `Origin` is not on the list is
 *    refused, `403 OriginNotAllowed`. A request with no `Origin` is not a browser's, and passes.
 * 4. The rate limit — a {@see TokenBucket} per application: `rate_limit_burst` at once, then
 *    `rate_limit_requests` per `rate_limit_window_seconds`. Every answer carries
 *    `X-RateLimit-Limit` and `X-RateLimit-Remaining`; a refusal is `429 TooManyRequests` with
 *    `Retry-After`.
 *
 * A request with no application — the site's own key, a signed-in page, an open path — is not
 * limited here.
 */
class ApplicationPolicyMiddleware implements \Pramnos\Http\MiddlewareInterface
{
    /** @var array<string, string> The headers of the last decision, for tests and logs */
    public array $headers = [];

    /**
     * @param \Closure        $apiKey fn(): ?object — the API key the request was authenticated with
     * @param TokenBucket|null $bucket Where the rate limit is counted
     */
    public function __construct(
        private readonly \Closure $apiKey,
        private ?TokenBucket $bucket = null
    ) {
    }

    /**
     * Apply the application's limits, or pass the request on.
     */
    public function handle(Request $request, callable $next): mixed
    {
        $this->headers = [];
        $key   = ($this->apiKey)();
        $appId = is_object($key) ? (int) ($key->appid ?? 0) : 0;
        if ($appId <= 0) {
            return $next($request);
        }

        $settings = ApplicationSettings::for($appId);
        $address  = Request::clientIp('');

        if (ApplicationSettings::refusesTransport($settings, $address, $_SERVER)) {
            return $this->refuse(403, 'HTTPSRequired', 'This application\'s requests must use HTTPS.');
        }
        if (!ApplicationSettings::admitsAddress($settings, $address)) {
            return $this->refuse(403, 'AddressNotAllowed', 'This application does not accept requests from this address.');
        }
        $origin = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
        if ($origin !== '' && $settings['cors_enabled'] && !in_array($origin, $settings['cors_origins'], true)) {
            return $this->refuse(403, 'OriginNotAllowed', 'This application does not accept requests from this origin.');
        }

        $take = ($this->bucket ??= new TokenBucket())->take(
            'app:' . $appId,
            (int) $settings['rate_limit_burst'],
            (int) $settings['rate_limit_requests'],
            (int) $settings['rate_limit_window_seconds']
        );
        $this->header('X-RateLimit-Limit', (string) $settings['rate_limit_requests']);
        $this->header('X-RateLimit-Remaining', (string) $take['remaining']);
        if (!$take['allowed']) {
            $this->header('Retry-After', (string) $take['retry_after']);

            return $this->refuse(429, 'TooManyRequests', 'This application has made too many requests. Try again in '
                . $take['retry_after'] . ' seconds.');
        }

        return $next($request);
    }

    /** Record a header, and send it when there is a response to send it on. */
    private function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
        // @codeCoverageIgnoreStart — a web request only; $headers is what a test reads
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header($name . ': ' . $value);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * The API's error body, in the shape {@see ApiAuthMiddleware} answers with.
     */
    private function refuse(int $status, string $error, string $message): string
    {
        // @codeCoverageIgnoreStart — a web request only; the body carries the status
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code($status);
        }
        // @codeCoverageIgnoreEnd

        return (string) json_encode([
            'status'        => $status,
            'statusmessage' => $status === 429 ? 'Too Many Requests' : 'Forbidden',
            'message'       => $message,
            'error'         => $error,
        ]);
    }
}
