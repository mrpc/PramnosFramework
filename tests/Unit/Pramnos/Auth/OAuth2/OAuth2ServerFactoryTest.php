<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth\OAuth2;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuth2\OAuth2ServerFactory;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Pramnos\Application\Controller;
use Pramnos\Application\Application;
use Pramnos\Auth\OAuth2\Repositories\ScopeRepository;

class OAuth2ServerFactoryTest extends TestCase
{
    private Controller $controller;
    private string $tempDir;
    private string $privateKeyPath;
    private string $publicKeyPath;

    protected function setUp(): void
    {
        $this->controller = $this->createMock(Controller::class);
        $this->tempDir = sys_get_temp_dir() . '/oauth_test_' . uniqid();
        mkdir($this->tempDir);
        $this->privateKeyPath = $this->tempDir . '/private.key';
        $this->publicKeyPath = $this->tempDir . '/public.key';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->privateKeyPath)) {
            unlink($this->privateKeyPath);
        }
        if (file_exists($this->publicKeyPath)) {
            unlink($this->publicKeyPath);
        }
        $encKey = dirname($this->privateKeyPath) . '/encryption.key';
        if (file_exists($encKey)) {
            unlink($encKey);
        }
        rmdir($this->tempDir);
    }

    public function testGenerateKeyPairCreatesKeys(): void
    {
        $factory = new OAuth2ServerFactory($this->controller, $this->privateKeyPath, $this->publicKeyPath);
        $factory->generateKeyPair();

        $this->assertFileExists($this->privateKeyPath);
        $this->assertFileExists($this->publicKeyPath);
    }

    public function testGenerateKeyPairDoesNotOverwriteExisting(): void
    {
        file_put_contents($this->privateKeyPath, 'private');
        file_put_contents($this->publicKeyPath, 'public');

        $factory = new OAuth2ServerFactory($this->controller, $this->privateKeyPath, $this->publicKeyPath);
        $factory->generateKeyPair();

        $this->assertEquals('private', file_get_contents($this->privateKeyPath));
        $this->assertEquals('public', file_get_contents($this->publicKeyPath));
    }

    public function testCreateAuthorizationServer(): void
    {
        $factory = new OAuth2ServerFactory($this->controller, $this->privateKeyPath, $this->publicKeyPath);
        $factory->generateKeyPair();

        $server = $factory->createAuthorizationServer();
        $this->assertInstanceOf(AuthorizationServer::class, $server);
    }

    public function testCreateResourceServer(): void
    {
        $factory = new OAuth2ServerFactory($this->controller, $this->privateKeyPath, $this->publicKeyPath);
        $factory->generateKeyPair();

        $server = $factory->createResourceServer();
        $this->assertInstanceOf(ResourceServer::class, $server);
    }

    public function testGetters(): void
    {
        $factory = new OAuth2ServerFactory($this->controller, $this->privateKeyPath, $this->publicKeyPath, 'enc-key');
        
        $this->assertEquals($this->privateKeyPath, $factory->getPrivateKeyPath());
        $this->assertEquals($this->publicKeyPath, $factory->getPublicKeyPath());
        $this->assertEquals('enc-key', $factory->getEncryptionKey());
    }

    public function testMakeScopeRepository(): void
    {
        $factory = new OAuth2ServerFactory($this->controller, $this->privateKeyPath, $this->publicKeyPath);
        $repo = $factory->makeScopeRepository();
        
        $this->assertInstanceOf(ScopeRepository::class, $repo);
    }

    public function testLoadOrGenerateEncryptionKeyLoadsExistingKey(): void
    {
        // Pre-create the encryption key file
        $encKeyPath = $this->tempDir . '/encryption.key';
        $expectedKey = base64_encode(random_bytes(32));
        file_put_contents($encKeyPath, $expectedKey);

        $factory = new OAuth2ServerFactory(
            $this->controller,
            $this->privateKeyPath,
            $this->publicKeyPath,
            null // triggers loadOrGenerateEncryptionKey
        );

        // The factory will read the existing key
        $this->assertEquals($expectedKey, $factory->getEncryptionKey());
    }

    public function testGenerateKeyPairCreatesDirectoryWhenMissing(): void
    {
        // Use a path in a non-existent directory
        $nestedDir = $this->tempDir . '/nested/keys';
        $privatePath = $nestedDir . '/private.key';
        $publicPath  = $nestedDir . '/public.key';

        $factory = new OAuth2ServerFactory($this->controller, $privatePath, $publicPath);
        $factory->generateKeyPair();

        // After generation, keys should exist (directory was created)
        $this->assertFileExists($privatePath);
        $this->assertFileExists($publicPath);

        // Cleanup
        unlink($privatePath);
        unlink($publicPath);
        unlink(dirname($privatePath) . '/encryption.key');
        rmdir($nestedDir);
        rmdir(dirname($nestedDir));
    }

    public function testGenerateKeyPairSkipsWhenPrivateKeyOnlyExists(): void
    {
        // Only private key exists — should regenerate
        file_put_contents($this->privateKeyPath, 'private_placeholder');
        // Public key does NOT exist

        $factory = new OAuth2ServerFactory($this->controller, $this->privateKeyPath, $this->publicKeyPath);
        $factory->generateKeyPair();

        // After generation, both keys should exist
        $this->assertFileExists($this->privateKeyPath);
        $this->assertFileExists($this->publicKeyPath);
        // Private key should have been regenerated (not the placeholder)
        $this->assertNotEquals('private_placeholder', file_get_contents($this->privateKeyPath));
    }

    public function testCreateAuthorizationServerWithGeneratedKeys(): void
    {
        $factory = new OAuth2ServerFactory(
            $this->controller,
            $this->privateKeyPath,
            $this->publicKeyPath
        );
        $factory->generateKeyPair();

        $server = $factory->createAuthorizationServer();
        $this->assertInstanceOf(\League\OAuth2\Server\AuthorizationServer::class, $server);
    }

    public function testCreateResourceServerWithGeneratedKeys(): void
    {
        $factory = new OAuth2ServerFactory(
            $this->controller,
            $this->privateKeyPath,
            $this->publicKeyPath
        );
        $factory->generateKeyPair();

        $server = $factory->createResourceServer();
        $this->assertInstanceOf(\League\OAuth2\Server\ResourceServer::class, $server);
    }

    public function testGettersReturnConfiguredValues(): void
    {
        $factory = new OAuth2ServerFactory(
            $this->controller,
            $this->privateKeyPath,
            $this->publicKeyPath,
            'my-encryption-key'
        );

        $this->assertEquals($this->privateKeyPath, $factory->getPrivateKeyPath());
        $this->assertEquals($this->publicKeyPath, $factory->getPublicKeyPath());
        $this->assertEquals('my-encryption-key', $factory->getEncryptionKey());
    }

    public function testEncryptionKeyIsPersistedOnFirstGeneration(): void
    {
        // Remove any existing encryption key
        $encKeyPath = $this->tempDir . '/encryption.key';
        if (file_exists($encKeyPath)) {
            unlink($encKeyPath);
        }

        // First factory construction triggers loadOrGenerateEncryptionKey
        $factory1 = new OAuth2ServerFactory(
            $this->controller,
            $this->privateKeyPath,
            $this->publicKeyPath
        );
        $key1 = $factory1->getEncryptionKey();

        // Second factory construction should load the same persisted key
        $factory2 = new OAuth2ServerFactory(
            $this->controller,
            $this->privateKeyPath,
            $this->publicKeyPath
        );
        $key2 = $factory2->getEncryptionKey();

        $this->assertEquals($key1, $key2);
        $this->assertNotEmpty($key1);
    }

    /**
     * generateKeyPair() logs an error and returns early when the keys directory
     * cannot be created (lines 125-129). Triggered by placing a regular file at
     * a path component that mkdir would need to traverse.
     */
    public function testGenerateKeyPairLogsErrorWhenCannotCreateDirectory(): void
    {
        // Arrange — create a file where the parent directory would need to be
        $parentFile = $this->tempDir . '/not_a_dir';
        file_put_contents($parentFile, 'x');
        $privatePath = $parentFile . '/sub/private.key';
        $publicPath  = $parentFile . '/sub/public.key';

        $factory = new OAuth2ServerFactory($this->controller, $privatePath, $publicPath, 'enc');

        // Act — mkdir fails silently; the method must not throw
        $factory->generateKeyPair();

        // Assert — no key file was created (method returned early after log)
        $this->assertFileDoesNotExist($privatePath,
            'generateKeyPair() must not create a key when the directory cannot be made');

        // Cleanup
        unlink($parentFile);
    }

    /**
     * generateKeyPair() logs an error and returns early when writing the private
     * key file fails (lines 151-155). Triggered by a directory pre-existing at
     * the private key path so file_put_contents() cannot write it.
     */
    public function testGenerateKeyPairLogsErrorWhenCannotWritePrivateKey(): void
    {
        // Arrange — create a directory where the private key file should be written
        $privateDirPath = $this->tempDir . '/private_is_a_dir';
        mkdir($privateDirPath, 0777);
        $publicPath = $this->tempDir . '/public_for_write_fail.key';

        $factory = new OAuth2ServerFactory($this->controller, $privateDirPath, $publicPath, 'enc');

        // Act — file_put_contents fails silently; method must not throw
        $factory->generateKeyPair();

        // Assert — the private key path is still a directory (not overwritten)
        $this->assertDirectoryExists($privateDirPath,
            'The directory at the private key path must not be destroyed');
        $this->assertFileDoesNotExist($publicPath,
            'Public key must not be written when private key write fails');

        // Cleanup
        rmdir($privateDirPath);
    }

    /**
     * generateKeyPair() logs an error and returns early when writing the public
     * key file fails (lines 161-165). Triggered by a directory pre-existing at
     * the public key path so file_put_contents() cannot write it.
     */
    public function testGenerateKeyPairLogsErrorWhenCannotWritePublicKey(): void
    {
        // Arrange — public key path is a directory; private key path is writable
        $privatePath  = $this->tempDir . '/priv_pub_fail.key';
        $publicDirPath = $this->tempDir . '/public_is_a_dir';
        mkdir($publicDirPath, 0777);

        $factory = new OAuth2ServerFactory($this->controller, $privatePath, $publicDirPath, 'enc');

        // Act — private key write succeeds; public key write fails silently
        $factory->generateKeyPair();

        // Assert — private key was written; public key path is still a directory
        $this->assertFileExists($privatePath,
            'Private key must have been written before the public-key failure');
        $this->assertDirectoryExists($publicDirPath,
            'The directory at the public key path must remain a directory');

        // Cleanup
        unlink($privatePath);
        rmdir($publicDirPath);
    }

    /**
     * loadOrGenerateEncryptionKey() logs an error and returns the generated key
     * in-memory when writing the encryption key file fails (lines 223-227).
     *
     * Triggered by placing a regular file at the parent directory component of
     * the private key path: dirname(privatePath)/encryption.key cannot be
     * traversed because a regular file sits in the way, so file_exists() returns
     * false (no early-return) and file_put_contents() also fails.
     */
    public function testLoadOrGenerateEncryptionKeyLogsErrorWhenCannotWrite(): void
    {
        // Arrange — a regular file blocks the path component that the factory
        // would need to traverse to reach encryption.key; file_exists() on a
        // path like "blocker_file/encryption.key" returns false, so the
        // generation branch is entered; file_put_contents also fails silently
        $blocker     = $this->tempDir . '/enc_blocker';
        file_put_contents($blocker, 'x');
        $privatePath = $blocker . '/private.key'; // unwritable path through a regular file
        $publicPath  = $this->tempDir . '/public_enctest.key';

        // Act — construction triggers loadOrGenerateEncryptionKey()
        $factory = new OAuth2ServerFactory(
            $this->controller,
            $privatePath,
            $publicPath
            // no explicit encryptionKey → triggers loadOrGenerateEncryptionKey()
        );

        $key = $factory->getEncryptionKey();

        // Assert — method must return a non-empty generated key even when it
        // could not be persisted to disk (the in-memory key is still usable)
        $this->assertIsString($key,
            'loadOrGenerateEncryptionKey() must return a string key');
        $this->assertNotEmpty($key,
            'loadOrGenerateEncryptionKey() must return a non-empty key even when persistence fails');

        // Cleanup
        unlink($blocker);
    }
}
