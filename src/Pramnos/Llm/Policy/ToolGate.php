<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

/**
 * Whether a tool call may run — asked before every call, after the model asked for it and before
 * anything is done.
 *
 * {@see \Pramnos\Llm\Chat\Proposals} asks the person after the model proposed a change; a gate
 * decides before the call runs at all, which is where an application says "never this", or "this,
 * but only within what was approved in advance" ({@see GrantGate}). The refusal is told to the model
 * in place of the result, so it can say so or ask the person: word it as "Not done: …".
 */
interface ToolGate
{
    /**
     * Why this call may not run; or null to let it.
     *
     * @param array<string, mixed> $arguments The call's arguments, decoded
     * @param array<string, mixed> $tool      The tool as the client has it: `writes`, `parallel`, `gated`…
     * @param array<string, mixed> $context   The call's context: `purpose`, `userid`, `organization_id`…
     */
    public function before(string $name, array $arguments, array $tool, array $context): ?string;
}
