<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\OAuthPolicyHelper;

/**
 * The default grants and client authentication methods an installation sets in `app.php`
 * (`authserver.default_grants`, `authserver.default_auth_methods`).
 */
#[CoversClass(OAuthPolicyHelper::class)]
class OAuthPolicyConfiguredDefaultsTest extends TestCase
{
    private bool $hadConfig = false;

    private mixed $savedConfig = null;

    /** Start from an application without an `authserver` section. */
    protected function setUp(): void
    {
        $app = Application::getInstance();
        $this->hadConfig   = isset($app->applicationInfo['authserver']);
        $this->savedConfig = $app->applicationInfo['authserver'] ?? null;
        unset($app->applicationInfo['authserver']);
    }

    /** Put back whatever `authserver` section the application had. */
    protected function tearDown(): void
    {
        $app = Application::getInstance();
        if ($this->hadConfig) {
            $app->applicationInfo['authserver'] = $this->savedConfig;
        } else {
            unset($app->applicationInfo['authserver']);
        }
    }

    /**
     * Without the keys, the built-in lists apply — password among the grants.
     */
    public function testWithoutConfigTheBuiltInListsApply(): void
    {
        // Act
        $grants  = OAuthPolicyHelper::getDefaultAllowedGrantTypes();
        $methods = OAuthPolicyHelper::getDefaultAllowedAuthMethods();

        // Assert
        $this->assertContains('password', $grants);
        $this->assertNotContains('jwt_bearer', $grants);
        $this->assertSame(['client_secret_basic', 'client_secret_post', 'private_key_jwt'], $methods);
        $this->assertSame([], OAuthPolicyHelper::getDefaultAllowedScopes(), 'no default scopes unless configured');
    }

    /**
     * The configured lists replace the built-in ones.
     */
    public function testConfiguredListsReplaceTheBuiltInOnes(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['authserver'] = [
            'default_grants'       => ['authorization_code', 'refresh_token'],
            'default_auth_methods' => ['private_key_jwt'],
        ];

        // Act + Assert
        $this->assertSame(['authorization_code', 'refresh_token'], OAuthPolicyHelper::getDefaultAllowedGrantTypes());
        $this->assertSame(['private_key_jwt'], OAuthPolicyHelper::getDefaultAllowedAuthMethods());
    }

    /**
     * jwt_bearer (a token for any user) and none (no client authentication) are never defaults:
     * listed in config, each is dropped and the operator is warned, rather than every
     * application without a policy row silently receiving it.
     */
    public function testTheDangerousValuesAreDroppedWithAWarning(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['authserver'] = [
            'default_grants'       => ['client_credentials', 'jwt_bearer'],
            'default_auth_methods' => ['none', 'client_secret_basic'],
        ];
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        // Act
        try {
            $grants  = OAuthPolicyHelper::getDefaultAllowedGrantTypes();
            $methods = OAuthPolicyHelper::getDefaultAllowedAuthMethods();
        } finally {
            restore_error_handler();
        }

        // Assert
        $this->assertSame(['client_credentials'], $grants);
        $this->assertSame(['client_secret_basic'], $methods);
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString("'jwt_bearer'", $warnings[0]);
        $this->assertStringContainsString("'none'", $warnings[1]);
    }

    /**
     * A `system:` scope is never a default scope: every client could then ask for administrative
     * access. Listed in config, it is dropped with a warning; the others are kept.
     */
    public function testASystemScopeIsNeverADefaultScope(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['authserver'] = ['default_scopes' => ['profile', 'system:admin', 'user']];
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        // Act
        try {
            $scopes = OAuthPolicyHelper::getDefaultAllowedScopes();
        } finally {
            restore_error_handler();
        }

        // Assert
        $this->assertSame(['profile', 'user'], $scopes);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("'system:admin'", $warnings[0]);
    }
}
