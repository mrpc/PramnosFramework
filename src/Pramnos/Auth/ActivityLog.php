<?php

namespace Pramnos\Auth;

/**
 * Canonical writer for the per-user activity log (authserver.user_activity_log).
 *
 * This is the single, reusable place that records security-relevant user
 * events (login, logout, password change, passkey add/remove, …) into the
 * activity-log table that the built-in authserver dashboard / security pages
 * read back. Both the framework's built-in login lifecycle
 * ({@see \Pramnos\Auth\Auth::executeDefaultLogin()} /
 * {@see \Pramnos\Auth\Auth::executeDefaultLogout()}) and the built-in auth
 * controllers ({@see \Pramnos\Auth\Controllers\Account},
 * {@see \Pramnos\Auth\Controllers\Passkey}) funnel through {@see self::record()}.
 *
 * Design constraints:
 *   - Additive and self-guarding. If the `user_activity_log` table is absent
 *     (an application that never ran the authserver migrations — e.g. a legacy
 *     install with a migration cutoff), every call is a silent no-op. It never
 *     throws into the caller: a logging failure must never break a login.
 *   - It is NOT wired into the shared {@see \Pramnos\Auth\Auth::triggerLogin()}
 *     choke point, only into the *built-in* lifecycle. Applications that bring
 *     their own `Addon\User\*` login handler (e.g. the reference application) take the addon
 *     path instead and own their own activity logging, so they are neither
 *     touched by this class nor double-logged by it.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license     MIT
 */
class ActivityLog
{
    /**
     * Memoized result of the table-existence probe, so we hit
     * information_schema at most once per request.
     *
     * @var bool|null
     */
    private static ?bool $tableAvailable = null;

    /**
     * Record a single activity-log entry for a user.
     *
     * Never throws: any database/adapter failure (including the table being
     * absent) is swallowed and logged to the framework log, so a failure here
     * can never interrupt a login/logout.
     *
     * @param int                  $userId  users.userid the event belongs to.
     * @param string               $action  Short action key (e.g. 'login',
     *                                       'logout', 'password_changed'); it is
     *                                       truncated to the column's 100 chars.
     * @param array<string, mixed> $details Optional context, JSON-encoded into
     *                                       the `details` column.
     * @return void
     */
    public static function record(int $userId, string $action, array $details = []): void
    {
        if ($userId <= 0 || $action === '') {
            return;
        }

        // Cheap gate first: the user_activity_log table ships with the 'auth'
        // feature's migrations, so when that feature is off the table cannot
        // exist — skip without touching the database at all.
        if (!\Pramnos\Application\FeatureRegistry::isEnabled('auth')) {
            return;
        }

        try {
            $database = \Pramnos\Framework\Factory::getDatabase();
            if (!self::tableAvailable($database)) {
                return;
            }

            $ip = \Pramnos\Http\Request::clientIp() ?: null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $database->queryBuilder()
                ->table('authserver.user_activity_log')
                ->insert([
                    'userid'     => $userId,
                    'action'     => substr($action, 0, 100),
                    'details'    => $details === [] ? null : json_encode($details),
                    'ip_address' => $ip !== null ? substr((string) $ip, 0, 45) : null,
                    'user_agent' => $ua !== null ? (string) $ua : null,
                    'created_at' => date('c'),
                ]);
        } catch (\Throwable $ex) {
            // Logging must never break the caller — record the failure and move on.
            \Pramnos\Logs\Logger::log(
                'ActivityLog::record failed: ' . $ex->getMessage(),
                'auth'
            );
        }
    }

    /**
     * Whether the activity-log table exists on the current connection.
     *
     * The result is memoized for the request. The probe goes through the
     * schema builder ({@see \Pramnos\Database\SchemaBuilder::hasTable()}) with
     * the fully-qualified `authserver.user_activity_log` name — the same call
     * the creating migration uses. This is driver-aware: on PostgreSQL it
     * resolves to the real `authserver` schema, and on MySQL to the
     * `authserver_` table-prefix emulation. A plain
     * {@see \Pramnos\Database\Database::tableExists()} would only match the
     * unqualified name and so miss the prefixed physical table on MySQL.
     *
     * @param \Pramnos\Database\Database $database Active database handle.
     * @return bool
     */
    private static function tableAvailable(\Pramnos\Database\Database $database): bool
    {
        if (self::$tableAvailable === null) {
            try {
                self::$tableAvailable = $database->schema()
                    ->hasTable('authserver.user_activity_log');
            } catch (\Throwable $ex) {
                self::$tableAvailable = false;
            }
        }

        return self::$tableAvailable;
    }

    /**
     * Reset the memoized table-existence probe.
     *
     * Test seam only: lets a test toggle the underlying schema between cases
     * without the first probe's result sticking for the whole process.
     *
     * @return void
     */
    public static function resetTableCache(): void
    {
        self::$tableAvailable = null;
    }
}
