<?php

declare(strict_types=1);

namespace Pramnos\Messaging;

use Pramnos\Database\Database;

/**
 * Who a mass message goes to, resolved from criteria an operator chose.
 *
 * Kept apart from the dispatcher, and stored as criteria rather than as a list, because the
 * two answer different questions at different times. The criteria are what somebody decided
 * ("everyone from usertype 50 up, validated accounts only") and belong in the audit trail;
 * the list is what that meant at the moment it was queued, and belongs in
 * `massmessagerecipients`.
 *
 * Resolving once, at queue time, is the important half: an audience re-resolved at delivery
 * time would silently include accounts created after the operator pressed send, and exclude
 * the ones deleted since — so a message would go to people who were never in the audience
 * that was approved, and the recipient rows would stop matching the send.
 *
 * ## The criteria
 *
 * ```php
 * ['usertype_min' => 0, 'validated_only' => true, 'active_only' => true]
 * ```
 *
 * All three are defaults, so `resolve([])` means "every account that can actually receive
 * something". Anything else is an application's own question, which is what {@see resolve()}
 * being one query over `users` leaves room for: an application with its own audience writes
 * its own list and hands it to `MassMessageDispatcher::queue()` directly.
 */
class MassMessageAudience
{
    private Database $database;

    public function __construct(?Database $database = null)
    {
        $this->database = $database ?? Database::getInstance();
    }

    /**
     * The account ids these criteria name.
     *
     * @param  array<string, mixed> $criteria
     * @return array<int, int>
     */
    public function resolve(array $criteria = []): array
    {
        $query = $this->database->queryBuilder()
            ->table('#PREFIX#users')
            ->select(['userid'])
            ->orderBy('userid', 'asc');

        $floor = (int) ($criteria['usertype_min'] ?? 0);

        if ($floor > 0) {
            $query->where('usertype', '>=', $floor);
        }

        // A validated address is the difference between a send and a bounce, and an inactive
        // account is one somebody switched off — mailing it is the opposite of what that
        // switch meant. Both default to on for that reason; both are switchable because an
        // application may be telling accounts *to* validate.
        if (($criteria['validated_only'] ?? true) !== false) {
            $query->where('validated', 1);
        }

        if (($criteria['active_only'] ?? true) !== false) {
            $query->where('active', 1);
        }

        $ids = [];

        try {
            $result = $query->get();
        } catch (\Throwable) {
            return [];
        }

        while (($row = $result->fetch()) !== null) {
            $id = (int) ($row['userid'] ?? 0);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * How many accounts these criteria name, without listing them.
     *
     * For the compose screen, which has to say "this will go to 4,812 people" *before*
     * anybody presses send. A count is the one number that changes an operator's mind.
     *
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        return count($this->resolve($criteria));
    }

    /**
     * The criteria in a sentence, for a screen and for the audit record.
     *
     * @param array<string, mixed> $criteria
     */
    public static function describe(array $criteria = []): string
    {
        $parts = [];
        $floor = (int) ($criteria['usertype_min'] ?? 0);

        $parts[] = $floor > 0
            ? 'accounts of usertype ' . $floor . ' and above'
            : 'every account';

        if (($criteria['validated_only'] ?? true) !== false) {
            $parts[] = 'validated';
        }

        if (($criteria['active_only'] ?? true) !== false) {
            $parts[] = 'active';
        }

        return implode(', ', $parts);
    }
}
