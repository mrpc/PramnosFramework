<?php

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Sets CORS response headers and handles OPTIONS preflight requests.
 *
 * Usage — globally for all API routes (in ServiceProvider::boot()):
 *   $router->addGlobalMiddleware(new CorsMiddleware(
 *       allowedOrigins: ['https://app.example.com', 'https://admin.example.com']
 *   ));
 *
 * Usage — wildcard (allow any origin, e.g. public API):
 *   $router->addGlobalMiddleware(new CorsMiddleware());  // defaults to ['*']
 *
 * Usage — per route:
 *   $router->get('/api/status', fn() => ...)->middleware(new CorsMiddleware());
 *
 * Preflight (OPTIONS) requests are answered with 204 and do not reach the action.
 *
 * @package    PramnosFramework
 * @subpackage Http\Middleware
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array $allowedOrigins = ['*'],
        private array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With'],
        private bool  $allowCredentials = false,
        private int   $maxAge = 86400
    ) {}

    public function handle(Request $request, callable $next): mixed
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array('*', $this->allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '' && in_array($origin, $this->allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
        header('Access-Control-Max-Age: ' . $this->maxAge);

        if ($this->allowCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }

        // Preflight — answer here, do not run the action.
        if ($request->getRequestMethod() === 'OPTIONS') {
            header('HTTP/1.1 204 No Content');
            return '';
        }

        return $next($request);
    }
}
