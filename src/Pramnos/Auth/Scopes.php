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
 *
 * ## An application's own catalogue
 *
 * Subclass and override {@see getScopes()}, then tell the framework which class to
 * ask — in `app.php`:
 *
 * ```php
 * 'scopes' => ['provider' => \App\Scopes::class],
 * ```
 *
 * Without that line the subclass answers only the calls that name it. Every internal
 * call here is `static::`, so overriding `getScopes()` gives the subclass's own callers
 * a consistent view — but ten sites in the framework write `Scopes::` literally, and
 * those are the ones that face outward: `scopes_supported` in the four `/.well-known/`
 * documents, the consent screen's descriptions, the OAuth2 `ScopeRepository`, and what
 * `init` offers. An installation could define forty-four scopes, publish fifteen, render
 * a consent screen with no description beside what the user is being asked to grant, and
 * have its own identifiers refused as unknown.
 *
 * ### Replace or merge is the subclass's decision, not the framework's
 *
 * Because it cannot be the framework's: a catalogue is not reliably a superset. Say
 * which you mean in code:
 *
 * ```php
 * // Replace — this installation's vocabulary is the whole vocabulary
 * public static function getScopes(): array { return ['Ours' => [...]]; }
 *
 * // Merge — the framework's standard scopes, plus ours
 * public static function getScopes(): array { return parent::getScopes() + ['Ours' => [...]]; }
 * ```
 *
 * **Replacing drops `openid`, `profile`, `email` and `offline_access` unless you declare
 * them**, and the OAuth2 server needs those to answer an OIDC request. Merging is the
 * safer default and replacing is the honest one for an installation that has curated its
 * own list; neither is right for everybody, which is why this is a sentence rather than a
 * flag.
 *
 * The MCP scopes are the exception and are appended either way — they are computed from
 * what {@see \Pramnos\Mcp\PublicRegistry} actually offers, so a replacing subclass
 * cannot accidentally make the endpoint ungrantable.
 */
class Scopes
{
    /**
     * The class that answers for this installation's catalogue, or null for this one.
     *
     * @var class-string<Scopes>|null
     */
    private static ?string $provider = null;

    /**
     * Name the class that owns this installation's scope catalogue.
     *
     * Called from `Application::init()` with `app.php`'s `scopes` block, the way features
     * and personal-data declarations are loaded. Calling it by hand is fine too — it is
     * one assignment.
     *
     * @param array<string, mixed> $config
     */
    public static function loadFromConfig(array $config): void
    {
        $class = $config['provider'] ?? null;

        if (!is_string($class) || $class === '') {
            return;
        }

        /*
         * Refused loudly rather than ignored.
         *
         * A misspelt class name that was quietly dropped would leave the installation
         * publishing the framework's fifteen scopes while its own forty-four sat in a
         * file nothing read — which is the exact failure this resolution point exists to
         * end, arrived at by a different route and just as invisible.
         */
        if (!is_subclass_of($class, self::class)) {
            throw new \InvalidArgumentException(
                $class . ' is not a ' . self::class . ' subclass, so it cannot answer for '
                . 'this installation\'s scopes.'
            );
        }

        self::$provider = $class;
    }

    /**
     * Forget the application's catalogue. For tests, and for an installation that
     * rebuilds its own configuration.
     */
    public static function resetProvider(): void
    {
        self::$provider = null;
    }

    /** Which class is answering. */
    public static function provider(): string
    {
        return self::$provider ?? self::class;
    }

    /**
     * Return all available scopes grouped by category.
     *
     * Keys are scope identifier strings (space-safe, no special chars except ':').
     * Suitable for rendering a consent screen grouped by category.
     *
     * Override this in a subclass and register it — see the class docblock. The
     * delegation below is what makes `Scopes::` at the framework's ten call sites reach
     * that subclass; it cannot recurse, because a subclass calling `parent::getScopes()`
     * arrives here with `static::class` equal to the provider and takes the second branch.
     *
     * @return array<string, array<string, array{description: string, is_default: bool, inherits: string[]}>>
     */
    public static function getScopes(): array
    {
        $provider = self::$provider;

        $catalogue = ($provider !== null && $provider !== static::class)
            ? $provider::getScopes()
            : static::frameworkScopes();

        // Appended outside the provider's answer, deliberately: these are derived from the
        // tools actually offered rather than declared anywhere, so a replacing subclass
        // must not be able to make the MCP endpoint ungrantable by not knowing about them.
        return $catalogue + static::mcpScopes();
    }

