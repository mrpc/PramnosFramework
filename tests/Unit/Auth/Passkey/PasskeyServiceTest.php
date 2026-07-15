<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Passkey;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Passkey\AuthenticationOptions;
use Pramnos\Auth\Passkey\PasskeyCredential;
use Pramnos\Auth\Passkey\PasskeyException;
use Pramnos\Auth\Passkey\PasskeyService;
use Pramnos\Auth\Passkey\RegistrationOptions;
use Pramnos\Auth\Passkey\VerificationResult;
use Pramnos\Auth\Passkey\WebAuthnAdapterInterface;

/**
 * Unit tests for the PasskeyService orchestration.
 *
 * WHAT: the non-crypto logic — the single-use challenge store, verification
 *       against SERVER-stored options, credential/user binding checks, and
 *       persisting the advanced sign counter.
 * WHY:  the crypto is covered against a real authenticator elsewhere; here we
 *       prove the orchestration's safety rules independently: a challenge can
 *       be used once, an unknown/expired challenge is refused, a credential from
 *       the wrong user is rejected in an identified ceremony, and the new
 *       counter is written back (cross-request replay defence). The DB and cache
 *       are replaced by in-memory seams so these rules are tested in isolation;
 *       real persistence is covered by the integration suite (§8).
 */
class PasskeyServiceTest extends TestCase
{
    private RecordingAdapter $adapter;
    private InMemoryPasskeyService $service;

    protected function setUp(): void
    {
        // Arrange — a recording adapter + an in-memory service (no DB/cache).
        $this->adapter = new RecordingAdapter();
        $this->service = new InMemoryPasskeyService($this->adapter);
    }

    /** beginRegistration stores the challenge (with label) for the finish step. */
    public function testBeginRegistrationStoresChallenge(): void
    {
        // Act
        $options = $this->service->beginRegistration(42, 'My Key');

        // Assert — challenge is remembered and the label travels with it.
        $this->assertArrayHasKey($options->challenge, $this->service->store);
        $this->assertSame('My Key', $this->service->store[$options->challenge]['label']);
    }

    /** finishRegistration verifies, persists, and consumes the challenge once. */
    public function testFinishRegistrationPersistsAndConsumes(): void
    {
        // Arrange
        $options = $this->service->beginRegistration(42, 'Key');
        $this->adapter->registrationResult = new PasskeyCredential(null, 42, 'cid', 'pk', 0);

        // Act
        $cred = $this->service->finishRegistration(42, new RegistrationOptions($options->challenge, '', 42), '{}');

        // Assert
        $this->assertSame(100, $cred->id, 'Persisted (got an id)');
        $this->assertSame('Key', $this->service->persisted[0]['label']);
        $this->assertArrayNotHasKey($options->challenge, $this->service->store, 'Challenge consumed (single-use)');
    }

    /** A finish with an unknown/expired challenge is refused. */
    public function testFinishRegistrationUnknownChallenge(): void
    {
        $this->expectException(PasskeyException::class);
        $this->service->finishRegistration(42, new RegistrationOptions('nope', '', 42), '{}');
    }

    /** A finish whose stored user id differs from the caller is refused. */
    public function testFinishRegistrationUserMismatch(): void
    {
        $options = $this->service->beginRegistration(42, null);
        $this->expectException(PasskeyException::class);
        // Same challenge, but a different user id than the one it was issued to.
        $this->service->finishRegistration(99, new RegistrationOptions($options->challenge, '', 99), '{}');
    }

    /** The adapter reporting a credential for a different user is refused. */
    public function testFinishRegistrationCredentialUserMismatch(): void
    {
        $options = $this->service->beginRegistration(42, null);
        // Adapter returns a credential belonging to user 7, not 42.
        $this->adapter->registrationResult = new PasskeyCredential(null, 7, 'cid', 'pk', 0);
        $this->expectException(PasskeyException::class);
        $this->service->finishRegistration(42, new RegistrationOptions($options->challenge, '', 42), '{}');
    }

    /** finishAuthentication verifies and writes back the advanced counter. */
    public function testFinishAuthenticationUpdatesSignCount(): void
    {
        // Arrange — a stored credential the adapter will "verify".
        $stored = new PasskeyCredential(5, 42, 'cid', 'pk', 3);
        $this->service->credentials['cid'] = $stored;
        $this->adapter->credentialIdToExtract = 'cid';
        $this->adapter->authenticationResult = new VerificationResult(42, $stored->withSignCount(4), 4);

        $options = $this->service->beginAuthentication(42);

        // Act
        $result = $this->service->finishAuthentication(
            new AuthenticationOptions($options->challenge, '', 42),
            '{}'
        );

        // Assert — the new counter was persisted for the stored credential.
        $this->assertSame(42, $result->userId);
        $this->assertSame(4, $this->service->signCountUpdates[5], 'Counter 3 → 4 written back for id 5');
    }

    /** An unknown credential id in the response is refused. */
    public function testFinishAuthenticationUnknownCredential(): void
    {
        $this->adapter->credentialIdToExtract = 'missing';
        $options = $this->service->beginAuthentication(42);
        $this->expectException(PasskeyException::class);
        $this->service->finishAuthentication(new AuthenticationOptions($options->challenge, '', 42), '{}');
    }

