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
 * scope must be among the application's **Allowed Scopes**, by the one rule the token endpoint
 * applies too — {@see \Pramnos\Auth\Application::scopesBeyond()}: an exact match, and an empty
 * list restricts nothing, as the edit form says. The refusal is `403` with
 * `error: insufficient_scope`, the OAuth2 vocabulary. A route that declares none is unaffected.
 *
 * A key with no application row is refused outright: nothing says what it may do.
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
     * @param \Closure|null $scopesOf `fn (string $apiKey): array|null|false` — the key's Allowed
     *                                Scopes (empty: no restriction), null for a key that is the
     *                                application itself, false for a key nothing knows. Defaults
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

        $missing = $granted === false
            ? $this->required
            : \Pramnos\Auth\Application::scopesBeyond($granted, $this->required);

        if ($missing === []) {
            return $next($request);
        }

        return $this->refuse($missing);
    }

    /**
     * The key's Allowed Scopes, null when the key is the site's own, false when no row knows it.
     *
     * The site key is the one {@see \Pramnos\Application\Api::checkApiKey()} accepts without a
     * row: the hash of the site's address.
     *
     * @return list<string>|null|false
     */
    public static function grantedScopes(string $apiKey): array|null|false
    {
        if (defined('sURL') && hash_equals(md5(str_replace('/api/', '/', (string) sURL)), $apiKey)) {
            return null;
        }

        $application = new \Pramnos\Application\Api\Apikey($apiKey);
        if ((int) $application->appid === 0) {
            return false;
        }

        return \Pramnos\User\Token::parseScopes((string) $application->scope);
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
