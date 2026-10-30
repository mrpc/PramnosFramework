<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

/**
 * Which tier of model answers a question — `'llm' => ['tiers' => ['fast' => feature, 'frontier' => feature]]`,
 * each a feature whose provider and model are set on the administration screen.
 *
 * Asked once a question, before it is answered. A routine question goes to the cheap, fast tier;
 * one that needs more — long reasoning, delicate personal matters, writing that matters — to the
 * frontier tier. Null keeps the assistant's own connection.
 */
interface Cascade
{
    /**
     * The tier to answer on, by its name in `tiers`; or null for the assistant's own connection.
     *
     * @param array<string, mixed>              $context The call's context: `purpose`, `userid`…
     * @param list<array{role: string, content: string}> $history What was said before it
     */
    public function tier(array $context, string $question, array $history): ?string;
}
