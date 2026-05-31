<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Addon\System;

use PHPUnit\Framework\TestCase;
use Pramnos\Addon\System\Session;
use Pramnos\Framework\Factory;
use Pramnos\Http\Request;
use Pramnos\Http\Session as HttpSession;
use Pramnos\Auth\Auth;
use Pramnos\Database\Database;
use Pramnos\Application\Application;

/**
 * Unit tests for Pramnos\Addon\System\Session.
 */
class SessionTest extends TestCase
{
    private array $originalServer;
    private $dbOriginal;
    private $sessionOriginal;
    private $authOriginal;
    private $requestOriginal;
    private $appOriginal;

    protected array $cookies = [];
    protected array $mockedQueryResults = [];
    protected array $queriesExecuted = [];

    protected function setUp(): void
    {
        // Define UNITTESTING constant if not defined
        if (!defined('UNITTESTING')) {
            define('UNITTESTING', true);
        }

        // Backup superglobals
        $this->originalServer = $_SERVER;
        $_SESSION = [];

        // Setup base dummy server vars
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        unset($_SERVER['HTTP_CF_IPCOUNTRY']);
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

        $this->cookies = [];
        $this->mockedQueryResults = [];
        $this->queriesExecuted = [];

        // Mock DB
        $dbMock = $this->createMock(Database::class);
        $dbMock->type = 'mysql';
        $dbMock->method('prepareQuery')->willReturnCallback(function ($query, ...$args) {
            // Simple replace of specifiers for testing
            $formatted = $query;
            foreach ($args as $arg) {
                if (is_int($arg)) {
                    $formatted = preg_replace('/%d/', (string)$arg, $formatted, 1);
                } else {
                    $formatted = preg_replace('/%s/', (string)$arg, $formatted, 1);
                }
            }
            return $formatted;
        });
        $dbMock->method('query')->willReturnCallback(function ($sql) {
            $this->queriesExecuted[] = $sql;
            $res = $this->createMock(\Pramnos\Database\Result::class);
            $res->numRows = 0;
            $res->fields = [];

            foreach ($this->mockedQueryResults as $pattern => $data) {
                if (preg_match($pattern, $sql)) {
                    $res->numRows = $data['numRows'] ?? 0;
                    $res->fields = $data['fields'] ?? [];
                    $res->method('fetch')->willReturn($data['fields'] ?? null);
                    break;
                }
            }
            return $res;
        });

        // Mock Http Request
        $requestMock = $this->createMock(Request::class);
        $requestMock->method('cookieget')->willReturnCallback(function ($name) {
            return $this->cookies[$name] ?? null;
        });
        $requestMock->method('cookieset')->willReturnCallback(function ($name, $value) {
            $this->cookies[$name] = $value;
        });
        $requestMock->method('getURL')->willReturn('/test-url');

        // Mock Session
        $sessionMock = $this->createMock(HttpSession::class);

        // Mock Auth
        $authMock = $this->createMock(Auth::class);

        // Mock App
        $appMock = $this->createMock(Application::class);

        // Backup and Swap Singletons via References
        $this->dbOriginal = &Database::getInstance();
        $this->dbOriginal = $dbMock;

        $this->sessionOriginal = &Factory::getSession();
        $this->sessionOriginal = $sessionMock;

        $this->authOriginal = &Factory::getAuth();
        $this->authOriginal = $authMock;

        $this->requestOriginal = &Factory::getRequest();
        $this->requestOriginal = $requestMock;

        $this->appOriginal = &Application::getInstance();
        $this->appOriginal = $appMock;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_SESSION = [];

        // Restore singletons
        $dbSingleton = &Database::getInstance();
        $dbSingleton = $this->dbOriginal;

        $sessionSingleton = &Factory::getSession();
        $sessionSingleton = $this->sessionOriginal;

        $authSingleton = &Factory::getAuth();
        $authSingleton = $this->authOriginal;

        $requestSingleton = &Factory::getRequest();
        $requestSingleton = $this->requestOriginal;

        $appSingleton = &Application::getInstance();
        $appSingleton = $this->appOriginal;
    }

