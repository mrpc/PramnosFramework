<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;

#[CoversClass(Application::class)]
class ApplicationTest extends TestCase
{
    private \Pramnos\Database\Database $db;
    
    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'app');
        }

        Settings::clearSettings();
        $settingsFile = realpath(__DIR__ . '/../../../../tests/fixtures/app/settings.php');
        if ($settingsFile) {
            Settings::loadSettings($settingsFile);
        }
        
        $singleton = &Factory::getDatabase();
        $singleton = null;

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        
        $this->db->query('DROP TABLE IF EXISTS `applications`');
        $this->db->query('
            CREATE TABLE `applications` (
                `appid` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `apikey` varchar(255) DEFAULT NULL,
                `apisecret` varchar(255) DEFAULT NULL,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `systemuser` int(11) DEFAULT NULL,
                PRIMARY KEY (`appid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `applications`');
        $singleton = &Factory::getDatabase();
        $singleton = null;
        Settings::clearSettings();
    }
    private function getAppModel(): Application
    {
        $app = new \Pramnos\Application\Application();
        $ctrl = new Controller($app);
        return new Application($ctrl);
    }

    public function testOAuth2InterfaceHelpers(): void
    {
        $app = $this->getAppModel();
        
        $app->apikey = 'client_123';
        $app->name = 'My App';
        $app->callback = 'https://example.com/cb, https://example.com/cb2';
        $app->scope = 'read write';
        
        $this->assertEquals('client_123', $app->getClientIdentifier());
        $this->assertEquals('My App', $app->getClientName());
        $this->assertEquals('https://example.com/cb', $app->getRedirectUri());
        $this->assertEquals(['https://example.com/cb', 'https://example.com/cb2'], $app->getRedirectUris());
        $this->assertTrue($app->isConfidential());
        
        $this->assertEquals(['read', 'write'], $app->getScopes());
        $this->assertTrue($app->hasScope('read'));
        $this->assertFalse($app->hasScope('admin'));
        
        // JSON format callback
        $app->callback = json_encode(['https://example.com/json']);
        $this->assertEquals('https://example.com/json', $app->getRedirectUri());
        $this->assertEquals(['https://example.com/json'], $app->getRedirectUris());
        
        // Empty callbacks
        $app->callback = '';
        $this->assertEquals('', $app->getRedirectUri());
        $this->assertEquals([], $app->getRedirectUris());
    }

    public function testLoadByApiKeyAndValidateCredentials(): void
    {
        $app = $this->getAppModel();
        
        $this->db->query("INSERT INTO `applications` (`name`, `apikey`, `apisecret`, `status`) VALUES ('My App', 'test_key', 'test_secret', 1)");
        $this->db->query("INSERT INTO `applications` (`name`, `apikey`, `apisecret`, `status`) VALUES ('Inactive App', 'bad_key', 'bad_secret', 0)");
        
        // test loadByApiKey
        $loaded = $app->loadByApiKey('test_key');
        
        $this->assertInstanceOf(Application::class, $loaded);
        $this->assertEquals('My App', $loaded->name);
        
        // loadByApiKey fails if not found or inactive
        $this->assertFalse($app->loadByApiKey('bad_key'));
        $this->assertFalse($app->loadByApiKey('unknown'));
        
        // validateCredentials
        $this->assertTrue($app->validateCredentials('test_key', 'test_secret'));
        $this->assertTrue($app->validateCredentials('test_key', null));
        $this->assertFalse($app->validateCredentials('test_key', 'wrong_secret'));
        $this->assertFalse($app->validateCredentials('bad_key', 'bad_secret'));
    }

    public function testAssignSystemUser(): void
    {
        $app = $this->getAppModel();
        $this->assertFalse($app->assignSystemUser(99)); // Fails because pk is 0
        
        $this->db->query("INSERT INTO `applications` (`name`, `apikey`, `status`) VALUES ('Test App', 'key', 1)");
        $loaded = clone $app;
        $loaded->loadByApiKey('key');
        
        $this->assertTrue($loaded->assignSystemUser(123));
        $this->assertEquals(123, $loaded->systemuser);
    }
}
