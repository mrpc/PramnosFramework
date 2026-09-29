<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2;

/**
 * How long the server's tokens last: the server's defaults, and one client's own.
 *
 * ```php
 * // app/app.php
 * 'oauth' => [
 *     'access_token_ttl'  => 3600,      // seconds; one hour unless set
 *     'refresh_token_ttl' => 2592000,   // thirty days
 *     'auth_code_ttl'     => 600,       // ten minutes
 * ],
 * ```
 *
 * An application's **Access token lifetime** and **Refresh token lifetime** (seconds, on its edit
 * screen) replace the first two for that client; empty is the default. A value outside
 * {@see MIN}–{@see MAX_ACCESS} / {@see MAX_REFRESH} is clamped rather than refused, so a typo
 * cannot mint a token that never expires or one that is dead on arrival.
 */
final class TokenLifetimes
{
    public const DEFAULT_ACCESS  = 3600;
    public const DEFAULT_REFRESH = 2592000;
    public const DEFAULT_CODE    = 600;

    /** A minute: shorter is a token nobody can use. */
    public const MIN = 60;

    /** A day for an access token — past that it is a credential, not a session. */
    public const MAX_ACCESS = 86400;

    /** A year for a refresh token. */
    public const MAX_REFRESH = 31536000;

    /** Ten minutes at most for a code, as RFC 6749 §4.1.2 recommends. */
    public const MAX_CODE = 600;

    public static function access(?int $client = null): int
    {
        return self::pick($client, 'access_token_ttl', self::DEFAULT_ACCESS, self::MAX_ACCESS);
    }

    public static function refresh(?int $client = null): int
    {
        return self::pick($client, 'refresh_token_ttl', self::DEFAULT_REFRESH, self::MAX_REFRESH);
    }

    public static function authCode(): int
    {
        return self::pick(null, 'auth_code_ttl', self::DEFAULT_CODE, self::MAX_CODE);
    }

    /** Seconds as the interval League takes. */
    public static function interval(int $seconds): \DateInterval
    {
        return new \DateInterval('PT' . max(1, $seconds) . 'S');
    }

    private static function pick(?int $client, string $key, int $default, int $max): int
    {
        $value = $client !== null && $client > 0 ? $client : (int) (self::config()[$key] ?? 0);

        return $value > 0 ? max(self::MIN, min($max, $value)) : $default;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $info  = \Pramnos\Application\Application::currentInstance()?->applicationInfo;
        $oauth = is_array($info) ? ($info['oauth'] ?? []) : [];

        return is_array($oauth) ? $oauth : [];
    }
}
