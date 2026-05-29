<?php

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;
use Pramnos\Http\Session;

/**
 * Rejects unauthenticated requests with a 401 exception.
 *
 * Usage — per route:
 *   $router->get('/dashboard', [DashboardController::class, 'index'])
 *          ->middleware(new AuthMiddleware());
 *
 * Usage — in controller constructor:
 *   $this->addMiddleware('*', new AuthMiddleware());
 *   $this->addMiddleware(['edit', 'delete'], new AuthMiddleware());
 *
 * The exception (code 401) is caught by Application::exec() which renders
 * the appropriate error page or JSON response depending on the document type.
 *
 */
class AuthMiddleware implements MiddlewareInterface
{
    /**
     * @param  string $redirectTo  If non-empty, redirect here instead of throwing.
     */
    public function __construct(private string $redirectTo = '') {}

    public function handle(Request $request, callable $next): mixed
    {
        $session = Session::getInstance();

        if (!$session->isLogged()) {
            if ($this->redirectTo !== '') {
                // Throw RedirectException — Application::exec() catches it and
                // performs the actual header()/exit, keeping this method testable.
                throw new \Pramnos\Http\RedirectException($this->redirectTo, 302);
            }
            throw new \Exception('Authentication required.', 401);
        }

        return $next($request);
    }
}
