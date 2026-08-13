<?php

declare(strict_types=1);

namespace Pramnos\Debug;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;
use Pramnos\Http\Response;

/**
 * Attaches this request's debug data to a JSON response, and sends the debug
 * headers.
 *
 * The framework's own API layer does this inside `Application\Api`. An application
 * that routes `#[Route]` attributes to controllers returning `Response::json()` —
 * a style the framework supports and the SPA scaffolding assumes — never goes near
 * that class, so it got no `_debug` payload at all. Every such project wrote the
 * same thirty lines: decode the body, refuse a top-level list, merge the key, set
 * the header. Each one also got to rediscover, from an empty panel, that a JSON
 * *array* has nowhere to put the key.
 *
 * One line in the global stack covers every routing style:
 *
 * ```php
 * $pipeline->add(new \Pramnos\Debug\ApiDebugMiddleware());
 * ```
 *
 * Inert outside development: {@see ApiDebugPayload::isEnabled()} asks the toolbar
 * whether any collector is registered, and collectors are registered only in debug
 * mode. In production this is one array check per request.
 *
 * The rule about *which* bodies can carry the key lives in
 * {@see ApiDebugPayload::attachTo()} and nowhere else — a body that is not a JSON
 * object (a top-level array, a plain string, HTML, or one that already has a
 * `_debug` key) is returned untouched. Mangling a response to annotate it would be
 * worse than not annotating it.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license     MIT
 */
class ApiDebugMiddleware implements MiddlewareInterface
{
    /**
     * @param  Request  $request
     * @param  callable $next
     * @return mixed The response, annotated where it can be
     */
    public function handle(Request $request, callable $next): mixed
    {
        $response = $next($request);

        // Headers first, and for every response type. They are the only channel
        // that works for a 204, a redirect or an HTML fragment — the ordinary
        // shapes of the calls a page makes after it has rendered. Sending them is
        // idempotent; ApiDebugPayload keeps them from going out twice.
        ApiDebugPayload::sendHeaders();

        if ($response instanceof Response) {
            $body = $response->getBody();
            $annotated = ApiDebugPayload::attachTo($body);

            return $annotated === $body ? $response : $response->withBody($annotated);
        }

        if (is_string($response)) {
            return ApiDebugPayload::attachTo($response);
        }

        // A controller that echoed its own output, or returned null after sending
        // headers itself. There is nothing here to annotate, and the response has
        // its headers.
        return $response;
    }
}
