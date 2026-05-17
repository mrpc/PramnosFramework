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

    // -------------------------------------------------------------------------
    // getAuthenticationMethods()
    // -------------------------------------------------------------------------

    /**
     * getAuthenticationMethods() must return a non-empty array of descriptor maps.
     *
     * Each entry must have 'method', 'name', and 'description' string keys — this
     * is the contract consumed by developer-facing UI renderers.
     */
    public function testGetAuthenticationMethodsReturnsDescriptors(): void
    {
        // Act
        $methods = OAuthPolicyHelper::getAuthenticationMethods();

        // Assert — non-empty, each entry has required keys
        $this->assertNotEmpty($methods);
        foreach ($methods as $entry) {
            $this->assertArrayHasKey('method', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('description', $entry);
            $this->assertIsString($entry['method']);
            $this->assertIsString($entry['name']);
            $this->assertIsString($entry['description']);
        }
    }

    /**
     * getAuthenticationMethods() must include 'none' (public client / PKCE)
     * as a descriptor entry even though it is excluded from the allowed-by-default list.
     *
     * 'none' is a valid auth method that clients can explicitly opt into; it must
     * appear in the descriptive registry so UIs can present it.
     */
    public function testGetAuthenticationMethodsIncludesNoneDescriptor(): void
    {
        // Act
        $methods  = OAuthPolicyHelper::getAuthenticationMethods();
        $methodIds = array_column($methods, 'method');

        // Assert — 'none' must appear as a descriptor (opt-in, not a default)
        $this->assertContains('none', $methodIds,
            "'none' must be in the descriptor registry even though it is not a default auth method");
    }

    // -------------------------------------------------------------------------
    // getGrantTypes()
    // -------------------------------------------------------------------------

    /**
     * getGrantTypes() must return a non-empty array of descriptor maps.
     *
     * Each entry must have 'method', 'name', and 'description' string keys.
     */
    public function testGetGrantTypesReturnsDescriptors(): void
    {
        // Act
        $grants = OAuthPolicyHelper::getGrantTypes();

        // Assert
        $this->assertNotEmpty($grants);
        foreach ($grants as $entry) {
            $this->assertArrayHasKey('method', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('description', $entry);
            $this->assertIsString($entry['method']);
        }
    }

    /**
     * getGrantTypes() must include the deprecated 'password' grant as a descriptor.
     *
     * Even though 'password' is excluded from the allowed-by-default list,
     * it must appear in the descriptive registry so operators can see it exists
     * and choose to allow it explicitly.
     */
    public function testGetGrantTypesIncludesPasswordGrantDescriptor(): void
    {
        // Act
        $grants   = OAuthPolicyHelper::getGrantTypes();
        $grantIds = array_column($grants, 'method');

        // Assert — 'password' must be in the descriptor even though not a default
        $this->assertContains('password', $grantIds,
            "'password' must be in the descriptor registry even though it is not a default grant");
    }

    // -------------------------------------------------------------------------
    // getWebhookTypes()
    // -------------------------------------------------------------------------

    /**
     * getWebhookTypes() must return a non-empty array of webhook event descriptors.
     *
     * Each entry must have 'type', 'name', and 'description' keys — different from
     * auth method descriptors which use 'method'. This is the contract for webhook
     * registration UIs.
     */
    public function testGetWebhookTypesReturnsDescriptors(): void
    {
        // Act
        $types = OAuthPolicyHelper::getWebhookTypes();

        // Assert
        $this->assertNotEmpty($types);
        foreach ($types as $entry) {
            $this->assertArrayHasKey('type', $entry);
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('description', $entry);
            $this->assertIsString($entry['type']);
            $this->assertIsString($entry['name']);
        }
    }

    /**
     * getWebhookTypes() must include the 'token_revoked' event type.
     *
     * Token revocation is a core OAuth2 event that all conforming implementations
     * must support for downstream notification.
     */
    public function testGetWebhookTypesIncludesTokenRevokedEvent(): void
    {
        // Act
        $types    = OAuthPolicyHelper::getWebhookTypes();
        $typeIds  = array_column($types, 'type');

        // Assert
        $this->assertContains('token_revoked', $typeIds,
            "'token_revoked' must be a supported webhook event type");
    }
}
