<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy\Grants;

/**
 * An approval given in advance for one tool, within typed limits on its arguments — what a
 * {@see \Pramnos\Llm\Policy\GrantGate} lets run without asking.
 *
 * ```php
 * new ToolGrant(
 *     'send_email',
 *     ['to' => new DomainMatcher('example.com'), 'cc' => new DomainMatcher('example.com')],
 *     free: ['subject', 'body'],
 *     userId: 7,
 *     expiresAt: time() + 86400,
 * );
 * ```
 *
 * **Every argument is accounted for.** A call is allowed only when each argument it carries has a
 * matcher that accepts it, or is named in `free` as one that cannot carry the change anywhere.
 * An argument the grant does not name refuses the call: `to` within the domain does not cover a
 * `cc` outside it, nor a `bcc` the grant never thought of. An argument with a matcher that the
 * call leaves out is nothing to check.
 */
final class ToolGrant
{
    /**
     * @param array<string, ArgumentMatcher> $matchers  By argument name
     * @param list<string>                   $free      Arguments allowed whatever they say
     * @param int|null                       $userId    Only for this person's calls; null for anybody's
     * @param int|null                       $expiresAt A Unix time after which it no longer holds; null for never
     */
    public function __construct(
        public readonly string $toolName,
        public readonly array $matchers = [],
        public readonly array $free = [],
        public readonly ?int $userId = null,
        public readonly ?int $expiresAt = null,
    ) {
    }

    /**
     * Whether this grant lets the call run.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     */
    public function allows(string $tool, array $arguments, array $context, int $now): bool
    {
        if ($tool !== $this->toolName
            || ($this->expiresAt !== null && $now > $this->expiresAt)
            || ($this->userId !== null && (int) ($context['userid'] ?? 0) !== $this->userId)) {
            return false;
        }

        foreach ($arguments as $name => $value) {
            $matcher = $this->matchers[$name] ?? null;
            if ($matcher instanceof ArgumentMatcher ? !$matcher->matches($value) : !in_array($name, $this->free, true)) {
                return false;
            }
        }

        return true;
    }
}
