<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2;

use Pramnos\Auth\OAuthPolicyHelper;

/**
 * Which grant types an application may use: `applications.oauth2_application_grants`.
 *
 * An application with no rows there may use the defaults,
 * {@see OAuthPolicyHelper::getDefaultAllowedGrantTypes()}. One with rows may use exactly the
 * grants it has an enabled row for. `jwt_bearer` is never a default — its holder obtains a token
 * for any user the assertion names — so an application uses it only with a row:
 *
 * ```php
 * GrantPolicy::enable($appId, 'jwt_bearer');
 * ```
 *
 * Enabling one grant on an application that had no rows replaces the defaults with that one; enable
 * the others it uses beside it.
 */
final class GrantPolicy
{
    private const TABLE = 'applications.oauth2_application_grants';

    /**
     * May this application use this grant?
     *
     * @param int    $appId The `applications.appid`
     * @param string $grant The policy name: `device_code`, `exchange_token`, `jwt_bearer`, …
     */
    public static function allows(int $appId, string $grant): bool
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        try {
            $rows = $db->schema()->hasTable(self::TABLE)
                ? $db->queryBuilder()->table(self::TABLE)->where('appid', $appId)->getAll()
                : [];
        } catch (\Throwable) {
            $rows = [];
        }

        if ($rows === []) {
            return in_array($grant, OAuthPolicyHelper::getDefaultAllowedGrantTypes(), true);
        }
        foreach ($rows as $row) {
            if ($row['grant_type'] === $grant && (bool) $row['is_enabled']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Let an application use a grant.
     */
    public static function enable(int $appId, string $grant): void
    {
        self::set($appId, $grant, true);
    }

    /**
     * Stop an application using a grant, keeping the row so the policy stays explicit.
     */
    public static function disable(int $appId, string $grant): void
    {
        self::set($appId, $grant, false);
    }

    /** Write one row of the policy. */
    private static function set(int $appId, string $grant, bool $enabled): void
    {
        $db       = \Pramnos\Framework\Factory::getDatabase();
        $existing = $db->queryBuilder()->table(self::TABLE)->where('appid', $appId)->where('grant_type', $grant)->count();
        if ($existing > 0) {
            $db->queryBuilder()->table(self::TABLE)->where('appid', $appId)->where('grant_type', $grant)
                ->update(['is_enabled' => $enabled]);

            return;
        }
        $db->queryBuilder()->table(self::TABLE)->insert(['appid' => $appId, 'grant_type' => $grant, 'is_enabled' => $enabled]);
    }
}
