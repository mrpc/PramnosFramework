<?php

declare(strict_types=1);

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Refuses an API key whose application was not granted the scopes a route declares.
 *
 * `applications.scope` is the space-separated list an administrator fills in on the
 * application's screen. {@see ApiAuthMiddleware} decides whether a key is valid and never reads
 * it, so a key issued read-only could write: a field somebody fills in, a screen implying it
 * does something, and a request path that never looks. This is the path looking.
 *
 * ```php
 * $router->post('/api/v1/stations', [Stations::class, 'store'])
 *        ->middleware(new ApiKeyScopeMiddleware(['stations:write']));
 *
 * // or on a discovered route:
 * #[Route('/api/v1/stations', methods: 'POST', apiKeyScopes: ['stations:write'])]
 * ```
 *
 * Runs **after** `ApiAuthMiddleware`, which has already refused an invalid key. Every required
 * scope must be granted; a granted scope may be `*` or end in a wildcard (`stations:*`), as in
 * the router's own scope matching. The refusal is `403` with `error: insufficient_scope`, the
 * OAuth2 vocabulary.
 *
 * **Fails closed.** A key whose application lists no scopes has none, so it is refused on a
 * route that declares any — a route an application chose to protect does not open to keys
 * issued before it did. A route that declares none is unaffected.
 *
 * **The application itself is not a client.** A request with no key — the application's own
 * signed-in page, admitted by `ApiAuthMiddleware` on its session — and the site's own key
 * answer to no scope list, because they are not a third party being limited.
 */
class ApiKeyScopeMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $required;

    /**
     * @param list<string>  $required The scopes the route needs, all of them.
     * @param \Closure|null $scopesOf `fn (string $apiKey): ?array` — the key's granted scopes,
     *                                or null for a key that is the application itself. Defaults
     *                                to reading `applications.scope`.
     */
    public function __construct(array $required, private ?\Closure $scopesOf = null)
    {
        $this->required = array_values(array_filter(
            array_map('strval', $required),
            static fn (string $scope): bool => trim($scope) !== ''
        ));
    }

    /**
     * Pass the request through, or refuse it with `403 insufficient_scope`.
     */
    public function handle(Request $request, callable $next): mixed
    {
        $key = (string) ($_SERVER['HTTP_APIKEY'] ?? '');

        if ($this->required === [] || $key === '') {
            return $next($request);
        }

        $granted = ($this->scopesOf ?? self::grantedScopes(...))($key);
        if ($granted === null) {
            return $next($request);
        }

        $missing = array_values(array_filter(
            $this->required,
            static fn (string $scope): bool => !self::isGranted($scope, $granted)
        ));

        if ($missing === []) {
            return $next($request);
        }

        return $this->refuse($missing);
    }

    /**
     * The scopes the key's application was granted, or null when the key is the site's own.
     *
     * The site key is the one {@see \Pramnos\Application\Api::checkApiKey()} accepts without a
     * row: the hash of the site's address. A key with no application row is granted nothing.
     *
     * @return list<string>|null
     */
    public static function grantedScopes(string $apiKey): ?array
    {
        if (defined('sURL') && hash_equals(md5(str_replace('/api/', '/', (string) sURL)), $apiKey)) {
            return null;
        }

        $application = new \Pramnos\Application\Api\Apikey($apiKey);
        if ((int) $application->appid === 0) {
            return [];
        }

        return \Pramnos\User\Token::parseScopes((string) $application->scope);
    }

    /**
     * Whether one required scope is covered by the granted list.
     *
     * Exact, `*`, or a granted pattern with `*` in it — the router's matching, so a scope means
     * the same thing on a key as it does on a route.
     *
     * @param list<string> $granted
     */
    private static function isGranted(string $required, array $granted): bool
    {
        foreach ($granted as $scope) {
            if ($scope === $required || $scope === '*') {
                return true;
            }
            if (str_contains($scope, '*')
                && preg_match('/^' . str_replace('\*', '.*', preg_quote($scope, '/')) . '$/', $required) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The refusal, in the same envelope `ApiAuthMiddleware` answers with.
     *
     * @param list<string> $missing
     */
    private function refuse(array $missing): string
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(403);
        }

        return (string) json_encode([
            'status'        => 403,
            'statusmessage' => 'Forbidden',
            'message'       => 'This API key was not granted: ' . implode(' ', $missing) . '.',
            'error'         => 'insufficient_scope',
            'scope'         => implode(' ', $this->required),
        ]);
    }
}
