<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Addon\Addon;
use Pramnos\Auth\Auth;

/**
 * Characterization tests for legacy Auth behavior.
 *
 * These tests capture observable behavior around addon-driven authentication,
 * login/logout triggers, and singleton lifecycle before any internal refactor.
 */
class AuthCharacterizationTest extends TestCase
{
    /**
     * Preserve and restore addon registry to avoid cross-test side effects.
     *
     * @var array<string, array<string, object>>
     */
    private array $originalAddons = [];

    protected function setUp(): void
    {
        // Arrange
        $this->originalAddons = $this->getAddonRegistry();
        $this->setAddonRegistry([]);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        // Arrange
        $this->setAddonRegistry($this->originalAddons);
    }

    /**
     * Ensures getInstance returns a stable singleton reference.
     *
     * This behavior is relied upon by legacy codepaths that keep global auth state.
     */
    public function testGetInstanceReturnsSameObjectReference(): void
    {
        // Arrange
        $first = Auth::getInstance();

        // Act
        $second = Auth::getInstance();

        // Assert
        $this->assertSame($first, $second, 'Auth::getInstance must return the same instance.');
    }

    /**
     * Ensures successful addon authentication returns true, stores lastResponse,
     * and triggers onLogin hooks for user addons.
     */
    public function testAuthReturnsTrueAndTriggersLoginForSuccessfulAddon(): void
    {
        // Arrange
        $auth = new Auth();
        $authAddon = new class {
            public array $captured = [];

            public function onAuth(string $username, string $password, bool $remember, bool $encryptedPassword, bool $validate): array
            {
                $this->captured = [$username, $password, $remember, $encryptedPassword, $validate];

                return [
                    'status' => true,
                    'message' => 'ok',
                    'userid' => 42,
                ];
            }
        };
        $userAddon = new class {
            public array $loginPayloads = [];

            public function onLogin(array $payload): void
            {
                $this->loginPayloads[] = $payload;
            }
        };

        $this->setAddonRegistry([
            'auth' => ['primary' => $authAddon],
            'user' => ['listener' => $userAddon],
        ]);

        // Act
        $result = $auth->auth('alice', 'secret', false, true, false);

        // Assert
        $this->assertTrue($result, 'Authentication should succeed when addon returns status=true.');
        $this->assertSame(['alice', 'secret', false, true, false], $authAddon->captured);
        $this->assertIsArray($auth->lastResponse);
        // This proves the successful addon response is preserved for downstream consumers.
        $this->assertSame(42, $auth->lastResponse['userid']);
        $this->assertCount(1, $userAddon->loginPayloads, 'onLogin should be triggered exactly once.');
    }

    /**
     * Ensures failed addon responses produce false and keep the last response
     * from the most recently executed auth addon.
     */
    public function testAuthReturnsFalseAndKeepsLastAddonResponseOnFailure(): void
    {
        // Arrange
        $auth = new Auth();
        $firstAddon = new class {
            public function onAuth(): array
            {
                return ['status' => false, 'message' => 'first'];
            }
        };
        $secondAddon = new class {
            public function onAuth(): array
            {
                return ['status' => false, 'message' => 'second'];
            }
        };

        $this->setAddonRegistry([
            'auth' => ['one' => $firstAddon, 'two' => $secondAddon],
        ]);

        // Act
        $result = $auth->auth('alice', 'wrong-password');

        // Assert
        $this->assertFalse($result, 'Authentication should fail when no addon returns status=true.');
        $this->assertIsArray($auth->lastResponse);
        // This proves iteration order determines which failed response remains visible.
        $this->assertSame('second', $auth->lastResponse['message']);
    }

    /**
     * Ensures authCheck triggers AuthCheck hooks for auth addons.
     */
    public function testAuthCheckTriggersAddonAuthCheckHandlers(): void
    {
        // Arrange
        $auth = new Auth();
        $authAddon = new class {
            public int $counter = 0;

            public function onAuthCheck(): void
            {
                $this->counter++;
            }
        };
        $this->setAddonRegistry([
            'auth' => ['primary' => $authAddon],
        ]);

        // Act
        $auth->authCheck();

        // Assert
        $this->assertSame(1, $authAddon->counter, 'AuthCheck hook should run once for the auth addon.');
    }

    /**
     * Ensures logout flips session state to false and triggers Logout hooks.
     */
    public function testLogoutSetsSessionLoggedFalseAndTriggersAddonLogoutHandlers(): void
    {
        // Arrange
        $auth = new Auth();
        $_SESSION['logged'] = true;

        $userAddon = new class {
            public int $counter = 0;

            public function onLogout(): void
            {
                $this->counter++;
            }
        };
        $this->setAddonRegistry([
            'user' => ['listener' => $userAddon],
        ]);

        // Act
        $auth->logout();

        // Assert
        $this->assertFalse($_SESSION['logged'], 'Logout must mark session as not logged in.');
        $this->assertSame(1, $userAddon->counter, 'Logout hook should run once for user addon listeners.');
    }

    /**
     * @return array<string, array<string, object>>
     */
    private function getAddonRegistry(): array
    {
        $reflection = new \ReflectionProperty(Addon::class, '_addons');
        $reflection->setAccessible(true);
        /** @var array<string, array<string, object>> $addons */
        $addons = $reflection->getValue();

        return $addons;
    }

    /**
     * @param array<string, array<string, object>> $addons
     */
    private function setAddonRegistry(array $addons): void
    {
        $reflection = new \ReflectionProperty(Addon::class, '_addons');
        $reflection->setAccessible(true);
        $reflection->setValue(null, $addons);
    }
}
