<?php

declare(strict_types=1);

namespace Pramnos\Mcp;

/**
 * Contract for MCP tool implementations.
 *
 * Each tool exposes a single capability to the AI assistant — e.g. listing
 * database tables, inspecting a migration status, or showing registered routes.
 * Apps register custom tools via McpServer::addTool().
 *
 * @package PramnosFramework
 */
interface McpToolInterface
{
    /**
     * Machine-readable identifier used in tools/call requests.
     * Must be unique within a server instance (e.g. 'list-tables').
     */
    public function name(): string;

    /**
     * Human-readable description shown in tools/list responses.
     * One sentence explaining what the tool does.
     */
    public function description(): string;

    /**
     * JSON Schema (as PHP array) describing the tool's input parameters.
     * Return an empty properties array for tools that take no input.
     *
     * @return array{type: string, properties: array<string, mixed>, required?: list<string>}
     */
    public function inputSchema(): array;

    /**
     * Execute the tool with the provided arguments.
     *
     * @param  array<string, mixed> $input  Validated input matching inputSchema().
     * @return mixed  Any JSON-serialisable value — string, array, or object.
     */
    public function execute(array $input): mixed;
}
