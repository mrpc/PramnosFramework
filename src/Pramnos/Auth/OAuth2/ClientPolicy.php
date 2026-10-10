<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2;

use Pramnos\Auth\ApplicationSettings;
use Pramnos\Auth\OAuthPolicyHelper;

/**
 * What an application may do at the token, revocation and introspection endpoints.
 *
 * Every request there names a client, and before anything is issued, revoked or reported this
 * checks, for that client's application:
 *
 * - the grant, against `applications.oauth2_application_grants` ({@see GrantPolicy});
 * - how the client authenticated — `client_secret_basic`, `client_secret_post`,
 *   `private_key_jwt` or `none` — against `applications.oauth2_client_auth_methods`;
 * - `require_https` and the IP lock from its {@see ApplicationSettings}.
 *
 * **Authentication methods.** With no rows, `client_secret_basic`, `client_secret_post` and
 * `private_key_jwt` are allowed, and `none` to a client that has no secret — a public client
 * proves itself with PKCE, which is the method it has. With rows, exactly the enabled ones.
 */
final class ClientPolicy
{
    private const METHODS_TABLE = 'applications.oauth2_client_auth_methods';

    /** Grant types as requested, by their policy names. */
    private const GRANT_NAMES = [
        'authorization_code'                              => 'authorization_code',
        'client_credentials'                              => 'client_credentials',
        'password'                                        => 'password',
        'refresh_token'                                   => 'refresh_token',
        'device_code'                                     => 'device_code',
        'urn:ietf:params:oauth:grant-type:device_code'    => 'device_code',
        'urn:ietf:params:oauth:grant-type:jwt-bearer'     => 'jwt_bearer',
        'exchange_token'                                  => 'exchange_token',
        'urn:ietf:params:oauth:grant-type:token-exchange' => 'exchange_token',
    ];

    /**
     * Why this client may not make this request, or null when it may.
     *
     * An unknown client passes: refusing it is the authentication's job, with the error that
     * belongs to it.
     *
     * @param string      $clientId The client the request names
     * @param string|null $grant    The `grant_type`, or null at revocation and introspection
     * @param string      $method   How the client authenticated — {@see methodOf()}
     * @param string      $address  The caller's address
     * @param array<string, mixed> $server The server variables, for the request's transport
     * @return array{error: string, error_description: string, status: int}|null
     */
    public static function refusal(string $clientId, ?string $grant, string $method, string $address, array $server): ?array
    {
        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#applications')
            ->select(['appid', 'apisecret', 'is_confidential'])->where('apikey', $clientId)->where('status', 1)->first();
        $appId = ($result && $result->numRows > 0) ? (int) ($result->fields['appid'] ?? 0) : 0;
        if ($appId <= 0) {
            return null;
        }
        $settings = ApplicationSettings::for($appId);

        if (ApplicationSettings::refusesTransport($settings, $address, $server)) {
            return self::refuse('invalid_request', 'This client\'s requests must use HTTPS.');
        }
        if (!ApplicationSettings::admitsAddress($settings, $address)) {
            return self::refuse('unauthorized_client', 'This client does not accept requests from this address.');
        }
        if ($grant !== null) {
            $name = self::GRANT_NAMES[$grant] ?? null;
            if ($name !== null && !GrantPolicy::allows($appId, $name)) {
                return self::refuse('unauthorized_client', "This client is not allowed the {$name} grant.");
            }
        }
        $public = \Pramnos\Auth\Application::cannotKeepASecret((array) $result->fields);
        if (!self::allowsMethod($appId, $method, $public)) {
            return self::refuse('unauthorized_client', "This client may not authenticate with {$method}.");
        }

        return null;
    }

