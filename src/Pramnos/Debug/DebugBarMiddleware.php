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
 * **The page is the job; the toolbar is a decoration.** Anything that goes wrong
 * while decorating gives the response back exactly as it arrived. That rule is
 * here because of the worst-shaped failure this project has produced: a 200 with
 * an empty body. It passes every status check, logs nothing, and looks to a
 * person like a broken front-end build — one development environment spent a day
 * on it. A missing toolbar is a bug report; a missing page is a phone call.
 *
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

        try {
            $decorated = $this->inject($response);
        } catch (\Throwable) {
            // Rendering the toolbar reads collectors, the session and the
            // container — all of which can throw for reasons that have nothing
            // to do with the page that is ready to be sent.
            return $response;
        }

        // Never hand back less than arrived. A decoration that produced an empty
        // or truncated body is a decoration that failed, whatever it thinks.
        return strlen($decorated) >= strlen($response) ? $decorated : $response;
    }

    /**
     * The response with the widget in it, or unchanged when there is nowhere to
     * put it.
     *
     * @param  string $response
     * @return string
     */
    private function inject(string $response): string
    {
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
