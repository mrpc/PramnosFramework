<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\ApiLoginFlow;
use Pramnos\Auth\Auth;

/**
 * The one seam this subclass exists for, and the one it forwards — every existing test
 * reaches `ApiLoginFlow` only through an anonymous subclass that overrides
 * `establishSession()` itself, so the real class had never run at all.
 */
#[CoversClass(ApiLoginFlow::class)]
class ApiLoginFlowTest extends TestCase
{
    /** `establishSession()` always answers true: no session to establish, only a token to mint. */
    public function testEstablishSessionAlwaysAnswersTrue(): void
    {
        // Arrange
        $flow   = new ApiLoginFlow();
        $method = new \ReflectionMethod(ApiLoginFlow::class, 'establishSession');

        // Act & Assert
        $this->assertTrue($method->invoke($flow, 42, true));
        $this->assertTrue($method->invoke($flow, 42, false));
    }

    /** With a resolver, credentials go through it, not through the real `Auth` service. */
    public function testVerifyCredentialsUsesTheGivenResolverWhenThereIsOne(): void
    {
        // Arrange
        $calls = [];
        $flow  = new ApiLoginFlow(function (string $username, string $password) use (&$calls): array {
            $calls[] = [$username, $password];

            return ['status' => true, 'uid' => 7];
        });
        $method = new \ReflectionMethod(ApiLoginFlow::class, 'verifyCredentials');

        // Act
        $result = $method->invoke($flow, 'alice', 'pw', true);

        // Assert
        $this->assertSame(['status' => true, 'uid' => 7], $result);
        $this->assertSame([['alice', 'pw']], $calls);
    }

    /** With no resolver, it falls back to the real flow's own check — `Auth::verifyCredentials()`. */
    public function testVerifyCredentialsFallsBackToTheParentWithNoResolver(): void
    {
        // Arrange
        $auth = $this->createMock(Auth::class);
        $auth->expects($this->once())
            ->method('verifyCredentials')
            ->with('alice', 'pw', false, true)
            ->willReturn(['status' => true, 'uid' => 7]);
        $flow   = new ApiLoginFlow(null, $auth);
        $method = new \ReflectionMethod(ApiLoginFlow::class, 'verifyCredentials');

        // Act
        $result = $method->invoke($flow, 'alice', 'pw', true);

        // Assert
        $this->assertSame(['status' => true, 'uid' => 7], $result);
    }
}
