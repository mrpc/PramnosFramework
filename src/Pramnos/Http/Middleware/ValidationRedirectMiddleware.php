<?php

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Validation\ValidationException;

/**
 * Turns a failed `Request::validate()` into a redirect back to the form.
 *
 * The Validation guide describes this behaviour as something the framework does for
 * you: a `ValidationException` flashes the errors and the submitted input into the
 * session and redirects, so the form can redraw itself with both. That is true, and
 * it lives **inside `Application::exec()`** — which an application routing with
 * `Router::dispatch()` never calls.
 *
 * So a router-dispatched application got an uncaught exception where the guide
 * promised a redirect, and the sentence explaining that was nowhere. It is the same
 * shape as `ApiDebugMiddleware`: a capability locked inside the MVC kernel, needing
 * one line to be reachable from outside it.
 *
 * ```php
 * $router->addGlobalMiddleware(new ValidationRedirectMiddleware());
 * ```
 *
 * With that in the pipeline, a controller can validate and forget:
 *
 * ```php
 * $data = (new Request())->validate(['email' => 'required|email'], [], [], 'POST');
 * ```
 *
 * and the template reads `$this->errors` and `$request->old('email')` exactly as it
 * would under `Application::exec()`.
 *
 * ## Session keys
 *
 * It writes `_validation_errors` and `_old_input` — the keys `View::__construct()`
 * exposes as `$this->errors` and `Request::old()` reads. `FormRequest::failWith()`
 * writes a **different** pair (`_form_errors`, `_form_old_input`) readable only
 * through `FormRequest`'s own statics, so a view using `$this->errors` sees nothing
 * after a `FormRequest` failure. Pick one convention per form; this middleware
 * implements the one the `View` already understands.
 *
 * ## Where it redirects
 *
 * `HTTP_REFERER`, falling back to `URL`. That is what `Application::exec()` does, and
 * it is worth knowing rather than discovering: a POST arriving without a referer —
 * some privacy tooling strips it — bounces to the site root instead of back to the
 * form. Pass a path to the constructor when a form has one place it should always
 * return to.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class ValidationRedirectMiddleware implements MiddlewareInterface
{
    /**
     * Where to send the browser back to, or empty to use the referer.
     *
     * @var string
     */
    private string $target;

    /**
     * @param string $target Redirect here instead of `HTTP_REFERER`. Use this when a
     *                       form has one address it should always return to — it also
     *                       removes the referer-less case, where the default sends the
     *                       visitor to the site root.
     */
    public function __construct(string $target = '')
    {
        $this->target = $target;
    }

    /**
     * The address a failed submission goes back to.
     *
     * @return string
     */
    private function redirectTarget(): string
    {
        if ($this->target !== '') {
            return $this->target;
        }

        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            return $referer;
        }

        // `URL` is checked for content, not only for existence. `Application::exec()`
        // writes `$_SERVER['HTTP_REFERER'] ?? URL`, which sends the browser to the
        // empty string when the constant is defined and empty — a redirect to nowhere
        // is a worse outcome than the uncaught exception this replaced, so it falls
        // through to the site root instead.
        $base = defined('URL') ? (string) URL : '';

        return $base !== '' ? $base : '/';
    }

    /**
     * Catch a validation failure, flash it, and redirect.
     *
     * @param  Request  $request The incoming request
     * @param  callable $next    The rest of the pipeline
     * @return mixed
     */
    public function handle(Request $request, callable $next): mixed
    {
        try {
            return $next($request);
        } catch (ValidationException $exception) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                // Read back by the page being redirected to, so it needs a session to
                // survive in — a failed form that redirects to a page with no errors on
                // it looks like the form silently succeeded.
                \Pramnos\Http\Session::getInstance()->ensureStarted();
                $_SESSION['_validation_errors'] = $exception->errors();
                $_SESSION['_old_input']         = $request->allCurrent();
            }

            return Response::redirect($this->redirectTarget());
        }
    }
}
