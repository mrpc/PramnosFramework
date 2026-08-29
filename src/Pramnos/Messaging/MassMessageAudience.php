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
 * something".
 *
 * | Key | Means |
 * | --- | --- |
 * | `usertype_min` / `usertype_max` | the band, either end |
 * | `validated_only` / `active_only` | on by default; an address that bounces is not an audience |
 * | `language` | the account's own, not the operator's |
 * | `twofactor` | `with` or `without` |
 * | `last_login_after` / `last_login_before` | recent, or dormant |
 * | `groups` | in **any** of these groups |
 * | `organizations` | in **any** of these organizations |
 * | `only_ids` | these accounts and no others |
 * | `exclude_ids` | everything above, minus these |
 * | `exclude_optouts` | the list name, so the count matches what will be sent |
 *
 * `only_ids` is applied as a filter rather than instead of the rest, deliberately: naming an
 * account that is inactive, unvalidated or opted out must not send to it, and {@see preview()}
 * is where an operator sees that it did not.
 *
 * Anything beyond these is an application's own question, which is what {@see resolve()} being
 * one query over `users` leaves room for: an application with its own audience writes its own
 * list and hands it to `MassMessageDispatcher::queue()` directly.
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

        $groups = static::ids($criteria['groups'] ?? []);

        if ($groups !== []) {
            $members = $this->membersOfGroups($groups);

            if ($members === []) {
                return [];   // named groups with nobody in them is an empty audience, not everybody
            }

            $query->whereIn('userid', $members);
        }

        $organizations = static::ids($criteria['organizations'] ?? []);

        if ($organizations !== []) {
            $members = $this->membersOfOrganizations($organizations);

            if ($members === []) {
                return [];
            }

            $query->whereIn('userid', $members);
        }

        /*
         * Named accounts, and named accounts only.
         *
         * `only_ids` answers "send this to these three people" — the commonest thing anybody
         * wants from this screen and the one it could not do. It is applied as a filter rather
         * than short-circuiting the rest, so the safety criteria still hold: naming an account
         * that is inactive, unvalidated or opted out does not send to it, and the preview shows
         * the operator that it did not.
         */
        $only = static::ids($criteria['only_ids'] ?? []);

        if ($only !== []) {
            $query->whereIn('userid', $only);
        }

        $exclude = static::ids($criteria['exclude_ids'] ?? []);

        if ($exclude !== []) {
            $query->whereIn('userid', $exclude, 'and', true);
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
     * Who this audience is, in enough detail to be read before it is sent to.
     *
     * The screen asked an operator to choose criteria and then pressed send. What those criteria
     * *meant* — how many people, and which ones — was visible only afterwards, in the recipient
     * rows of a message that had already gone out. A send to the wrong band of accounts is not
     * something an operator can take back, and «4,812 people» is the number that changes their
     * mind, so it has to arrive before the decision rather than after it.
     *
     * The sample is a window, not the audience: an audience of forty thousand is not a thing to
     * render, and the first `$sample` rows are enough to recognise that the filter did what was
     * meant. They are the same order the send uses.
     *
     * @param  array<string, mixed> $criteria
     * @param  int                  $sample How many accounts to describe
     * @return array{total: int, sample: list<array<string, mixed>>, truncated: int}
     */
    public function preview(array $criteria = [], int $sample = 25): array
    {
        $ids = $this->resolve($criteria);
        $total = count($ids);

        if ($total === 0) {
            return ['total' => 0, 'sample' => [], 'truncated' => 0];
        }

        $window = array_slice($ids, 0, max(1, $sample));
        $rows   = [];

        try {
            $result = $this->database->queryBuilder()
                ->table($this->sampleTable())
                ->select(['userid', 'username', 'email', 'usertype', 'language', 'lastlogin'])
                ->whereIn('userid', $window)
                ->orderBy('userid', 'asc')
                ->get();

            while (($row = $result->fetch()) !== null) {
                $rows[] = [
                    'userid'    => (int) ($row['userid'] ?? 0),
                    'username'  => (string) ($row['username'] ?? ''),
                    'email'     => (string) ($row['email'] ?? ''),
                    'usertype'  => (int) ($row['usertype'] ?? 0),
                    'language'  => (string) ($row['language'] ?? ''),
                    'lastlogin' => (int) ($row['lastlogin'] ?? 0),
                ];
            }
        } catch (\Throwable) {
            // The count is the part that matters; a sample that cannot be read is a smaller
            // failure than a preview that refuses to render.
            $rows = [];
        }

        return [
            'total'     => $total,
            'sample'    => $rows,
            'truncated' => max(0, $total - count($rows)),
        ];
    }

    /**
     * Where the preview's sample is read from, as a seam.
     *
     * The count and the sample come from different reads, and only the sample is allowed to
     * fail: an operator decides on the number. Without a seam that guarantee is untestable.
     */
    protected function sampleTable(): string
    {
        return '#PREFIX#users';
    }

    /**
     * A list of account ids from whatever the form sent — an array, or a pasted string.
     *
     * People paste ids. From a spreadsheet they arrive newline-separated, from a chat message
     * comma-separated, and from a colleague with spaces between them. All three are the same
     * intention, and refusing two of them is a screen telling somebody their list is wrong when
     * it is the screen that is.
     *
     * @param  mixed $value
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('~[^0-9]+~', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            $id = (int) $item;

            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The accounts in any of these groups.
     *
     * *Any*, not all. "Members and volunteers" is a message to both, and an operator who wants
     * the intersection has a smaller audience they can name directly — whereas the union cannot
     * be expressed at all if this is an AND.
     *
     * @param  list<int> $groups
     * @return list<int>
     */
    protected function membersOfGroups(array $groups): array
    {
        $ids = [];

        try {
            $result = $this->database->queryBuilder()
                ->table('#PREFIX#userstogroups')
                ->select(['userid'])
                ->whereIn('groupid', $groups)
                ->get();

            while (($row = $result->fetch()) !== null) {
                $id = (int) ($row['userid'] ?? 0);

                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return array_keys($ids);
    }

    /**
     * The accounts belonging to any of these organizations.
     *
     * The membership table is the authserver feature's, and its name is configurable — so an
     * installation without that feature has no such table, and this answers "nobody" rather
     * than raising. A filter nobody can satisfy resolving to an empty audience is the safe
     * direction: the other one sends to everybody.
     *
     * @param  list<int> $organizations
     * @return list<int>
     */
    protected function membersOfOrganizations(array $organizations): array
    {
        $ids = [];

        try {
            $result = $this->database->queryBuilder()
                ->table(static::organizationMembershipTable())
                ->select(['user_id'])
                ->whereIn(static::organizationColumn(), $organizations)
                ->get();

            while (($row = $result->fetch()) !== null) {
                $id = (int) ($row['user_id'] ?? 0);

                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return array_keys($ids);
    }

    /** Where organization membership lives — the same setting the organizations screen reads. */
    public static function organizationMembershipTable(): string
    {
        $setting = trim((string) \Pramnos\Application\Settings::getSetting(
            'authserver_organization_table',
            ''
        ));

        return 'authserver.' . ($setting !== '' ? $setting : 'user_organizations');
    }

    /** The organization foreign key on that table. */
    public static function organizationColumn(): string
    {
        return (string) \Pramnos\Application\Settings::getSetting(
            'authserver_organization_column',
            'organization_id'
        );
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
                ->table('pramnos.emailoptouts')
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
