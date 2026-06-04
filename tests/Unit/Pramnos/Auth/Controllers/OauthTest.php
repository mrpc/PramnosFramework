<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Oauth;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Auth\JWT;
use Pramnos\Http\Response;

if (!defined('PRAMNOS_TESTING')) {
    define('PRAMNOS_TESTING', true);
}

#[CoversClass(Oauth::class)]
class OauthTest extends TestCase
{
    private \Pramnos\Database\Database $db;
    private $controller;

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
                `description` text,
                `apikey` varchar(255) DEFAULT NULL,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `created` bigint(20) NOT NULL DEFAULT 0,
                `redirect_uri` varchar(255) DEFAULT NULL,
                PRIMARY KEY (`appid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
        $this->db->query('
            CREATE TABLE IF NOT EXISTS `users` (
                `userid` int(11) NOT NULL AUTO_INCREMENT,
                `username` varchar(255) NOT NULL,
                `email` varchar(255) NOT NULL,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `firstname` varchar(255) DEFAULT NULL,
                `lastname` varchar(255) DEFAULT NULL,
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
                `scope` text,
                `redirect_uri` text,
                `code_challenge` varchar(255),
                `code_challenge_method` varchar(50),
                `expires` bigint(20) NOT NULL,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                `created` bigint(20) NOT NULL,
                `lastused` bigint(20) NOT NULL DEFAULT 0,
                PRIMARY KEY (`tokenid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
        $this->db->query('
            CREATE TABLE IF NOT EXISTS `authserver_oauth2_user_consents` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `userid` int(11) NOT NULL,
                `applicationid` int(11) NOT NULL,
                `scope` text,
                `created_at` datetime,
                `updated_at` datetime,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
        $this->db->query('
            CREATE TABLE IF NOT EXISTS `authserver_oauth2_device_codes` (
                `device_code` varchar(255) NOT NULL,
                `user_code` varchar(50) NOT NULL,
                `client_id` varchar(255) NOT NULL,
                `scope` text,
                `expires_at` bigint(20) NOT NULL,
                `status` varchar(50) NOT NULL,
                PRIMARY KEY (`device_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');

        $this->cleanDb();

        $_SESSION = [];
        $_SERVER = [];
        $_POST = [];
        $_GET = [];
        
        $this->controller = new Oauth(new Application());
    }

    protected function tearDown(): void
    {
        $this->cleanDb();
        
        $singleton = &Factory::getDatabase();
        $singleton = null;
        Settings::clearSettings();
        
        $_SESSION = [];
        $_SERVER = [];
        $_POST = [];
        $_GET = [];
    }
    
    private function cleanDb(): void
    {
        $this->db->queryBuilder()->table('applications')->whereIn('appid', [1, 2])->delete();
        $this->db->queryBuilder()->table('users')->where('userid', 55)->delete();
        $this->db->queryBuilder()->table('usertokens')->delete();
        $this->db->queryBuilder()->table('authserver_oauth2_user_consents')->delete();
        $this->db->queryBuilder()->table('authserver_oauth2_device_codes')->delete();
        
        global $unittesting_logged;
        $unittesting_logged = false;
        $app = \Pramnos\Application\Application::getInstance();
        if ($app) {
            $app->currentUser = null;
        }
    }

    public function testOptionsMethodReturnsEarly(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('OAuth controller terminated');

        new Oauth(new Application());
    }
    
    public function testDisplayShowsApps(): void
    {
        $this->db->queryBuilder()->table('applications')->insert(['appid' => 1, 'name' => 'App 1', 'status' => 1, 'apikey' => 'key1']);
        $this->db->queryBuilder()->table('applications')->insert(['appid' => 2, 'name' => 'App 2', 'status' => 1, 'apikey' => 'key2']);
        
        // Mock getView
        $mockView = $this->createMock(\Pramnos\Application\View::class);
        $mockView->expects($this->once())->method('display')->willReturn('html_content');
        
        $controller = $this->getMockBuilder(Oauth::class)
            ->setConstructorArgs([new Application()])
            ->onlyMethods(['getView'])
            ->getMock();
            
        $controller->expects($this->once())->method('getView')->willReturn($mockView);
        
        $result = $controller->display();
        $this->assertEquals('html_content', $result);
    }
    
    public function testAuthorizeRequiresClientId(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['client_id'] = '';
        
        ob_start();
        $this->controller->authorize();
        $output = ob_get_clean();
        
        $this->assertStringContainsString('Missing client_id', $output);
        $this->assertStringContainsString('Authorization Error', $output);
    }
    
    public function testAuthorizeRedirectsWhenNotLoggedIn(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['client_id'] = 'test_client_id';
        $_GET['response_type'] = 'code';
        $_GET['state'] = 'abc';
        $_GET['redirect_uri'] = 'https://example.com/cb';
        
        $this->db->queryBuilder()->table('applications')->insert(['appid' => 1, 'name' => 'App 1', 'status' => 1, 'apikey' => 'test_client_id']);

        $controller = $this->getMockBuilder(Oauth::class)
            ->setConstructorArgs([new Application()])
            ->onlyMethods(['redirect'])
            ->getMock();
            
        $controller->expects($this->once())->method('redirect')->with($this->stringContains('login?return_url='));
        
        $controller->authorize();
    }
    
    public function testAuthorizePostRecordsConsent(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['client_id'] = 'test_client_id';
        $_GET['response_type'] = 'code';
        $_GET['redirect_uri'] = 'https://example.com/cb';
        $_POST['authorize'] = 'yes';
        
        if (!defined('UNITTESTING')) {
            define('UNITTESTING', true);
        }
        global $unittesting_logged;
        $unittesting_logged = true;
        $user = new \Pramnos\User\User();
        $user->userid = 55;
        $user->username = 'test';
        $user->email = 'test@test.com';
        $user->language = \Pramnos\Framework\Factory::getLanguage()->currentlang();
        
        $this->db->queryBuilder()->table('users')->insert(['userid' => 55, 'username' => 'test', 'email' => 'test@test.com', 'active' => 1]);
        $this->db->queryBuilder()->table('applications')->insert(['appid' => 1, 'name' => 'App 1', 'status' => 1, 'apikey' => 'test_client_id']);
        
        $app = \Pramnos\Application\Application::getInstance();
        if ($app) {
            $app->currentUser = clone $user;
        }
        
        $this->expectOutputString('<h1>Authorization Error</h1><p>OAuth controller terminated</p>');
        $this->controller->authorize();
        
        // Verify consent and auth code
        $consent = $this->db->queryBuilder()->table('authserver_oauth2_user_consents')->where('userid', 55)->first();
        $this->assertNotEmpty($consent);
        
        $token = $this->db->queryBuilder()->table('usertokens')->where('userid', 55)->where('tokentype', 'auth_code')->first();
        $this->assertNotEmpty($token);
    }
    
    public function testTokenWithJwtClientAssertion(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['grant_type'] = 'client_credentials';
        $_POST['client_id'] = 'test_client_id';
        $_POST['client_assertion'] = 'invalid_jwt.header.payload';
        $_POST['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
        
        $response = $this->controller->token();
        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertEquals('invalid_client', $data['error']);
    }
    
    public function testTokenLeagueErrorHandling(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['grant_type'] = 'client_credentials';
        
        $response = $this->controller->token();
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertEquals('invalid_request', $data['error']);
    }
    
    public function testTokenThrowsGeneralException(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        
        $mockFactory = $this->createMock(\Pramnos\Auth\OAuth2\OAuth2ServerFactory::class);
        $mockFactory->expects($this->once())->method('createAuthorizationServer')
            ->willThrowException(new \Exception('General error test'));
            
        $reflection = new \ReflectionClass($this->controller);
        $property = $reflection->getProperty('oauth2Factory');
        $property->setAccessible(true);
        $property->setValue($this->controller, $mockFactory);
        
        $response = $this->controller->token();
        $this->assertEquals(500, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertEquals('server_error', $data['error']);
        $this->assertEquals('General error test', $data['error_description']);
    }
    
    public function testRevokeWithNoTokenFails(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        
        $response = $this->controller->revoke();
        
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertEquals('invalid_request', $data['error']);
    }
    
    public function testRevokeValidToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['token'] = 'some_token';
        
        $this->db->queryBuilder()->table('usertokens')->insert([
            'userid' => 55, 'applicationid' => 1, 'tokentype' => 'access_token',
            'token' => 'some_token', 'expires' => time() + 3600, 'status' => 1, 'created' => time()
        ]);
        
        $response = $this->controller->revoke();
        
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        
        // Check DB that it was revoked
        $res = $this->db->queryBuilder()->table('usertokens')->where('token', 'some_token')->first();
        $this->assertEquals(0, $res->fields['status']);
    }
    
    public function testIntrospectWithoutAuthFails(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        
        $response = $this->controller->introspect();
        $this->assertEquals(401, $response->getStatusCode());
    }
    
    public function testLogoutWithoutToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        
        $response = $this->controller->logout();
        $this->assertEquals(401, $response->getStatusCode());
    }
    
    public function testDeviceAuthorizationRequiresClientId(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        
        $response = $this->controller->deviceauthorization();
        $this->assertEquals(400, $response->getStatusCode());
    }
    
    public function testDeviceAuthorizationSuccess(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['client_id'] = 'test_client_id';
        $_POST['scope'] = 'profile';
        
        $this->db->queryBuilder()->table('applications')->insert(['appid' => 1, 'name' => 'App 1', 'status' => 1, 'apikey' => 'test_client_id']);
        
        $response = $this->controller->deviceauthorization();
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getBody(), true);
        $this->assertNotEmpty($data['device_code']);
        $this->assertNotEmpty($data['user_code']);
    }
}
