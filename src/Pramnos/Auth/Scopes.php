<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * OAuth2 scope registry — centralized definition of all available scopes.
 *
 * Scopes are organised into named categories. Each scope entry carries:
 *   - 'description'  Human-readable string shown on consent screens
 *   - 'is_default'   When true, the scope is implicitly granted to all clients
 *   - 'inherits'     Scopes that are automatically included when this scope is granted
 *
 * Framework scopes defined here cover standard OpenID Connect / OAuth 2.0 scopes.
 * Application-specific scopes should extend or override getScopes() in a subclass.
 *
 * @package     PramnosFramework
 * @subpackage  Auth
 */
class Scopes
{
    /**
     * Return all available scopes grouped by category.
     *
     * Keys are scope identifier strings (space-safe, no special chars except ':').
     * Suitable for rendering a consent screen grouped by category.
     *
     * @return array<string, array<string, array{description: string, is_default: bool, inherits: string[]}>>
     */
    public static function getScopes(): array
    {
        return [
            'Personal User Data' => [
                'profile' => [
                    'description' => 'Access to basic profile information (name, picture, locale, etc.).',
                    'is_default'  => true,
                    'inherits'    => [],
                ],
                'email' => [
                    'description' => 'Access to the user\'s primary email address.',
                    'is_default'  => true,
                    'inherits'    => [],
                ],
                'phone' => [
                    'description' => 'Access to the user\'s phone number.',
                    'is_default'  => false,
                    'inherits'    => [],
                ],
                'address' => [
                    'description' => 'Access to the user\'s physical address.',
                    'is_default'  => false,
                    'inherits'    => [],
                ],
                'user' => [
                    'description' => 'Access to user account information.',
                    'is_default'  => true,
                    'inherits'    => [],
                ],
            ],
            'Account Actions' => [
                'openid' => [
                    'description' => 'Required for OpenID Connect requests — enables the ID Token.',
                    'is_default'  => false,
                    'inherits'    => [],
                ],
                'offline_access' => [
                    'description' => 'Allow the application to perform actions on your behalf when you are not online (issues a refresh token).',
                    'is_default'  => false,
                    'inherits'    => [],
                ],
            ],
            'System & Administrative' => [
                'system:admin' => [
                    'description' => 'Full administrative access — grants all standard scopes.',
                    'is_default'  => false,
                    'inherits'    => [
                        'profile', 'email', 'phone', 'address', 'user',
                        'openid', 'offline_access',
                        'system:audit_read', 'system:health',
                        'system:notifications_read', 'system:notifications_write',
                    ],
                ],
                'system:audit_read' => [
                    'description' => 'Read access to audit logs.',
                    'is_default'  => false,
                    'inherits'    => [],
                ],
                'system:health' => [
                    'description' => 'Access to system health and monitoring data.',
                    'is_default'  => false,
                    'inherits'    => [],
                ],
                'system:notifications_read' => [
                    'description' => 'Read user notifications.',
                    'is_default'  => false,
                    'inherits'    => [],
                ],
                'system:notifications_write' => [
                    'description' => 'Send and manage user notifications.',
                    'is_default'  => false,
                    'inherits'    => ['system:notifications_read'],
                ],
            ],
        ];
    }

    /**
     * Return a flat map of scope → description for all defined scopes.
     *
     * @return array<string, string>
     */
    public static function getScopeDescriptions(): array
    {
        $descriptions = [];
        foreach (static::getScopes() as $category) {
            foreach ($category as $scope => $details) {
                $descriptions[$scope] = $details['description'];
            }
        }
        return $descriptions;
    }

    /**
     * Return the list of scopes that are granted by default to all clients.
     *
     * @return string[]
     */
    public static function getDefaultScopes(): array
    {
        $defaults = [];
        foreach (static::getScopes() as $category) {
            foreach ($category as $scope => $details) {
                if (!empty($details['is_default'])) {
                    $defaults[] = $scope;
                }
            }
        }
        return $defaults;
    }

    /**
     * Check whether a scope string contains any undefined scope identifiers.
     *
     * @param string $scopeString Space-delimited scope string (e.g. "profile email openid")
     * @return array{0: bool, 1: string[]} [hasInvalid, invalidScopes]
     */
    public static function hasInvalidScopes(string $scopeString): array
    {
        $valid   = array_keys(static::getScopeDescriptions());
        $invalid = [];

        foreach (preg_split('/\s+/', trim($scopeString)) as $scope) {
            if ($scope !== '' && !in_array($scope, $valid, true)) {
                $invalid[] = $scope;
            }
        }

        return [count($invalid) > 0, $invalid];
    }

    /**
     * Resolve a set of scopes to include all transitively inherited scopes.
     *
     * Given `['system:notifications_write']`, returns
     * `['system:notifications_read', 'system:notifications_write']`.
     * Infinite recursion is prevented by tracking already-visited scopes.
     *
     * @param string|string[] $scopes Space-delimited string or array of scope identifiers
     * @return string[] Unique, sorted array of all scopes including inherited ones
     */
    public static function resolveInheritedScopes($scopes): array
    {
        if (is_string($scopes)) {
            $scopes = array_filter(preg_split('/\s+/', trim($scopes)));
        }

        if (!is_array($scopes)) {
            return [];
        }

        // Build a flat lookup of scope → details
        $flat = [];
        foreach (static::getScopes() as $category) {
            foreach ($category as $scope => $details) {
                $flat[$scope] = $details;
            }
        }

        $resolved = [];

        $resolve = function (string $scope) use (&$resolve, $flat, &$resolved): void {
            if (in_array($scope, $resolved, true) || !isset($flat[$scope])) {
                return;
            }
            $resolved[] = $scope;
            foreach ($flat[$scope]['inherits'] as $inherited) {
                $resolve($inherited);
            }
        };

        foreach ($scopes as $scope) {
            $resolve($scope);
        }

        $unique = array_unique($resolved);
        sort($unique);
        return $unique;
    }

    /**
     * Verify that all requested scopes are valid and permitted for a given application.
     *
     * A scope is permitted when it is either:
     *   - a default scope (implicitly granted to all clients), OR
     *   - listed in the application's allowed scopes in the `applications` table.
     *
     * Requires the OAuth server `applications` table (OAuth server migrations).
     *
     * @param string $requestedScopesString Space-delimited requested scopes
     * @param string $apiKey                The application's API key
     * @return array{0: bool, 1: string[]} [allGranted, problematicScopes]
     */
    public static function areApplicationScopesGranted(string $requestedScopesString, string $apiKey): array
    {
        [, $invalidScopes] = static::hasInvalidScopes($requestedScopesString);
        $problematic       = $invalidScopes;

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('applications')
            ->select('scope')
            ->where('apikey', $apiKey)
            ->first();

        $allowedScopes = [];
        if ($result && $result->numRows > 0) {
            $raw           = (string) ($result->fields['scope'] ?? '');
            $allowedScopes = array_filter(preg_split('/\s+/', trim($raw)));
        }

        $defaultScopes  = static::getDefaultScopes();
        $requestedScopes = array_filter(preg_split('/\s+/', trim($requestedScopesString)));

        foreach ($requestedScopes as $scope) {
            if (in_array($scope, $invalidScopes, true)) {
                continue;
            }
            if (!in_array($scope, $defaultScopes, true) && !in_array($scope, $allowedScopes, true)) {
                $problematic[] = $scope;
            }
        }

        return [empty($problematic), array_unique($problematic)];
    }
}
