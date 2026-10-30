<?php

declare(strict_types=1);

namespace Pramnos\Llm\Mcp;

/** An MCP server that could not be reached, answered nothing in time, or answered with an error. */
final class McpException extends \RuntimeException
{
}
