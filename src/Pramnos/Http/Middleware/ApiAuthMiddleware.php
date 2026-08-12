<?php

declare(strict_types=1);

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Validates the API key (HTTP_APIKEY header) and optional Bearer / Access Token
 * (HTTP_ACCESSTOKEN header) on every API request.
 *
 * On success the middleware calls `$next`. On failure it returns a JSON-encoded
 * error envelope and does NOT call `$next` — short-circuiting the pipeline.
 *
 * ## Setup in an API group
 *
 * ```php
 * $router->group([
 *     'prefix'     => '/api/v1',
 *     'middleware' => [
 *         new CorsMiddleware(),
 *         new JsonResponseMiddleware(),
 *         new ApiAuthMiddleware(
 *             apiKeyChecker: fn(string $k) => $app->checkApiKey($k),
 *             authKey:       $app->authenticationKey,
 *             appNamespace:  $app->applicationInfo['namespace'] ?? null,
 *         ),
 *     ],
 * ], function (Router $r): void {
 *     $r->get('/users', [UsersController::class, 'index']);
 * });
 * ```
 *
 * ## Session side-effects
 *
 * When a valid `HTTP_ACCESSTOKEN` JWT is presented, the middleware sets:
 * - `Application::$currentUser` — the identity of *this request*, and nothing
 *   longer-lived. {@see \Pramnos\User\User::getCurrentUser()} reads it first.
 *
 * It deliberately writes **no session**. An application that serves a website
 * and an API from one origin shares a session cookie between them, so an API
 * call authenticated as one user used to change who the browser's next page
 * belonged to — and an anonymous API call used to sign the browser out, because
 * the anonymous branch destroyed the session instead of ignoring it.
 *
 * This mirrors the behaviour of `Api::exec()` so that controllers reading
 * the session work the same way.
 *
 */
class ApiAuthMiddleware implements MiddlewareInterface
{
    /**
     * @param callable    $apiKeyChecker  fn(string $key): bool — returns true for a valid API key.
     * @param string      $authKey        Symmetric HMAC key for HS256 JWT verification.
     * @param string|null $appNamespace   Application namespace used to resolve a custom User class.
     */
    public function __construct(
        private readonly mixed   $apiKeyChecker,
        private readonly string  $authKey       = '',
        private readonly ?string $appNamespace  = null,
    ) {}

    public function handle(Request $request, callable $next): mixed
    {
        // --- API key check ---
        if (empty($_SERVER['HTTP_APIKEY'])) {
            return $this->error(403, 'APIKeyMissing', 'API key is missing.');
        }

        if (!($this->apiKeyChecker)($_SERVER['HTTP_APIKEY'])) {
            return $this->error(401, 'APIKeyInvalid', 'Invalid API key.');
        }

        // --- Bearer / Access Token auth (optional) ---
        // Request::accessToken() takes the framework's accessToken header first
        // and falls back to a standard "Authorization: Bearer …", so a client
        // that only knows the RFC header authenticates instead of silently
        // being treated as anonymous.
        $tkn = Request::accessToken();
        if ($tkn !== null) {

            $user = $this->resolveUser();

            // Read RSA public key if the token header indicates RS256
            $decodeKey  = $this->authKey;
            $tokenInfo  = \Pramnos\Auth\JWT::getTokenInformation($tkn);

            if (!$tokenInfo) {
                return $this->error(403, 'InvalidAccessToken', 'Invalid Access Token.',
                    'Token information could not be retrieved.');
            }

            if (isset($tokenInfo->alg) && $tokenInfo->alg === 'RS256') {
                foreach ([ROOT . '/app/keys/public.key', ROOT . '/keys/public.key'] as $path) {
                    if (file_exists($path)) {
                        $decodeKey = file_get_contents($path);
                        break;
                    }
                }
            }

            try {
                \Pramnos\Auth\JWT::$leeway = 60;
                \Pramnos\Auth\JWT::decode(
                    $tkn,
                    $decodeKey,
                    isset($tokenInfo->alg) && $tokenInfo->alg === 'RS256'
                        ? ['HS256', 'RS256']
                        : ['HS256']
                );
            } catch (\Exception $ex) {
                return $this->error(403, 'InvalidAccessToken', 'Invalid Access Token.',
                    $ex->getMessage());
            }

            $user->loadByToken($tkn);
            if ($user->userid > 1) {
                // The identity of *this request*, and nowhere else.
                //
                // This used to write $_SESSION['logged'] and ['user'], which in
                // an application serving both a website and an API from one
                // origin is a cross-wire: the two share a session cookie, so an
                // API call authenticated as one user changed who the browser's
                // next page belonged to. User::getCurrentUser() now reads this
                // first, so a token identifies its own request without touching
                // anything the website relies on.
                $this->setRequestUser($user);
            } else {
                $this->setRequestUser(null);
                return $this->error(403, 'InvalidAccessToken', 'Invalid Access Token.');
            }

        } elseif (!empty($_SERVER['HTTP_USERAUTH'])) {
            // @deprecated since v1.2 — sending the password hash as HTTP_USERAUTH is insecure.
            // Use UnifiedAuthMiddleware with session-cookie + X-CSRF-Token instead (Phase 16).
            if (isset($_SESSION['logged'], $_SESSION['auth'], $_SESSION['uid'])
                && $_SESSION['logged'] === true
                && $_SESSION['auth'] === $_SERVER['HTTP_USERAUTH']) {
                // Reads the session — that is what this deprecated header is —
                // but still publishes the result as the request's identity
                // rather than writing back into it.
                $this->setRequestUser($this->resolveUser($_SESSION['uid']), 'userAuth');
            }
        } else {
            // No access token (and no legacy user-auth) presented. The REST API
            // is stateless: a request without a token is ANONYMOUS, and a
            // same-domain web-login cookie must never authenticate it.
            //
            // That is achieved by leaving this request without an identity —
            // *not* by destroying the session, which is what used to happen. In
            // an application that serves a website from the same origin, a
            // single anonymous API call (a widget polling, an unauthenticated
            // status check) signed the user out of the website. The session is
            // the browser's; the API only declines to read it.
            $this->setRequestUser(null);
        }

        return $next($request);
    }

