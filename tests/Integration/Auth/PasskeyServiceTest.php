<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Passkey\Config;
use Pramnos\Auth\Passkey\PasskeyException;
use Pramnos\Auth\Passkey\PasskeyService;
use Pramnos\Auth\Passkey\WebAuthnAdapterInterface;
use Pramnos\Auth\Passkey\WebAuthnLibAdapter;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\AuthServer\CreatePasskeyCredentialsTable;
use Pramnos\Tests\Fixtures\Passkey\FakeAuthenticator;

/**
 * Integration tests for PasskeyService against a real database.
 *
 * WHAT: the full passkey lifecycle — register a credential, authenticate with
 *       it, and manage it (list/rename/revoke) — driven end-to-end through the
 *       REAL WebAuthn adapter and a software authenticator, persisting into the
 *       real `authserver.passkey_credentials` table.
 * WHY:  unit tests cover the crypto and orchestration in isolation; §8 requires
 *       proving the DML actually takes effect (rows written, sign counter
 *       advanced and persisted so replay is caught across requests, soft-delete
 *       on revoke). Runs against whichever engine DB_TYPE selects (CI covers all).
 *
 * The challenge store (cache) is replaced with an in-memory array and the user
 * lookup is stubbed, so the test needs only the passkey table — not a live cache
 * or a users table — while every credential read/write hits the real DB.
 */
class PasskeyServiceTest extends TestCase
{
    private const RP_ID   = 'example.com';
    private const ORIGIN  = 'https://example.com';
    private const USER_ID = 770001;

    private Database $db;
    private Application $app;
    private TestablePasskeyService $service;
    private FakeAuthenticator $authenticator;
    private bool $isPg;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $driver = $_ENV['DB_TYPE'] ?? (getenv('DB_TYPE') ?: 'mysql');
        $this->isPg = in_array($driver, ['postgresql', 'pgsql', 'timescaledb'], true);

