<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * Progressive login brute-force lockout.
 *
 * Tracks failed login attempts per scope+identifier pair and applies a
 * progressive lockout based on configurable thresholds. Supported scopes
 * are 'user' (by user ID string), 'identifier' (by normalised email/username),
 * and 'ip' (by IP address), but any string is accepted.
 *
 * Lockout thresholds (default):
 *   3 failures  →  60 s
 *   5 failures  → 300 s
 *   7 failures  → 900 s
 *   10+ failures → 3600 s
 *
 * A sliding window (default 900 s) applies: if the gap between the last failure
 * and the current attempt exceeds the window, the counter resets to 1.
 *
 * Timestamps are stored as UTC datetime strings (TIMESTAMPTZ on PostgreSQL,
 * DATETIME on MySQL) in the `authserver.loginlockouts` table.
 * NULL timestamps mean "never occurred" — there is no integer-0 sentinel.
 * On MySQL the schema prefix is expressed as a table-name prefix
 * (`authserver_loginlockouts`); on PostgreSQL the schema is the `authserver`
 * namespace. Schema resolution and dialect-appropriate quoting is handled
 * automatically by QueryBuilder::table().
 *
 */
class Loginlockout
{
    /**
     * Sliding window duration in seconds.
     * Failures outside this window do not count toward the next lockout.
     */
    public const DEFAULT_WINDOW_SECONDS = 900;

    /**
     * Progressive lockout steps: [minAttempts => lockDurationSeconds].
     * Evaluated in ascending key order; the highest matching threshold wins.
     */
    public const DEFAULT_STEPS = [
        3  => 60,
        5  => 300,
        7  => 900,
        10 => 3600,
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Record a failed login attempt for the given scope and identifier.
     *
     * Increments the failure counter (or starts a new window if the previous
     * failure was outside the sliding window) and updates lockoutuntil based
     * on the configured thresholds.
     *
     * @param string $scope      Lock scope: 'user', 'identifier', or 'ip'
     * @param string $identifier The value to track (user ID, email/username, IP)
     */
    public function recordFailedAttempt(string $scope, string $identifier): void
    {
        $db       = \Pramnos\Database\Database::getInstance();
        $now      = time();
        $nowStr   = $this->formatTimestamp($now);
        $row      = $this->loadRow($scope, $identifier);

        // Determine attempt count within the current sliding window
        $windowStart = $now - self::DEFAULT_WINDOW_SECONDS;
        if ($row && !empty($row['lastfailedat'])
            && strtotime((string) $row['lastfailedat']) >= $windowStart
        ) {
            $attempts    = (int) $row['failedattempts'] + 1;
            $firstFailed = (string) $row['firstfailedat'];
        } else {
            $attempts    = 1;
            $firstFailed = $nowStr;
        }

        $duration     = $this->calculateDuration($attempts);
        $lockoutUntil = $duration > 0
            ? $this->formatTimestamp($now + $duration)
            : null;

        if ($row) {
            $db->queryBuilder()
                ->table('authserver.loginlockouts')
                ->where('lockoutid', (int) $row['lockoutid'])
                ->update([
                    'failedattempts' => $attempts,
                    'firstfailedat'  => $firstFailed,
                    'lastfailedat'   => $nowStr,
                    'lockoutuntil'   => $lockoutUntil,
                    'updatedat'      => $nowStr,
                ]);
        } else {
            $db->queryBuilder()
                ->table('authserver.loginlockouts')
                ->insert([
                    'locktype'       => $scope,
                    'lookupvalue'    => $identifier,
                    'failedattempts' => $attempts,
                    'firstfailedat'  => $firstFailed,
                    'lastfailedat'   => $nowStr,
                    'lockoutuntil'   => $lockoutUntil,
                    'createdat'      => $nowStr,
                    'updatedat'      => $nowStr,
                ]);
        }
    }

    /**
     * Return the lockout state for the given scope and identifier.
     *
     * @param string $scope
     * @param string $identifier
     * @return array{locked: bool, remaining: int}
     *         'locked'    — true when an active lockout is in effect
     *         'remaining' — seconds until the lockout expires (0 if not locked)
     */
    public function getLockoutStatus(string $scope, string $identifier): array
    {
        $row = $this->loadRow($scope, $identifier);

        if (!$row) {
            return ['locked' => false, 'remaining' => 0];
        }

        $now          = time();
        $lockoutUntil = !empty($row['lockoutuntil'])
            ? strtotime((string) $row['lockoutuntil'])
            : 0;

        if ($lockoutUntil > $now) {
            return ['locked' => true, 'remaining' => $lockoutUntil - $now];
        }

        return ['locked' => false, 'remaining' => 0];
    }

    /**
     * Clear lockout state after a successful login.
     *
     * Deletes the tracking row for the given scope+identifier pair, resetting
     * both the failure counter and any active lockout.
     *
     * @param string $scope
     * @param string $identifier
     */
    public function clearSuccessfulLoginState(string $scope, string $identifier): void
    {
        $db = \Pramnos\Database\Database::getInstance();
        $db->queryBuilder()
            ->table('authserver.loginlockouts')
            ->where('locktype', $scope)
            ->where('lookupvalue', $identifier)
            ->delete();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Load the lockout row for the given scope+identifier, or null if absent.
     *
     * @return array<string, mixed>|null
     */
    protected function loadRow(string $scope, string $identifier): ?array
    {
        $db     = \Pramnos\Database\Database::getInstance();
        $result = $db->queryBuilder()
            ->table('authserver.loginlockouts')
            ->select('*')
            ->where('locktype', $scope)
            ->where('lookupvalue', $identifier)
            ->first();

        if (!$result || $result->numRows < 1) {
            return null;
        }

        return $result->fields;
    }

    /**
     * Format a Unix timestamp as a UTC datetime string for DB storage.
     *
     * @param int $ts Unix timestamp
     * @return string  e.g. '2024-01-15 10:30:00'
     */
    protected function formatTimestamp(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Resolve lockout duration from the configured steps.
     *
     * Steps are evaluated in ascending threshold order; the duration from the
     * highest threshold that the attempt count meets or exceeds is returned.
     * Returns 0 when no threshold has been crossed.
     *
     * @param int $attempts Number of failed attempts in the current window
     * @return int Lockout duration in seconds (0 = no lockout yet)
     */
    protected function calculateDuration(int $attempts): int
    {
        $steps    = self::DEFAULT_STEPS;
        $duration = 0;
        ksort($steps, SORT_NUMERIC);

        foreach ($steps as $threshold => $stepDuration) {
            if ($attempts >= (int) $threshold) {
                $duration = (int) $stepDuration;
            }
        }

        return $duration;
    }
}
