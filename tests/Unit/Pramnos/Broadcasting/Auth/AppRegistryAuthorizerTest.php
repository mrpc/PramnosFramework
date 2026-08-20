<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Apps\AppRegistryInterface;
use Pramnos\Broadcasting\Apps\BroadcastApp;
use Pramnos\Broadcasting\Auth\AppRegistryAuthorizer;
use Pramnos\Broadcasting\Auth\PusherAuthSigner;

/**
 * The authorizer that makes more than one app possible at the edge.
 *
 * `PusherAuthorizer` holds one key and one secret, which is why the AuthServer
 * registry could describe fifty applications while the daemon could only ever
 * verify against the pair in `app.php`. This resolves the app per connection from
 * the key half of the token itself.
 */
#[CoversClass(AppRegistryAuthorizer::class)]
class AppRegistryAuthorizerTest extends TestCase
{
    /**
     * A registry of two tenants plus one app with no secret, and one that is
     * unknown by omission.
     */
    private function registry(?BroadcastApp $default = null): AppRegistryInterface
    {
        return new class($default) implements AppRegistryInterface {
            /** @var array<string,BroadcastApp> */
            private array $apps;

            public int $lookups = 0;

            public function __construct(private ?BroadcastApp $default)
            {
                $this->apps = [
                    'tenant-a' => new BroadcastApp('tenant-a', 'secret-a', '1', 'A'),
                    'tenant-b' => new BroadcastApp('tenant-b', 'secret-b', '2', 'B'),
                    'keyless'  => new BroadcastApp('keyless', '', '3', 'No secret'),
                ];
            }

            public function findByKey(string $key): ?BroadcastApp
            {
                $this->lookups++;

                return $this->apps[$key] ?? null;
            }

            public function defaultApp(): ?BroadcastApp
            {
                return $this->default;
            }
        };
    }

    /**
     * Each tenant's token verifies against its own secret, and only its own.
     *
     * This is the property the whole class exists for: two apps, two secrets, one
     * daemon — and a token minted for one must not open a channel on the other.
     */
    public function testEachTenantVerifiesAgainstItsOwnSecret(): void
    {
        // Arrange
        $authorizer = new AppRegistryAuthorizer($this->registry());
        $tokenA = (new PusherAuthSigner(new BroadcastApp('tenant-a', 'secret-a')))
            ->signPrivate('1.2', 'private-x')['auth'];
        $tokenB = (new PusherAuthSigner(new BroadcastApp('tenant-b', 'secret-b')))
            ->signPrivate('1.2', 'private-x')['auth'];

        // Act & Assert
        $this->assertTrue($authorizer->authorizeChannel('private-x', '1.2', $tokenA));
        $this->assertTrue($authorizer->authorizeChannel('private-x', '1.2', $tokenB));
    }

    /**
     * A token signed with the wrong secret but naming a real app is refused.
     *
     * The key in the token is what selects the secret, so naming another tenant is
     * the obvious attack — and it fails on the HMAC, which is why the protocol can
     * afford to let the client name the app.
     */
    public function testTokenSignedWithTheWrongSecretIsRefused(): void
    {
        // Arrange
        $authorizer = new AppRegistryAuthorizer($this->registry());
        // Claim to be tenant-a while signing with tenant-b's secret.
        $forged = 'tenant-a:' . hash_hmac('sha256', '1.2:private-x', 'secret-b');

        // Act & Assert
        $this->assertFalse($authorizer->authorizeChannel('private-x', '1.2', $forged));
    }

    /**
     * A presence token verifies with its channel data.
     */
    public function testPresenceTokenVerifiesWithChannelData(): void
    {
        // Arrange
        $authorizer = new AppRegistryAuthorizer($this->registry());
        $signed = (new PusherAuthSigner(new BroadcastApp('tenant-a', 'secret-a')))
            ->signPresence('1.2', 'presence-room', ['user_id' => '7', 'user_info' => ['n' => 'Ada']]);

        // Act & Assert
        $this->assertTrue($authorizer->authorizeChannel(
            'presence-room',
            '1.2',
            $signed['auth'],
            $signed['channel_data']
        ));
        $this->assertSame(
            ['user_id' => '7', 'user_info' => ['n' => 'Ada']],
            $authorizer->presenceMember('presence-room', '1.2', $signed['channel_data'])
        );
    }

