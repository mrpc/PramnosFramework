<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Client;

/**
 * One stored authorisation: this user, this provider, this account, these tokens.
 *
 * A plain object rather than a model, because the interesting behaviour is not "save me"
 * but "am I still usable, and for how much longer" — three questions the scheduled refresh
 * and every caller both need, answered in one place so they cannot answer them differently.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
final class Connection
{
    public const ACTIVE = 'active';
    public const DEAD   = 'dead';

    /**
     * How long before expiry a token counts as needing a refresh.
     *
     * Five minutes, and the number is a compromise rather than an arbitrary round figure.
     * Too short and a token that was valid when the job picked it up has expired by the
     * time the request lands — clock skew between this machine and the provider's is
     * routinely tens of seconds, and the window has to cover it. Too long and every run
     * refreshes everything, which on a provider that rotates refresh tokens is a lot of
     * writes and a lot of chances to lose one.
     */
    public const REFRESH_WINDOW = 300;

    /**
     * @param list<string> $scopes The scopes the provider granted, not the ones requested
     */
    public function __construct(
        public readonly int    $id,
        public readonly int    $userId,
        public readonly string $provider,
        public readonly string $accountId,
        public readonly string $accountName,
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly ?int   $expiresAt,
        public readonly ?int   $refreshExpiresAt,
        public readonly array  $scopes,
        public readonly string $status = self::ACTIVE,
        public readonly string $deadReason = '',
        public readonly ?int   $deadAt = null,
    ) {
    }

    /** A connection that cannot be refreshed any more. Kept, not deleted: the row is the evidence. */
    public function isDead(): bool
    {
        return $this->status === self::DEAD;
    }

    /**
     * Has the access token expired, or is it about to?
     *
     * A connection with no `expiresAt` never needs one — some providers issue tokens that
     * do not expire, and treating "no expiry" as "expired" would refresh them for ever.
     */
    public function needsRefresh(?int $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt - self::REFRESH_WINDOW <= ($now ?? time());
    }

    /**
     * Can this connection still be refreshed at all?
     *
     * Two ways the answer is no, and they are different failures. Without a refresh token
     * there was never a way to renew it — the connection simply ends at `expiresAt` and the
     * user has to authorise again. With one that has itself expired, the connection was
     * renewable and was left too long; that is the case the scheduled refresh exists to
     * prevent, and an idle connection is exactly the one nothing else would have touched.
     */
    public function isRefreshable(?int $now = null): bool
    {
        if ($this->refreshToken === '') {
            return false;
        }

        return $this->refreshExpiresAt === null || $this->refreshExpiresAt > ($now ?? time());
    }

    /** True when the provider granted this scope. */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** The tokens as a {@see TokenSet}, for merging a refresh response into. */
    public function toTokenSet(): TokenSet
    {
        return new TokenSet(
            accessToken: $this->accessToken,
            refreshToken: $this->refreshToken,
            expiresAt: $this->expiresAt,
            refreshExpiresAt: $this->refreshExpiresAt,
            scopes: $this->scopes,
        );
    }
}
