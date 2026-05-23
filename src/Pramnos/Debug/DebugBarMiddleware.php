<?php

declare(strict_types=1);

namespace Pramnos\Debug;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Injects the DebugBar HTML widget before `</body>` in HTML responses.
 *
 * Only activates when:
 *   - The response is a non-empty string
 *   - The response contains `</body>` (i.e., it is an HTML page)
 *
 * JSON API responses and redirects are passed through untouched.
 *
 * @package PramnosFramework
 */
class DebugBarMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly DebugBar $debugBar) {}

    public function handle(Request $request, callable $next): mixed
    {
        $response = $next($request);

        if (!is_string($response) || $response === '') {
            return $response;
        }

        $bodyPos = strripos($response, '</body>');
        if ($bodyPos === false) {
            return $response;
        }

        $widget = $this->debugBar->render();
        if ($widget === '') {
            return $response;
        }

        return substr($response, 0, $bodyPos) . $widget . substr($response, $bodyPos);
    }
}
