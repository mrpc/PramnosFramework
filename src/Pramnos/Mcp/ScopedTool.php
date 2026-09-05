<?php

declare(strict_types=1);

namespace Pramnos\Mcp;

/**
 * Put a scope on a tool that was written without one.
 *
 * The internal server's tools implement {@see McpToolInterface}, which has no
 * answer to «who may call this». That is deliberate — see {@see ScopedMcpTool}
 * for why exposure is a type rather than a flag — and it means none of them can
 * be handed to {@see PublicRegistry} directly, however useful they would be to a
 * developer diagnosing a production installation from somewhere else.
 *
 * This is the door, and it is a door somebody has to walk through on purpose:
 *
 * ```php
 * PublicRegistry::add(ScopedTool::wrap(new StatusTool($app), 'mcp:diagnostics'));
 * ```
 *
 * ### Why a wrapper and not a `requiredScope()` on each tool
 *
 * Adding the method to the diagnostic tools would make every one of them publicly
 * registrable from then on, and the property that protects this endpoint is
 * exactly that they are *not*: a twenty-first development tool cannot reach the
 * internet by being written, only by somebody naming it and choosing its scope.
 * Wrapping keeps the decision at the call site, where it is visible in a diff,
 * rather than in a method somebody copies from the tool next to it.
 *
 * It also means the scope is a property of *this exposure* rather than of the
 * tool. The same reader can sit behind `mcp:diagnostics` on one installation and
 * a narrower scope on another, without either of them editing the framework.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
final class ScopedTool implements ScopedMcpTool
{
    public function __construct(
        private readonly McpToolInterface $tool,
        private readonly string $scope
    ) {
    }

    /**
     * The same thing as the constructor, named for how it reads at a call site.
     */
    public static function wrap(McpToolInterface $tool, string $scope): self
    {
        return new self($tool, $scope);
    }

    public function name(): string
    {
        return $this->tool->name();
    }

    public function description(): string
    {
        return $this->tool->description();
    }

    /**
     * @return array{type: string, properties: array<string, mixed>, required?: list<string>}
     */
    public function inputSchema(): array
    {
        return $this->tool->inputSchema();
    }

    public function execute(array $input): mixed
    {
        return $this->tool->execute($input);
    }

    public function requiredScope(): string
    {
        return $this->scope;
    }

    /**
     * The tool underneath.
     *
     * So that a registry holding wrapped tools can still be asked what it is
     * actually serving — a list of `ScopedTool` objects answers nothing.
     */
    public function inner(): McpToolInterface
    {
        return $this->tool;
    }
}