    /**
     * Presence channel data must verify byte-for-byte: a re-encoded copy is refused.
     *
     * Re-encoding changes key order or escaping, so a token nobody tampered with
     * stops verifying — the canonicalisation bug, and the reason the signer emits
     * the exact string it signed.
     */
    public function testReEncodedChannelDataDoesNotVerify(): void
    {
        // Arrange
        $authorizer = new AppRegistryAuthorizer($this->registry());
        $signed = (new PusherAuthSigner(new BroadcastApp('tenant-a', 'secret-a')))
            ->signPresence('1.2', 'presence-room', ['user_id' => '7', 'user_info' => ['n' => 'Ada']]);

        $reordered = (string) json_encode([
            'user_info' => ['n' => 'Ada'],
            'user_id'   => '7',
        ]);

        // Act & Assert
        $this->assertFalse($authorizer->authorizeChannel(
            'presence-room',
            '1.2',
            $signed['auth'],
            $reordered
        ));
    }

    /**
     * A public channel needs no token.
     */
    public function testPublicChannelNeedsNoToken(): void
    {
        // Arrange
        $authorizer = new AppRegistryAuthorizer($this->registry());

        // Act & Assert
        $this->assertTrue($authorizer->authorizeChannel('updates', '1.2', ''));
    }

    /**
     * An unknown app key, a malformed token and an empty one are all refused, and
     * refused identically.
     *
     * A caller has no use for the difference, and distinguishing them would let
     * somebody probing keys learn which ones exist.
     */
    public function testMalformedAndUnknownTokensAreRefused(): void
    {
        // Arrange
        $authorizer = new AppRegistryAuthorizer($this->registry());

        foreach (['', 'no-colon', ':leading-colon', 'ghost-tenant:abc'] as $token) {
            // Act & Assert
            $this->assertFalse(
                $authorizer->authorizeChannel('private-x', '1.2', $token),
                'token: ' . $token
            );
        }
    }

    /**
     * An app with no secret cannot authorize a private channel.
     *
     * It is admitted at connection time, though — the app exists and its public
     * channels are legitimately usable, and refusing the connection would report a
     * signing misconfiguration as an authentication failure.
     */
    public function testAppWithoutSecretConnectsButCannotAuthorize(): void
    {
        // Arrange
        $authorizer = new AppRegistryAuthorizer($this->registry());

        // Act & Assert
        $this->assertTrue($authorizer->authorizeConnection('keyless'));
        $this->assertFalse($authorizer->authorizeChannel('private-x', '1.2', 'keyless:whatever'));
    }

    /**
     * A connection naming an active app is admitted; an unknown one is not.
     */
    public function testConnectionIsAdmittedForAKnownApp(): void
    {
        // Arrange
        $authorizer = new AppRegistryAuthorizer($this->registry());

        // Act & Assert
        $this->assertTrue($authorizer->authorizeConnection('tenant-a'));
        $this->assertFalse($authorizer->authorizeConnection('ghost-tenant'));
    }

    /**
     * With no key presented, a single-app registry's default is used and a
     * multi-tenant one refuses.
     *
     * The two answers are both correct for their deployment: one app means there is
     * nothing to disambiguate, several mean the connection has to say which.
     */
    public function testEmptyKeyFallsBackToTheDefaultAppWhenThereIsOne(): void
    {
        // Arrange
        $withDefault = new AppRegistryAuthorizer(
            $this->registry(new BroadcastApp('tenant-a', 'secret-a'))
        );
        $multiTenant = new AppRegistryAuthorizer($this->registry());

        // Act & Assert
        $this->assertTrue($withDefault->authorizeConnection(''));
        $this->assertFalse($multiTenant->authorizeConnection(''));
    }

    /**
     * Member data with no usable identity yields no member.
     */
    public function testUnusableMemberDataYieldsNoMember(): void
    {
        // Arrange
        $authorizer = new AppRegistryAuthorizer($this->registry());

        foreach ([null, '', 'not json', '{}', '{"user_id":""}', '"scalar"'] as $channelData) {
            // Act & Assert
            $this->assertNull(
                $authorizer->presenceMember('presence-x', '1.2', $channelData),
                'channel_data: ' . var_export($channelData, true)
            );
        }
    }

    /**
     * A scalar user_info is dropped rather than forwarded.
     */
    public function testScalarUserInfoIsDropped(): void
    {
        // Arrange
        $authorizer = new AppRegistryAuthorizer($this->registry());

        // Act
        $member = $authorizer->presenceMember('presence-x', '1.2', '{"user_id":"7","user_info":"nope"}');

        // Assert
        $this->assertSame(['user_id' => '7'], $member);
    }
}
