<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Discovery;
use Pramnos\Document\Document;
use Pramnos\Framework\Factory;

/**
 * `/.well-known/oauth-protected-resource` — the half of discovery that was missing.
 *
 * RFC 8414 says where the **authorization server** is. RFC 9728 says where the **resource** is
 * and which authorization servers it trusts, and until now an installation only answered the
 * first. A client with only that has to be told the rest out of band: configuration somebody
 * types, gets wrong, and cannot verify.
 *
 * It is also the first step of the Model Context Protocol's authorization flow — a client calls
 * a protected endpoint, is refused, is pointed at this document, and from there runs the ordinary
 * OAuth 2.1 exchange it already knows. Without it that chain stops before it starts.
 *
 * These assertions are against what the method actually renders, not against a copy of the array
 * it builds. A test that rebuilds the value it is checking passes when the method is deleted.
 */
#[CoversClass(Discovery::class)]
class ProtectedResourceMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Document::reset();
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        (new Discovery(null))->oauthProtectedResource();

        return (array) json_decode((string) Factory::getDocument('raw')->render(), true);
    }

    /**
     * Every field RFC 9728 requires, and the two an MCP client reads first.
     *
     * `resource` is what a token is audience-bound to; `authorization_servers` is where the
     * client goes to get one. A document missing either is a document that answers nothing —
     * and it would still be valid JSON, which is how this kind of gap survives a smoke test.
     */
    public function testItCarriesWhatAClientNeedsToFindTheAuthorizationServer(): void
    {
        // Act
        $metadata = $this->document();

        // Assert
        $this->assertArrayHasKey('resource', $metadata);
        $this->assertArrayHasKey('authorization_servers', $metadata);

        // Both name this installation. Asserted as a relationship rather than as a literal,
        // because `sURL` is what it is per installation — and a test that hardcoded a host
        // would pass on a value the running server never emits.
        $this->assertSame(rtrim(sURL, '/'), (string) $metadata['resource']);
        $this->assertSame([rtrim(sURL, '/')], $metadata['authorization_servers']);
        $this->assertIsList($metadata['authorization_servers'],
            'RFC 9728 defines this as an array, and a client that gets a string cannot iterate it');
    }

    /**
     * The scopes it advertises are the ones the installation actually has.
     *
     * A hardcoded list drifts the first time somebody adds a scope, and the failure is a client
     * asking for something the server will refuse — after the person has already been sent
     * through a consent screen.
     */
    public function testTheScopesAreTheOnesThisInstallationDefines(): void
    {
        // Act
        $metadata = $this->document();

        // Assert
        $this->assertSame(
            array_keys(\Pramnos\Auth\Scopes::getScopeDescriptions()),
            $metadata['scopes_supported'] ?? []
        );
    }

    /**
     * Only the `Authorization` header is offered.
     *
     * RFC 6750 also allows a bearer token in a form body and in a query string. The query-string
     * form puts a credential in every access log, proxy log and `Referer` between here and the
     * client, and this framework does not accept it — so it must not advertise it. An unstated
     * capability is one a client tries anyway.
     */
    public function testItOffersOnlyTheHeaderForm(): void
    {
        // Act
        $metadata = $this->document();

        // Assert
        $this->assertSame(['header'], $metadata['bearer_methods_supported'] ?? []);
    }

    /**
     * The resource identifier has no trailing slash.
     *
     * It is compared as a string when a token's audience is checked. `https://example.com` and
     * `https://example.com/` are the same address and different strings, and the mismatch shows
     * up as a token that is valid and rejected — the least debuggable failure in OAuth.
     */
    public function testTheResourceIdentifierIsNormalised(): void
    {
        // Act
        $metadata = $this->document();

        // Assert
        $this->assertStringEndsNotWith('/', (string) $metadata['resource']);

        foreach ((array) $metadata['authorization_servers'] as $issuer) {
            $this->assertStringEndsNotWith('/', (string) $issuer);
        }
    }
}
