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
 *   <meta name="csrf-token" content="<?php echo CsrfMiddleware::token(); ?>">
 *   // then send it as the X-CSRF-Token header
 *
 * When the token is missing or invalid, throws an Exception with code 419.
 *
 */
class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /**
     * Paths that must accept a POST from somewhere that cannot hold a session token.
     *
     * `unsubscribe` is here by default, and it is not an oversight to be tidied away. RFC 8058
     * one-click means a *mailbox provider's* server POSTs to that URL on the reader's behalf —
     * Gmail has no session with this site and no token to send, so a CSRF check there rejects
     * every unsubscribe a provider makes and the sender is judged on the failure. The signed
     * token in the URL is that endpoint's defence, and it is a better one for the purpose: an
     * attacker who cannot mint one cannot unsubscribe anybody, with or without a session.
     *
     * @var list<string>
     */
    private const DEFAULT_EXEMPT = ['unsubscribe'];

    /**
     * @param string       $fieldName   POST field carrying the token
     * @param list<string> $exemptPaths First URL segments to let through, in addition to
     *                                  {@see DEFAULT_EXEMPT}
     */
    public function __construct(
        private string $fieldName = '_csrf_token',
        private array $exemptPaths = []
    ) {}

    public function handle(Request $request, callable $next): mixed
    {
        if (in_array(strtoupper($request->getRequestMethod()), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        if ($this->isExempt()) {
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
     * Is this request's first path segment exempt?
     *
     * The first segment only, matched exactly: `unsubscribe` must not also exempt
     * `unsubscribe-everything` or an application route that merely starts with those letters.
     */
    private function isExempt(): bool
    {
        $path    = trim((string) Request::$requestUri, '/');
        $segment = strtolower(explode('/', explode('?', $path)[0])[0]);

        if ($segment === '') {
            return false;
        }

        return in_array($segment, self::DEFAULT_EXEMPT, true)
            || in_array($segment, array_map('strtolower', $this->exemptPaths), true);
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

    /**
     * Return an HTML <meta> tag with the CSRF token.
     *
     * Place this inside <head>. JavaScript reads it via:
     *   document.querySelector('meta[name="csrf"]').content
     * and sends it as the X-CSRF-Token header on AJAX requests.
     *
     * Compatible with UnifiedAuthMiddleware session-cookie auth path (Phase 16).
     */
    public static function csrfMeta(string $metaName = 'csrf'): string
    {
        $name  = htmlspecialchars($metaName, ENT_QUOTES, 'UTF-8');
        $value = htmlspecialchars(static::token(), ENT_QUOTES, 'UTF-8');
        return '<meta name="' . $name . '" content="' . $value . '" />';
    }
}
