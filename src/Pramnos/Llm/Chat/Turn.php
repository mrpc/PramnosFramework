<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * One question being answered: what an {@see Assistant} is given to build the answer's tools,
 * prompt and checks, and to finish what the answer did.
 *
 * `bag` is the application's, for the length of the turn: what its tools wrote — drafts made,
 * edits proposed — kept there so {@see Assistant::answered()} can attach it to the stored answer.
 */
final class Turn
{
    /** @var array<string, mixed> The application's, for the length of the turn */
    public array $bag = [];

    /** @var list<string> The data tags the answer's results carried, once it is answered */
    public array $tags = [];

    /**
     * @param list<array{role: string, content: string}> $messages  What the model is sent
     * @param array<string, string>                      $documents Attached documents by name, latest last
     * @param array<string, mixed>                       $extra     What the caller passed to ask()
     */
    public function __construct(
        public readonly Scope $scope,
        public ?int $conversationId,
        public readonly bool $scheduled,
        public readonly array $messages,
        public readonly array $documents,
        public readonly array $extra,
        public readonly Conversations $conversations,
    ) {
    }

    /**
     * Whose rights the answer is given with: the asker's, unless others read it too — `@Ask` in a
     * channel — when the caller names its least privileged reader in `extra['rights_of']`. Tools
     * that cut what a person sees ask this, not `scope->userId`, who asked and pays.
     */
    public function rightsOf(): int
    {
        return (int) ($this->extra['rights_of'] ?? $this->scope->userId);
    }

    /** Whether others read the answer besides its asker: then nothing of the asker's own goes into it. */
    public function shared(): bool
    {
        return isset($this->extra['rights_of']);
    }
}
