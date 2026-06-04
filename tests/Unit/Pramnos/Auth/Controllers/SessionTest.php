<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Session;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Auth\JWT;
use Pramnos\Http\Response;

#[CoversClass(Session::class)]
class SessionTest extends TestCase
{
    private \Pramnos\Database\Database $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'app');
        }

        Settings::clearSettings();
        $settingsFile = realpath(__DIR__ . '/../../../../../tests/fixtures/app/settings.php');
        if ($settingsFile) {
            Settings::loadSettings($settingsFile);
        }

        $singleton = &Factory::getDatabase();
        $singleton = null;

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        $this->db->query('
            CREATE TABLE IF NOT EXISTS `applications` (
                `appid` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `apikey` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`appid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
        $this->db->query('
            CREATE TABLE IF NOT EXISTS `users` (
                `userid` int(11) NOT NULL AUTO_INCREMENT,
                `username` varchar(255) NOT NULL,
                `email` varchar(255) NOT NULL,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`userid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
        $this->db->query('
            CREATE TABLE IF NOT EXISTS `usertokens` (
                `tokenid` int(11) NOT NULL AUTO_INCREMENT,
                `userid` int(11) NOT NULL,
                `applicationid` int(11) NOT NULL,
                `tokentype` varchar(50) NOT NULL,
                `token` text NOT NULL,
                `expires` bigint(20) NOT NULL,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `created` bigint(20) NOT NULL,
                `lastused` bigint(20) NOT NULL DEFAULT 0,
                PRIMARY KEY (`tokenid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');

        // Clean up any old test data
        $this->db->query('DELETE FROM `applications` WHERE `appid` = 1');
        $this->db->query('DELETE FROM `users` WHERE `userid` = 55');
        $this->db->query('DELETE FROM `usertokens` WHERE `userid` = 55');

        // Clear superglobals
        $_SESSION = [];
        $_SERVER = [];
        ini_set('session.gc_maxlifetime', '1440');
    }

    protected function tearDown(): void
    {
        $this->db->query('DELETE FROM `applications` WHERE `appid` = 1');
        $this->db->query('DELETE FROM `users` WHERE `userid` = 55');
        $this->db->query('DELETE FROM `usertokens` WHERE `userid` = 55');
        
        $singleton = &Factory::getDatabase();
        $singleton = null;
        Settings::clearSettings();
        
        $_SESSION = [];
        $_SERVER = [];
    }

    private function getController(): Session
    {
        return new Session(new Application());
    }

    // --- Session-based tests ---

    public function testCheckWithNoSessionReturnsExpired(): void
    {
        $controller = $this->getController();
        
        /** @var Response $response */
        $response = $controller->check();
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getBody(), true);
        $this->assertEquals('expired', $data['status']);
        $this->assertFalse($data['logged_in']);
    }

    public function testCheckWithValidSessionReturnsActive(): void
    {
        $_SESSION['user'] = [
            'userid' => 123,
            'username' => 'testuser',
            'email' => 'test@example.com'
        ];
        $_SESSION['last_activity'] = time();

        $controller = $this->getController();
        $response = $controller->check();
        
        $data = json_decode($response->getBody(), true);
        $this->assertEquals('active', $data['status']);
        $this->assertTrue($data['logged_in']);
        $this->assertEquals('session', $data['auth_method']);
        $this->assertEquals(123, $data['user_id']);
        $this->assertEquals('testuser', $data['username']);
        $this->assertTrue(isset($data['expires_in']));
    }

    public function testHeartbeatExtendsSession(): void
    {
        $_SESSION['user'] = ['userid' => 1];
        $_SESSION['last_activity'] = time() - 100;

        $controller = $this->getController();
        $response = $controller->heartbeat();
        
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertEquals('ok', $data['status']);
        
        // Ensure last_activity was updated
        $this->assertGreaterThan(time() - 5, $_SESSION['last_activity']);
    }

    public function testHeartbeatWithoutSessionReturnsUnauthorized(): void
    {
        $controller = $this->getController();
        $response = $controller->heartbeat();
        
        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertEquals('unauthorized', $data['status']);
    }

    public function testInfoWithSession(): void
    {
        $_SESSION['user'] = [
            'userid' => 1,
            'username' => 'alice',
            'email' => 'alice@example.com'
        ];
        $_SESSION['login_time'] = time() - 3600;
        $_SESSION['last_activity'] = time();

        $controller = $this->getController();
        $response = $controller->info();
        
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        
        $this->assertEquals('alice', $data['user']['username']);
        $this->assertEquals('session', $data['authentication']['method']);
        $this->assertTrue(isset($data['session']));
        $this->assertEquals($_SESSION['login_time'], $data['session']['started']);
    }

    public function testRefreshExtendsSession(): void
    {
        $_SESSION['user'] = ['userid' => 1];
        $_SESSION['last_activity'] = time() - 500;

        $controller = $this->getController();
        $response = $controller->refresh();
        
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertEquals('refreshed', $data['status']);
        $this->assertGreaterThan(time() - 5, $_SESSION['last_activity']);
    }

    public function testRefreshWithoutSessionFails(): void
    {
        $controller = $this->getController();
        $response = $controller->refresh();
        
        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertEquals('failed', $data['status']);
    }

    public function testSessionTimeoutEnforced(): void
    {
        $maxLifetime = (int) ini_get('session.gc_maxlifetime');
        
        $_SESSION['user'] = ['userid' => 1];
        $_SESSION['last_activity'] = time() - $maxLifetime - 10; // Expired

        $controller = $this->getController();
        $response = $controller->check();
        
        $data = json_decode($response->getBody(), true);
        $this->assertEquals('expired', $data['status']);
        $this->assertFalse(isset($_SESSION['user'])); // User should be unset
    }

    // --- Bearer-token tests ---

    public function testBearerTokenEndpoints(): void
    {
        $secretKey = 'super_secret_key_0123456789abcde';
        // Setup DB
        $this->db->query("INSERT INTO `applications` (`appid`, `name`, `apikey`) VALUES (1, 'Test App', '{$secretKey}')");
        $this->db->query("INSERT INTO `users` (`userid`, `username`, `email`, `active`) VALUES (55, 'tokenguy', 'guy@token.com', 1)");
        
        // Create a JWT
        $payload = [
            'userid' => 55,
            'expires' => time() + 3600
        ];
        $privatePath = ROOT . '/app/keys/private.key';
        $publicPath  = ROOT . '/app/keys/public.key';

        if (file_exists($privatePath) && file_exists($publicPath)) {
            $privateKey = file_get_contents($privatePath);
            $tokenStr = JWT::encode($payload, $privateKey, 'RS256');
        } else {
            $tokenStr = JWT::encode($payload, $secretKey, 'HS256');
        }
        
        $expires = time() + 3600;
        $this->db->query("INSERT INTO `usertokens` (`userid`, `applicationid`, `tokentype`, `token`, `expires`, `status`, `created`) VALUES (55, 1, 'access_token', '{$tokenStr}', {$expires}, 1, " . time() . ")");
        
        // Mock Bearer Header
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokenStr;

        $controller = $this->getController();
        
        // 1. check()
        $resCheck = $controller->check();
        $dataCheck = json_decode($resCheck->getBody(), true);
        
        $this->assertEquals('active', $dataCheck['status']);
        $this->assertEquals('bearer_token', $dataCheck['auth_method']);
        $this->assertEquals(55, $dataCheck['user_id']);
        $this->assertEquals('tokenguy', $dataCheck['username']);
        
        // 2. heartbeat()
        $resHeartbeat = $controller->heartbeat();
        $dataHeartbeat = json_decode($resHeartbeat->getBody(), true);
        $this->assertEquals('ok', $dataHeartbeat['status']);
        $this->assertEquals('bearer_token', $dataHeartbeat['auth_method']);
        
        // 3. info()
        $resInfo = $controller->info();
        $dataInfo = json_decode($resInfo->getBody(), true);
        $this->assertEquals(55, $dataInfo['user']['id']);
        $this->assertEquals('bearer_token', $dataInfo['authentication']['method']);
        $this->assertEquals(1, $dataInfo['tokens']['active_count']);
        $this->assertEquals('Test App', $dataInfo['tokens']['applications'][0]['name']);
        
        // 4. refresh() (Should fail for Bearer tokens)
        $resRefresh = $controller->refresh();
        $this->assertEquals(400, $resRefresh->getStatusCode());
        $dataRefresh = json_decode($resRefresh->getBody(), true);
        $this->assertEquals('error', $dataRefresh['status']);
    }

    public function testOptionsMethod(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        if (!defined('PRAMNOS_TESTING')) {
            define('PRAMNOS_TESTING', true);
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('CORS OPTIONS request');

        $this->getController();
    }
}
