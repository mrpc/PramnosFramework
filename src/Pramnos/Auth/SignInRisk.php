<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * Why *this* sign-in is worth questioning — beyond the browser being unfamiliar.
 *
 * "A device this account has not used" is a weak signal on its own. People buy phones,
 * clear cookies and use a colleague's laptop, so on a large site it fires constantly, and
 * a demand attached to it becomes a tax everybody pays and nobody reads. The signals worth
 * acting on are the ones that are hard to explain innocently: the account is being used
 * from a country it has never been used from, or from two places at once, or immediately
 * after somebody spent ten minutes guessing its password.
 *
 * This class collects those signals so that {@see NewSignInAlert} can act on *suspicion*
 * rather than on novelty. It answers with names, not a score: a screen and a log both have
 * to be able to say **which** signal fired, and a number cannot be argued with by the
 * person it inconveniences.
 *
 * ## What it can see, and what it honestly cannot
 *
 * Geography comes from `HTTP_CF_IPCOUNTRY` when the site is behind Cloudflare — the same
 * header the session tracker already uses. **There is no IP-to-location database in this
 * framework**, so without that header (or one an application supplies through
 * {@see country()}'s hook) the country signals simply do not fire, and this class says so
 * rather than guessing from the address. Two consequences worth stating plainly:
 *
 *   - "impossible travel" is measured at country granularity, not in kilometres. Rome to
 *     Milan in ten minutes is invisible; Rome to Jakarta in ten minutes is not. That is
 *     the resolution the available data supports, and pretending otherwise would be a
 *     number nobody could trust.
 *   - "two places at once" falls back to comparing address prefixes when no country is
 *     available, which catches a different network and not a different street.
 *
 * An application with a real geolocation source should add its own signals rather than
 * have this class pretend: see {@see SIGNAL_APPLICATION} and the `auth.signin_risk` event.
 */
class SignInRisk
{
    /** The browser/device combination is new to this account. */
    public const SIGNAL_NEW_DEVICE = 'new_device';

    /** The account has never been used from this country. */
    public const SIGNAL_NEW_COUNTRY = 'new_country';

    /** A different country from the previous sign-in, too recently to have travelled. */
    public const SIGNAL_IMPOSSIBLE_TRAVEL = 'impossible_travel';

    /** Another session is live somewhere else right now. */
    public const SIGNAL_CONCURRENT_ELSEWHERE = 'concurrent_elsewhere';

    /** Somebody was guessing this account's password just before this succeeded. */
    public const SIGNAL_AFTER_FAILURES = 'after_failures';

    /** Something the application's own assessment added. */
    public const SIGNAL_APPLICATION = 'application';

    /**
     * Signals that make a sign-in *suspicious* rather than merely unfamiliar.
     *
     * `new_device` is deliberately absent: it is the novelty signal, and treating it as
     * suspicion is what makes a step-up fire on every new phone.
     */
    public const SUSPICIOUS = [
        self::SIGNAL_NEW_COUNTRY,
        self::SIGNAL_IMPOSSIBLE_TRAVEL,
        self::SIGNAL_CONCURRENT_ELSEWHERE,
        self::SIGNAL_AFTER_FAILURES,
        self::SIGNAL_APPLICATION,
    ];

    /** How recent another session must be to count as concurrent, in seconds. */
    public const CONCURRENT_WINDOW = 900;

    /** Two countries closer together in time than this cannot both be true. */
    public const TRAVEL_WINDOW = 10800;

    /** Failed attempts within this window count as somebody guessing. */
    public const FAILURE_WINDOW = 900;

    /** How many failures in that window it takes to count. */
    public const FAILURE_THRESHOLD = 3;

    /**
     * Every signal that fires for this sign-in.
     *
     * Best-effort throughout: a missing table or an unreadable log means fewer signals,
     * never a failed login. A risk assessment that can break authentication is a worse
     * problem than the one it solves.
     *
     * @return list<string>
     */
    public static function assess(
        int $userId,
        ?\Pramnos\Database\Database $database = null
    ): array {
        if ($userId < 2) {
            return array();
        }

        $signals = array();

        try {
            $fingerprint = SignInFingerprint::current();
            if (NewSignInAlert::isNew($userId, $fingerprint, $database)) {
                $signals[] = self::SIGNAL_NEW_DEVICE;
            }
        } catch (\Throwable $exception) {
            // Novelty unknown; the remaining signals still apply.
        }

        $country = self::country();
        if ($country !== '') {
            $history = self::countryHistory($userId, $database);

            if ($history !== array() && !isset($history[$country])) {
                $signals[] = self::SIGNAL_NEW_COUNTRY;
            }

            $last = self::lastCountryAndTime($userId, $database);
            if ($last !== null
                && $last['country'] !== ''
                && $last['country'] !== $country
                && (time() - $last['when']) < self::TRAVEL_WINDOW
            ) {
                $signals[] = self::SIGNAL_IMPOSSIBLE_TRAVEL;
            }
        }

        if (self::hasConcurrentSessionElsewhere($userId, $database)) {
            $signals[] = self::SIGNAL_CONCURRENT_ELSEWHERE;
        }

        if (self::recentFailureCount($userId, $database) >= self::FAILURE_THRESHOLD) {
            $signals[] = self::SIGNAL_AFTER_FAILURES;
        }

        /**
         * The application's own assessment.
         *
         * The hook exists because the signals above are the ones computable from data this
         * framework already keeps. An application with a geolocation database, a device
         * reputation service or knowledge of its own users' habits knows things this class
         * cannot, and the alternative to a hook is that it forks the policy.
         *
         * Listeners return true to add {@see SIGNAL_APPLICATION}.
         */
        try {
            $verdict = \Pramnos\Event\Event::fire('auth.signin_risk', array(
                'userid'  => $userId,
                'country' => $country,
                'signals' => $signals,
            ));

            // `fire()` always answers with an array of listener results — one per
            // listener, in priority order — so any single `true` is a vote to flag.
            if (in_array(true, $verdict, true)) {
                $signals[] = self::SIGNAL_APPLICATION;
            }
        } catch (\Throwable $exception) {
            // A listener that throws must not fail the login.
        }

        return array_values(array_unique($signals));
    }

    /**
     * Does this sign-in look suspicious, as opposed to merely unfamiliar?
     *
     * @param list<string>|null $signals Pass an assessment to avoid repeating it.
     */
    public static function isSuspicious(
        int $userId,
        ?array $signals = null,
        ?\Pramnos\Database\Database $database = null
    ): bool {
        $signals ??= self::assess($userId, $database);

        return array_intersect($signals, self::SUSPICIOUS) !== array();
    }

    /**
     * The two-letter country for this request, or '' when nothing can say.
     *
     * Cloudflare's header, or whatever an application answers on the `auth.signin_country`
     * event — which is the seam for an installation that has a geolocation database. `XX`
     * is Cloudflare's own "unknown" and is treated as unknown rather than as a country,
     * since otherwise every unresolvable address would look like one consistent place.
     */
    public static function country(): string
    {
        $header = (string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '');
        $country = strtoupper(preg_replace('/[^A-Za-z]/', '', $header) ?? '');

        if ($country !== '' && $country !== 'XX' && strlen($country) === 2) {
            return $country;
        }

        try {
            $supplied = \Pramnos\Event\Event::fire('auth.signin_country', array(
                'ip' => \Pramnos\Http\Request::clientIp(),
            ));
        } catch (\Throwable $exception) {
            return '';
        }

        // The first listener that answers with a country wins; the rest are ignored
        // rather than merged, because two answers to "where is this request from" is a
        // misconfiguration and picking between them would hide it.
        foreach ($supplied as $value) {
            if (is_string($value) && strlen($value) === 2) {
                return strtoupper($value);
            }
        }

        return '';
    }

    // ── The individual questions ──────────────────────────────────────────────

    /**
     * Countries this account has signed in from, from the activity log's own details.
     *
     * An empty result means *unknown*, not *none* — an account whose history predates
     * country recording must not have every sign-in flagged as a new country. This is the
     * same rule {@see NewSignInAlert::isNew()} follows for devices, and for the same
     * reason: on the day a signal ships, every account looks new.
     *
     * @return array<string, true>
     */
    private static function countryHistory(
        int $userId,
        ?\Pramnos\Database\Database $database
    ): array {
        $rows = self::signInDetails($userId, $database, 100);
        $countries = array();

        foreach ($rows as $row) {
            $country = (string) ($row['country'] ?? '');
            if ($country !== '') {
                $countries[$country] = true;
            }
        }

        return $countries;
    }

    /**
     * The country and time of the most recent previous sign-in that recorded one.
     *
     * @return array{country: string, when: int}|null
     */
    private static function lastCountryAndTime(
        int $userId,
        ?\Pramnos\Database\Database $database
    ): ?array {
        foreach (self::signInDetails($userId, $database, 20) as $row) {
            $country = (string) ($row['country'] ?? '');
            if ($country !== '') {
                return array('country' => $country, 'when' => (int) ($row['when'] ?? 0));
            }
        }

        return null;
    }

    /**
     * Recent sign-ins for this account, newest first, as decoded details.
     *
     * @return list<array{country?: string, when?: int}>
     */
    private static function signInDetails(
        int $userId,
        ?\Pramnos\Database\Database $database,
        int $limit
    ): array {
        $database ??= \Pramnos\Framework\Factory::getDatabase();

        try {
            $result = $database->queryBuilder()
                ->table('authserver.user_activity_log')
                ->select(['details', 'created_at'])
                ->where('userid', $userId)
                ->where('action', 'login')
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();
        } catch (\Throwable $exception) {
            return array();
        }

        $rows = array();
        while ($result !== null && ($result->numRows ?? 0) > 0 && !$result->eof) {
            $details = json_decode((string) ($result->fields['details'] ?? ''), true);
            $rows[] = array(
                'country' => is_array($details) ? (string) ($details['country'] ?? '') : '',
                'when'    => (int) strtotime((string) ($result->fields['created_at'] ?? '')),
            );
            $result->MoveNext();
        }

        return $rows;
    }

    /**
     * Is another session live right now, from somewhere that is not here?
     *
     * "Somewhere else" is a different country when countries are known, and a different
     * address prefix when they are not — which catches a different network rather than a
     * different street, and is named that way in the guide so nobody reads more into it.
     *
     * The account's *own* current session is excluded by address: a person with two tabs
     * is not two places.
     */
    private static function hasConcurrentSessionElsewhere(
        int $userId,
        ?\Pramnos\Database\Database $database
    ): bool {
        $database ??= \Pramnos\Framework\Factory::getDatabase();
        $here = (string) (\Pramnos\Http\Request::clientIp() ?: '');
        if ($here === '') {
            return false;
        }

        try {
            $result = $database->queryBuilder()
                ->table('#PREFIX#sessions')
                ->select(['host_addr'])
                ->where('userid', $userId)
                ->where('logout', 0)
                ->where('time', '>', time() - self::CONCURRENT_WINDOW)
                ->limit(20)
                ->get();
        } catch (\Throwable $exception) {
            return false;
        }

        while ($result !== null && ($result->numRows ?? 0) > 0 && !$result->eof) {
            $there = (string) ($result->fields['host_addr'] ?? '');
            if ($there !== '' && !self::samePlace($here, $there)) {
                return true;
            }
            $result->MoveNext();
        }

        return false;
    }

    /**
     * Two addresses that plausibly belong to one person in one place.
     *
     * The first two octets for IPv4 (a /16), the first three groups for IPv6. Crude on
     * purpose: the question is "did this account just appear on an unrelated network",
     * and a mobile connection changes its address within an operator's range constantly —
     * comparing whole addresses would report every phone as a second place.
     */
    private static function samePlace(string $here, string $there): bool
    {
        if ($here === $there) {
            return true;
        }

        if (str_contains($here, ':') || str_contains($there, ':')) {
            return implode(':', array_slice(explode(':', $here), 0, 3))
                === implode(':', array_slice(explode(':', $there), 0, 3));
        }

        return implode('.', array_slice(explode('.', $here), 0, 2))
            === implode('.', array_slice(explode('.', $there), 0, 2));
    }

    /**
     * Failed sign-ins for this account in the recent past.
     *
     * A success straight after a run of failures is the signature of a guess that landed —
     * whether by a list of leaked passwords or by somebody who knew three of the four
     * things they needed.
     */
    private static function recentFailureCount(
        int $userId,
        ?\Pramnos\Database\Database $database
    ): int {
        $database ??= \Pramnos\Framework\Factory::getDatabase();

        try {
            $result = $database->queryBuilder()
                ->table('authserver.user_activity_log')
                ->select(['action'])
                ->where('userid', $userId)
                ->where('action', 'login_failed')
                ->where('created_at', '>', gmdate('Y-m-d H:i:s', time() - self::FAILURE_WINDOW))
                ->limit(self::FAILURE_THRESHOLD + 1)
                ->get();
        } catch (\Throwable $exception) {
            return 0;
        }

        return (int) ($result->numRows ?? 0);
    }
}
