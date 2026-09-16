<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Client;

/**
 * What a provider answered with: the tokens, and when they stop working.
 *
 * A token endpoint's response is the one place a provider's paperwork and its behaviour
 * are most likely to disagree, so the parsing is deliberately forgiving about spelling and
 * strict about meaning.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
final class TokenSet
{
    /**
     * @param string       $accessToken      Always present; a response without one is an error, not a TokenSet
     * @param string       $refreshToken     Empty when the provider issued none *this time* — which is not the same as "none at all"
     * @param int|null     $expiresAt        Unix timestamp, or null when the provider says it does not expire
     * @param int|null     $refreshExpiresAt Unix timestamp the refresh token itself dies
     * @param list<string> $scopes           What was actually granted, which is not always what was asked for
     * @param array<string,mixed> $raw       The untouched response, for the per-provider fields this does not model
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken = '',
        public readonly ?int   $expiresAt = null,
        public readonly ?int   $refreshExpiresAt = null,
        public readonly array  $scopes = [],
        public readonly array  $raw = [],
    ) {
    }

    /**
     * Read a token endpoint's JSON.
     *
     * @param array<string,mixed> $payload
     */
    public static function fromResponse(array $payload): self
    {
        /*
         * `expires_in` is a duration and the column is an instant, so the conversion
         * happens once, here, against this machine's clock. Doing it at read time instead
         * would mean every caller needed the moment the response arrived, and one of them
         * would use the wrong one.
         *
         * Cast through int rather than trusting the type: several providers send it as a
         * string, and `"3600" + time()` is fine in PHP while `null + time()` is not.
         */
        $expiresIn = isset($payload['expires_in']) ? (int) $payload['expires_in'] : 0;

        // Instagram and a few others name the refresh window this way; where neither is
        // present the refresh token is assumed not to expire on its own, which is the
        // specification's default and what every provider that says nothing means.
        $refreshIn = (int) ($payload['refresh_token_expires_in'] ?? $payload['refresh_expires_in'] ?? 0);

        $scopes = $payload['scope'] ?? '';
        if (is_array($scopes)) {
            // A minority send a list rather than a delimited string.
            $scopes = implode(' ', $scopes);
        }

        return new self(
            accessToken: (string) $payload['access_token'],
            refreshToken: (string) ($payload['refresh_token'] ?? ''),
            expiresAt: $expiresIn > 0 ? time() + $expiresIn : null,
            refreshExpiresAt: $refreshIn > 0 ? time() + $refreshIn : null,
            // Both separators, because the specification says space and a handful of
            // providers send commas anyway.
            scopes: $scopes === '' ? [] : preg_split('/[\s,]+/', (string) $scopes, -1, PREG_SPLIT_NO_EMPTY),
            raw: $payload,
        );
    }

    /**
     * This token set, with anything the provider left out carried over from the old one.
     *
     * **The refresh token is the reason this exists.** Providers differ: some rotate it on
     * every refresh, some return the same one, and some return none at all and mean "keep
     * using the one you have". Overwriting with an empty string in that last case throws
     * away the only thing that can renew the connection — and nothing fails until the
     * access token expires, hours or days later, somewhere far from the code that lost it.
     *
     * The granted scopes are carried over for the same reason: a refresh response usually
     * omits them, and an omission is not a revocation.
     */
    public function mergedInto(self $previous): self
    {
        return new self(
            accessToken: $this->accessToken,
            refreshToken: $this->refreshToken !== '' ? $this->refreshToken : $previous->refreshToken,
            expiresAt: $this->expiresAt,
            refreshExpiresAt: $this->refreshExpiresAt ?? $previous->refreshExpiresAt,
            scopes: $this->scopes !== [] ? $this->scopes : $previous->scopes,
            raw: $this->raw,
        );
    }
}
