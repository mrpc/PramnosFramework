<?php

namespace Pramnos\Http;

/**
 * Thrown when an HTTP request fails at the transport level (connection refused,
 * DNS failure, timeout, SSL error). NOT thrown for 4xx/5xx responses — those
 * are returned as ClientResponse objects so the caller can inspect them.
 *
 */
class ClientException extends \RuntimeException
{
    public function __construct(string $message, int $curlErrno = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $curlErrno, $previous);
    }

    /** The libcurl error number (CURLE_* constant value), or 0 if not applicable. */
    public function getCurlErrno(): int
    {
        return $this->getCode();
    }
}
