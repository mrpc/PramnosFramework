<?php

declare(strict_types=1);

namespace Pramnos\Llm\Mcp;

/**
 * An MCP server that could not be reached, answered nothing in time, or answered with an error.
 * An HTTP refusal carries its status, and a 401 its `WWW-Authenticate`, which names where a
 * person signs in ({@see Connections}).
 */
final class McpException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly int $status = 0,
        public readonly string $authenticate = ''
    ) {
        parent::__construct($message, $code, $previous);
    }
}
