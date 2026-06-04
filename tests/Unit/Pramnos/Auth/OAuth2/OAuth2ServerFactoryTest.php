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

    public function testLoadOrGenerateEncryptionKeyCreatesNewKeyIfMissing(): void
    {
        $factory = new OAuth2ServerFactory($this->controller, $this->privateKeyPath, $this->publicKeyPath);
        
        $encKeyPath = dirname($this->privateKeyPath) . '/encryption.key';
        $this->assertFileExists($encKeyPath);
        $key1 = file_get_contents($encKeyPath);
        $this->assertNotEmpty($key1);
        
        $factory2 = new OAuth2ServerFactory($this->controller, $this->privateKeyPath, $this->publicKeyPath);
        $key2 = file_get_contents($encKeyPath);
        
        $this->assertEquals($key1, $key2);
    }
}
