<?php

namespace Pramnos\Http;

/**
 * Executes a stack of middleware around a final action.
 *
 * Usage:
 *   $result = (new MiddlewarePipeline())
 *       ->pipe(new LoggingMiddleware())
 *       ->pipe(new AuthMiddleware())
 *       ->run($request, fn($req) => $controller->action());
 *
 * Middleware is called in registration order: first pipe()d = outermost = runs first.
 * Pass a FQCN string for lazy instantiation (no constructor args).
 *
 * @package    PramnosFramework
 * @subpackage Http
 */
class MiddlewarePipeline
{
    /** @var array<MiddlewareInterface|class-string> */
    private array $middlewares = [];

    /**
     * Append a middleware to the pipeline.
     *
     * @param  MiddlewareInterface|class-string $middleware Instance or FQCN.
     * @return static
     */
    public function pipe(MiddlewareInterface|string $middleware): static
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Build and execute the pipeline.
     *
     * @param  \Pramnos\Http\Request              $request
     * @param  callable(\Pramnos\Http\Request):mixed $destination The final action.
     * @return mixed
     */
    public function run(\Pramnos\Http\Request $request, callable $destination): mixed
    {
        // Build the chain from innermost (destination) outward using fold-right.
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            function (callable $carry, MiddlewareInterface|string $middleware): callable {
                return function (\Pramnos\Http\Request $req) use ($carry, $middleware): mixed {
                    $instance = is_string($middleware) ? new $middleware() : $middleware;
                    return $instance->handle($req, $carry);
                };
            },
            $destination
        );

        return $pipeline($request);
    }
}
