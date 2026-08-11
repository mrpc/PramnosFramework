<?php

declare(strict_types=1);

namespace Pramnos\Http;

/**
 * Thrown when a rate limit rejects a request.
 *
 * It extends `\Exception` with code 429, so every existing `catch (\Exception)`
 * and every check on `getCode() === 429` keeps working unchanged — the type is
 * additive. What it adds is the ability to tell "rate limited" apart from any
 * other exception that happens to carry the same code, and somewhere to put the
 * `Retry-After` value so the response can carry it properly.
 *
 * The limiters used to emit that header with a bare `header()` call. That is
 * invisible to anything inspecting or buffering the response, and in CLI or a
 * test it emits nothing at all, so the one piece of information a well-behaved
 * client needs in order to back off correctly was the piece most likely to go
 * missing. {@see ExceptionHandler::render()} now reads it from here.
 *
 * @package Pramnos\Http
 */
class TooManyRequestsException extends \Exception
{
    /**
     * @param string          $message    Shown to the client.
     * @param int             $retryAfter Seconds until the window resets. Zero
     *                                    means "unknown", and no header is sent.
     * @param \Throwable|null $previous
     */
    public function __construct(
        string          $message    = 'Too many requests. Please slow down.',
        private int     $retryAfter = 0,
        ?\Throwable     $previous   = null
    ) {
        parent::__construct($message, 429, $previous);
    }

    /**
     * Seconds the client should wait before retrying, or 0 when unknown.
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
