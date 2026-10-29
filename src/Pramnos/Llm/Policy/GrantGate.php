<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

use Pramnos\Llm\Policy\Grants\ToolGrant;

/**
 * A gate that lets a gated call run only within an approval given in advance — a {@see ToolGrant}.
 *
 * ```php
 * Llm::use('tool_gate', new GrantGate([
 *     new ToolGrant('send_email', ['to' => new DomainMatcher('example.com')], free: ['subject', 'body'], userId: 7),
 * ]));
 * ```
 *
 * Gated, by default, are the tools that write (`'writes' => true`) and any marked `'gated' => true`;
 * every other call runs. A gated call runs when a grant for its tool, its person, still in force,
 * accepts every one of its arguments ({@see ToolGrant::allows()}). Otherwise the model is told it
 * needs the person's word, and says what it would do instead.
 *
 * An application whose grants are stored, or depend on the person, overrides {@see grants()}.
 */
class GrantGate implements ToolGate
{
    /** Told for a gated call no grant covers. */
    public const NEEDS_APPROVAL = 'Not done: %s with these arguments is outside what the person approved in advance. '
        . 'Say exactly what you would do, and do it only when they confirm.';

    /** @param iterable<ToolGrant> $grants */
    public function __construct(private readonly iterable $grants = [])
    {
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $context
     */
    public function before(string $name, array $arguments, array $tool, array $context): ?string
    {
        if (!$this->gated($name, $tool)) {
            return null;
        }

        $now = time();
        foreach ($this->grants($context) as $grant) {
            if ($grant instanceof ToolGrant && $grant->allows($name, $arguments, $context, $now)) {
                return null;
            }
        }

        return sprintf(self::NEEDS_APPROVAL, $name);
    }

    /**
     * Whether a call to this tool needs a grant: one that writes, or is marked `gated`.
     *
     * @param array<string, mixed> $tool
     */
    protected function gated(string $name, array $tool): bool
    {
        return !empty($tool['gated']) || !empty($tool['writes']);
    }

    /**
     * The grants that may cover a call in this context.
     *
     * @param array<string, mixed> $context
     * @return iterable<ToolGrant>
     */
    protected function grants(array $context): iterable
    {
        return $this->grants;
    }
}