    /** In an identified ceremony a credential of another user is refused. */
    public function testFinishAuthenticationCredentialBelongsToOtherUser(): void
    {
        $stored = new PasskeyCredential(5, 7, 'cid', 'pk', 0); // belongs to user 7
        $this->service->credentials['cid'] = $stored;
        $this->adapter->credentialIdToExtract = 'cid';
        $options = $this->service->beginAuthentication(42); // ceremony for user 42
        $this->expectException(PasskeyException::class);
        $this->service->finishAuthentication(new AuthenticationOptions($options->challenge, '', 42), '{}');
    }

    /** A missing credential id (adapter returns null) is refused. */
    public function testFinishAuthenticationMissingCredentialId(): void
    {
        $this->adapter->credentialIdToExtract = null;
        $options = $this->service->beginAuthentication(null);
        $this->expectException(PasskeyException::class);
        $this->service->finishAuthentication(new AuthenticationOptions($options->challenge, '', null), '{}');
    }

    /** An unknown/expired authentication challenge is refused. */
    public function testFinishAuthenticationUnknownChallenge(): void
    {
        $this->expectException(PasskeyException::class);
        // No beginAuthentication ran, so this challenge is not in the store.
        $this->service->finishAuthentication(new AuthenticationOptions('never-issued', '', 42), '{}');
    }

    /** toBool normalises DB booleans across drivers (int, "t"/"f", "1"/"0"). */
    public function testToBoolNormalisation(): void
    {
        $svc = new class ($this->adapter) extends InMemoryPasskeyService {
            public function pub(mixed $v): bool { return $this->toBool($v); }
        };
        $this->assertTrue($svc->pub(true));
        $this->assertFalse($svc->pub(false));
        $this->assertTrue($svc->pub(1));
        $this->assertFalse($svc->pub(0));
        $this->assertTrue($svc->pub('t'));   // PostgreSQL
        $this->assertTrue($svc->pub('1'));   // MySQL
        $this->assertFalse($svc->pub('f'));
        $this->assertFalse($svc->pub('0'));
    }
}

/**
 * A WebAuthnAdapterInterface double that returns canned results and records the
 * credential id it should "extract" from a response.
 */
class RecordingAdapter implements WebAuthnAdapterInterface
{
    public ?PasskeyCredential $registrationResult = null;
    public ?VerificationResult $authenticationResult = null;
    public ?string $credentialIdToExtract = 'cid';

    public function createRegistrationOptions(int $userId, string $userName, string $displayName, array $excludeCredentialIds = []): RegistrationOptions
    {
        return new RegistrationOptions('chal-' . $userId . '-' . count($excludeCredentialIds), '{}', $userId);
    }

    public function verifyRegistration(RegistrationOptions $options, string $clientResponse, string $host): PasskeyCredential
    {
        return $this->registrationResult ?? new PasskeyCredential(null, $options->userId, 'cid', 'pk', 0);
    }

    public function createAuthenticationOptions(?int $userId, array $allowCredentialIds = []): AuthenticationOptions
    {
        return new AuthenticationOptions('achal-' . ($userId ?? 'x'), '{}', $userId);
    }

    public function verifyAuthentication(AuthenticationOptions $options, string $clientResponse, PasskeyCredential $stored, string $host): VerificationResult
    {
        return $this->authenticationResult ?? new VerificationResult($stored->userId, $stored, $stored->signCount);
    }

    public function extractCredentialId(string $clientResponse): ?string
    {
        return $this->credentialIdToExtract;
    }
}

/**
 * PasskeyService with the DB and cache replaced by in-memory arrays, so the
 * orchestration can be tested without infrastructure.
 */
class InMemoryPasskeyService extends PasskeyService
{
    /** @var array<string,array<string,mixed>> */
    public array $store = [];
    /** @var array<string,PasskeyCredential> */
    public array $credentials = [];
    /** @var list<array{cred:PasskeyCredential,label:?string}> */
    public array $persisted = [];
    /** @var array<int,int> */
    public array $signCountUpdates = [];

    public function __construct(WebAuthnAdapterInterface $adapter)
    {
        // Bypass the parent constructor (no DB/config/cache wiring needed).
        $this->adapter = $adapter;
        $this->config  = new \Pramnos\Auth\Passkey\Config('rp.id', 'RP', ['https://rp.id']);
    }

    protected function storeChallenge(string $type, string $challenge, array $data): void
    {
        $this->store[$challenge] = $data;
    }

    protected function consumeChallenge(string $type, string $challenge): ?array
    {
        if (!isset($this->store[$challenge])) {
            return null;
        }
        $data = $this->store[$challenge];
        unset($this->store[$challenge]); // single-use
        return $data;
    }

    protected function host(): string
    {
        return 'rp.id';
    }

    protected function userIdentity(int $userId): array
    {
        return ['user' . $userId, 'User ' . $userId];
    }

    protected function activeCredentialIds(int $userId): array
    {
        return [];
    }

    protected function findActiveByCredentialId(string $credentialId): ?PasskeyCredential
    {
        return $this->credentials[$credentialId] ?? null;
    }

    protected function persistCredential(PasskeyCredential $credential, ?string $label): PasskeyCredential
    {
        $this->persisted[] = ['cred' => $credential, 'label' => $label];
        // Simulate the DB assigning a primary key and storing the label.
        return new PasskeyCredential(
            100,
            $credential->userId,
            $credential->credentialId,
            $credential->publicKey,
            $credential->signCount,
            $credential->aaguid,
            $credential->transports,
            $label
        );
    }

    protected function updateSignCount(int $id, int $signCount): void
    {
        $this->signCountUpdates[$id] = $signCount;
    }
}
