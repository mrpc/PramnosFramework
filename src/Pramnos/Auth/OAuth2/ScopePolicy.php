<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2;

/**
 * Which scopes a person may grant: a role per scope, checked when they consent and whenever a
 * token is issued for them, a refresh included.
 *
 * ```php
 * // app/app.php
 * 'roles' => ['staff' => 50],
 * 'oauth' => ['scopes' => [
 *     'mcp:*'        => ['min_role' => 'staff'],      // a glob
 *     'mcp:db_write' => ['min_role' => 'admin'],      // an exact name wins over a glob
 *     'reports:read' => ['min_usertype' => 20],
 * ]],
 * ```
 *
 * A client's Allowed Scopes say what a *client* may ask for; this says what a *person* may hand
 * it. Without it, any signed-in account could approve any scope a client requested, so a site's
 * customer could hand an assistant `mcp:db_read`. A scope with no rule is open to anybody signed
 * in, as before.
 *
 * For a rule a role cannot say, extend this class, override {@see allows()} and name it:
 * `'oauth' => ['scope_policy' => \App\ScopePolicy::class]`. A rule that cannot be read (a role
 * nobody defined, an account that cannot be loaded) refuses: a typo must not open a scope.
 */
class ScopePolicy
{
    /**
     * The policy app.php names in `oauth.scope_policy`, or this one.
     */
    public static function current(): self
    {
        $class = self::config()['scope_policy'] ?? null;

        return is_string($class) && is_a($class, self::class, true) ? new $class() : new self();
    }

    /**
     * The scopes of `$scopes` this account may not grant, in their order; [] when it may grant
     * them all.
     *
     * @param list<string> $scopes
     * @return list<string>
     */
    public function refused(int $userId, array $scopes, ?string $clientId = null): array
    {
        $scopes = array_values(array_unique(array_filter($scopes, static fn ($scope): bool => is_string($scope) && $scope !== '')));
        $rules  = $this->rules();
        if ($scopes === [] || ($rules === [] && static::class === self::class)) {
            return [];
        }
        $user = $this->user($userId);

        $refused = [];
        foreach ($scopes as $scope) {
            try {
                $rule    = $this->ruleFor($scope, $rules);
                $allowed = $user === null ? $rule === null : $this->allows($user, $scope, $clientId, $rule);
            } catch (\Throwable $e) {
                \Pramnos\Logs\Logger::log('OAuth scope policy refused "' . $scope . '": ' . $e->getMessage(), 'oauth');
                $allowed = false;
            }
            if (!$allowed) {
                $refused[] = $scope;
            }
        }

        return $refused;
    }

    /**
     * Whether `$user` may grant `$scope` to the client, given the rule app.php has for it (null:
     * none). Override for a rule a role cannot say; call the parent for the configured one.
     *
     * @param array<string, mixed>|null $rule
     */
    protected function allows(object $user, string $scope, ?string $clientId, ?array $rule): bool
    {
        if ($rule === null) {
            return true;
        }
        if (isset($rule['min_role']) && !\Pramnos\User\Roles::atLeast($user, (string) $rule['min_role'])) {
            return false;
        }
        if (isset($rule['min_usertype']) && (int) ($user->usertype ?? 0) < (int) $rule['min_usertype']) {
            return false;
        }

        return true;
    }

    /**
     * The account, or null when there is none to judge — which refuses every scope with a rule.
     */
    protected function user(int $userId): ?object
    {
        if ($userId <= 1) {
            return null;
        }
        $user = new \Pramnos\User\User($userId);

        return (int) ($user->userid ?? 0) === $userId ? $user : null;
    }

    /**
     * The rules from app.php, by scope or glob.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function rules(): array
    {
        $rules = self::config()['scopes'] ?? [];

        return is_array($rules) ? array_filter($rules, 'is_array') : [];
    }

    /**
     * The rule for a scope: its exact entry, else the first glob that matches it, else null.
     *
     * @param array<string, array<string, mixed>> $rules
     * @return array<string, mixed>|null
     */
    private function ruleFor(string $scope, array $rules): ?array
    {
        if (isset($rules[$scope])) {
            return $rules[$scope];
        }
        foreach ($rules as $pattern => $rule) {
            if (str_contains((string) $pattern, '*') && fnmatch((string) $pattern, $scope)) {
                return $rule;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $config = is_object($app) ? ($app->applicationInfo['oauth'] ?? []) : [];

        return is_array($config) ? $config : [];
    }
}
