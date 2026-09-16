<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\OAuth2\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\Client\Connection;

/**
 * The two predicates the whole refresh story rests on.
 *
 * `needsRefresh()` decides whether a token is touched; `isRefreshable()` decides whether
 * touching it is even possible. Between them they are the difference between a connection
 * that stays alive and one that dies quietly, and both have an edge case that is easy to
 * get backwards and impossible to notice — a token with no expiry, and a refresh token
 * that has itself expired.
 */
#[CoversClass(Connection::class)]
class ConnectionTest extends TestCase
{
    private function connection(?int $expiresAt, string $refreshToken = 'rt', ?int $refreshExpiresAt = null): Connection
    {
        return new Connection(
            id: 1,
            userId: 1,
            provider: 'acme',
            accountId: '',
            accountName: '',
            accessToken: 'at',
            refreshToken: $refreshToken,
            expiresAt: $expiresAt,
            refreshExpiresAt: $refreshExpiresAt,
            scopes: ['read'],
        );
    }

    /**
     * A token inside the window needs refreshing; one comfortably ahead of it does not.
     *
     * The window exists for clock skew: this machine and the provider's routinely differ by
     * tens of seconds, and a token refreshed at the exact moment of expiry has already
     * expired by the time the request lands.
     */
    public function testATokenInsideTheWindowNeedsRefreshing(): void
    {
        // Arrange
        $now = 1_000_000;

        // Act & Assert — inside the window…
        $this->assertTrue($this->connection($now + 60)->needsRefresh($now));
        // …exactly on its edge, which is the boundary a `<` instead of a `<=` would miss…
        $this->assertTrue($this->connection($now + Connection::REFRESH_WINDOW)->needsRefresh($now));
        // …already expired…
        $this->assertTrue($this->connection($now - 1)->needsRefresh($now));
        // …and comfortably ahead of it.
        $this->assertFalse($this->connection($now + 3600)->needsRefresh($now));
    }

    /**
     * A token with no stated expiry never needs refreshing.
     *
     * Some providers issue tokens that do not expire. Treating "no expiry" as "expired"
     * would refresh them on every scheduled run for ever — and on a provider that rotates
     * refresh tokens, every one of those is a chance to lose one.
     */
    public function testATokenWithNoExpiryNeverNeedsRefreshing(): void
    {
        // Act & Assert
        $this->assertFalse($this->connection(null)->needsRefresh());
    }

    /**
     * Refreshable means having a refresh token that is itself still valid.
     *
     * Two different failures. No refresh token: there was never a way to renew this, and
     * the connection simply ends. An expired one: it *was* renewable and was left too long
     * — which is the case the scheduled job exists to prevent, and an idle connection is
     * exactly the one nothing else would have touched.
     */
    public function testRefreshabilityDistinguishesNeverFromTooLate(): void
    {
        // Arrange
        $now = 1_000_000;

        // Act & Assert
        $this->assertTrue($this->connection($now, 'rt')->isRefreshable($now));
        $this->assertTrue($this->connection($now, 'rt', $now + 60)->isRefreshable($now));
        $this->assertFalse($this->connection($now, '')->isRefreshable($now), 'no refresh token was ever issued');
        $this->assertFalse($this->connection($now, 'rt', $now - 1)->isRefreshable($now), 'the refresh token itself expired');
    }

    /** The granted scopes answer membership, and a scope that was not granted is absent. */
    public function testScopeMembership(): void
    {
        // Act & Assert
        $this->assertTrue($this->connection(null)->hasScope('read'));
        $this->assertFalse($this->connection(null)->hasScope('write'));
    }

    /** The round trip into a TokenSet keeps every field a refresh will need to merge. */
    public function testItConvertsToATokenSetForMerging(): void
    {
        // Act
        $tokens = $this->connection(1234, 'rt', 5678)->toTokenSet();

        // Assert
        $this->assertSame('at', $tokens->accessToken);
        $this->assertSame('rt', $tokens->refreshToken);
        $this->assertSame(1234, $tokens->expiresAt);
        $this->assertSame(5678, $tokens->refreshExpiresAt);
        $this->assertSame(['read'], $tokens->scopes);
    }
}
