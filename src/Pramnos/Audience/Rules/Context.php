<?php

declare(strict_types=1);

namespace Pramnos\Audience\Rules;

/**
 * Where an action is being done from: the rule and its version, the step, the run, what started
 * it — what every message records about its origin.
 */
final class Context
{
    /**
     * @param array<string, mixed> $trigger What brought the person in, with its causation ids
     */
    public function __construct(
        public readonly int $rule,
        public readonly string $ruleName,
        public readonly int $version,
        public readonly string $step,
        public readonly int $run,
        public readonly int $person,
        public readonly array $trigger = [],
        public readonly ?string $variant = null,
        public readonly bool $shadow = false,
        public readonly ?int $consentList = null,
        public readonly ?int $recipient = null,
    ) {
    }

    /**
     * Who receives a message: the person — or, for a test send, the account that asked for it,
     * with the person's details filled in.
     */
    public function to(): int
    {
        return $this->recipient ?? $this->person;
    }

    /** Whether this is a test send to somebody other than the person. */
    public function isTest(): bool
    {
        return $this->recipient !== null && $this->recipient !== $this->person;
    }

    /**
     * The origin as one short string — `rule:4:v3:mail:run812` — what a message's log row keeps
     * (`mails.moduleinfo`), so a message can be traced to the step that sent it.
     */
    public function origin(): string
    {
        return 'rule:' . $this->rule . ':v' . $this->version . ':' . $this->step . ':run' . $this->run;
    }

    /**
     * An origin read back — what an Email Log row's `moduleinfo` says — or null when it is not one,
     * with the rule's name and the address of its page where the rule is still there.
     *
     * @return array{rule: int, version: int, step: string, run: int, name: ?string, url: ?string}|null
     */
    public static function parse(string $origin): ?array
    {
        if (preg_match('/^rule:(\d+):v(\d+):([A-Za-z0-9_.-]+):run(\d+)$/', $origin, $m) !== 1) {
            return null;
        }
        try {
            $rule = \Pramnos\Audience\Installed::has('rules') ? Rules::get((int) $m[1]) : null;
        } catch (\Throwable) {
            $rule = null;
        }

        return ['rule' => (int) $m[1], 'version' => (int) $m[2], 'step' => $m[3], 'run' => (int) $m[4],
            'name' => $rule['name'] ?? null, 'url' => $rule === null ? null : adminUrl('Rules/view/') . $m[1]];
    }

    /**
     * Everything about the origin, for a webhook or a provider's metadata.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule'    => $this->rule,
            'name'    => $this->ruleName,
            'version' => $this->version,
            'step'    => $this->step,
            'run'     => $this->run,
            'variant' => $this->variant,
            'consent' => $this->consentList,
            'trigger' => $this->trigger,
            'origin'  => $this->origin(),
            'test'    => $this->isTest(),
        ];
    }
}