    /**
     * The framework's own catalogue: standard OpenID Connect and OAuth 2.0 scopes, plus
     * the administrative ones it defines.
     *
     * @return array<string, array<string, array{description: string, is_default: bool, inherits: string[]}>>
     */
    protected static function frameworkScopes(): array
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
     * The MCP scopes, and only while something asks for them.
     *
     * Returned as its own category so that a scope appears in `scopes_supported` —
     * which is served to anybody from `/.well-known/oauth-authorization-server` —
     * exactly when this installation has a tool behind it. A permanently-registered
     * `mcp:db_read` would tell the internet that every Pramnos site has a capability
     * for running SELECTs against its live database, including the great majority
     * that never offered one.
     *
     * `mcp:db_read` inherits `mcp`, so a token that may query can also call `whoami`
     * and check itself. Neither is inherited by `system:admin`, deliberately:
     * administering the application and reading its production tables from another
     * machine are two decisions, and one grant covering both is how the second gets
     * made without ever being taken.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    protected static function mcpScopes(): array
    {
        $offered = \Pramnos\Mcp\PublicRegistry::scopesInUse();

        if ($offered === []) {
            return [];
        }

        $known = [
            'mcp' => [
                'description' => 'Reach this installation\'s MCP endpoint.',
                'is_default'  => false,
                'inherits'    => [],
            ],
            'mcp:diagnostics' => [
                'description' => 'Read this installation\'s health, pending migrations, '
                    . 'schema drift, tables, routes and models — structure and state, '
                    . 'not data.',
                'is_default'  => false,
                'inherits'    => ['mcp'],
            ],
            'mcp:logs' => [
                'description' => 'Read this installation\'s error logs and failed-request '
                    . 'traces. Log lines are free text and are not redacted.',
                'is_default'  => false,
                'inherits'    => ['mcp'],
            ],
            'mcp:db_read' => [
                'description' => 'Run one read-only SELECT against the live database, '
                    . 'with rows from tables holding personal data withheld.',
                'is_default'  => false,
                'inherits'    => ['mcp'],
            ],
        ];

        $scopes = [];

        foreach ($offered as $scope) {
            if (isset($known[$scope])) {
                $scopes[$scope] = $known[$scope];
            }
        }

        /*
         * Every one of these inherits `mcp`, so registering any of them without it
         * would leave the inheritance pointing at a scope `hasInvalidScopes()`
         * rejects — and the token that could call the tool could not call `whoami` to
         * find out why anything else was missing.
         */
        if ($scopes !== [] && !isset($scopes['mcp'])) {
            $scopes['mcp'] = $known['mcp'];
        }

        return $scopes === [] ? [] : ['MCP' => $scopes];
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
     * Merge a token's existing scopes with all default scopes.
     *
     * Accepts the raw scope string that may optionally be wrapped in square
     * brackets (e.g. `[profile email]`), strips those brackets, splits on
     * whitespace, merges with the result of {@see getDefaultScopes()}, and
     * returns a single space-delimited string of unique scopes.
     *
     * @param string $tokenScopesString Current token scopes, optionally bracket-wrapped
     * @return string Space-delimited string of all scopes including defaults
     */
    public static function addDefaultScopesToToken(string $tokenScopesString): string
    {
        // The bracket-stripping this method used to do itself now lives in
        // `Token::parseScopes()`, with the other five shapes. It was the only reader in
        // the framework that knew about `[profile email]`, which is why the canonical
        // parser did not — and why a value this method accepted was refused everywhere
        // else that read the same column.
        $tokenScopes   = \Pramnos\User\Token::parseScopes($tokenScopesString);
        $defaultScopes = static::getDefaultScopes();
        return implode(' ', array_unique(array_merge($tokenScopes, $defaultScopes)));
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
            // A string here has usually come from a column by way of some caller, and
            // this method cannot tell. Parsing it the forgiving way costs nothing when
            // it was already the standard shape.
            $scopes = \Pramnos\User\Token::parseScopes($scopes);
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
            ->table('#PREFIX#applications')
            ->select('scope')
            ->where('apikey', $apiKey)
            ->first();

        $allowedScopes = [];
        if ($result && $result->numRows > 0) {
            // `applications.scope` is a column. Splitting it on whitespace reads one of
            // the six shapes it can hold, and which one you get depends on how the row
            // was written rather than on anything this caller did.
            $allowedScopes = \Pramnos\User\Token::parseScopes($result->fields['scope'] ?? '');
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
