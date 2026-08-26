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
        // HealthRegistry is static, and health() now reads it. Without this the
        // outcome of these tests depends on which health test ran last in the
        // same process — a check another class left registered as `down` makes
        // this server unhealthy for reasons that have nothing to do with it.
        \Pramnos\Health\HealthRegistry::reset();

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
        // Leave the registry as we found it, so the next class in the process
        // does not inherit whatever health() registered on our behalf.
        \Pramnos\Health\HealthRegistry::reset();

        if (file_exists($this->publicKeyPath)) {
            unlink($this->publicKeyPath);
        }
    }

    public function testConfigurationReturnsJson(): void
    {
        $output = $this->responseBody(fn () => $this->controller->configuration());

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

        $output = $this->responseBody(fn () => $this->controller->jwks());

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

        $output = $this->responseBody(fn () => $this->controller->jwks());

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
        $output = $this->responseBody(fn () => $this->controller->oauth2Metadata());

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

        $output = $this->responseBody(fn () => $this->controller->health());

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

        $output = $this->responseBody(fn () => $this->controller->health());

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertEquals('healthy', $json['status']);
        $this->assertEquals('ok', $json['components']['database']);
        $this->assertEquals('inactive', $json['components']['session']);
    }

    /**
     * The JSON body one of these actions produced.
     *
     * They used to `echo` it, so a test captured the output stream. They no
     * longer do — an echo leaves the framework free to render the page it was
     * going to render anyway, which is how every one of these endpoints came to
     * answer with valid JSON followed by a full HTML document. The response is
     * now the `raw` document, so that is where the body is read from.
     */
    private function responseBody(callable $action): string
    {
        \Pramnos\Document\Document::reset();
        ob_start();
        $action();
        $echoed = (string) ob_get_clean();

        // Nothing should be echoed any more; if something is, say so here rather
        // than in whichever assertion happens to fail next.
        $this->assertSame('', $echoed, 'a discovery endpoint must not echo its body');

        return (string) \Pramnos\Framework\Factory::getDocument('raw')->render();
    }
}
