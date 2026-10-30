<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

/** No tiers: every question goes to the assistant's own connection. */
final class NoCascade implements Cascade
{
    /**
     * @param array<string, mixed>                       $context
     * @param list<array{role: string, content: string}> $history
     */
    public function tier(array $context, string $question, array $history): ?string
    {
        return null;
    }
}
