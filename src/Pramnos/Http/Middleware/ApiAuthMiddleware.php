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
 * - `$_SESSION['logged'] = true`
 * - `$_SESSION['user']   = <User instance>`
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
        if (!empty($_SERVER['HTTP_ACCESSTOKEN'])
            && trim($_SERVER['HTTP_ACCESSTOKEN']) !== '') {

            $user = $this->resolveUser();
            $tkn  = $_SERVER['HTTP_ACCESSTOKEN'];

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
                $_SESSION['logged'] = true;
                $_SESSION['user']   = $user;
            } else {
                $_SESSION['user'] = null;
                return $this->error(403, 'InvalidAccessToken', 'Invalid Access Token.');
            }

        } elseif (!empty($_SERVER['HTTP_USERAUTH'])) {
            // @deprecated since v1.2 — sending the password hash as HTTP_USERAUTH is insecure.
            // Use UnifiedAuthMiddleware with session-cookie + X-CSRF-Token instead (Phase 16).
            if (isset($_SESSION['logged'], $_SESSION['auth'], $_SESSION['uid'])
                && $_SESSION['logged'] === true
                && $_SESSION['auth'] === $_SERVER['HTTP_USERAUTH']) {
                $user = $this->resolveUser($_SESSION['uid']);
                $_SESSION['user'] = $user;
            }
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

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return (string) json_encode($payload);
    }

    private function statusText(int $code): string
    {
        return match ($code) {
            401 => 'Authentication failure',
            403 => 'Forbidden',
            default => 'Error',
        };
    }
}