        $this->db = new Database();
        $this->db->type     = $driver;
        $this->db->server   = $_ENV['DB_HOST'] ?? (getenv('DB_HOST') ?: 'db');
        $this->db->port     = (int) ($_ENV['DB_PORT'] ?? (getenv('DB_PORT') ?: ($this->isPg ? 5432 : 3306)));
        $this->db->user     = $_ENV['DB_USER'] ?? (getenv('DB_USER') ?: 'root');
        $this->db->password = $_ENV['DB_PASS'] ?? (getenv('DB_PASS') ?: 'secret');
        $this->db->database = $_ENV['DB_NAME'] ?? (getenv('DB_NAME') ?: 'pramnos_test');

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped('Database not reachable');
            }
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Database not reachable: ' . $e->getMessage());
        }

        if ($this->isPg) {
            $this->db->statement('CREATE SCHEMA IF NOT EXISTS authserver');
        }

        $this->app = new Application();
        $this->app->database = $this->db;

        // Fresh passkey table each test.
        $migration = new CreatePasskeyCredentialsTable($this->app);
        $migration->down();
        $migration->up();

        $config  = new Config(self::RP_ID, 'Example', [self::ORIGIN], 60000, 'preferred');
        $adapter = new WebAuthnLibAdapter($config);
        $this->service = new TestablePasskeyService($adapter, $this->db, $config);
        $this->authenticator = new FakeAuthenticator();

        // The real host() reads this; pin it to the RP host.
        $_SERVER['HTTP_HOST'] = self::RP_ID;
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST']);
        try {
            (new CreatePasskeyCredentialsTable($this->app))->down();
        } catch (\Throwable) {
            // Non-fatal cleanup.
        }
    }

    /** Run a full registration ceremony and return the persisted credential. */
    private function doRegister(?string $label = 'My Key'): \Pramnos\Auth\Passkey\PasskeyCredential
    {
        $options = $this->service->beginRegistration(self::USER_ID, $label);
        $response = $this->authenticator->attestationResponse($options->challenge, self::RP_ID, self::ORIGIN, 0);
        return $this->service->finishRegistration(self::USER_ID, $options, $response);
    }

    /**
     * A completed registration writes a row that can be read back with its
     * user, label, and initial counter.
     */
    public function testRegistrationPersistsCredential(): void
    {
        // Act
        $cred = $this->doRegister('Yubikey');

        // Assert — a real row with a primary key.
        $this->assertNotNull($cred->id);
        $this->assertSame(self::USER_ID, $cred->userId);
        $this->assertSame('Yubikey', $cred->name);

        $list = $this->service->listCredentials(self::USER_ID);
        $this->assertCount(1, $list, 'Exactly one active credential stored');
        $this->assertSame($this->authenticator->credentialIdBase64Url(), $list[0]->credentialId);
    }

    /** Registering the same credential twice is rejected (unique credential id). */
    public function testDuplicateRegistrationRejected(): void
    {
        $this->doRegister();
        $this->expectException(PasskeyException::class);
        $this->doRegister();
    }

    /**
     * After registration, an assertion authenticates and the advanced sign
     * counter is PERSISTED — so a later replay of the same counter is caught.
     */
    public function testAuthenticationPersistsSignCount(): void
    {
        // Arrange
        $cred = $this->doRegister();

        // Act — authenticate with counter 1.
        $authOpts = $this->service->beginAuthentication(self::USER_ID);
        $response = $this->authenticator->assertionResponse($authOpts->challenge, self::RP_ID, self::ORIGIN, (string) self::USER_ID, 1);
        $result = $this->service->finishAuthentication($authOpts, $response);

        // Assert — resolved user + persisted counter.
        $this->assertSame(self::USER_ID, $result->userId);
        $this->assertSame(1, $this->service->listCredentials(self::USER_ID)[0]->signCount, 'Counter persisted as 1');

        // Act 2 — a replay reporting the same counter (1) must be rejected,
        // proving the persisted counter is what the next ceremony checks against.
        $replayOpts = $this->service->beginAuthentication(self::USER_ID);
        $replay = $this->authenticator->assertionResponse($replayOpts->challenge, self::RP_ID, self::ORIGIN, (string) self::USER_ID, 1);
        $this->expectException(PasskeyException::class);
        $this->service->finishAuthentication($replayOpts, $replay);
    }

    /** A challenge is single-use: replaying the same finish call fails. */
    public function testChallengeIsSingleUse(): void
    {
        $options = $this->service->beginRegistration(self::USER_ID, 'K');
        $response = $this->authenticator->attestationResponse($options->challenge, self::RP_ID, self::ORIGIN, 0);
        $this->service->finishRegistration(self::USER_ID, $options, $response);

        // Second finish with the same (now consumed) challenge is refused.
        $this->expectException(PasskeyException::class);
        $this->service->finishRegistration(self::USER_ID, $options, $response);
    }

    /** Renaming an owned credential updates the stored label. */
    public function testRenameCredential(): void
    {
        $cred = $this->doRegister('Old');
        $ok = $this->service->renameCredential(self::USER_ID, (int) $cred->id, 'New Name');
        $this->assertTrue($ok);
        $this->assertSame('New Name', $this->service->listCredentials(self::USER_ID)[0]->name);
    }

    /** Renaming a credential the user does not own returns false. */
    public function testRenameForeignCredentialFails(): void
    {
        $cred = $this->doRegister();
        $this->assertFalse($this->service->renameCredential(999999, (int) $cred->id, 'X'));
    }

    /** Revoking a credential the user does not own returns false (and leaves it). */
    public function testRevokeForeignCredentialFails(): void
    {
        $cred = $this->doRegister();
        $this->assertFalse($this->service->revokeCredential(999999, (int) $cred->id));
        // Still active for the real owner.
        $this->assertCount(1, $this->service->listCredentials(self::USER_ID));
    }

    /** Revoking soft-deletes: the credential disappears from the active list. */
    public function testRevokeCredential(): void
    {
        $cred = $this->doRegister();
        $this->assertTrue($this->service->hasCredentials(self::USER_ID));

        $ok = $this->service->revokeCredential(self::USER_ID, (int) $cred->id);

        $this->assertTrue($ok);
        $this->assertCount(0, $this->service->listCredentials(self::USER_ID), 'No active credentials after revoke');
        $this->assertFalse($this->service->hasCredentials(self::USER_ID));
    }

    /** A revoked credential can no longer be used to authenticate. */
    public function testRevokedCredentialCannotAuthenticate(): void
    {
        $cred = $this->doRegister();
        $this->service->revokeCredential(self::USER_ID, (int) $cred->id);

        $authOpts = $this->service->beginAuthentication(self::USER_ID);
        $response = $this->authenticator->assertionResponse($authOpts->challenge, self::RP_ID, self::ORIGIN, (string) self::USER_ID, 1);

        $this->expectException(PasskeyException::class);
        $this->service->finishAuthentication($authOpts, $response);
    }
}

/**
 * PasskeyService with only the user lookup stubbed, so the integration test
 * exercises the REAL DB persistence AND the REAL cache-backed challenge store
 * (single-use, TTL) without needing a users table. The host is pinned to the RP
 * id (no HTTP request in tests).
 */
class TestablePasskeyService extends PasskeyService
{
    public function __construct(WebAuthnAdapterInterface $adapter, Database $database, Config $config)
    {
        parent::__construct($adapter, $database, $config);
    }

    // host() is NOT overridden: the real implementation reads $_SERVER['HTTP_HOST'],
    // which the test sets, so that code path is exercised.

    protected function userIdentity(int $userId): array
    {
        return ['user' . $userId, 'User ' . $userId];
    }
}
