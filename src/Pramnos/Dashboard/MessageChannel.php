<?php

declare(strict_types=1);

namespace Pramnos\Dashboard;

/**
 * A way an application reaches people — email, SMS, push, a chat app — as the dashboard's
 * `messages` card shows it: one row of figures per channel, for the period the card is set to.
 *
 * ```php
 * final class Sms implements \Pramnos\Dashboard\MessageChannel
 * {
 *     public function id(): string { return 'sms'; }
 *     public function label(): string { return 'SMS'; }
 *     public function link(): ?string { return adminUrl('Sms'); }
 *     public function figures(int $from, int $to): array
 *     {
 *         return ['total' => …, 'failed' => …, 'delivered' => …, 'cost' => 12.40, 'currency' => '€'];
 *     }
 * }
 *
 * // app/app.php
 * 'dashboard' => ['channels' => [\App\Dashboard\Sms::class]],
 * ```
 *
 * A channel that can say how many people it reached implements {@see MessageReach} too.
 */
interface MessageChannel
{
    /** A short, unique name. */
    public function id(): string;

    /** The row's name, in English: the card translates it. */
    public function label(): string;

    /** Where its own screen is, or null. */
    public function link(): ?string;

    /**
     * The figures of a period, between two moments: `total` and `failed` always; `queued`,
     * `delivered`, `cost` and `currency` where the channel has them.
     *
     * @return array{total: int, failed: int, queued?: int, delivered?: int, cost?: float, currency?: string}
     */
    public function figures(int $from, int $to): array;
}
