<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Apps\BroadcastApp;
use Pramnos\Broadcasting\Auth\PusherAuthSigner;
use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Broadcasting\Encryption\ChannelEncrypter;
use Pramnos\Broadcasting\Testing\FakeDriver;

/**
 * End-to-end encryption for `private-encrypted-` channels.
 *
 * A private channel is private because the server checks who may subscribe. An
 * encrypted one adds what that check cannot give you: the payload is unreadable to
 * the relay itself. So the assertions that matter are that the ciphertext really is
 * opaque, that the key is derivable from the channel name alone (because the two
 * ends derive it independently and never exchange it), and that a wrong key fails
 * closed.
 */
#[CoversClass(ChannelEncrypter::class)]
#[CoversClass(PusherAuthSigner::class)]
#[CoversClass(BroadcastingManager::class)]
#[CoversClass(\Pramnos\Broadcasting\BroadcastingServiceProvider::class)]
class ChannelEncrypterTest extends TestCase
{
    // Public because the anonymous Application subclasses below cannot reach a
    // private constant of their enclosing scope.
    public const MASTER_KEY = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; // 32 zero bytes

    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            $this->markTestSkipped('The sodium extension is required.');
        }
    }

    protected function tearDown(): void
    {
        FakeDriver::restore();
    }

    private function encrypter(): ChannelEncrypter
    {
        return ChannelEncrypter::fromBase64(self::MASTER_KEY);
    }

    /**
     * A payload round-trips through encrypt/decrypt.
     */
    public function testRoundTripsAPayload(): void
    {
        // Arrange
        $encrypter = $this->encrypter();
        $payload   = ['id' => 42, 'note' => 'a "quoted" / slashed value', 'nested' => ['a' => 1]];

        // Act
        $envelope  = $encrypter->encrypt('private-encrypted-room', $payload);
        $recovered = $encrypter->decrypt('private-encrypted-room', $envelope);

        // Assert
        $this->assertSame($payload, $recovered);
    }

    /**
     * The envelope is the Pusher wire shape, and the plaintext does not appear in it.
     *
     * The shape matters because `pusher-js` decrypts these natively — matching it is
     * what makes this need no client-side code. The absence of the plaintext is the
     * property the feature exists for.
     */
    public function testEnvelopeIsOpaqueAndInPusherShape(): void
    {
        // Arrange
        $encrypter = $this->encrypter();

        // Act
        $envelope = $encrypter->encrypt('private-encrypted-room', ['secret' => 'do-not-log-me']);

        // Assert
        $this->assertSame(['nonce', 'ciphertext'], array_keys($envelope));
        $this->assertStringNotContainsString('do-not-log-me', json_encode($envelope));
        $this->assertStringNotContainsString('secret', json_encode($envelope));
        $this->assertSame(
            SODIUM_CRYPTO_SECRETBOX_NONCEBYTES,
            strlen((string) base64_decode($envelope['nonce'], true))
        );
    }

    /**
     * Every message gets a fresh nonce.
     *
     * Reusing a nonce under the same key breaks the construction outright — not
     * weakens it — and the key is fixed per channel, so the nonce is the only thing
     * that varies. Two encryptions of identical input must differ.
     */
    public function testEachMessageGetsAFreshNonce(): void
    {
        // Arrange
        $encrypter = $this->encrypter();

        // Act
        $first  = $encrypter->encrypt('private-encrypted-room', ['x' => 1]);
        $second = $encrypter->encrypt('private-encrypted-room', ['x' => 1]);

        // Assert
        $this->assertNotSame($first['nonce'], $second['nonce']);
        $this->assertNotSame(
            $first['ciphertext'],
            $second['ciphertext'],
            'identical plaintext must not produce identical ciphertext'
        );
    }

    /**
     * The per-channel key depends on the channel name, so one channel's key cannot
     * open another's messages.
     *
     * This is what makes a shared_secret safe to hand a subscriber: it only unlocks
     * the channel they were authorized for.
     */
    public function testKeysAreScopedToTheChannel(): void
    {
        // Arrange
        $encrypter = $this->encrypter();

        // Act
        $envelope = $encrypter->encrypt('private-encrypted-room-a', ['x' => 1]);

        // Assert
        $this->assertNotSame(
            $encrypter->sharedSecret('private-encrypted-room-a'),
            $encrypter->sharedSecret('private-encrypted-room-b')
        );
        $this->assertNull(
            $encrypter->decrypt('private-encrypted-room-b', $envelope),
            "one channel's key must not open another's message"
        );
    }

    /**
     * The key derivation is deterministic, because both ends compute it
     * independently and it is never sent over the socket.
     */
    public function testKeyDerivationIsDeterministic(): void
    {
        // Arrange
        $a = $this->encrypter();
        $b = $this->encrypter();

        // Assert
        $this->assertSame(
            $a->sharedSecret('private-encrypted-room'),
            $b->sharedSecret('private-encrypted-room')
        );
        $this->assertSame(
            base64_encode($a->sharedSecret('private-encrypted-room')),
            $a->sharedSecretForClient('private-encrypted-room')
        );
    }

    /**
     * A different master key cannot decrypt, and fails closed rather than returning
     * something.
     */
    public function testAnotherMasterKeyCannotDecrypt(): void
    {
        // Arrange
        $envelope = $this->encrypter()->encrypt('private-encrypted-room', ['x' => 1]);
        $other    = new ChannelEncrypter(str_repeat("\x01", 32));

        // Act & Assert
        $this->assertNull($other->decrypt('private-encrypted-room', $envelope));
    }

    /**
     * A tampered ciphertext does not authenticate.
     *
     * Poly1305 is what makes the ciphertext tamper-evident rather than merely
     * unreadable — without the check a relay could flip bits and the subscriber would
     * decrypt garbage instead of rejecting it.
     */
    public function testTamperedCiphertextIsRejected(): void
    {
        // Arrange
        $encrypter = $this->encrypter();
        $envelope  = $encrypter->encrypt('private-encrypted-room', ['x' => 1]);

        $raw    = (string) base64_decode($envelope['ciphertext'], true);
        $raw[0] = $raw[0] === "\x00" ? "\x01" : "\x00";
        $envelope['ciphertext'] = base64_encode($raw);

        // Act & Assert
        $this->assertNull($encrypter->decrypt('private-encrypted-room', $envelope));
    }

    /**
     * A malformed envelope yields null rather than throwing, and every malformation
     * yields the same answer.
     *
     * One answer on purpose: a caller can do nothing different with the difference,
     * and distinguishing "bad base64" from "did not authenticate" is exactly what an
     * oracle-style probe wants.
     */
    public function testMalformedEnvelopesAllYieldNull(): void
    {
        // Arrange
        $encrypter = $this->encrypter();

        $cases = [
            [],
            ['nonce' => 'not base64!!', 'ciphertext' => 'also not'],
            ['nonce' => base64_encode('short'), 'ciphertext' => base64_encode('x')],
            ['nonce' => base64_encode(random_bytes(24)), 'ciphertext' => base64_encode('too short')],
            ['ciphertext' => base64_encode('x')],
            ['nonce' => base64_encode(random_bytes(24))],
        ];

        // Act & Assert
        foreach ($cases as $index => $envelope) {
            $this->assertNull(
                $encrypter->decrypt('private-encrypted-room', $envelope),
                'case ' . $index
            );
        }
    }

    /**
     * Ciphertext that decrypts to something that is not a JSON object yields null.
     */
    public function testNonObjectPlaintextYieldsNull(): void
    {
        // Arrange
        $encrypter = $this->encrypter();
        $nonce     = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher    = sodium_crypto_secretbox(
            'not json at all',
            $nonce,
            $encrypter->sharedSecret('private-encrypted-room')
        );

        // Act & Assert
        $this->assertNull($encrypter->decrypt('private-encrypted-room', [
            'nonce'      => base64_encode($nonce),
            'ciphertext' => base64_encode($cipher),
        ]));
    }

    /**
     * A key of the wrong length is refused at construction, not at the first publish.
     *
     * A realtime feature that fails on its first real event fails in front of users.
     */
    public function testWrongLengthKeyIsRefusedAtConstruction(): void
    {
        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exactly 32 raw bytes/');
        new ChannelEncrypter('too short');
    }

    /**
     * A non-base64 configured key is refused with a message that says so.
     */
    public function testInvalidBase64IsRefused(): void
    {
        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid base64/');
        ChannelEncrypter::fromBase64('!!! not base64 !!!');
    }

    /**
     * The prefix test recognises only the encrypted prefix.
     */
    public function testPrefixDetection(): void
    {
        // Assert
        $this->assertTrue(ChannelEncrypter::isEncrypted('private-encrypted-room'));
        $this->assertFalse(ChannelEncrypter::isEncrypted('private-room'));
        $this->assertFalse(ChannelEncrypter::isEncrypted('presence-room'));
        $this->assertFalse(ChannelEncrypter::isEncrypted('room'));
    }

    // -------------------------------------------------------------------------
    // Integration with the manager and the signer
    // -------------------------------------------------------------------------

    /**
     * The manager encrypts payloads bound for an encrypted channel, and leaves
     * everything else alone.
     */
    public function testManagerEncryptsOnlyEncryptedChannels(): void
    {
        // Arrange
        $fake    = new FakeDriver();
        $manager = (new BroadcastingManager())
            ->addDriver($fake)
            ->setDefault('fake')
            ->useEncryption($this->encrypter());

        // Act
        $manager->broadcast('private-encrypted-room', 'e', ['secret' => 'hidden']);
        $manager->broadcast('private-room', 'e', ['open' => 'visible']);

        // Assert
        $recorded = $fake->recorded();
        $this->assertSame(['nonce', 'ciphertext'], array_keys($recorded[0]['payload']));
        $this->assertSame(['open' => 'visible'], $recorded[1]['payload'], 'a private channel is untouched');

        // And what was published really does decrypt back.
        $this->assertSame(
            ['secret' => 'hidden'],
            $this->encrypter()->decrypt('private-encrypted-room', $recorded[0]['payload'])
        );
    }

    /**
     * Without an encrypter, a broadcast to an encrypted channel is refused.
     *
     * **This is a reversal.** It used to publish plaintext under a channel name that
     * promises otherwise, documented and pinned by a test as a deliberate decision on
     * the reasoning that the prefix alone does nothing.
     *
     * A consuming project asked whether that was the decision we wanted — noting that
     * authorizing such a channel without a key already throws, and that a wrong-length
     * key is refused at construction, both on the reasoning that a realtime feature
     * failing on its first real event fails in front of users. The two halves of one
     * feature disagreed, and the publish half had the worse failure: a visible
     * exception costs a request, while silent plaintext on a channel whose whole
     * purpose is that the relay cannot read it costs the thing the feature exists to
     * protect.
     *
     * There is no legitimate case for the old behaviour: `pusher-js` decrypts a
     * `private-encrypted-` channel natively, so a plaintext payload on one does not
     * merely leak — it also does not arrive.
     */
    public function testWithoutAnEncrypterAnEncryptedChannelIsRefused(): void
    {
        // Arrange
        $fake    = new FakeDriver();
        $manager = (new BroadcastingManager())->addDriver($fake)->setDefault('fake');

        // Act & Assert
        try {
            $manager->broadcast('private-encrypted-room', 'e', ['secret' => 'exposed']);
            $this->fail('publishing to an encrypted channel with no key must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('published in the clear', $e->getMessage());
        }

        $fake->assertNothingBroadcast();
    }

    /**
     * The refusal is scoped to encrypted channels: everything else is untouched.
     *
     * An installation with no encryption key configured — which is most of them — must
     * be entirely unaffected.
     */
    public function testChannelsWithoutThePrefixAreUnaffected(): void
    {
        // Arrange
        $fake    = new FakeDriver();
        $manager = (new BroadcastingManager())->addDriver($fake)->setDefault('fake');

        // Act
        $manager->broadcast('private-room', 'e', ['open' => 'visible']);
        $manager->broadcast('presence-room', 'e', []);
        $manager->broadcast('updates', 'e', []);

        // Assert
        $fake->assertBroadcastCount(3);
    }

    /**
     * The auth token for an encrypted channel carries the per-channel key.
     */
    public function testAuthTokenCarriesTheSharedSecret(): void
    {
        // Arrange
        $signer = new PusherAuthSigner(new BroadcastApp('k', 's'), $this->encrypter());

        // Act
        $body = $signer->signFor('1.2', 'private-encrypted-room', true);

        // Assert
        $this->assertArrayHasKey('auth', $body);
        $this->assertSame(
            $this->encrypter()->sharedSecretForClient('private-encrypted-room'),
            $body['shared_secret']
        );
    }

    /**
     * Signing an encrypted channel with no key configured throws.
     *
     * A token without `shared_secret` produces a client that subscribes
     * successfully and then silently drops every message it cannot decrypt — a
     * channel that looks connected and delivers nothing. Refusing is louder and
     * cheaper to diagnose.
     */
    public function testSigningAnEncryptedChannelWithoutAKeyThrows(): void
    {
        // Arrange
        $signer = new PusherAuthSigner(new BroadcastApp('k', 's'));

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no encryption key is configured/');
        $signer->signFor('1.2', 'private-encrypted-room', true);
    }

    /**
     * A plain private channel is signed without a shared secret, so the key is only
     * ever handed out where it is needed.
     */
    public function testPlainPrivateChannelGetsNoSharedSecret(): void
    {
        // Arrange
        $signer = new PusherAuthSigner(new BroadcastApp('k', 's'), $this->encrypter());

        // Act
        $body = $signer->signFor('1.2', 'private-room', true);

        // Assert
        $this->assertArrayNotHasKey('shared_secret', $body);
    }

    /**
     * The manager exposes its encrypter, so an auth endpoint can hand a subscriber
     * the per-channel key without being configured separately.
     */
    public function testManagerExposesItsEncrypter(): void
    {
        // Arrange
        $encrypter = $this->encrypter();
        $manager   = new BroadcastingManager();

        // Act & Assert
        $this->assertNull($manager->encrypter(), 'absent until configured');
        $this->assertSame($encrypter, $manager->useEncryption($encrypter)->encrypter());
        $this->assertNull($manager->useEncryption(null)->encrypter(), 'and can be cleared');
    }

    /**
     * The service provider installs an encrypter from `broadcasting.encryption_key`.
     */
    public function testProviderInstallsTheEncrypterFromConfig(): void
    {
        // Arrange
        $container = new \Pramnos\Application\Container();
        $app = new class($container) extends \Pramnos\Application\Application {
            public function __construct(\Pramnos\Application\Container $c)
            {
                $this->_data['container'] = $c;
                $this->applicationInfo    = [
                    'broadcasting' => ['encryption_key' => ChannelEncrypterTest::MASTER_KEY],
                    'features'     => ['broadcasting'],
                ];
            }
        };

        // Act
        (new \Pramnos\Broadcasting\BroadcastingServiceProvider($app))->register();
        $manager = $app->container->get('broadcasting');

        // Assert
        $this->assertNotNull($manager->encrypter());
        $this->assertSame(
            $this->encrypter()->sharedSecretForClient('private-encrypted-x'),
            $manager->encrypter()->sharedSecretForClient('private-encrypted-x')
        );
    }

    /**
     * An unusable key leaves the manager without an encrypter rather than failing to
     * boot.
     *
     * A broadcasting misconfiguration must not take the whole application down —
     * every other feature still works, and the encrypted channels fail visibly at
     * the auth endpoint, which is where somebody is looking.
     */
    public function testProviderToleratesAnUnusableKey(): void
    {
        // Arrange
        $container = new \Pramnos\Application\Container();
        $app = new class($container) extends \Pramnos\Application\Application {
            public function __construct(\Pramnos\Application\Container $c)
            {
                $this->_data['container'] = $c;
                $this->applicationInfo    = [
                    'broadcasting' => ['encryption_key' => 'this is not base64 !!!'],
                    'features'     => ['broadcasting'],
                ];
            }
        };

        // Act
        (new \Pramnos\Broadcasting\BroadcastingServiceProvider($app))->register();
        $manager = $app->container->get('broadcasting');

        // Assert
        $this->assertNull($manager->encrypter(), 'a bad key means no encryption, not no application');
    }
}