    /**
     * Instantiate the User class — resolves the application-namespace override
     * when available, otherwise falls back to `\Pramnos\User\User`.
     *
     * @param mixed $userid Optional user ID passed to the constructor.
     */
    private function resolveUser(mixed $userid = null): \Pramnos\User\User
    {
        if ($this->appNamespace !== null && $this->appNamespace !== '') {
            $class = '\\' . $this->appNamespace . '\\User';
            if (class_exists($class)) {
                return $userid !== null ? new $class($userid) : new $class();
            }
        }
        return $userid !== null
            ? new \Pramnos\User\User($userid)
            : new \Pramnos\User\User();
    }

    /**
     * Build a JSON-encoded API error envelope and set the HTTP response code.
     *
     * @param int         $status  HTTP status code.
     * @param string      $error   Machine-readable error key.
     * @param string      $message Human-readable message.
     * @param string|null $data    Optional extra detail.
     */
    private function error(int $status, string $error, string $message, ?string $data = null): string
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code($status);
        }

        $payload = [
            'status'        => $status,
            'statusmessage' => $this->statusText($status),
            'message'       => $message,
            'error'         => $error,
        ];

        // The detail is whatever the JWT library said, which describes the
        // token rather than the caller's mistake. Useful while developing,
        // needless information for anyone else — an unauthenticated caller has
        // already been told everything they are entitled to: it did not work.
        if ($data !== null && $this->isDebugging()) {
            $payload['data'] = $data;
        }

        return (string) json_encode($payload);
    }

    /**
     * Is this environment one where internal detail may be shown?
     *
     * The same signal the framework's exception handler uses for its own
     * decision — one definition of "developing", not a second opinion.
     */
    private function isDebugging(): bool
    {
        if (defined('DEVELOPMENT') && DEVELOPMENT === true) {
            return true;
        }
        $env = getenv('APP_DEBUG');

        return $env !== false && $env !== '' && $env !== '0' && $env !== 'false';
    }

    private function statusText(int $code): string
    {
        return match ($code) {
            401 => 'Authentication failure',
            403 => 'Forbidden',
            default => 'Error',
        };
    }
    /**
     * Publish the identity of this request.
     *
     * Request-scoped by design: `Application::$currentUser` lives as long as the
     * request does, so nothing here outlives the call it describes.
     *
     * @param mixed $user A User instance, or null for anonymous
     */
    protected function setRequestUser(mixed $user, string $via = 'accessToken'): void
    {
        $user = is_object($user) ? $user : null;

        // Sealed, not merely set. Setting says "this is the user"; sealing also
        // says "and no cookie may answer instead" — which is the part that makes
        // an anonymous API call anonymous even when the same browser is signed
        // in to the website on the same domain.
        \Pramnos\Http\RequestIdentity::seal($user, $via);

        $app = \Pramnos\Application\Application::getInstance();
        if ($app) {
            $app->currentUser = $user;
        }
    }

}
