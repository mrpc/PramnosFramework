<?php

declare(strict_types=1);

namespace Pramnos\Http\Middleware;

/**
 * Recognising a request that came from the application's own signed-in page.
 *
 * A browser page has no API key and no bearer token — it has a session cookie,
 * which the browser also attaches to a cross-site request, which is why the
 * cookie alone is not an authentication signal. What a cross-site page cannot do
 * is read this origin's document and copy the CSRF token out of it, so the pair
 * — an active web-session token in the session, plus an `X-CSRF-Token` header
 * matching the session's — is the signal, and either half alone is not.
 *
 * Shared by {@see UnifiedAuthMiddleware}, whose session path this was, and
 * {@see ApiAuthMiddleware}, which needs the same answer for an application that
 * serves a website and its API from one origin.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
trait SameOriginSessionTrait
{
    /**
     * Does this request carry a live web session *and* prove it can read the page?
     */
    protected function hasValidSessionWithCsrf(): bool
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
            $session  = \Pramnos\Http\Session::getInstance();
            $csrfSess = $session->getCsrfToken();
            // Constant-time comparison to avoid timing attacks
            return hash_equals($csrfSess, $csrfHeader);
        } catch (\Throwable) {
            return false;
        }
    }
}
