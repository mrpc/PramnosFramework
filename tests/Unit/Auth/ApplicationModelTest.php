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

    // ── parseRedirectUris() ──────────────────────────────────────────────────

    /**
     * The static parser is what the authorization endpoint asks, so it has to answer the
     * shapes production data actually holds — and refuse the ones that would let a request
     * with no `redirect_uri` match a registration.
     *
     * An empty entry is the dangerous one: a `callback` of `,` parses into two empty
     * strings, and an empty string equals an absent parameter. That the endpoint also
     * rejects an empty `redirect_uri` earlier is not a reason to leave it — the two checks
     * would then only be safe in the order they happen to run in.
     *
     * @param string|null   $callback The raw column value
     * @param array<string> $expected What is registered
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('callbackColumns')]
    public function testParseRedirectUrisReadsBothStoredShapes(?string $callback, array $expected): void
    {
        // Act & Assert
        $this->assertSame(
            $expected,
            \Pramnos\Auth\Application::parseRedirectUris($callback)
        );
    }

    /**
     * @return array<string,array{string|null,array<string>}>
     */
    public static function callbackColumns(): array
    {
        return [
            'null'              => [null, []],
            'empty'             => ['', []],
            'whitespace'        => ['   ', []],
            'one uri'           => ['https://a.example/cb', ['https://a.example/cb']],
            'comma list'        => ['https://a.example/cb,https://b.example/cb',
                                    ['https://a.example/cb', 'https://b.example/cb']],
            'comma list spaced' => [' https://a.example/cb , https://b.example/cb ',
                                    ['https://a.example/cb', 'https://b.example/cb']],
            'trailing comma'    => ['https://a.example/cb,', ['https://a.example/cb']],
            'commas only'       => [',,', []],
            'json array'        => ['["https://a.example/cb","https://b.example/cb"]',
                                    ['https://a.example/cb', 'https://b.example/cb']],
            'json with blanks'  => ['["https://a.example/cb","","  "]', ['https://a.example/cb']],
            'json empty array'  => ['[]', []],
            // A malformed column should not produce a registration nobody wrote: a JSON
            // array of numbers is not a list of URIs, and casting one to a string would
            // register `1`.
            'json of numbers'   => ['[1,2]', []],
            'json object'       => ['{"a":"https://a.example/cb"}', ['https://a.example/cb']],
            // Not JSON, so it is read as a list — and the one piece it yields starts with
            // `[`, which is not a destination a browser could be sent to. It used to be
            // registered anyway: a string that can never match any request, so the client
            // was registered and permanently locked out. Now it parses to nothing, the
            // client counts as unregistered, and the condition is logged.
            'broken json'       => ['["https://a.example/cb"', []],

            // A human types this field. A parser insisting on one separator silently drops
            // a callback, which is a registration wrong in the direction that locks people
            // out — and that is exactly what happened: one production value in the column,
            // and a customer's localhost login stopped working while production kept
            // working. `scope` in the same table already accepts whitespace.
            'spaces'            => ['https://a.example/cb https://b.example/cb',
                                    ['https://a.example/cb', 'https://b.example/cb']],
            'newlines'          => ["https://a.example/cb\nhttps://b.example/cb",
                                    ['https://a.example/cb', 'https://b.example/cb']],
            'newline and comma' => ["https://a.example/cb,\n  https://b.example/cb\n",
                                    ['https://a.example/cb', 'https://b.example/cb']],
            'tabs'              => ["https://a.example/cb\t\thttps://b.example/cb",
                                    ['https://a.example/cb', 'https://b.example/cb']],
            // The one shape a whitespace split must not break: a native scheme with no
            // spaces in it, alongside an http one.
            'native and http'   => ['hwmapp://oauth https://a.example/cb',
                                    ['hwmapp://oauth', 'https://a.example/cb']],
        ];
    }

    // ── Schemes that are never a callback ────────────────────────────────────

    /**
     * A scheme that is only ever script is not a registration.
     *
     * The scheme cannot be restricted to `http(s)` — a mobile client returns to
     * `hwmapp://oauth/callback`, and that is real. What can be refused is the set that has
     * no other use, and the reason to refuse it rather than trust a URL parser is that
     * `javascript://x/%0aalert(1)` is structurally a valid URL with a host and a path.
     *
     * This value reaches a `Location` header and a CSP `form-action` source, so a
     * registration is not the only thing at stake.
     *
     * @param string $uri A candidate callback
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedSchemes')]
    public function testAScriptSchemeIsNotRegistered(string $uri): void
    {
        // Act
        $parsed = \Pramnos\Auth\Application::parseRedirectUris(
            'https://good.example/cb ' . $uri
        );

        // Assert — the legitimate one survives and the other is gone
        $this->assertSame(['https://good.example/cb'], $parsed);
        $this->assertTrue(\Pramnos\Auth\Application::isRefusedScheme($uri));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function refusedSchemes(): array
    {
        return [
            'javascript'          => ['javascript:alert(1)'],
            'javascript with host' => ['javascript://x/%0aalert(1)'],
            'JavaScript uppercase' => ['JavaScript:alert(1)'],
            'javascript padded'    => ["\tjavascript:alert(1)"],
            'data'                 => ['data:text/html,<script>alert(1)</script>'],
            'vbscript'             => ['vbscript:msgbox(1)'],
            'file'                 => ['file:///etc/passwd'],
            'blob'                 => ['blob:https://x/uuid'],
            'about'                => ['about:blank'],
        ];
    }

    /**
     * And a scheme that is merely unusual is kept.
     *
     * The control. Refusing everything but `http(s)` would break every native client, so
     * the list has to be a deny-list — and a deny-list that is too eager is the same
     * lockout as a single-valued column.
     *
     * @param string $uri A candidate callback that must survive
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedSchemes')]
    public function testAnUnusualButLegitimateSchemeIsKept(string $uri): void
    {
        // Act & Assert
        $this->assertSame([$uri], \Pramnos\Auth\Application::parseRedirectUris($uri));
        $this->assertFalse(\Pramnos\Auth\Application::isRefusedScheme($uri));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function acceptedSchemes(): array
    {
        return [
            'https'          => ['https://a.example/cb'],
            'http localhost' => ['http://localhost:3000/callback'],
            'native scheme'  => ['hwmapp://oauth/callback'],
            'reverse dns'    => ['com.example.app://oauth'],
            'no scheme'      => ['/relative/callback'],
        ];
    }
}
