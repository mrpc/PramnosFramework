<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Auth;
use Pramnos\Auth\Drivers\AuthDriverInterface;
use Pramnos\Auth\Drivers\AuthResult;
use Pramnos\Framework\Factory;
use Pramnos\Database\Database;
use Pramnos\Http\Request;
use Pramnos\Http\Session;

#[CoversClass(Auth::class)]
class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset Auth instance drivers and callbacks
        $auth = Auth::getInstance();
        
        $reflection = new \ReflectionClass($auth);
        
        $driversProp = $reflection->getProperty('drivers');
        $driversProp->setValue($auth, null); // Reset to default null state
        
        $loginProp = $reflection->getProperty('afterLoginCallbacks');
        $loginProp->setValue($auth, []);
        
        $logoutProp = $reflection->getProperty('afterLogoutCallbacks');
        $logoutProp->setValue($auth, []);
    }

    protected function tearDown(): void
    {
        // Clean up Auth instance drivers to avoid leaking mutated state to other tests
        $auth = Auth::getInstance();
        $reflection = new \ReflectionClass($auth);
        $driversProp = $reflection->getProperty('drivers');
        $driversProp->setValue($auth, null);
    }

    public function testGetInstance(): void
    {
        $auth1 = Auth::getInstance();
        $auth2 = Auth::getInstance();
        $this->assertSame($auth1, $auth2);
    }

    public function testDriverManagement(): void
    {
        $auth = Auth::getInstance();
        $driver1 = $this->createMock(AuthDriverInterface::class);
        $driver2 = $this->createMock(AuthDriverInterface::class);
        
        $auth->setDriver($driver1);
        $auth->addDriver($driver2);
        
        $reflection = new \ReflectionClass($auth);
        $driversProp = $reflection->getProperty('drivers');
        $drivers = $driversProp->getValue($auth);
        
        $this->assertCount(2, $drivers);
        $this->assertSame($driver1, $drivers[0]);
        $this->assertSame($driver2, $drivers[1]);
        
        $auth->clearDrivers();
        $this->assertEmpty($driversProp->getValue($auth));
    }

    public function testAuthFailsWithNoDriversAndNoAddons(): void
    {
        $auth = Auth::getInstance();
        $auth->clearDrivers();
        $this->assertFalse($auth->auth('user', 'pass'));
    }

    public function testAuthSucceedsWithDriver(): void
    {
        $auth = Auth::getInstance();
        
        $driver = $this->createMock(AuthDriverInterface::class);
        $result = AuthResult::success('testuser', 123, 'test@example.com', 'authkey');
        
        $driver->expects($this->once())
               ->method('verify')
               ->with('testuser', 'pass', false)
               ->willReturn($result);
               
        $auth->setDriver($driver);
        
        $callbackTriggered = false;
        $auth->afterLogin(function(array $response) use (&$callbackTriggered) {
            $callbackTriggered = true;
            $this->assertEquals(123, $response['uid']);
        });

        $this->assertTrue($auth->auth('testuser', 'pass'));
        $this->assertTrue($callbackTriggered);
        $this->assertEquals(123, $auth->lastResponse['uid']);
    }

    public function testAuthFailsWithDriver(): void
    {
        $auth = Auth::getInstance();
        
        $driver = $this->createMock(AuthDriverInterface::class);
        $result = AuthResult::failure('Invalid credentials', 400);
        
        $driver->expects($this->once())
               ->method('verify')
               ->willReturn($result);
               
        $auth->setDriver($driver);
        $this->assertFalse($auth->auth('testuser', 'badpass'));
    }

    public function testLogoutTriggersCallbacks(): void
    {
        $auth = Auth::getInstance();
        
        $callbackTriggered = false;
        $auth->afterLogout(function() use (&$callbackTriggered) {
            $callbackTriggered = true;
        });

        $auth->logout();
        $this->assertTrue($callbackTriggered);
    }

    public function testLegacyAccessMethods(): void
    {
        $auth = Auth::getInstance();
        $auth->authCheck();
        
        // Value = 1 (allow)
        $auth->setaccess(1, 'module', 'test', 'read', 0, 'user', '', 1);
        // Value = 2 (remove)
        $auth->setaccess(1, 'module', 'test', 'read', 0, 'user', '', 2);
        // Value = 0 (deny)
        $auth->setaccess(1, 'module', 'test', 'read', 0, 'user', '', 0);
        
        // Read checks
        $auth->useraccess(1, 'module', 'test');
        $auth->groupaccess(1, 'module', 'test');
        
        $this->assertTrue(true);
    }
}
