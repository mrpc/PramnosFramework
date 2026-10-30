<?php

declare(strict_types=1);

namespace Pramnos\Llm;

/**
 * What a {@see Subagent} gives back — always, whatever happened to it.
 *
 * | Status | Reason | Means |
 * |---|---|---|
 * | `success` | `completed` | it answered within its rounds and budget; its artifacts are kept |
 * | `partial` | `max_rounds_reached`, `budget_exceeded` | it answered from what it had when it ran out; its artifacts are drafts |
 * | `failed` | `tool_error`, `provider_error`, `cancelled` | no answer; the summary says why, and what it had called |
 *
 * `summary` is never empty: a parent that gets a failure still learns what was tried.
 * `committedArtifacts` are kept (each as {@see Chat\Artifacts::create()} answered it);
 * `draftArtifacts` are what it would have kept, as it gave them, for somebody to confirm with
 * `create()` — nothing of a task that did not finish is taken as final.
 */
final class SubagentResult
{
    public const SUCCESS = 'success';
    public const PARTIAL = 'partial';
    public const FAILED  = 'failed';

    /**
     * @param list<array<string, mixed>> $committedArtifacts
     * @param list<array<string, mixed>> $draftArtifacts
     */
    public function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly string $summary,
        public readonly array $committedArtifacts = [],
        public readonly array $draftArtifacts = [],
        public readonly int $tokensSpent = 0,
        public readonly int $roundsUsed = 0
    ) {
    }

    /** Whether it finished: its answer is whole and its artifacts are kept. */
    public function succeeded(): bool
    {
        return $this->status === self::SUCCESS;
    }

    /**
     * What the parent model reads as the tool's result: the summary, and what was kept and what
     * was only drafted — by title, not their content, which would fill the parent's context.
     */
    public function forParent(): string
    {
        return (string) json_encode(array_filter([
            'status'    => $this->status,
            'reason'    => $this->reason,
            'summary'   => $this->summary,
            'committed' => array_map(static fn (array $a): array => ['artifact_id' => $a['artifact_id'] ?? null, 'title' => $a['title'] ?? ''], $this->committedArtifacts),
            'drafts'    => array_map(static fn (array $a): array => ['kind' => $a['kind'] ?? '', 'title' => $a['title'] ?? '', 'not_saved' => true], $this->draftArtifacts),
        ], static fn ($v): bool => $v !== []), JSON_UNESCAPED_UNICODE);
    }
}
