<?php

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Rate-limits requests per client IP using APCu as a sliding-window counter.
 *
 * Requires the APCu PHP extension. When APCu is unavailable, the middleware
 * passes through without limiting (graceful degradation — log a warning in
 * production if you depend on this).
 *
 * Usage — global throttle for all API routes:
 *   $router->addGlobalMiddleware(new ThrottleMiddleware(maxRequests: 120, perSeconds: 60));
 *
 * Usage — stricter limit on expensive endpoints:
 *   $router->post('/api/export', fn() => ...)
 *          ->middleware(new ThrottleMiddleware(maxRequests: 5, perSeconds: 60));
 *
 * Usage — custom key prefix to isolate counters per route group:
 *   new ThrottleMiddleware(60, 60, keyPrefix: 'api:')
 *
 * When the limit is exceeded, throws an Exception with code 429.
 * Application::exec() renders this as a 429 response.
 *
 * @package    PramnosFramework
 * @subpackage Http\Middleware
 */
class ThrottleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private int    $maxRequests = 60,
        private int    $perSeconds  = 60,
        private string $keyPrefix   = 'throttle:'
    ) {}

    public function handle(Request $request, callable $next): mixed
    {
        if (function_exists('apcu_fetch')) {
            $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $key = $this->keyPrefix . md5($ip);

            $count = apcu_fetch($key);

            if ($count === false) {
                apcu_store($key, 1, $this->perSeconds);
            } elseif ($count >= $this->maxRequests) {
                header('Retry-After: ' . $this->perSeconds);
                throw new \Exception(
                    'Too many requests. Please slow down.',
                    429
                );
            } else {
                apcu_inc($key);
            }
        }

        return $next($request);
    }
}