    /**
     * Test that an anonymous visitor gets session variables initialized and
     * a row inserted into the sessions table.
     */
    public function testOnAppInitAnonymousVisitor(): void
    {
        // Arrange
        $sessionAddon = new Session();

        // Act
        $sessionAddon->onAppInit();

        // Assert
        $this->assertNotEmpty($_SESSION['visitorid'], 'Visitor ID must be generated');
        $this->assertSame(16, strlen($_SESSION['visitorid']), 'Visitor ID must be 16 chars long');
        $this->assertSame($_SESSION['visitorid'], $this->cookies['visitorid'], 'Visitor ID cookie must match session');
        $this->assertNotEmpty($this->cookies['lastseen'], 'Lastseen cookie must be set');
        $this->assertFalse($_SESSION['logged'], 'Logged in session must default to false');
        $this->assertSame(1, $_SESSION['uid'], 'Default user ID must be 1');

        // Check DB queries executed
        $this->assertCount(3, $this->queriesExecuted);
        $this->assertStringContainsString('DELETE FROM `#PREFIX#sessions`', $this->queriesExecuted[0]);
        $this->assertStringContainsString('select * from `#PREFIX#sessions`', $this->queriesExecuted[1]);
        $this->assertStringContainsString('insert into `#PREFIX#sessions`', $this->queriesExecuted[2]);
        $this->assertStringContainsString('Anonymous', $this->queriesExecuted[2]);
    }

    /**
     * Test that a bot is correctly detected and its name is written to the sessions table.
     */
    public function testOnAppInitWithBotUserAgent(): void
    {
        // Arrange
        $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
        $sessionAddon = new Session();

        // Act
        $sessionAddon->onAppInit();

        // Assert
        $this->assertCount(3, $this->queriesExecuted);
        $this->assertStringContainsString('Googlebot', $this->queriesExecuted[2], 'Username in insert must match bot name');
    }

    /**
     * Test that Cloudflare headers connect IP and Country are correctly extracted and passed.
     */
    public function testOnAppInitWithCloudflareHeaders(): void
    {
        // Arrange
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.195';
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'GR';
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'el-GR,el;q=0.9';
        $sessionAddon = new Session();

        // Act
        $sessionAddon->onAppInit();

        // Assert
        $this->assertCount(3, $this->queriesExecuted);
        $this->assertStringContainsString('203.0.113.195', $this->queriesExecuted[2], 'Insert query must contain cloudflare connecting IP');
    }

    /**
     * Test that a logged-in user initializes the session properties correctly
     * and passes correct guest status (0) and uid to the database insertion query.
     */
    public function testOnAppInitLoggedInUser(): void
    {
        // Arrange
        $_SESSION['logged'] = true;
        $_SESSION['uid'] = 42;
        $_SESSION['username'] = 'testuser';
        $_SESSION['auth'] = 'token123';
        
        $this->cookies['auth'] = 'token123';
        $this->cookies['username'] = 'testuser';

        $sessionAddon = new Session();

        // Act
        $sessionAddon->onAppInit();

        // Assert
        $this->assertSame(42, $_SESSION['uid']);
        $this->assertTrue($_SESSION['logged']);
        
        // Assert cookies set
        $this->assertTrue($this->cookies['logged']);
        $this->assertSame(42, $this->cookies['uid']);
        $this->assertSame('testuser', $this->cookies['username']);
        $this->assertSame('token123', $this->cookies['auth']);

        // Check insert sql contains values
        $this->assertCount(3, $this->queriesExecuted);
        $this->assertStringContainsString('testuser', $this->queriesExecuted[2]);
        $this->assertStringContainsString('42', $this->queriesExecuted[2]);
    }

