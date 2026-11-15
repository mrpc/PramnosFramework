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
 * ], hardFloors: ['delete_account', 'wire_transfer']));
 * ```
 *
 * Gated, by default, are the tools that write (`'writes' => true`) and any marked `'gated' => true`;
 * every other call runs. A gated call runs when a grant for its tool, its person, still in force,
 * accepts every one of its arguments ({@see ToolGrant::allows()}). Otherwise the model is told it
 * needs the person's word, and says what it would do instead.
 *
 * **A hard floor** — a tool marked `'hard_floor' => true`, or named in `$hardFloors` — is never
 * covered by any grant, however exactly it matches: the idea, borrowed from openworker's own
 * sandboxed tool runner (andrewyng/openworker), that some actions always wait for the person's
 * word said right now, no standing approval ever substituting for it, whatever an allowlist built
 * in advance says. Every refusal of one is written to {@see \Pramnos\Auth\AuditLog} — the attempt
 * is worth a permanent trail whether or not the person goes on to approve it through
 * {@see \Pramnos\Llm\Chat\Proposals} or an application's own equivalent.
 *
 * An application whose grants are stored, or depend on the person, overrides {@see grants()}.
 */
class GrantGate implements ToolGate
{
    /** Told for a gated call no grant covers. */
    public const NEEDS_APPROVAL = 'Not done: %s with these arguments is outside what the person approved in advance. '
        . 'Say exactly what you would do, and do it only when they confirm.';

    /** Told for a hard floor: no grant, present or future, changes this answer. */
    public const HARD_FLOOR = 'Not done: %s always needs the person\'s word said right now — no standing approval '
        . 'covers it, however similar a past one was. Say exactly what you would do, and do it only once they say '
        . 'yes, every time.';

    /** The audit event a hard floor's refusal is recorded under. */
    public const AUDITED = 'ai.hard_floor_refused';

    /**
     * @param iterable<ToolGrant> $grants
     * @param list<string>        $hardFloors Tool names no grant ever covers, beside any tool marked `'hard_floor' => true`
     */
    public function __construct(private readonly iterable $grants = [], private readonly array $hardFloors = [])
    {
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $context
     */
    public function before(string $name, array $arguments, array $tool, array $context): ?string
    {
        if ($this->hardFloor($name, $tool)) {
            $this->audit($name, $arguments, $context);

            return sprintf(self::HARD_FLOOR, $name);
        }
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
     * Whether no grant may ever cover this call: the tool says so itself, or this gate was told
     * its name — checked before {@see gated()}, since a hard floor needs no `writes`/`gated` flag
     * of its own to mean it.
     *
     * @param array<string, mixed> $tool
     */
    protected function hardFloor(string $name, array $tool): bool
    {
        return !empty($tool['hard_floor']) || in_array($name, $this->hardFloors, true);
    }

    /**
     * A hard floor's refusal, permanently: who was asking, the call and its arguments — never
     * thrown into the caller ({@see \Pramnos\Auth\AuditLog}'s own guarantee), since auditing the
     * refusal is not why the call runs or fails.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     */
    protected function audit(string $name, array $arguments, array $context): void
    {
        $userId = $context['userid'] ?? null;
        \Pramnos\Auth\AuditLog::record(self::AUDITED, is_numeric($userId) ? (int) $userId : null, null, [
            'tool' => $name, 'arguments' => $arguments, 'purpose' => $context['purpose'] ?? null,
        ], 'ai_tool', $name);
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
