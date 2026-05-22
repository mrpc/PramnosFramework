<?php

declare(strict_types=1);

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Unified authentication middleware for API route groups.
 *
 * Accepts two credential types — Bearer token OR session cookie — so that
 * both first-party web clients (same-origin AJAX) and third-party API clients
 * (native apps, external services) can call the same API endpoints without
 * duplicate controllers.
 *
 * ## Auth resolution order
 *
 * 1. **`Authorization: Bearer <jwt>`** — verifies the JWT against `$authKey`
 *    (HS256) or an RSA public key (RS256).  On success the corresponding user
 *    is loaded from `usertokens` with explicit scopes.
 *
 * 2. **Session cookie + `X-CSRF-Token` header** — if `$_SESSION['usertoken']`
 *    is an active `Token::TYPE_WEB_SESSION` token and the CSRF token in the
 *    header matches the session, the user is considered authenticated with
 *    wildcard scopes (`['*']`).  The wildcard means "pass all scope checks at
 *    the routing layer" — the controller is still responsible for its own
 *    policy/usertype checks.
 *
 * 3. **No credentials** — 401 JSON error returned, pipeline short-circuits.
 *
 * ## Differences from ApiAuthMiddleware
 *
 * `ApiAuthMiddleware` also validates an API key (`HTTP_APIKEY`) which is
 * required for every request.  `UnifiedAuthMiddleware` does not require an
 * API key — it is designed for first-party (same-app) route groups where the
 * key requirement would be meaningless.
 *
 * ## Setup example
 *
 * ```php
 * $router->group([
 *     'prefix'     => '/api/v1',
 *     'middleware' => [
 *         new CorsMiddleware(['https://myapp.com']),
 *         new JsonResponseMiddleware(),
 *         new UnifiedAuthMiddleware(
 *             authKey:      $app->authenticationKey,
 *             appNamespace: $app->applicationInfo['namespace'] ?? null,
 *         ),
 *     ],
 * ], function (Router $r): void {
 *     $r->get('/profile', [ProfileController::class, 'show']);
 * });
 * ```
 *
 * @package    PramnosFramework
 * @subpackage Http\Middleware
 */
class UnifiedAuthMiddleware implements MiddlewareInterface
{
    /**
     * @param string      $authKey       HS256 HMAC key for JWT verification.
     * @param string|null $appNamespace  Application namespace for User class resolution.
     */
    public function __construct(
        private readonly string  $authKey      = '',
        private readonly ?string $appNamespace = null,
    ) {}

    public function handle(Request $request, callable $next): mixed
    {
        // --- Path 1: Bearer token ---
        $bearer = $this->extractBearer();
        if ($bearer !== null) {
            return $this->handleBearer($bearer, $request, $next);
        }

        // --- Path 2: Session cookie + X-CSRF-Token ---
        if ($this->hasValidSessionWithCsrf()) {
            return $this->handleSessionCookie($request, $next);
        }

        return $this->error(401, 'Unauthenticated', 'Authentication required.');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extract the Bearer token from the Authorization header.
     * Returns null when no Bearer credential is present.
     */
    private function extractBearer(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
            if ($token !== '') {
                return $token;
            }
        }
        // Some servers forward as REDIRECT_HTTP_AUTHORIZATION
        $redirectHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($redirectHeader, 'Bearer ')) {
            $token = substr($redirectHeader, 7);
            if ($token !== '') {
                return $token;
            }
        }
        return null;
    }

    /**
     * Validate the Bearer JWT, load the matching user, and continue the pipeline.
     */
    private function handleBearer(string $token, Request $request, callable $next): mixed
    {
        $decodeKey = $this->authKey;
        $tokenInfo = \Pramnos\Auth\JWT::getTokenInformation($token);

        if (!$tokenInfo) {
            return $this->error(401, 'InvalidToken', 'Invalid Bearer token.');
        }

        // RS256 tokens use the RSA public key file
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
                $token,
                $decodeKey,
                isset($tokenInfo->alg) && $tokenInfo->alg === 'RS256'
                    ? ['HS256', 'RS256']
                    : ['HS256']
            );
        } catch (\Exception $ex) {
            return $this->error(401, 'InvalidToken', 'Bearer token validation failed.',
                $ex->getMessage());
        }

        $user = $this->resolveUser();
        $user->loadByToken($token);
        if ($user->userid > 1) {
            $_SESSION['logged'] = true;
            $_SESSION['user']   = $user;
        } else {
            return $this->error(401, 'InvalidToken', 'Token not found or expired.');
        }

        return $next($request);
    }

    /**
     * Return true when a valid web-session token exists in the session AND
     * the X-CSRF-Token header matches the session CSRF token.
     */
    private function hasValidSessionWithCsrf(): bool
    {
        // Must have an active web-session token in the session
        if (!isset($_SESSION['usertoken']) || !is_object($_SESSION['usertoken'])) {
            return false;
        }
        /** @var \Pramnos\User\Token $tkn */
        $tkn = $_SESSION['usertoken'];
        if ($tkn->tokentype !== \Pramnos\User\Token::TYPE_WEB_SESSION) {
            return false;
        }
        if ((int) $tkn->status !== 1) {
            return false;
        }

        // Must have X-CSRF-Token header that matches the session CSRF token
        $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_SERVER['HTTP_X_XSRF_TOKEN']
            ?? '';
        if ($csrfHeader === '') {
            return false;
        }

        try {
            $session    = \Pramnos\Http\Session::getInstance();
            $csrfSess   = $session->getCsrfToken();
            // Constant-time comparison to avoid timing attacks
            return hash_equals($csrfSess, $csrfHeader);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Authenticate via session cookie — wildcard scopes.
     */
    private function handleSessionCookie(Request $request, callable $next): mixed
    {
        /** @var \Pramnos\User\Token $tkn */
        $tkn = $_SESSION['usertoken'];
        if (!isset($_SESSION['user']) || !is_object($_SESSION['user'])) {
            $user = $this->resolveUser($_SESSION['uid'] ?? null);
            if ($user->userid > 1) {
                $_SESSION['user']   = $user;
                $_SESSION['logged'] = true;
            }
        }

        // Wildcard scopes signal to the router that all scope checks pass.
        // The controller is still responsible for its own policy/usertype checks.
        $tkn->scope = ['*'];

        return $next($request);
    }

    /**
     * Instantiate the application User class (or fall back to the framework default).
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
     * Build a JSON error envelope and set the HTTP response code.
     *
     * @param int         $status  HTTP status code.
     * @param string      $error   Machine-readable error key.
     * @param string      $message Human-readable message.
     * @param string|null $detail  Optional extra detail (omitted in production).
     */
    private function error(int $status, string $error, string $message, ?string $detail = null): string
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code($status);
        }

        $payload = [
            'status'        => $status,
            'statusmessage' => match ($status) {
                401 => 'Unauthenticated',
                403 => 'Forbidden',
                default => 'Error',
            },
            'message' => $message,
            'error'   => $error,
        ];

        if ($detail !== null) {
            $payload['data'] = $detail;
        }

        return (string) json_encode($payload);
    }
}
