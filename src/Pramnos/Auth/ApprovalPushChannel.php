<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Notification\Channels\PushChannel;

/**
 * The push channel for a sign-in approval: only to the devices chosen, urgently, briefly.
 *
 * A notification's ordinary channel goes to every browser an account subscribed in. An
 * approval must go only to its **trusted** devices ({@see PushApprovals::recipients()}), so
 * the subscriptions are handed in rather than looked up. Two minutes to live, because an
 * approval arriving after the ask expired is a prompt that can only confuse; and `high`
 * urgency, because somebody is watching a screen waiting for it.
 */
class ApprovalPushChannel extends PushChannel
{
    public const TTL = PushApprovals::TTL;

    /** @param list<array<string, mixed>> $subscriptions */
    public function __construct(private array $subscriptions)
    {
    }

    protected function subscriptionsFor(int $userId): array
    {
        return array_values(array_filter(
            $this->subscriptions,
            static fn (array $row): bool => (int) ($row['userid'] ?? 0) === $userId
        ));
    }

    protected function urgency(): string
    {
        return 'high';
    }
}
