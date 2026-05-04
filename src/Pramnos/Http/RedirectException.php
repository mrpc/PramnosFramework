<?php

namespace Pramnos\Http;

/**
 * Thrown by middleware (or any framework component) to signal an HTTP redirect.
 *
 * Application::exec() catches this before the general Exception handler and
 * performs the actual redirect via header()/exit. This avoids bare `exit` calls
 * inside middleware, making redirect logic fully testable.
 *
 * @package    PramnosFramework
 * @subpackage Http
 */
class RedirectException extends \RuntimeException
{
    /**
     * @param string $url        Destination URL.
     * @param int    $statusCode HTTP redirect status (301, 302, 303, 307, 308).
     */
    public function __construct(
        private readonly string $url,
        private readonly int $statusCode = 302
    ) {
        parent::__construct('Redirect to: ' . $url, $statusCode);
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
