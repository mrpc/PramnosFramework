<?php

declare(strict_types=1);

namespace Pramnos\Application;

/**
 * The application ended the request, in a process that must not end.
 *
 * `Application::close()` calls `exit()`, which is right for a web request and
 * fatal for anything serving more than one — a test run, a worker, a
 * long-running server. Under `PRAMNOS_TESTING` it throws instead, and this is
 * what it throws.
 *
 * It carries the status the application had decided on. Before it existed the
 * throw was a bare `\Exception`, so everything that ends a request arrived at the
 * caller identically: a 404, a maintenance 503 and a genuine fault were the same
 * exception with the same message shape. A `TestClient` could only render all
 * three as a 500, which meant no test could assert that a URL is *not found* —
 * the one thing about a 404 worth asserting.
 *
 * Extends `\Exception` so every existing `catch (\Exception)` still catches it.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ApplicationClosedException extends \Exception
{
    /**
     * @param string $message    The exception message, in close()'s existing shape
     * @param int    $statusCode The status the application had decided on
     * @param string $body       What close() was going to send
     */
    public function __construct(
        string $message,
        private int $statusCode = 200,
        private string $body = ''
    ) {
        parent::__construct($message);
    }

    /**
     * The status the application had decided on before ending the request.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * What would have been sent to the client.
     */
    public function getBody(): string
    {
        return $this->body;
    }
}
