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

    /**
     * The legacy access methods reach the framework's permission system.
     *
     * They used to name `pramnos_factory::getPermissions()` — written without a
     * leading backslash, so PHP resolved it inside `Pramnos\Auth`, a class the
     * framework does not define. The test suite supplied a stub for exactly that
     * name, which is why this test passed while the methods could not work
     * anywhere else.
     *
     * Their behaviour is verified against a real store by
     * {@see \Pramnos\Tests\Integration\Auth\AuthPermissionDelegationTest}.
     * What is pinned here is what a unit test can honestly pin: the signatures
     * callers depend on, and the absence of the name that broke them.
     */
    public function testLegacyAccessMethodsKeepTheirContract(): void
    {
        // Arrange
        $expected = ['setaccess' => 8, 'useraccess' => 6, 'groupaccess' => 5];

        // Act + Assert — signatures are backwards-compatible
        foreach ($expected as $method => $parameters) {
            $reflection = new \ReflectionMethod(Auth::class, $method);
            $this->assertTrue($reflection->isPublic(), $method . ' must stay public');
            $this->assertSame(
                $parameters,
                $reflection->getNumberOfParameters(),
                $method . '() must keep its parameter list'
            );
        }

        // ...and none of them names the class that does not exist
        $source = (string) file_get_contents(
            (new \ReflectionClass(Auth::class))->getFileName()
        );
        $this->assertStringNotContainsString('pramnos_factory', $source);
    }
}
