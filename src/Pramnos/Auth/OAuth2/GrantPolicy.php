<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2;

use Pramnos\Auth\OAuthPolicyHelper;

/**
 * Which grant types an application may use: `applications.oauth2_application_grants`.
 *
 * A policy table that cannot be read is an error, not the defaults: a server must not grant
 * more than it was told because it could not read what it was told.
 *
 * Each row overrides the default for its one grant: a grant with a row is allowed when the row is
 * enabled, and a grant without one follows {@see OAuthPolicyHelper::getDefaultAllowedGrantTypes()}.
 * `jwt_bearer` is never a default — its holder obtains a token for any user the assertion names —
 * so an application uses it only with an enabled row:
 *
 * ```php
 * GrantPolicy::enable($appId, 'jwt_bearer');   // adds jwt_bearer; the defaults still apply
 * GrantPolicy::disable($appId, 'password');    // removes password only
 * ```
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
        return self::overrides($appId)[$grant] ?? in_array($grant, OAuthPolicyHelper::getDefaultAllowedGrantTypes(), true);
    }

    /**
     * The grants an application allows, as stored; empty when it has no rows (the defaults apply).
     *
     * @return list<string>
     */
    public static function grants(int $appId): array
    {
        $db   = \Pramnos\Framework\Factory::getDatabase();
        $rows = $db->schema()->hasTable(self::TABLE)
            ? $db->queryBuilder()->table(self::TABLE)->where('appid', $appId)->getAll()
            : [];

        return array_values(array_map(
            static fn (array $row): string => (string) $row['grant_type'],
            array_filter($rows, static fn (array $row): bool => (bool) $row['is_enabled'] && $row['is_enabled'] !== 'f')
        ));
    }

    /**
     * The grants an application may use now: the defaults, with its rows applied over them.
     *
     * @return list<string>
     */
    public static function effective(int $appId): array
    {
        $allowed = array_fill_keys(OAuthPolicyHelper::getDefaultAllowedGrantTypes(), true);
        foreach (self::overrides($appId) as $grant => $enabled) {
            $allowed[$grant] = $enabled;
        }

        return array_keys(array_filter($allowed));
    }

    /**
     * An application's rows, as grant => enabled.
     *
     * @return array<string, bool>
     */
    private static function overrides(int $appId): array
    {
        $db   = \Pramnos\Framework\Factory::getDatabase();
        $rows = $db->schema()->hasTable(self::TABLE)
            ? $db->queryBuilder()->table(self::TABLE)->where('appid', $appId)->getAll()
            : [];

        $overrides = [];
        foreach ($rows as $row) {
            $overrides[(string) $row['grant_type']] = (bool) $row['is_enabled'] && $row['is_enabled'] !== 'f';
        }

        return $overrides;
    }

    /**
     * Allow an application exactly these grants.
     *
     * Every known grant gets a row, enabled or not, so an empty list means none — never "the
     * defaults". {@see useDefaults()} is how an application goes back to them.
     *
     * @param list<string> $grants Each one of {@see OAuthPolicyHelper::getGrantTypes()}
     * @throws \InvalidArgumentException for a grant the server does not know
     */
    public static function setGrants(int $appId, array $grants): void
    {
        $known = array_column(OAuthPolicyHelper::getGrantTypes(), 'method');
        foreach ($grants as $grant) {
            if (!in_array($grant, $known, true)) {
                throw new \InvalidArgumentException("Unknown grant type \"{$grant}\".");
            }
        }

        $db = \Pramnos\Framework\Factory::getDatabase();
        $db->queryBuilder()->table(self::TABLE)->where('appid', $appId)->delete();
        foreach ($known as $grant) {
            $db->queryBuilder()->table(self::TABLE)
                ->insert(['appid' => $appId, 'grant_type' => $grant, 'is_enabled' => in_array($grant, $grants, true)]);
        }
    }

    /**
     * Remove an application's rows, so the defaults apply — and follow them when they change.
     */
    public static function useDefaults(int $appId): void
    {
        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table(self::TABLE)->where('appid', $appId)->delete();
    }

    /** Whether an application has a policy of its own. */
    public static function hasRows(int $appId): bool
    {
        $db = \Pramnos\Framework\Factory::getDatabase();

        return $db->schema()->hasTable(self::TABLE)
            && $db->queryBuilder()->table(self::TABLE)->where('appid', $appId)->count() > 0;
    }

    /**
     * Let an application use a grant, whatever the defaults say.
     */
    public static function enable(int $appId, string $grant): void
    {
        self::set($appId, $grant, true);
    }

    /**
     * Stop an application using a grant, whatever the defaults say.
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
