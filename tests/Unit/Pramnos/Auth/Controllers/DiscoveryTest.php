<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Controllers\Discovery;
use Pramnos\Framework\Factory;

class DiscoveryTest extends TestCase
{
    private Discovery $controller;
    private string $keyDir;
    private string $publicKeyPath;

    protected function setUp(): void
    {
        \Pramnos\Application\Settings::clearSettings();
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        \Pramnos\Application\Settings::loadSettings($settingsFile);

        $singleton = &Factory::getDatabase();
        $singleton = null;

        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        $app = Application::getInstance();
        if (!$app) {
            $app = new Application();
            $reflection = new \ReflectionClass($app);
            $prop = $reflection->getProperty('initialized');
            $prop->setValue($app, true);
        }

        $this->controller = new Discovery($app);
        
        $this->keyDir = ROOT . '/app/keys';
        if (!is_dir($this->keyDir)) {
            mkdir($this->keyDir, 0777, true);
        }
        $this->publicKeyPath = $this->keyDir . '/public.key';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->publicKeyPath)) {
            unlink($this->publicKeyPath);
        }
    }

    public function testConfigurationReturnsJson(): void
    {
        ob_start();
        $this->controller->configuration();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('issuer', $json);
        $this->assertArrayHasKey('authorization_endpoint', $json);
        $this->assertArrayHasKey('token_endpoint', $json);
    }

    public function testJwksReturnsEmptyWhenNoKeyFile(): void
    {
        if (file_exists($this->publicKeyPath)) {
            unlink($this->publicKeyPath);
        }

        ob_start();
        $this->controller->jwks();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('keys', $json);
        $this->assertEmpty($json['keys']);
    }

    public function testJwksReturnsKeyWhenFileExists(): void
    {
        // Generate a test RSA key pair
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $details = openssl_pkey_get_details($res);
        file_put_contents($this->publicKeyPath, $details['key']);

        ob_start();
        $this->controller->jwks();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('keys', $json);
        $this->assertNotEmpty($json['keys']);
        $this->assertEquals('RSA', $json['keys'][0]['kty']);
        $this->assertEquals('sig', $json['keys'][0]['use']);
        $this->assertEquals('RS256', $json['keys'][0]['alg']);
    }

    public function testOauth2MetadataReturnsJson(): void
    {
        ob_start();
        $this->controller->oauth2Metadata();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('issuer', $json);
        $this->assertArrayHasKey('authorization_endpoint', $json);
        $this->assertArrayHasKey('token_endpoint', $json);
    }

    public function testHealthReturnsHealthy(): void
    {
        // Start session so it's active
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        ob_start();
        $this->controller->health();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertEquals('healthy', $json['status']);
        $this->assertEquals('ok', $json['components']['database']);
        $this->assertEquals('ok', $json['components']['session']);
    }

    public function testHealthReturnsUnhealthyWhenSessionInactive(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        ob_start();
        $this->controller->health();
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertEquals('healthy', $json['status']);
        $this->assertEquals('ok', $json['components']['database']);
        $this->assertEquals('inactive', $json['components']['session']);
    }
}
