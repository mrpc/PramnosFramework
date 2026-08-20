<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Apps\BroadcastApp;
use Pramnos\Broadcasting\Auth\PusherAuthorizer;
use Pramnos\Broadcasting\Auth\PusherAuthSigner;

/**
 * Covers signing, and — more importantly — that what this class signs is what
 * {@see PusherAuthorizer} verifies.
 *
 * The two halves shipped years apart: the framework could verify a signature but
 * never produce one, so every application wrote its own signer. Two independent
 * implementations of one string-to-sign definition is a drift waiting to happen,
 * and the round-trip tests below are what make the pair a pair.
 */
#[CoversClass(PusherAuthSigner::class)]
class PusherAuthSignerTest extends TestCase
{
    private const KEY    = 'app-key';
    private const SECRET = 'app-secret';

    private function signer(): PusherAuthSigner
    {
        return new PusherAuthSigner(new BroadcastApp(self::KEY, self::SECRET, '1', 'test'));
    }

    private function authorizer(): PusherAuthorizer
    {
        return new PusherAuthorizer(self::KEY, self::SECRET);
    }

    /**
     * A private channel token is `key:hmac` over "<socketId>:<channel>", and the
     * shipped authorizer accepts it.
     *
     * The round trip is the assertion that matters: the token's exact bytes are an
     * implementation detail, but the two classes agreeing is the contract.
     */
    public function testPrivateTokenVerifiesAgainstTheAuthorizer(): void
    {
        // Arrange
        $socketId = '123.456';
        $channel  = 'private-order.42';

        // Act
        $body = $this->signer()->signPrivate($socketId, $channel);

        // Assert
        $this->assertStringStartsWith(self::KEY . ':', $body['auth']);
        $this->assertTrue(
            $this->authorizer()->authorizeChannel($channel, $socketId, $body['auth']),
            'what the signer produces must be what the authorizer accepts'
        );
    }

    /**
     * A presence token signs the channel data too, and round-trips with the exact
     * JSON string that is sent alongside it.
     *
     * Re-encoding the member data on the verifying side would change key order or
     * escaping and invalidate a token nobody tampered with — the canonicalisation
     * bug this pair avoids by never canonicalising twice.
     */
    public function testPresenceTokenVerifiesWithItsChannelData(): void
    {
        // Arrange
        $socketId = '123.456';
        $channel  = 'presence-room.lobby';

        // Act
        $body = $this->signer()->signPresence($socketId, $channel, [
            'user_id'   => 7,
            'user_info' => ['name' => 'Ada', 'quote' => 'a "quoted" / slashed value'],
        ]);

        // Assert
        $this->assertArrayHasKey('channel_data', $body);
        $this->assertTrue(
            $this->authorizer()->authorizeChannel($channel, $socketId, $body['auth'], $body['channel_data']),
            'the signed channel_data must verify byte-for-byte'
        );
    }

    /**
     * user_id is cast to a string.
     *
     * A client comparing the member id against its own gets `7 !== "7"` otherwise,
     * which presents as a member who is in the room but never recognised as "me" —
     * a bug that looks like a presence bug and is a type bug.
     */
    public function testCastsUserIdToString(): void
    {
        // Act
        $body = $this->signer()->signPresence('1.2', 'presence-room', ['user_id' => 7]);
        $data = json_decode($body['channel_data'], true);

        // Assert
        $this->assertSame('7', $data['user_id']);
    }

    /**
     * A token bound to one socket must not verify for another.
     *
     * This is what stops a leaked token from being replayed on a different
     * connection, and it is the reason the socket id is in the signed string.
     */
    public function testTokenIsBoundToItsSocket(): void
    {
        // Arrange
        $body = $this->signer()->signPrivate('123.456', 'private-order.42');

        // Act & Assert
        $this->assertFalse(
            $this->authorizer()->authorizeChannel('private-order.42', '999.999', $body['auth']),
            'a token for one socket must not authorize another'
        );
    }

    /**
     * A token for one channel must not verify for another.
     */
    public function testTokenIsBoundToItsChannel(): void
    {
        // Arrange
        $body = $this->signer()->signPrivate('123.456', 'private-order.42');

        // Act & Assert
        $this->assertFalse(
            $this->authorizer()->authorizeChannel('private-order.99', '123.456', $body['auth'])
        );
    }

    /**
     * signFor() picks the right signature kind from the channel name and the
     * authorization result.
     */
    public function testSignForDispatchesOnChannelKind(): void
    {
        // Act
        $private  = $this->signer()->signFor('1.2', 'private-order.1', true);
        $presence = $this->signer()->signFor('1.2', 'presence-room', ['user_id' => '3']);

        // Assert
        $this->assertArrayNotHasKey('channel_data', $private);
        $this->assertArrayHasKey('channel_data', $presence);
    }

    /**
     * A presence channel whose rule returned a plain `true` is a programming
     * error, and is reported as one rather than signed without member data.
     *
     * Signing it would produce a token the server accepts for a channel it then
     * cannot build a member list for — a presence channel with an invisible
     * member.
     */
    public function testPresenceChannelWithBooleanAuthorizationThrows(): void
    {
        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must return member data/');
        $this->signer()->signFor('1.2', 'presence-room', true);
    }

    /**
     * Presence member data with no user_id is refused.
     */
    public function testPresenceWithoutUserIdThrows(): void
    {
        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must include a non-empty user_id/');
        $this->signer()->signPresence('1.2', 'presence-room', ['user_info' => ['name' => 'x']]);
    }

    /**
     * An empty user_id is refused as well as an absent one — '' is not an identity.
     */
    public function testPresenceWithEmptyUserIdThrows(): void
    {
        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->signer()->signPresence('1.2', 'presence-room', ['user_id' => '']);
    }

    /**
     * An app with no secret cannot sign, and says which setting is missing.
     *
     * Reported as a server misconfiguration rather than a denial: an operator
     * reading "forbidden" goes looking at permissions, and the problem is in
     * app.php or an applications row.
     */
    public function testAppWithoutSecretCannotSign(): void
    {
        // Arrange
        $signer = new PusherAuthSigner(new BroadcastApp('key-only', '', '1', 'test'));

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no broadcasting secret/');
        $signer->signPrivate('1.2', 'private-x');
    }
}
