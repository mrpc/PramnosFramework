<?php

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;
use Pramnos\Http\Session;

/**
 * Synchronizer-token CSRF protection middleware.
 *
 * Validates the CSRF token on state-changing HTTP methods (POST, PUT, PATCH,
 * DELETE). Safe methods (GET, HEAD, OPTIONS, TRACE) pass through untouched.
 *
 * Token sources checked in order:
 *   1. POST field (default name: '_csrf_token', configurable via constructor)
 *   2. X-CSRF-Token request header (for AJAX/fetch requests)
 *
 * The token is the synchronizer token stored in the session by Session::getCsrfToken().
 * Comparison uses hash_equals() (timing-safe).
 *
 * Usage — per-route:
 *   $router->post('/transfer', fn() => ...)
 *          ->middleware(new CsrfMiddleware());
 *
 * Usage — global (protects all state-changing routes):
 *   $router->addGlobalMiddleware(new CsrfMiddleware());
 *
 * Usage — in templates (emit the hidden field):
 *   echo CsrfMiddleware::tokenField();
 *   // <input type="hidden" name="_csrf_token" value="…" />
 *
 * Usage — AJAX (read the token):
 *   <meta name="csrf-token" content="<?= CsrfMiddleware::token() ?>">
 *   // then send it as the X-CSRF-Token header
 *
 * When the token is missing or invalid, throws an Exception with code 419.
 *
 * @package    PramnosFramework
 * @subpackage Http\Middleware
 */
class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    public function __construct(
        private string $fieldName = '_csrf_token'
    ) {}

    public function handle(Request $request, callable $next): mixed
    {
        if (in_array(strtoupper($request->getRequestMethod()), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $session   = Session::getInstance();
        $submitted = $_POST[$this->fieldName]
                  ?? $_SERVER['HTTP_X_CSRF_TOKEN']
                  ?? null;

        if ($submitted === null || !$session->verifyCsrfToken($submitted)) {
            throw new \Exception('CSRF token mismatch.', 419);
        }

        return $next($request);
    }

    /**
     * Return the current session's CSRF token string.
     * Embed it in a <meta> tag or pass it to JavaScript for AJAX use.
     */
    public static function token(): string
    {
        return Session::getInstance()->getCsrfToken();
    }

    /**
     * Return an HTML hidden input containing the CSRF token.
     * Drop this inside every HTML form that submits via POST/PUT/PATCH/DELETE.
     */
    public static function tokenField(string $fieldName = '_csrf_token'): string
    {
        $token = static::token();
        $name  = htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8');
        $value = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="' . $name . '" value="' . $value . '" />';
    }
}
