<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Health\SigningKeysCheck;
use Pramnos\Health\HealthStatus;

/**
 * Unit tests for SigningKeysCheck.
 *
 * The check exists because `file_exists()` is not the question. Every case below
 * is a state in which both key files are on disk and the server still cannot
 * issue a usable token, so each test asserts on a distinct way of being broken
 * rather than on a distinct message.
 *
 * Real key pairs are generated per case: a fixture pair committed to the
 * repository would be a private key in version control, and a mocked openssl
 * would test the mock rather than the round trip that makes case 5 detectable.
 */
#[CoversClass(SigningKeysCheck::class)]
class SigningKeysCheckTest extends TestCase
{
    /** @var string Temporary directory holding the generated keys */
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_keys_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    /**
     * Writes an RSA key pair into the temporary directory.
     *
     * @param  int    $bits   Modulus size
     * @param  string $prefix File name prefix, so one test can hold two pairs
     * @return array{0: string, 1: string} Private and public key paths
     */
    private function writeKeyPair(int $bits = 2048, string $prefix = 'a'): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($resource, 'the test environment must be able to generate a key');

        $privatePem = '';
        openssl_pkey_export($resource, $privatePem);
        $publicPem = (string) (openssl_pkey_get_details($resource)['key'] ?? '');

        $privatePath = $this->tmpDir . '/' . $prefix . '-private.key';
        $publicPath  = $this->tmpDir . '/' . $prefix . '-public.key';
        file_put_contents($privatePath, $privatePem);
        file_put_contents($publicPath, $publicPem);

        return [$privatePath, $publicPath];
    }

    /**
     * The name is part of the JSON contract.
     *
     * The health report is keyed by it, so a monitor or dashboard reading
     * `checks.signing_keys` breaks if this string changes.
     */
    public function testTheNameIsStable(): void
    {
        // Arrange / Act / Assert
        $this->assertSame('signing_keys', (new SigningKeysCheck())->getName());
    }

    /**
     * A matching 2048-bit pair is healthy, and reports the key size.
     *
     * The size is in the details because it is the one thing an operator wants
     * from a green result — it says whether a rotation is due.
     */
    public function testAMatchingPairIsHealthy(): void
    {
        // Arrange
        [$private, $public] = $this->writeKeyPair(2048);

        // Act
        $result = (new SigningKeysCheck($private, $public))->run();

        // Assert
        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertSame(2048, $result->details['bits']);
    }

    /**
     * A 1024-bit key is degraded, not down.
     *
     * It signs RS256 perfectly well; it is simply below the size the algorithm
     * should be used with. Reporting it as down would page somebody about a
     * server that works, and reporting it as ok would never get it rotated.
     */
    public function testAnUndersizedKeyIsDegradedRatherThanDown(): void
    {
        // Arrange
        [$private, $public] = $this->writeKeyPair(1024);

        // Act
        $result = (new SigningKeysCheck($private, $public))->run();

        // Assert
        $this->assertSame(HealthStatus::Degraded, $result->status);
        $this->assertStringContainsString('1024', $result->message);
        $this->assertSame(1024, $result->details['bits']);
    }

    /**
     * A missing private key is down, and says which half is missing.
     *
     * "The signing key is missing" would send an operator to check the wrong
     * file half the time.
     */
    public function testAMissingPrivateKeyIsDown(): void
    {
        // Arrange
        [, $public] = $this->writeKeyPair();

        // Act
        $result = (new SigningKeysCheck($this->tmpDir . '/absent.key', $public))->run();

        // Assert
        $this->assertSame(HealthStatus::Down, $result->status);
        $this->assertStringContainsString('private', $result->message);
    }

    /**
     * A missing public key is down too.
     *
     * The server can still sign without it, which is exactly why this is worth
     * checking: signing keeps working and every relying party fails to verify,
     * so the symptom appears in somebody else's application.
     */
    public function testAMissingPublicKeyIsDown(): void
    {
        // Arrange
        [$private] = $this->writeKeyPair();

        // Act
        $result = (new SigningKeysCheck($private, $this->tmpDir . '/absent.key'))->run();

        // Assert
        $this->assertSame(HealthStatus::Down, $result->status);
        $this->assertStringContainsString('public', $result->message);
    }

    /**
     * A directory where a key should be is down, not a crash.
     *
     * `is_file()` rather than `file_exists()` is what makes this a clean result:
     * a deploy that created the path as a directory is a real mistake, and
     * reading it would raise rather than report.
     */
    public function testADirectoryInPlaceOfAKeyIsDown(): void
    {
        // Arrange
        [, $public] = $this->writeKeyPair();
        mkdir($this->tmpDir . '/private.key');

        // Act
        $result = (new SigningKeysCheck($this->tmpDir . '/private.key', $public))->run();

        // Assert
        $this->assertSame(HealthStatus::Down, $result->status);
        $this->assertStringContainsString('private', $result->message);
    }

    /**
     * A truncated private key is down.
     *
     * This is the case `file_exists()` cannot see, and the most common one after
     * an interrupted write or a PEM that lost its trailing newline.
     */
    public function testAnUnparseablePrivateKeyIsDown(): void
    {
        // Arrange
        [, $public] = $this->writeKeyPair();
        $private = $this->tmpDir . '/broken-private.key';
        file_put_contents($private, "-----BEGIN PRIVATE KEY-----\nnot a key\n");

        // Act
        $result = (new SigningKeysCheck($private, $public))->run();

        // Assert
        $this->assertSame(HealthStatus::Down, $result->status);
        $this->assertStringContainsString('cannot be parsed', $result->message);
        $this->assertStringContainsString('private', $result->message);
    }

    /**
     * A corrupt public key is down, and is reported separately from the private one.
     */
    public function testAnUnparseablePublicKeyIsDown(): void
    {
        // Arrange
        [$private] = $this->writeKeyPair();
        $public = $this->tmpDir . '/broken-public.key';
        file_put_contents($public, "-----BEGIN PUBLIC KEY-----\nnot a key\n");

        // Act
        $result = (new SigningKeysCheck($private, $public))->run();

        // Assert
        $this->assertSame(HealthStatus::Down, $result->status);
        $this->assertStringContainsString('cannot be parsed', $result->message);
        $this->assertStringContainsString('public', $result->message);
    }

    /**
     * Two valid keys from different pairs are down.
     *
     * This is the case that justifies the sign-and-verify round trip: both files
     * parse, both are real keys, every file test passes, and no token this server
     * issues can be verified by anybody. Nothing short of using the keys detects
     * it.
     */
    public function testAMismatchedPairIsDown(): void
    {
        // Arrange — two independently generated pairs
        [$privateA] = $this->writeKeyPair(2048, 'a');
        [, $publicB] = $this->writeKeyPair(2048, 'b');

        // Act
        $result = (new SigningKeysCheck($privateA, $publicB))->run();

        // Assert
        $this->assertSame(HealthStatus::Down, $result->status);
        $this->assertStringContainsString('do not match', $result->message);
    }
}
