<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\OAuth2ServerFactory;
use Pramnos\Auth\OAuth2\Repositories\ScopeRepository;

/**
 * Unit tests for OAuth2ServerFactory.
 *
 * Tests focus on:
 *  - Constructor path selection (explicit keys vs. defaults, explicit
 *    encryptionKey vs. loadOrGenerateEncryptionKey fallback).
 *  - Getter methods (getPrivateKeyPath, getPublicKeyPath, getEncryptionKey).
 *  - makeScopeRepository() factory.
 *  - generateKeyPair() end-to-end: creates PEM files when they don't exist
 *    and skips regeneration when they already exist.
 *  - loadOrGenerateEncryptionKey() via constructor: creates the key file on
 *    first run and reuses the same value on the second run.
 *
 * Methods that require a wired Authorization/Resource Server (createAuthorizationServer,
 * createResourceServer) are not exercised here — they need real RSA key files and
 * a live DB, so they belong in integration tests.
 */
#[CoversClass(OAuth2ServerFactory::class)]
class OAuth2ServerFactoryTest extends TestCase
{
    private string $keysDir;
    private \Pramnos\Application\Controller $controller;

    protected function setUp(): void
    {
        $this->keysDir    = sys_get_temp_dir() . '/pramnos_oauth_' . bin2hex(random_bytes(4));
        mkdir($this->keysDir, 0700, true);

        // Controller is stored but never called by the pure-utility code paths
        // exercised in this file; a mock satisfies the type hint.
        $this->controller = $this->createMock(\Pramnos\Application\Controller::class);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->keysDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ── Constructor / getters ─────────────────────────────────────────────────

    /**
     * When explicit private/public key paths and an encryptionKey are passed
     * to the constructor, the getters must return those exact values.
     *
     * This covers the non-null branches of the three nullable constructor params
     * without touching the filesystem (no loadOrGenerateEncryptionKey() called).
     */
    public function testConstructorWithExplicitPathsAndKeyStoresValues(): void
    {
        // Arrange
        $priv = $this->keysDir . '/private.key';
        $pub  = $this->keysDir . '/public.key';
        $enc  = 'test-encryption-key-abc123';

        // Act
        $factory = new OAuth2ServerFactory($this->controller, $priv, $pub, $enc);

        // Assert — getters return exactly what was passed in
        $this->assertSame($priv, $factory->getPrivateKeyPath(),
            'getPrivateKeyPath() must return the explicitly provided path');
        $this->assertSame($pub, $factory->getPublicKeyPath(),
            'getPublicKeyPath() must return the explicitly provided path');
        $this->assertSame($enc, $factory->getEncryptionKey(),
            'getEncryptionKey() must return the explicitly provided key');
    }

    // ── makeScopeRepository() ─────────────────────────────────────────────────

    /**
     * makeScopeRepository() must return a new ScopeRepository instance each time
     * it is called. It is a pure factory with no side effects.
     */
    public function testMakeScopeRepositoryReturnsScopeRepository(): void
    {
        // Arrange
        $factory = new OAuth2ServerFactory(
            $this->controller,
            $this->keysDir . '/p.key',
            $this->keysDir . '/pub.key',
            'fixed-key'
        );

        // Act
        $repo1 = $factory->makeScopeRepository();
        $repo2 = $factory->makeScopeRepository();

        // Assert — returns correct type
        $this->assertInstanceOf(ScopeRepository::class, $repo1,
            'makeScopeRepository() must return a ScopeRepository');

        // Assert — new instance each call (not a singleton)
        $this->assertNotSame($repo1, $repo2,
            'makeScopeRepository() must return a fresh instance on each call');
    }

    // ── generateKeyPair() ─────────────────────────────────────────────────────

    /**
     * generateKeyPair() must create a PEM private key and a PEM public key at
     * the configured paths when neither file exists yet.
     *
     * Verifies that the written files are non-empty and that the private key
     * begins with the standard PEM header.
     */
    public function testGenerateKeyPairCreatesKeyFilesWhenAbsent(): void
    {
        // Arrange — paths inside a fresh empty sub-directory
        $sub  = $this->keysDir . '/keys';
        $priv = $sub . '/private.key';
        $pub  = $sub . '/public.key';

        $factory = new OAuth2ServerFactory($this->controller, $priv, $pub, 'enc-key');

        // Pre-condition — neither file exists yet
        $this->assertFileDoesNotExist($priv);
        $this->assertFileDoesNotExist($pub);

        // Act
        $factory->generateKeyPair();

        // Assert — both files created
        $this->assertFileExists($priv, 'generateKeyPair() must create the private key file');
        $this->assertFileExists($pub,  'generateKeyPair() must create the public key file');

        // Assert — files contain PEM-formatted key material
        $privContent = file_get_contents($priv);
        $this->assertStringContainsString('-----BEGIN', $privContent,
            'Private key file must contain a PEM header');
        $this->assertNotEmpty(file_get_contents($pub),
            'Public key file must be non-empty');
    }

    /**
     * generateKeyPair() must NOT overwrite existing key files.
     *
     * If both private and public key files already exist the method must return
     * immediately without modifying them, preserving any tokens signed with
     * the current private key.
     */
    public function testGenerateKeyPairSkipsWhenBothFilesExist(): void
    {
        // Arrange — create stub "existing" key files
        $sub  = $this->keysDir . '/keys2';
        mkdir($sub, 0700, true);
        $priv = $sub . '/private.key';
        $pub  = $sub . '/public.key';

        file_put_contents($priv, 'EXISTING_PRIVATE');
        file_put_contents($pub,  'EXISTING_PUBLIC');

        $factory = new OAuth2ServerFactory($this->controller, $priv, $pub, 'enc-key');

        // Act
        $factory->generateKeyPair();

        // Assert — files were not modified
        $this->assertSame('EXISTING_PRIVATE', file_get_contents($priv),
            'generateKeyPair() must not overwrite an existing private key');
        $this->assertSame('EXISTING_PUBLIC', file_get_contents($pub),
            'generateKeyPair() must not overwrite an existing public key');
    }

    // ── loadOrGenerateEncryptionKey() (via constructor without explicit key) ──

    /**
     * When no encryptionKey is supplied to the constructor, it must call
     * loadOrGenerateEncryptionKey() which generates and persists a base64
     * encryption key to {keysDir}/encryption.key on first run.
     *
     * Verifies:
     *  1. The generated key is stored in the factory.
     *  2. An encryption.key file was written under the key directory.
     *  3. The key is valid base64 (decodes to 32 bytes).
     */
    public function testConstructorWithoutEncryptionKeyGeneratesAndPersistsKey(): void
    {
        // Arrange — paths under a fresh sub-dir so the encryption.key doesn't exist yet
        $sub  = $this->keysDir . '/gen';
        mkdir($sub, 0700, true);
        $priv    = $sub . '/private.key';
        $pub     = $sub . '/public.key';
        $keyFile = $sub . '/encryption.key';

        // Pre-condition
        $this->assertFileDoesNotExist($keyFile);

        // Act — omit $encryptionKey to trigger loadOrGenerateEncryptionKey()
        $factory = new OAuth2ServerFactory($this->controller, $priv, $pub);

        // Assert — the factory has a non-empty encryption key
        $encKey = $factory->getEncryptionKey();
        $this->assertNotEmpty($encKey, 'Encryption key must not be empty after generation');

        // Assert — the key file was created
        $this->assertFileExists($keyFile, 'encryption.key must be written on first run');

        // Assert — stored key matches what was written to disk
        $this->assertSame(trim((string)file_get_contents($keyFile)), $encKey,
            'The encryption key stored in the factory must match the persisted file');

        // Assert — key decodes to 32 bytes (base64_encode(random_bytes(32)))
        $decoded = base64_decode($encKey, true);
        $this->assertNotFalse($decoded, 'Encryption key must be valid base64');
        $this->assertSame(32, strlen($decoded), 'Decoded encryption key must be 32 bytes');
    }

    /**
     * When the encryption.key file already exists, the constructor must load its
     * contents instead of generating a new key.
     *
     * Two successive factory instantiations from the same directory must therefore
     * yield the same encryption key (stable across server restarts).
     */
    public function testConstructorLoadsExistingEncryptionKeyFromFile(): void
    {
        // Arrange — write a known key file
        $sub     = $this->keysDir . '/load';
        mkdir($sub, 0700, true);
        $priv    = $sub . '/private.key';
        $pub     = $sub . '/public.key';
        $keyFile = $sub . '/encryption.key';
        $fixedKey = base64_encode(str_repeat('x', 32));
        file_put_contents($keyFile, $fixedKey);

        // Act — construct factory without explicit key (triggers loadOrGenerateEncryptionKey)
        $factory = new OAuth2ServerFactory($this->controller, $priv, $pub);

        // Assert — the factory loaded the pre-existing key, not a new random one
        $this->assertSame($fixedKey, $factory->getEncryptionKey(),
            'Constructor must load the encryption key from an existing file');
    }
}