    /**
     * How a token-endpoint request authenticates its client.
     *
     * @param array<string, mixed>  $post   The request body
     * @param array<string, mixed>  $server The server variables
     */
    public static function methodOf(array $post, array $server): string
    {
        if (is_string($post['client_assertion'] ?? null)
            && ($post['client_assertion_type'] ?? '') === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
        ) {
            return 'private_key_jwt';
        }
        $authorization = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (stripos($authorization, 'Basic ') === 0 || isset($server['PHP_AUTH_USER'])) {
            return 'client_secret_basic';
        }

        return is_string($post['client_secret'] ?? null) && $post['client_secret'] !== ''
            ? 'client_secret_post' : 'none';
    }

    /**
     * May this application authenticate this way?
     */
    public static function allowsMethod(int $appId, string $method, bool $public): bool
    {
        $rows = self::methodRows($appId);
        if ($rows === []) {
            return in_array($method, OAuthPolicyHelper::getDefaultAllowedAuthMethods(), true)
                || ($method === 'none' && $public);
        }
        foreach ($rows as $row) {
            if ($row['auth_method'] === $method && self::enabled($row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An application's rows in the methods table; none when the installation has no such table.
     *
     * @return list<array<string, mixed>>
     */
    private static function methodRows(int $appId): array
    {
        $db = \Pramnos\Framework\Factory::getDatabase();

        return $db->schema()->hasTable(self::METHODS_TABLE)
            ? $db->queryBuilder()->table(self::METHODS_TABLE)->where('appid', $appId)->getAll()
            : [];
    }

    /** Whether a policy row is enabled, as either driver returns a boolean. */
    private static function enabled(array $row): bool
    {
        return in_array(strtolower((string) $row['is_enabled']), ['1', 't', 'true'], true);
    }

    /**
     * The authentication methods an application allows, as stored; empty when it has no rows.
     *
     * @return list<string>
     */
    public static function methods(int $appId): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['auth_method'],
            array_filter(self::methodRows($appId), static fn (array $row): bool => self::enabled($row))
        ));
    }

    /**
     * The methods an application may authenticate with now: its rows, or the defaults.
     *
     * @param bool $public Whether the application has no secret, which makes `none` a default
     * @return list<string>
     */
    public static function effectiveMethods(int $appId, bool $public): array
    {
        if (!self::hasMethodRows($appId)) {
            return [...OAuthPolicyHelper::getDefaultAllowedAuthMethods(), ...($public ? ['none'] : [])];
        }

        return self::methods($appId);
    }

    /**
     * Allow an application exactly these authentication methods.
     *
     * Every known method gets a row, enabled or not, so an empty list means none — never "the
     * defaults". {@see useDefaultMethods()} is how an application goes back to them.
     *
     * @param list<string> $methods Each one of {@see OAuthPolicyHelper::getAuthenticationMethods()}
     * @throws \InvalidArgumentException for a method the server does not know
     */
    public static function setMethods(int $appId, array $methods): void
    {
        $known = array_column(OAuthPolicyHelper::getAuthenticationMethods(), 'method');
        foreach ($methods as $method) {
            if (!in_array($method, $known, true)) {
                throw new \InvalidArgumentException("Unknown authentication method \"{$method}\".");
            }
        }

        $db      = \Pramnos\Framework\Factory::getDatabase();
        $primary = array_values(array_intersect($known, $methods))[0] ?? null;
        $db->queryBuilder()->table(self::METHODS_TABLE)->where('appid', $appId)->delete();
        foreach ($known as $method) {
            $db->queryBuilder()->table(self::METHODS_TABLE)->insert([
                'appid'       => $appId,
                'auth_method' => $method,
                'is_enabled'  => in_array($method, $methods, true),
                'is_primary'  => $method === $primary,
            ]);
        }
    }

    /**
     * Remove an application's rows, so the default methods apply.
     */
    public static function useDefaultMethods(int $appId): void
    {
        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table(self::METHODS_TABLE)->where('appid', $appId)->delete();
    }

    /** Whether an application has authentication methods of its own. */
    public static function hasMethodRows(int $appId): bool
    {
        return self::methodRows($appId) !== [];
    }

    /** @return array{error: string, error_description: string, status: int} */
    private static function refuse(string $error, string $description): array
    {
        return ['error' => $error, 'error_description' => $description, 'status' => 400];
    }
}
