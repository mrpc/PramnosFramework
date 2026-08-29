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
            ->select(['userid', 'email'])
            ->orderBy('userid', 'asc');

        $floor = (int) ($criteria['usertype_min'] ?? 0);

        if ($floor > 0) {
            $query->where('usertype', '>=', $floor);
        }

        $ceiling = (int) ($criteria['usertype_max'] ?? 0);

        if ($ceiling > 0) {
            // The other end of the band. "Everybody below staff" is a real audience — a notice
            // about a feature that only members see — and with a floor alone it can only be
            // expressed as "everybody", which then also reaches the operators.
            $query->where('usertype', '<=', $ceiling);
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

        $language = trim((string) ($criteria['language'] ?? ''));

        if ($language !== '') {
            /*
             * The account's own language, not the operator's.
             *
             * A message written in Greek sent to everybody reaches the people who set their
             * account to English too, and they cannot read it. Writing one message per language
             * and sending each to its own audience is the only honest way to do this, and it
             * needs exactly this filter.
             */
            $query->where('language', $language);
        }

        $factor = trim((string) ($criteria['twofactor'] ?? ''));

        if ($factor === 'with' || $factor === 'without') {
            $ids = $this->accountsWithASecondFactor();

            if ($factor === 'with') {
                if ($ids === []) {
                    return [];   // nobody has one; an empty `IN ()` is not a query
                }

                $query->whereIn('userid', $ids);
            } elseif ($ids !== []) {
                $query->whereIn('userid', $ids, 'and', true);
            }
        }

        $since = (int) ($criteria['last_login_after'] ?? 0);

        if ($since > 0) {
            $query->where('lastlogin', '>=', $since);
        }

        $before = (int) ($criteria['last_login_before'] ?? 0);

        if ($before > 0) {
            /*
             * The dormant audience — "you have not signed in since March".
             *
             * `lastlogin` is 0 for an account that has never signed in at all, and 0 is before
             * every cutoff, so those accounts are in. That is correct for this question: never
             * having signed in is the most dormant an account can be.
             */
            $query->where('lastlogin', '<=', $before);
        }

        $rows = [];

        try {
            $result = $query->get();
        } catch (\Throwable) {
            return [];
        }

        while (($row = $result->fetch()) !== null) {
            $id = (int) ($row['userid'] ?? 0);

            if ($id > 0) {
                $rows[$id] = trim((string) ($row['email'] ?? ''));
            }
        }

        $list = $criteria['exclude_optouts'] ?? null;

        if ($list !== null && $list !== false && $list !== '') {
            $rows = $this->withoutOptOuts($rows, (string) $list);
        }

        return array_values(array_keys($rows));
    }

    /**
     * Drop the accounts that asked this list to stop.
     *
     * The dispatcher already skips them at delivery, so this is not what makes the send
     * correct — it is what makes the **count** correct. "This will go to 4,812 people" is the
     * one number that changes an operator's mind, and a count that includes nine hundred
     * people who unsubscribed is a number that changes it in the wrong direction.
     *
     * Read as one query rather than one per address: an audience is tens of thousands of rows,
     * and `isOptedOut()` in a loop is tens of thousands of round trips.
     *
     * @param  array<int, string> $rows userid => email
     * @return array<int, string>
     */
    protected function withoutOptOuts(array $rows, string $list): array
    {
        $optedOut = [];

        try {
            $result = $this->database->queryBuilder()
                ->table('#PREFIX#emailoptouts')
                ->select(['email'])
                ->whereIn('list', [$list, \Pramnos\Email\Unsubscribe::LIST_ALL])
                ->get();

            while (($row = $result->fetch()) !== null) {
                $address = strtolower(trim((string) ($row['email'] ?? '')));

                if ($address !== '') {
                    $optedOut[$address] = true;
                }
            }
        } catch (\Throwable) {
            /*
             * No records, or no table. Returning the audience unfiltered is the safe failure
             * here *only* because the dispatcher checks again per recipient — the count is
             * optimistic, the send is not.
             */
            return $rows;
        }

        if ($optedOut === []) {
            return $rows;
        }

        foreach ($rows as $id => $email) {
            if ($email !== '' && isset($optedOut[strtolower($email)])) {
                unset($rows[$id]);
            }
        }

        return $rows;
    }

    /**
     * The accounts that hold a second factor.
     *
     * @return array<int, int>
     */
    protected function accountsWithASecondFactor(): array
    {
        $ids = [];

        try {
            $result = $this->database->queryBuilder()
                ->table('authserver.user_twofactor')
                ->select(['userid'])
                ->where('enabled', 1)
                ->get();

            while (($row = $result->fetch()) !== null) {
                $id = (int) ($row['userid'] ?? 0);

                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } catch (\Throwable) {
            // No authserver feature, so nobody has one.
            return [];
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
        $parts   = [];
        $floor   = (int) ($criteria['usertype_min'] ?? 0);
        $ceiling = (int) ($criteria['usertype_max'] ?? 0);

        if ($floor > 0 && $ceiling > 0) {
            $parts[] = 'accounts of usertype ' . $floor . ' to ' . $ceiling;
        } elseif ($floor > 0) {
            $parts[] = 'accounts of usertype ' . $floor . ' and above';
        } elseif ($ceiling > 0) {
            $parts[] = 'accounts of usertype ' . $ceiling . ' and below';
        } else {
            $parts[] = 'every account';
        }

        if (($criteria['validated_only'] ?? true) !== false) {
            $parts[] = 'validated';
        }

        if (($criteria['active_only'] ?? true) !== false) {
            $parts[] = 'active';
        }

        $language = trim((string) ($criteria['language'] ?? ''));

        if ($language !== '') {
            $parts[] = 'writing in ' . $language;
        }

        $factor = trim((string) ($criteria['twofactor'] ?? ''));

        if ($factor === 'with') {
            $parts[] = 'holding a second factor';
        } elseif ($factor === 'without') {
            $parts[] = 'without a second factor';
        }

        $since  = (int) ($criteria['last_login_after'] ?? 0);
        $before = (int) ($criteria['last_login_before'] ?? 0);

        if ($since > 0) {
            $parts[] = 'last signed in after ' . date('Y-m-d', $since);
        }

        if ($before > 0) {
            $parts[] = 'not signed in since ' . date('Y-m-d', $before);
        }

        $list = $criteria['exclude_optouts'] ?? null;

        if ($list !== null && $list !== false && $list !== '') {
            $parts[] = 'excluding anyone who unsubscribed from ' . (string) $list;
        }

        return implode(', ', $parts);
    }
}
