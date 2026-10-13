<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

use Pramnos\Llm\Connection;

/**
 * Whether a call may spend, and what it spent.
 *
 * An application's plans, quotas and credits live behind this. The framework asks
 * {@see refusal()} before anything is sent and calls {@see record()} after every round that
 * reported usage — including the rounds of a tool conversation, so a long answer is counted as
 * it goes rather than once at the end.
 *
 * `$context` is what the caller passed to `Client::about()`, after the router filled it in:
 * `purpose`, `userid`, `organization_id` and whatever else the application put there.
 * `$connection->ownKey()` says whether the call goes out on a key the organisation supplied
 * itself — usually a call nobody else pays for.
 */
interface Limits
{
    /**
     * Why this call may not be made, in a sentence the person can act on; or null to allow it.
     *
     * @param array<string, mixed> $context
     */
    public function refusal(array $context, Connection $connection): ?string;

    /**
     * What one round spent. Must not throw: metering must not break the answer it meters.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $usage The provider's own `usage` object
     * @param float|null           $cost  Its cost, or null when the model has no price
     */
    public function record(array $context, Connection $connection, array $usage, ?float $cost): void;
}
