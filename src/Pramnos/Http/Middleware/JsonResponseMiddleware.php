<?php

declare(strict_types=1);

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Sets the response Content-Type to application/json (or application/xml when
 * the client sends `Accept: application/xml`).
 *
 * Intended for API route groups so every response carries the correct MIME type
 * without controllers having to call `header()` manually.
 *
 * ```php
 * $router->group([
 *     'prefix'     => '/api/v1',
 *     'middleware' => [CorsMiddleware::class, JsonResponseMiddleware::class],
 * ], function (Router $r): void {
 *     $r->get('/users', [UsersController::class, 'index']);
 * });
 * ```
 *
 */
class JsonResponseMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        if ($accept === 'application/xml' || $accept === 'xml') {
            header('Content-Type: application/xml; charset=utf-8');
        } else {
            header('Content-Type: application/json; charset=utf-8');
        }

        return $next($request);
    }
}
