<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Application;

/**
 * Tests for the pure utility methods of the Auth Application OAuth2 model.
 *
 * Methods that touch the database (load, save, loadByApiKey, validateCredentials,
 * assignSystemUser with non-zero appid) are covered by integration tests.
 * This file focuses on the stateless helper methods that can run without a DB.
 */
#[CoversClass(Application::class)]
class ApplicationModelTest extends TestCase
{
    /**
     * Helper: create an Application instance without going through the
     * constructor (which would require a real Controller + DB).
     * Properties are set via Reflection so the pure methods can be exercised.
     */
    private function makeApp(array $props = []): Application
    {
        $app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        foreach ($props as $k => $v) {
            $app->$k = $v;
        }
        return $app;
    }

    // ── getClientIdentifier() ─────────────────────────────────────────────────

    /**
     * getClientIdentifier() must return the value of the apikey property
     * as the OAuth2 client_id.
     */
    public function testGetClientIdentifierReturnsApikey(): void
    {
        // Arrange
        $app = $this->makeApp(['apikey' => 'my-client-id']);

        // Act + Assert
        $this->assertSame('my-client-id', $app->getClientIdentifier());
    }

    /**
     * getClientIdentifier() must return null when apikey is not set.
     */
    public function testGetClientIdentifierReturnsNullWhenApikeyNull(): void
    {
        // Arrange
        $app = $this->makeApp(['apikey' => null]);

        // Act + Assert
        $this->assertNull($app->getClientIdentifier());
    }

    // ── getClientName() ───────────────────────────────────────────────────────

    /**
     * getClientName() must return the application name (used in consent screens).
     */
    public function testGetClientNameReturnsName(): void
    {
        // Arrange
        $app = $this->makeApp(['name' => 'My OAuth App']);

        // Act + Assert
        $this->assertSame('My OAuth App', $app->getClientName());
    }

    // ── isConfidential() ─────────────────────────────────────────────────────

    /**
     * All Auth Application clients are confidential (require a secret).
     */
    public function testIsConfidentialAlwaysReturnsTrue(): void
    {
        // Arrange
        $app = $this->makeApp();

        // Act + Assert — public clients not supported in this framework
        $this->assertTrue($app->isConfidential());
    }

    // ── getScopes() ───────────────────────────────────────────────────────────

    /**
     * getScopes() must return an empty array when scope is null.
     */
    public function testGetScopesReturnsEmptyArrayWhenNull(): void
    {
        // Arrange
        $app = $this->makeApp(['scope' => null]);

        // Act + Assert
        $this->assertSame([], $app->getScopes());
    }

    /**
     * getScopes() must split a space-separated scope string into an array.
     */
    public function testGetScopesSplitsSpaceSeparatedString(): void
    {
        // Arrange
        $app = $this->makeApp(['scope' => 'read write admin']);

        // Act + Assert
        $this->assertSame(['read', 'write', 'admin'], $app->getScopes());
    }

    /**
     * getScopes() must handle leading/trailing spaces by trimming.
     */
    public function testGetScopesTrimsWhitespace(): void
    {
        // Arrange
        $app = $this->makeApp(['scope' => '  read write  ']);

        // Act
        $scopes = $app->getScopes();

        // Assert — trim happens before explode
        $this->assertContains('read', $scopes);
        $this->assertContains('write', $scopes);
    }

    // ── hasScope() ────────────────────────────────────────────────────────────

    /**
     * hasScope() must return true when the requested scope is in the allowed list.
     */
    public function testHasScopeReturnsTrueForAllowedScope(): void
    {
        // Arrange
        $app = $this->makeApp(['scope' => 'read write']);

        // Act + Assert
        $this->assertTrue($app->hasScope('read'));
        $this->assertTrue($app->hasScope('write'));
    }

    /**
     * hasScope() must return false when the requested scope is not allowed.
     */
    public function testHasScopeReturnsFalseForDisallowedScope(): void
    {
        // Arrange
        $app = $this->makeApp(['scope' => 'read write']);

        // Act + Assert
        $this->assertFalse($app->hasScope('admin'));
        $this->assertFalse($app->hasScope('delete'));
    }

    // ── getRedirectUri() ─────────────────────────────────────────────────────

    /**
     * getRedirectUri() must return empty string when callback is empty.
     */
    public function testGetRedirectUriReturnsEmptyStringWhenCallbackEmpty(): void
    {
        // Arrange
        $app = $this->makeApp(['callback' => null]);

        // Act + Assert
        $this->assertSame('', $app->getRedirectUri());
    }

    /**
     * getRedirectUri() must return the first URI from a JSON array callback.
     */
    public function testGetRedirectUriReturnsFirstFromJsonArray(): void
    {
        // Arrange
        $app = $this->makeApp(['callback' => '["https://example.com/cb","https://other.com/cb"]']);

        // Act + Assert
        $this->assertSame('https://example.com/cb', $app->getRedirectUri());
    }

    /**
     * getRedirectUri() must return the first URI from a comma-separated callback.
     */
    public function testGetRedirectUriReturnsFirstFromCommaSeparated(): void
    {
        // Arrange
        $app = $this->makeApp(['callback' => 'https://example.com/cb, https://other.com/cb']);

        // Act + Assert
        $this->assertSame('https://example.com/cb', $app->getRedirectUri());
    }

    // ── getRedirectUris() ────────────────────────────────────────────────────

    /**
     * getRedirectUris() must return an empty array when callback is empty.
     */
    public function testGetRedirectUrisReturnsEmptyArrayWhenCallbackEmpty(): void
    {
        // Arrange
        $app = $this->makeApp(['callback' => '']);

        // Act + Assert
        $this->assertSame([], $app->getRedirectUris());
    }

    /**
     * getRedirectUris() must decode a JSON array and return all URIs.
     */
    public function testGetRedirectUrisDecodesJsonArray(): void
    {
        // Arrange
        $app = $this->makeApp(['callback' => '["https://a.com","https://b.com"]']);

        // Act + Assert
        $this->assertSame(['https://a.com', 'https://b.com'], $app->getRedirectUris());
    }

    /**
     * getRedirectUris() must split a comma-separated callback into an array,
     * trimming whitespace from each entry.
     */
    public function testGetRedirectUrisSplitsCommaSeparated(): void
    {
        // Arrange
        $app = $this->makeApp(['callback' => 'https://a.com, https://b.com , https://c.com']);

        // Act
        $uris = $app->getRedirectUris();

        // Assert
        $this->assertCount(3, $uris);
        $this->assertSame('https://a.com', $uris[0]);
        $this->assertSame('https://b.com', $uris[1]);
        $this->assertSame('https://c.com', $uris[2]);
    }

    // ── assignSystemUser() early-return ──────────────────────────────────────

    /**
     * assignSystemUser() must return false immediately when appid is 0 (new record).
     * This avoids a DB update on an unsaved record.
     */
    public function testAssignSystemUserReturnsFalseWhenAppidIsZero(): void
    {
        // Arrange
        $app = $this->makeApp(['appid' => 0]);

        // Act + Assert — no DB query should be attempted
        $this->assertFalse($app->assignSystemUser(42));
    }
}
