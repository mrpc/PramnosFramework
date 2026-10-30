<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

/**
 * Whether the assistant may reach out to a person now — a scheduled question it asks on its own,
 * a reminder before an appointment — or later, or not this time: `'llm' => ['proactivity' => …]`.
 *
 * Asked before anything is spent, so a ping that is held costs no model call.
 */
interface Proactivity
{
    /**
     * When it may go: `$now` for now, a later moment to hold it until, or 0 for not this time.
     *
     * @param bool $critical An alarm the person set: past quiet hours, the day's limit and being busy
     */
    public function when(int $userId, string $timezone, int $now, bool $critical = false): int;
}
