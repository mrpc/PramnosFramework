<?php

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\OAuthPolicyHelper;

/**
 * Unit tests for Pramnos\Auth\OAuthPolicyHelper.
 *
 * OAuthPolicyHelper is a pure static class with no database interaction.
 *
 * Coverage:
 * - getDefaultAllowedAuthMethods() returns a non-empty string array
 * - getDefaultAllowedAuthMethods() includes expected standard methods
 * - getDefaultAllowedAuthMethods() does NOT include 'none' (public client)
 * - getDefaultAllowedGrantTypes() returns a non-empty string array
 * - getDefaultAllowedGrantTypes() includes expected standard grant types
 * - getDefaultAllowedGrantTypes() does NOT include 'password' (deprecated)
 */
class OAuthPolicyHelperTest extends TestCase
{
    // -------------------------------------------------------------------------
    // getDefaultAllowedAuthMethods()
    // -------------------------------------------------------------------------

    /**
     * getDefaultAllowedAuthMethods() must return a non-empty list of strings.
     */
    public function testGetDefaultAllowedAuthMethodsReturnsStrings(): void
    {
        // Act
        $methods = OAuthPolicyHelper::getDefaultAllowedAuthMethods();

        // Assert
        $this->assertNotEmpty($methods, 'at least one auth method must be allowed by default');
        foreach ($methods as $method) {
            $this->assertIsString($method, 'each auth method must be a string');
            $this->assertNotEmpty($method);
        }
    }

    /**
     * Standard confidential-client auth methods must be in the default list.
     *
     * 'client_secret_basic' (HTTP Basic), 'client_secret_post' (request body),
     * and 'private_key_jwt' (RFC 7523) are the three methods appropriate for
     * confidential clients. All three must be allowed by default.
     */
    public function testGetDefaultAllowedAuthMethodsIncludesStandardMethods(): void
    {
        // Act
        $methods = OAuthPolicyHelper::getDefaultAllowedAuthMethods();

        // Assert
        foreach (['client_secret_basic', 'client_secret_post', 'private_key_jwt'] as $expected) {
            $this->assertContains($expected, $methods,
                "standard auth method '{$expected}' must be in the default list");
        }
    }

    /**
     * 'none' must NOT be in the default allowed auth methods.
     *
     * 'none' is the public-client / PKCE auth method — it removes all client
     * authentication. It must be explicitly opted in per client and must never
     * be granted by default.
     */
    public function testGetDefaultAllowedAuthMethodsExcludesNone(): void
    {
        // Act
        $methods = OAuthPolicyHelper::getDefaultAllowedAuthMethods();

        // Assert
        $this->assertNotContains('none', $methods,
            "'none' (public client / PKCE) must not be allowed by default — it removes client authentication');");
    }

    // -------------------------------------------------------------------------
    // getDefaultAllowedGrantTypes()
    // -------------------------------------------------------------------------

    /**
     * getDefaultAllowedGrantTypes() must return a non-empty list of strings.
     */
    public function testGetDefaultAllowedGrantTypesReturnsStrings(): void
    {
        // Act
        $types = OAuthPolicyHelper::getDefaultAllowedGrantTypes();

        // Assert
        $this->assertNotEmpty($types);
        foreach ($types as $type) {
            $this->assertIsString($type);
            $this->assertNotEmpty($type);
        }
    }

    /**
     * Standard grant types must be included by default.
     *
     * 'authorization_code', 'client_credentials', 'device_code', and
     * 'refresh_token' are the current standard grant types that most
     * OAuth applications require.
     */
    public function testGetDefaultAllowedGrantTypesIncludesStandardTypes(): void
    {
        // Act
        $types = OAuthPolicyHelper::getDefaultAllowedGrantTypes();

        // Assert
        foreach (['authorization_code', 'client_credentials', 'refresh_token'] as $expected) {
            $this->assertContains($expected, $types,
                "grant type '{$expected}' must be allowed by default");
        }
    }

    /**
     * The 'password' grant must NOT be included by default.
     *
     * The Resource Owner Password Credentials grant is deprecated by RFC 9126
     * (OAuth 2.1) due to security concerns. It must not be granted to clients
     * without explicit opt-in.
     */
    public function testGetDefaultAllowedGrantTypesExcludesPasswordGrant(): void
    {
        // Act
        $types = OAuthPolicyHelper::getDefaultAllowedGrantTypes();

        // Assert
        $this->assertNotContains('password', $types,
            "'password' grant (deprecated in OAuth 2.1) must not be in the default list");
    }
}
