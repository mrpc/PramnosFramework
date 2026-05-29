<?php

declare(strict_types=1);

namespace Pramnos\Http\Psr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware pipeline.
 *
 * Executes a stack of `MiddlewareInterface` layers around a final
 * `RequestHandlerInterface`.  Layers are processed in FIFO order
 * (first added = outermost wrapper).
 *
 * ## Usage
 *
 * ```php
 * $response = (new Pipeline())
 *     ->pipe(new AuthMiddleware())
 *     ->pipe(new CsrfMiddleware())
 *     ->process($request, $finalHandler);
 * ```
 *
 * The pipeline itself implements `MiddlewareInterface`, so pipelines can
 * be nested.
 *
 * @see         https://www.php-fig.org/psr/psr-15/
 */
class Pipeline implements MiddlewareInterface
{
    /** @var MiddlewareInterface[] */
    private array $middleware = [];

    /**
     * Add a middleware layer to the end of the pipeline.
     *
     * @return static fluent interface
     */
    public function pipe(MiddlewareInterface $middleware): static
    {
        $clone = clone $this;
        $clone->middleware[] = $middleware;
        return $clone;
    }

    /**
     * Process the request through all middleware layers, then hand off to
     * the final handler.
     *
     * Implements `MiddlewareInterface::process()` so that a Pipeline can be
     * used as a middleware inside another pipeline.
     */
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        return $this->buildRunner($this->middleware, $handler)->handle($request);
    }

    // -------------------------------------------------------------------------

    /**
     * Wrap the middleware stack around the final handler using an anonymous
     * RequestHandlerInterface chain (recursive, innermost first).
     *
     * @param  MiddlewareInterface[]    $layers
     */
    private function buildRunner(array $layers, RequestHandlerInterface $handler): RequestHandlerInterface
    {
        if ($layers === []) {
            return $handler;
        }

        $middleware = array_shift($layers);
        $next       = $this->buildRunner($layers, $handler);

        return new class ($middleware, $next) implements RequestHandlerInterface {
            public function __construct(
                private readonly MiddlewareInterface   $middleware,
                private readonly RequestHandlerInterface $next
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
    }
}
