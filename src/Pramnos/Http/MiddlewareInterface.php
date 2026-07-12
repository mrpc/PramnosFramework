<?php

namespace Pramnos\Http;

/**
 * Contract for HTTP middleware.
 *
 * A middleware wraps a request/action pair. It may inspect or mutate the
 * request, short-circuit the pipeline by returning early, or delegate to the
 * next handler via $next($request).
 *
 */
interface MiddlewareInterface
{
    /**
     * Handle an incoming request.
     *
     * Call $next($request) to pass control to the next middleware (or the
     * final action). Return without calling $next to short-circuit the pipeline.
     *
     * @param  \Pramnos\Http\Request $request
     * @param  callable(\Pramnos\Http\Request):mixed $next
     * @return mixed
     */
    public function handle(\Pramnos\Http\Request $request, callable $next): mixed;
}
