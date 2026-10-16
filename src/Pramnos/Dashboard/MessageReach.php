<?php

declare(strict_types=1);

namespace Pramnos\Dashboard;

/**
 * A {@see MessageChannel} that can say how many people a period's messages reached. Answered from
 * what the channel keeps per day — a distinct count over a long history cannot run inside a page
 * request — so it says how many days it has no figure for.
 */
interface MessageReach
{
    /**
     * `recipients` in the period, the `average` per day, and the `missing_days` it has no figure
     * for; null when it cannot say.
     *
     * @return array{recipients: int, average: float, missing_days: int}|null
     */
    public function reach(int $from, int $to): ?array;
}
