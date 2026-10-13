<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

use Pramnos\Llm\Connection;

/** No limits: every call is allowed and nothing is metered beyond the call log. */
final class NoLimits implements Limits
{
    /** @param array<string, mixed> $context */
    public function refusal(array $context, Connection $connection): ?string
    {
        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $usage
     */
    public function record(array $context, Connection $connection, array $usage, ?float $cost): void
    {
    }
}
