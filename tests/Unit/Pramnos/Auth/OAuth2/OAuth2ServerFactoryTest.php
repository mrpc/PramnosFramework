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
}