    /**
     * Test that if cookies indicate auth/username but session is not logged, authCheck is executed.
     */
    public function testOnAppInitAuthCheckExecuted(): void
    {
        // Arrange
        $this->cookies['auth'] = 'token123';
        $this->cookies['username'] = 'testuser';

        // Swap Auth singleton to verify action
        $authMock = $this->createMock(Auth::class);
        $authMock->expects($this->once())->method('authCheck');

        $authSingleton = &Factory::getAuth();
        $authSingleton = $authMock;

        $sessionAddon = new Session();

        // Act
        $sessionAddon->onAppInit();
    }

    /**
     * Test the workflow where a session is flagged as logged out (kicked out) in the database.
     * The reset and logout methods must be triggered, and the guest name becomes "Kicked Out".
     */
    public function testOnAppInitKickedOutVisitor(): void
    {
        // Arrange
        $this->cookies['visitorid'] = '1234567890abcdef';
        $this->mockedQueryResults['/select \* from `#PREFIX#sessions`/'] = [
            'numRows' => 1,
            'fields' => ['logout' => '1']
        ];

        // Swap HttpSession and Auth singletons to verify calls
        $sessionMock = $this->createMock(HttpSession::class);
        $sessionMock->expects($this->once())->method('reset');

        $authMock = $this->createMock(Auth::class);
        $authMock->expects($this->once())->method('logout');

        $sessionSingleton = &Factory::getSession();
        $sessionSingleton = $sessionMock;

        $authSingleton = &Factory::getAuth();
        $authSingleton = $authMock;

        $sessionAddon = new Session();

        // Act
        $sessionAddon->onAppInit();

        // Assert
        $this->assertCount(3, $this->queriesExecuted);
        $this->assertStringContainsString('Kicked Out', $this->queriesExecuted[2], 'Username must be set to Kicked Out in insert');
    }

    /**
     * Test database write exception logic.
     * When database query triggers an exception, HttpSession::reset and Auth::logout are called.
     */
    public function testOnAppInitDatabaseException(): void
    {
        // Arrange
        $dbMock = $this->createMock(Database::class);
        $dbMock->type = 'mysql';
        $dbMock->method('prepareQuery')->willReturn('DUMMY SQL');
        $dbMock->method('query')->willReturnCallback(function($sql) {
            // First two queries succeed, third (insert) throws exception
            static $count = 0;
            $count++;
            if ($count === 3) {
                throw new \RuntimeException('Database insertion failure');
            }
            $res = $this->createMock(\Pramnos\Database\Result::class);
            $res->numRows = 0;
            return $res;
        });

        $dbSingleton = &Database::getInstance();
        $dbSingleton = $dbMock;

        // Expectations
        $sessionMock = $this->createMock(HttpSession::class);
        $sessionMock->expects($this->once())->method('reset');

        $authMock = $this->createMock(Auth::class);
        $authMock->expects($this->once())->method('logout');

        $sessionSingleton = &Factory::getSession();
        $sessionSingleton = $sessionMock;

        $authSingleton = &Factory::getAuth();
        $authSingleton = $authMock;

        $sessionAddon = new Session();

        // Act
        $sessionAddon->onAppInit();
    }

    /**
     * Test postgresql insert compatibility in onAppInit.
     */
    public function testOnAppInitPostgresqlInsert(): void
    {
        // Arrange
        $dbMock = $this->createMock(Database::class);
        $dbMock->type = 'postgresql';
        $dbMock->method('prepareQuery')->willReturnCallback(function ($query, ...$args) {
            return 'POSTGRESQL INSERT QUERY';
        });
        $dbMock->method('query')->willReturnCallback(function($sql) {
            $this->queriesExecuted[] = $sql;
            $res = $this->createMock(\Pramnos\Database\Result::class);
            $res->numRows = 0;
            return $res;
        });

        $dbSingleton = &Database::getInstance();
        $dbSingleton = $dbMock;

        $sessionAddon = new Session();

        // Act
        $sessionAddon->onAppInit();

        // Assert
        $this->assertCount(3, $this->queriesExecuted);
        $this->assertSame('POSTGRESQL INSERT QUERY', $this->queriesExecuted[2]);
    }
}
